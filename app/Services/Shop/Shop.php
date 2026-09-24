<?php
declare(strict_types=1);

namespace HeleXa\Services\Shop;

use HeleXa\Models\PackageRepository;
use HeleXa\Models\SettingRepository;
use HeleXa\Models\ShopRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivationService;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Notify;
use HeleXa\Services\Settings;

/**
 * The store's rules in one place: what a cart costs, whether a discount code
 * applies, turning a cart into an order, and handing over what was bought
 * once the order is paid.
 */
final class Shop
{
    public const TONES = ['orange', 'indigo', 'violet', 'blue', 'sky', 'teal', 'green', 'amber', 'rose', 'pink', 'red', 'slate'];

    /** Everything the admin can change about how the store looks. */
    public const THEME_DEFAULTS = [
        'title'        => 'فروشگاه',
        'subtitle'     => 'پکیج‌ها و محصولات آموزشی — با دسترسی فوری بعد از پرداخت',
        'tone'         => 'orange',
        'hero'         => 'gradient',  // gradient | image | minimal
        'banner'       => '',
        'notice'       => '',
        'layout'       => 'grid',      // grid | list
        'columns'      => 3,
        'card'         => 'glass',     // glass | solid | outline
        'radius'       => 22,
        'show_search'  => true,
        'show_categories' => true,
        'show_sold'    => true,
        'show_compare' => true,
        'trust'        => ['دسترسی فوری بعد از پرداخت', 'پشتیبانی در همین سایت', 'پرداخت امن'],
        'footer'       => '',
        'currency'     => 'تومان',
    ];

    public const CARD_DEFAULTS = [
        'enabled' => false,
        'number'  => '',
        'holder'  => '',
        'bank'    => '',
        'sheba'   => '',
        'note'    => 'مبلغ را دقیقاً به همین کارت واریز کن و تصویر رسید را بارگذاری کن. سفارش پس از بررسی (معمولاً کمتر از چند ساعت) فعال می‌شود.',
    ];

    public static function theme(): array
    {
        $saved = Settings::get('shop_theme', []);
        $t = array_merge(self::THEME_DEFAULTS, is_array($saved) ? $saved : []);
        $t['columns'] = max(2, min(4, (int) $t['columns']));
        $t['radius']  = max(6, min(32, (int) $t['radius']));
        $t['tone']    = in_array($t['tone'], self::TONES, true) ? $t['tone'] : 'orange';
        return $t;
    }

    public static function card(): array
    {
        $saved = Settings::get('shop_card', []);
        return array_merge(self::CARD_DEFAULTS, is_array($saved) ? $saved : []);
    }

    public static function cardEnabled(): bool
    {
        $c = self::card();
        return (bool) $c['enabled'] && preg_replace('/\D/', '', (string) $c['number']) !== '';
    }

    public static function save(string $key, array $value, ?int $actorId): void
    {
        (new SettingRepository())->set($key, (string) json_encode($value, JSON_UNESCAPED_UNICODE), 'json', $actorId);
        Settings::flush();
    }

    public static function money(int $amount): string
    {
        return fa(number_format($amount)) . ' ' . self::theme()['currency'];
    }

    /** Card numbers are shown in groups of four, left to right. */
    public static function cardNumber(string $n): string
    {
        return trim(chunk_split(preg_replace('/\D/', '', $n) ?? '', 4, ' '));
    }

    public static function discountPercent(array $p): int
    {
        $cmp = (int) ($p['compare_price'] ?? 0);
        return $cmp > (int) $p['price'] && $cmp > 0 ? (int) round(100 - ((int) $p['price'] * 100 / $cmp)) : 0;
    }

    /* ============================================================== cart */

    /** Whether one more of this product may go into the cart, and why not. */
    public static function canAdd(array $product, int $userId, int $wantQty): ?string
    {
        if ($product['status'] !== 'published') {
            return 'این محصول در حال حاضر فروخته نمی‌شود.';
        }
        if ($product['stock'] !== null && (int) $product['stock'] < $wantQty) {
            return (int) $product['stock'] <= 0 ? 'موجودی این محصول تمام شده است.' : 'بیشتر از ' . fa((string) $product['stock']) . ' عدد موجود نیست.';
        }
        if ($wantQty > max(1, (int) $product['max_per_order'])) {
            return 'از این محصول حداکثر ' . fa((string) max(1, (int) $product['max_per_order'])) . ' عدد در هر سفارش.';
        }
        if ($product['kind'] === 'package' && !empty($product['package_id']) && self::holdsForever($userId, (int) $product['package_id'])) {
            return 'این پکیج را از قبل بدون محدودیت زمانی داری.';
        }
        return null;
    }

    private static function holdsForever(int $userId, int $packageId): bool
    {
        $a = (new PackageRepository())->activation($userId, $packageId);
        return $a !== null && $a['status'] === 'active' && $a['ends_at'] === null;
    }

    /**
     * Prices a cart with an optional discount code.
     *
     * @param list<array> $lines products with qty
     * @return array{lines:list<array>, subtotal:int, discount:int, total:int, count:int, coupon:?array, couponError:?string, savings:int}
     */
    public static function price(array $lines, ?string $code, int $userId): array
    {
        $subtotal = 0;
        $count = 0;
        $savings = 0;
        foreach ($lines as &$l) {
            $l['qty'] = max(1, (int) $l['qty']);
            $l['line_total'] = (int) $l['price'] * $l['qty'];
            $subtotal += $l['line_total'];
            $count += $l['qty'];
            $cmp = (int) ($l['compare_price'] ?? 0);
            if ($cmp > (int) $l['price']) {
                $savings += ($cmp - (int) $l['price']) * $l['qty'];
            }
        }
        unset($l);

        $out = ['lines' => $lines, 'subtotal' => $subtotal, 'discount' => 0, 'total' => $subtotal, 'count' => $count,
                'coupon' => null, 'couponError' => null, 'savings' => $savings];
        $code = trim((string) $code);
        if ($code === '' || $lines === []) {
            return $out;
        }
        $coupon = (new ShopRepository())->findCoupon($code);
        $error = $coupon === null ? 'این کد تخفیف وجود ندارد.' : self::couponProblem($coupon, $lines, $subtotal, $userId);
        if ($error !== null) {
            $out['couponError'] = $error;
            return $out;
        }
        $eligible = self::eligibleTotal($coupon, $lines);
        $discount = $coupon['kind'] === 'percent'
            ? (int) floor($eligible * min(100, (int) $coupon['amount']) / 100)
            : min($eligible, (int) $coupon['amount']);
        if ($coupon['max_discount'] !== null) {
            $discount = min($discount, (int) $coupon['max_discount']);
        }
        $out['discount'] = max(0, min($subtotal, $discount));
        $out['total'] = $subtotal - $out['discount'];
        $out['coupon'] = $coupon;
        return $out;
    }

    private static function couponProblem(array $c, array $lines, int $subtotal, int $userId): ?string
    {
        $now = date('Y-m-d H:i:s');
        if ($c['status'] !== 'active') {
            return 'این کد تخفیف غیرفعال است.';
        }
        if ($c['starts_at'] !== null && $c['starts_at'] > $now) {
            return 'این کد تخفیف هنوز شروع نشده است.';
        }
        if ($c['ends_at'] !== null && $c['ends_at'] < $now) {
            return 'مهلت این کد تخفیف تمام شده است.';
        }
        if ($c['min_total'] !== null && $subtotal < (int) $c['min_total']) {
            return 'این کد برای خریدهای بالای ' . self::money((int) $c['min_total']) . ' است.';
        }
        $repo = new ShopRepository();
        if ($c['usage_limit'] !== null && $repo->couponUses((int) $c['id']) >= (int) $c['usage_limit']) {
            return 'ظرفیت این کد تخفیف پر شده است.';
        }
        if ($c['per_user_limit'] !== null && $repo->couponUses((int) $c['id'], $userId) >= (int) $c['per_user_limit']) {
            return 'این کد را قبلاً استفاده کرده‌ای.';
        }
        if (self::eligibleTotal($c, $lines) <= 0) {
            return 'این کد برای محصولات سبد تو نیست.';
        }
        return null;
    }

    private static function eligibleTotal(array $c, array $lines): int
    {
        $only = $c['product_ids'] !== null ? array_map('intval', (array) json_decode((string) $c['product_ids'], true)) : [];
        $sum = 0;
        foreach ($lines as $l) {
            if ($only === [] || in_array((int) $l['id'], $only, true)) {
                $sum += (int) $l['price'] * max(1, (int) $l['qty']);
            }
        }
        return $sum;
    }

    /** Anything physical in the cart means an address is needed. */
    public static function needsShipping(array $lines): bool
    {
        foreach ($lines as $l) {
            if ($l['kind'] === 'physical') {
                return true;
            }
        }
        return false;
    }

    /* ======================================================== fulfilment */

    /**
     * Marks an order paid (only once, whoever gets there first) and hands
     * over what it bought: each package line activates its package for the
     * product's number of days, extending an activation that is still
     * running rather than cutting it short.
     */
    public static function markPaid(array $order, ?int $actorId, array $fields = []): bool
    {
        $repo = new ShopRepository();
        $moved = $repo->transition((int) $order['id'], ['pending', 'review', 'rejected'], 'paid', $fields + [
            'paid_at'     => date('Y-m-d H:i:s'),
            'reviewed_by' => $actorId,
            'reviewed_at' => $actorId !== null ? date('Y-m-d H:i:s') : null,
        ]);
        if (!$moved) {
            return false;
        }
        self::fulfil($order, $actorId);
        $repo->clearCart((int) $order['user_id']);
        ActivityLogger::log('shop.order_paid', $actorId, 'shop_order', (int) $order['id'], ['total' => (int) $order['total'], 'method' => $order['method']], 'notice');
        Notify::user((int) $order['user_id'], 'سفارش ' . fa((string) $order['number']) . ' پرداخت شد 🎉',
            'خریدت ثبت شد و دسترسی‌ها همین حالا فعال است.', '/shop/orders/' . $order['uuid'], 'package');
        return true;
    }

    public static function fulfil(array $order, ?int $actorId): void
    {
        $repo = new ShopRepository();
        $packages = new PackageRepository();
        $user = (new UserRepository())->findById((int) $order['user_id']);
        if ($user === null) {
            return;
        }
        foreach ($repo->items((int) $order['id']) as $item) {
            if ($item['fulfilled_at'] !== null) {
                continue;
            }
            if ($item['product_id'] !== null) {
                $repo->countSold((int) $item['product_id'], (int) $item['qty']);
            }
            if ($item['kind'] === 'package' && $item['package_id'] !== null) {
                $package = $packages->findById((int) $item['package_id']);
                if ($package !== null) {
                    try {
                        ActivationService::activatePackage($user, $package, self::window($packages, (int) $user['id'], (int) $package['id'], $item['access_days']), $actorId);
                    } catch (\Throwable $e) {
                        error_log('[shop] package activation failed for order ' . $order['id'] . ': ' . $e->getMessage());
                        continue;
                    }
                }
            }
            $repo->markFulfilled((int) $item['id']);
        }
    }

    private static function window(PackageRepository $packages, int $userId, int $packageId, mixed $days): array
    {
        $now = date('Y-m-d H:i:s');
        if ($days === null || (int) $days <= 0) {
            return ['status' => 'active', 'starts_at' => $now, 'ends_at' => null];
        }
        $existing = $packages->activation($userId, $packageId);
        $from = time();
        if ($existing !== null && $existing['status'] === 'active') {
            if ($existing['ends_at'] === null) {
                return ['status' => 'active', 'starts_at' => $existing['starts_at'] ?? $now, 'ends_at' => null];
            }
            $from = max($from, (int) strtotime((string) $existing['ends_at']));
        }
        return [
            'status'    => 'active',
            'starts_at' => $existing !== null && $existing['status'] === 'active' ? ($existing['starts_at'] ?? $now) : $now,
            'ends_at'   => date('Y-m-d H:i:s', $from + (int) $days * 86400),
        ];
    }

    public static function reject(array $order, int $actorId, string $note): bool
    {
        $ok = (new ShopRepository())->transition((int) $order['id'], ['review', 'pending'], 'rejected', [
            'admin_note' => mb_substr($note, 0, 500), 'reviewed_by' => $actorId, 'reviewed_at' => date('Y-m-d H:i:s'),
        ]);
        if ($ok) {
            ActivityLogger::log('shop.order_rejected', $actorId, 'shop_order', (int) $order['id'], [], 'notice');
            Notify::user((int) $order['user_id'], 'رسید سفارش ' . fa((string) $order['number']) . ' تأیید نشد',
                $note !== '' ? $note : 'رسید را بررسی کردیم و تأیید نشد. می‌توانی رسید درست را دوباره بفرستی یا با پشتیبانی در تماس باشی.',
                '/shop/orders/' . $order['uuid'], 'package');
        }
        return $ok;
    }
}
