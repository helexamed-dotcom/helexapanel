<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\PackageRepository;
use HeleXa\Models\SettingRepository;
use HeleXa\Models\ShopRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\MediaStore;
use HeleXa\Services\RichText;
use HeleXa\Services\Settings;
use HeleXa\Services\Shop\Gateway;
use HeleXa\Services\Shop\Shop;

/**
 * «فروشگاه» for the admin: an overview, products and their shelves, orders
 * and card-to-card receipts, discount codes, and payment and appearance.
 */
final class ShopController extends Controller
{
    private const ICONS = ['bag', 'package', 'qbank', 'book', 'lesson', 'cards', 'school', 'stethoscope', 'gift', 'star', 'crown', 'bolt', 'calendar', 'figure', 'mindmap'];
    private const MAX_GALLERY = 8;

    private ShopRepository $shop;

    public function __construct()
    {
        $this->shop = new ShopRepository();
    }

    /* ========================================================= overview */

    public function index(Request $request, array $params = []): Response
    {
        $this->ready();
        return $this->page('layouts.app', 'admin.shop.index', [
            'title'    => 'فروشگاه',
            'sales'    => $this->shop->salesSummary(),
            'daily'    => $this->shop->daily(14),
            'counts'   => $this->shop->statusCounts(),
            'review'   => array_slice($this->shop->orders(['status' => 'review']), 0, 6),
            'recent'   => array_slice($this->shop->orders([]), 0, 8),
            'top'      => $this->shop->topProducts(5),
            'products' => count($this->shop->products([])),
            'gateway'  => Gateway::enabled(),
            'card'     => Shop::cardEnabled(),
            'extraCss' => ['shop-admin'],
        ]);
    }

    /* ========================================================= products */

    public function products(Request $request, array $params = []): Response
    {
        $this->ready();
        $filters = ['q' => mb_substr(trim($request->string('q')), 0, 80), 'status' => $request->string('status'), 'category' => $request->int('category')];
        return $this->page('layouts.app', 'admin.shop.products', [
            'title'      => 'محصولات فروشگاه',
            'rows'       => $this->shop->products($filters),
            'filters'    => $filters,
            'categories' => $this->shop->categories(),
            'icons'      => self::ICONS,
            'tones'      => Shop::TONES,
            'extraCss'   => ['shop-admin'],
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        $this->ready();
        return $this->form(null);
    }

    public function edit(Request $request, array $params = []): Response
    {
        return $this->form($this->productOr404((string) ($params['uuid'] ?? '')));
    }

    public function store(Request $request, array $params = []): Response
    {
        $this->ready();
        return $this->persist($request, null);
    }

    public function update(Request $request, array $params = []): Response
    {
        return $this->persist($request, $this->productOr404((string) ($params['uuid'] ?? '')));
    }

    public function setStatus(Request $request, array $params = []): Response
    {
        $p = $this->productOr404((string) ($params['uuid'] ?? ''));
        $status = in_array($request->string('status'), ['draft', 'published', 'archived'], true) ? $request->string('status') : 'draft';
        $this->shop->setStatus((int) $p['id'], $status);
        $this->flash('success', $status === 'published' ? '«' . $p['title'] . '» در فروشگاه نمایش داده می‌شود.' : 'از ویترین برداشته شد.');
        return $this->redirect('/admin/shop/products');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $p = $this->productOr404((string) ($params['uuid'] ?? ''));
        $this->shop->softDeleteProduct((int) $p['id']);
        ActivityLogger::log('shop.product_deleted', Auth::id(), 'shop_product', (int) $p['id'], [], 'warning', $request);
        $this->flash('success', 'محصول حذف شد. سفارش‌های قبلی آن دست نمی‌خورد.');
        return $this->redirect('/admin/shop/products');
    }

    /** The description editor's image button and paste. */
    public function upload(Request $request, array $params = []): Response
    {
        $store = new MediaStore('shop');
        try {
            $file = $request->file('image');
            if ($file !== null) {
                $name = $store->store($file);
            } else {
                $bytes = MediaStore::decodeDataUrl($request->string('data'));
                if ($bytes === null) {
                    return $this->json(['ok' => false, 'message' => 'تصویری دریافت نشد.'], 422);
                }
                $name = $store->storeBlob($bytes);
            }
        } catch (\RuntimeException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        return $this->json(['ok' => true, 'url' => '/media/shop/' . $name]);
    }

    public function saveCategory(Request $request, array $params = []): Response
    {
        $this->ready();
        $title = trim(mb_substr($request->string('title'), 0, 120));
        if ($title === '') {
            $this->flash('error', 'نام دسته لازم است.');
            return $this->redirect('/admin/shop/products#categories');
        }
        $id = $request->int('id');
        $this->shop->saveCategory($id > 0 ? $id : null, [
            'title'      => $title,
            'icon'       => in_array($request->string('icon'), self::ICONS, true) ? $request->string('icon') : 'bag',
            'tone'       => in_array($request->string('tone'), Shop::TONES, true) ? $request->string('tone') : 'orange',
            'sort_order' => $request->int('sort_order'),
        ]);
        $this->flash('success', 'دسته ذخیره شد.');
        return $this->redirect('/admin/shop/products#categories');
    }

    public function deleteCategory(Request $request, array $params = []): Response
    {
        $this->ready();
        $this->shop->deleteCategory((int) ($params['id'] ?? 0));
        $this->flash('success', 'دسته حذف شد؛ محصولاتش بدون دسته ماندند.');
        return $this->redirect('/admin/shop/products#categories');
    }

    /* =========================================================== orders */

    public function orders(Request $request, array $params = []): Response
    {
        $this->ready();
        $filters = ['status' => $request->string('status'), 'q' => mb_substr(trim($request->string('q')), 0, 80)];
        return $this->page('layouts.app', 'admin.shop.orders', [
            'title'    => 'سفارش‌ها',
            'rows'     => $this->shop->orders($filters),
            'filters'  => $filters,
            'counts'   => $this->shop->statusCounts(),
            'extraCss' => ['shop-admin'],
        ]);
    }

    public function order(Request $request, array $params = []): Response
    {
        $order = $this->orderOr404((string) ($params['uuid'] ?? ''));
        return $this->page('layouts.app', 'admin.shop.order', [
            'title'    => 'سفارش ' . fa((string) $order['number']),
            'order'    => $order,
            'items'    => $this->shop->items((int) $order['id']),
            'history'  => array_slice(array_values(array_filter($this->shop->ordersFor((int) $order['user_id']),
                static fn (array $o): bool => (int) $o['id'] !== (int) $order['id'])), 0, 6),
            'extraCss' => ['shop-admin'],
        ]);
    }

    /** Approve a receipt (or mark any open order paid by hand). */
    public function approve(Request $request, array $params = []): Response
    {
        $order = $this->orderOr404((string) ($params['uuid'] ?? ''));
        $note = mb_substr(trim($request->string('note')), 0, 500);
        $ok = Shop::markPaid($order, (int) Auth::id(), $note !== '' ? ['admin_note' => $note] : []);
        $this->flash($ok ? 'success' : 'error', $ok ? 'سفارش تأیید شد و دسترسی‌ها فعال شد ✓' : 'این سفارش قبلاً تأیید یا بسته شده است.');
        return $this->redirect($this->nextReview($order));
    }

    public function reject(Request $request, array $params = []): Response
    {
        $order = $this->orderOr404((string) ($params['uuid'] ?? ''));
        $ok = Shop::reject($order, (int) Auth::id(), trim($request->string('note')));
        $this->flash($ok ? 'success' : 'error', $ok ? 'رسید رد شد و به دانشجو خبر داده شد.' : 'این سفارش در وضعیتی نیست که رد شود.');
        return $this->redirect($this->nextReview($order));
    }

    public function note(Request $request, array $params = []): Response
    {
        $order = $this->orderOr404((string) ($params['uuid'] ?? ''));
        $this->shop->update((int) $order['id'], ['admin_note' => mb_substr(trim($request->string('note')), 0, 500) ?: null]);
        $this->flash('success', 'یادداشت ذخیره شد.');
        return $this->redirect('/admin/shop/orders/' . $order['uuid']);
    }

    /* ========================================================== coupons */

    public function coupons(Request $request, array $params = []): Response
    {
        $this->ready();
        $editing = $request->int('edit') > 0 ? $this->shop->findCouponById($request->int('edit')) : null;
        return $this->page('layouts.app', 'admin.shop.coupons', [
            'title'    => 'کدهای تخفیف',
            'rows'     => $this->shop->coupons(),
            'editing'  => $editing,
            'products' => $this->shop->products([]),
            'extraCss' => ['shop-admin'],
        ]);
    }

    public function saveCoupon(Request $request, array $params = []): Response
    {
        $this->ready();
        $id = $request->int('id') > 0 ? $request->int('id') : null;
        $code = mb_strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $this->latin($request->string('code'))) ?? '');
        if ($code === '' && $request->bool('generate')) {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }
        $kind = $request->string('kind') === 'fixed' ? 'fixed' : 'percent';
        $amount = (int) $this->latin($request->string('amount'));
        if ($code === '' || strlen($code) > 40 || $amount <= 0 || ($kind === 'percent' && $amount > 100)) {
            $this->flash('error', 'کد (حروف و عدد لاتین) و مقدار تخفیف معتبر لازم است؛ درصد حداکثر ۱۰۰.');
            return $this->redirect('/admin/shop/coupons' . ($id ? '?edit=' . $id : ''));
        }
        $existing = $this->shop->findCoupon($code);
        if ($existing !== null && (int) $existing['id'] !== (int) $id) {
            $this->flash('error', 'این کد قبلاً ساخته شده است.');
            return $this->redirect('/admin/shop/coupons' . ($id ? '?edit=' . $id : ''));
        }
        $productIds = array_values(array_unique(array_filter(array_map('intval', (array) ($request->input('products') ?? [])))));
        $this->shop->saveCoupon($id, [
            'code'           => $code,
            'kind'           => $kind,
            'amount'         => $amount,
            'max_discount'   => $this->optInt($request, 'max_discount'),
            'min_total'      => $this->optInt($request, 'min_total'),
            'starts_at'      => $this->jalaliToDate($request->string('starts_at'), false),
            'ends_at'        => $this->jalaliToDate($request->string('ends_at'), true),
            'usage_limit'    => $this->optInt($request, 'usage_limit'),
            'per_user_limit' => $this->optInt($request, 'per_user_limit'),
            'product_ids'    => $productIds,
            'status'         => $request->string('status') === 'disabled' ? 'disabled' : 'active',
            'note'           => mb_substr(trim($request->string('note')), 0, 255) ?: null,
        ]);
        ActivityLogger::log('shop.coupon_saved', Auth::id(), 'shop_coupon', $id, ['code' => $code], 'info', $request);
        $this->flash('success', 'کد تخفیف «' . $code . '» ذخیره شد.');
        return $this->redirect('/admin/shop/coupons');
    }

    public function deleteCoupon(Request $request, array $params = []): Response
    {
        $this->ready();
        $this->shop->deleteCoupon((int) ($params['id'] ?? 0));
        $this->flash('success', 'کد تخفیف حذف شد.');
        return $this->redirect('/admin/shop/coupons');
    }

    /* ========================================================= settings */

    public function settings(Request $request, array $params = []): Response
    {
        $this->ready();
        return $this->page('layouts.app', 'admin.shop.settings', [
            'title'    => 'پرداخت و ظاهر فروشگاه',
            'tab'      => $request->string('tab') === 'look' ? 'look' : 'pay',
            'theme'    => Shop::theme(),
            'card'     => Shop::card(),
            'gateway'  => (string) Settings::get('shop_gateway', 'none'),
            'merchant' => (string) Settings::get('shop_zarinpal_merchant', ''),
            'sandbox'  => Settings::bool('shop_zarinpal_sandbox', false),
            'callback' => Gateway::callbackUrl('…'),
            'tones'    => Shop::TONES,
            'sample'   => array_slice($this->shop->products([], true), 0, 3),
            'extraCss' => ['shop', 'shop-admin'],
            'extraJs'  => ['shop-admin'],
        ]);
    }

    public function savePayment(Request $request, array $params = []): Response
    {
        $this->ready();
        $repo = new SettingRepository();
        $actor = (int) Auth::id();
        $gateway = $request->string('gateway') === 'zarinpal' ? 'zarinpal' : 'none';
        $merchant = preg_replace('/[^A-Za-z0-9\-]/', '', $request->string('merchant')) ?? '';
        if ($gateway === 'zarinpal' && strlen($merchant) < 20) {
            $this->flash('error', 'کد پذیرنده (مرچنت) زرین‌پال ۳۶ کاراکتر است؛ آن را کامل وارد کنید.');
            return $this->redirect('/admin/shop/settings');
        }
        $repo->set('shop_gateway', $gateway, 'string', $actor);
        $repo->set('shop_zarinpal_merchant', $merchant, 'string', $actor);
        $repo->set('shop_zarinpal_sandbox', $request->bool('sandbox') ? '1' : '0', 'bool', $actor);

        $number = preg_replace('/\D/', '', $this->latin($request->string('card_number'))) ?? '';
        $cardOn = $request->bool('card_enabled');
        if ($cardOn && strlen($number) !== 16) {
            $this->flash('error', 'شماره کارت باید ۱۶ رقم باشد.');
            return $this->redirect('/admin/shop/settings');
        }
        Shop::save('shop_card', [
            'enabled' => $cardOn,
            'number'  => $number,
            'holder'  => mb_substr(trim($request->string('card_holder')), 0, 80),
            'bank'    => mb_substr(trim($request->string('card_bank')), 0, 60),
            'sheba'   => mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $this->latin($request->string('card_sheba'))) ?? ''),
            'note'    => mb_substr(trim($request->string('card_note')), 0, 600),
        ], $actor);
        Settings::flush();
        ActivityLogger::log('shop.payment_settings', $actor, 'settings', null, ['gateway' => $gateway, 'card' => $cardOn], 'notice', $request);
        $this->flash('success', 'تنظیمات پرداخت ذخیره شد.');
        return $this->redirect('/admin/shop/settings');
    }

    public function saveTheme(Request $request, array $params = []): Response
    {
        $this->ready();
        $old = Shop::theme();
        $trust = array_values(array_filter(array_map(static fn (string $l): string => mb_substr(trim($l), 0, 60),
            preg_split('/\R/u', $request->string('trust')) ?: [])));
        $theme = [
            'title'           => mb_substr(trim($request->string('title')), 0, 60) ?: Shop::THEME_DEFAULTS['title'],
            'subtitle'        => mb_substr(trim($request->string('subtitle')), 0, 200),
            'tone'            => in_array($request->string('tone'), Shop::TONES, true) ? $request->string('tone') : 'orange',
            'hero'            => in_array($request->string('hero'), ['gradient', 'image', 'minimal'], true) ? $request->string('hero') : 'gradient',
            'banner'          => $old['banner'],
            'notice'          => mb_substr(trim($request->string('notice')), 0, 160),
            'layout'          => $request->string('layout') === 'list' ? 'list' : 'grid',
            'columns'         => max(2, min(4, $request->int('columns', 3))),
            'card'            => in_array($request->string('card'), ['glass', 'solid', 'outline'], true) ? $request->string('card') : 'glass',
            'radius'          => max(6, min(32, $request->int('radius', 22))),
            'show_search'     => $request->bool('show_search'),
            'show_categories' => $request->bool('show_categories'),
            'show_sold'       => $request->bool('show_sold'),
            'show_compare'    => $request->bool('show_compare'),
            'trust'           => array_slice($trust, 0, 5),
            'footer'          => mb_substr(trim($request->string('footer')), 0, 500),
            'currency'        => mb_substr(trim($request->string('currency')), 0, 12) ?: 'تومان',
        ];
        $banner = $request->file('banner');
        if ($banner !== null && (int) ($banner['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $store = new MediaStore('shop');
                $theme['banner'] = $store->store($banner);
                if ($old['banner'] !== '') {
                    $store->forget($old['banner']);
                }
            } catch (\RuntimeException $e) {
                $this->flash('error', 'تصویر بنر: ' . $e->getMessage());
            }
        } elseif ($request->bool('remove_banner') && $old['banner'] !== '') {
            (new MediaStore('shop'))->forget($old['banner']);
            $theme['banner'] = '';
        }
        Shop::save('shop_theme', $theme, (int) Auth::id());
        $this->flash('success', 'ظاهر فروشگاه ذخیره شد.');
        return $this->redirect('/admin/shop/settings?tab=look');
    }

    /* ======================================================== internals */

    private function ready(): void
    {
        if (!ShopRepository::ready()) {
            $this->flash('error', 'جدول‌های فروشگاه هنوز ساخته نشده‌اند. install.php را دوباره اجرا کنید.');
            throw HttpException::notFound();
        }
    }

    private function form(?array $p): Response
    {
        $packages = [];
        try {
            $packages = array_values(array_filter((new PackageRepository())->all(), static fn (array $k): bool => $k['status'] !== 'archived'));
        } catch (\PDOException) {
        }
        return $this->page('layouts.app', 'admin.shop.form', [
            'title'      => $p === null ? 'محصول تازه' : 'ویرایش «' . $p['title'] . '»',
            'product'    => $p,
            'categories' => $this->shop->categories(),
            'packages'   => $packages,
            'kinds'      => ShopRepository::KINDS,
            'tones'      => Shop::TONES,
            'extraCss'   => ['lessons', 'shop-admin'],
            'extraJs'    => ['lesson-editor', 'shop-admin'],
        ]);
    }

    private function persist(Request $request, ?array $p): Response
    {
        $back = $p === null ? '/admin/shop/products/create' : '/admin/shop/products/' . $p['uuid'] . '/edit';
        $title = trim(mb_substr($request->string('title'), 0, 191));
        $price = (int) $this->latin(str_replace([',', '٬', '،'], '', $request->string('price')));
        if ($title === '' || $price < 0) {
            $this->flash('error', 'نام محصول و قیمت لازم است.');
            return $this->redirect($back);
        }
        $kind = array_key_exists($request->string('kind'), ShopRepository::KINDS) ? $request->string('kind') : 'package';
        $packageId = $request->int('package_id') > 0 ? $request->int('package_id') : null;
        if ($kind === 'package' && $packageId === null) {
            $this->flash('error', 'محصول از نوع «پکیج آموزشی» باید به یک پکیج وصل باشد؛ همان پکیجی که بعد از پرداخت فعال می‌شود.');
            return $this->redirect($back);
        }
        $compare = (int) $this->latin(str_replace([',', '٬', '،'], '', $request->string('compare_price')));
        $features = array_values(array_filter(array_map(static fn (string $l): string => mb_substr(trim($l), 0, 120),
            preg_split('/\R/u', $request->string('features')) ?: [])));
        $categoryId = $request->int('category_id');

        $gallery = $p['gallery'] ?? [];
        $remove = array_map('strval', (array) ($request->input('remove_gallery') ?? []));
        if ($remove !== []) {
            $store = new MediaStore('shop');
            foreach ($gallery as $i => $g) {
                if (in_array($g, $remove, true)) {
                    $store->forget($g);
                    unset($gallery[$i]);
                }
            }
            $gallery = array_values($gallery);
        }
        foreach ($this->files($request, 'gallery') as $file) {
            if (count($gallery) >= self::MAX_GALLERY) {
                break;
            }
            try {
                $gallery[] = (new MediaStore('shop'))->store($file);
            } catch (\RuntimeException $e) {
                $this->flash('error', 'گالری: ' . $e->getMessage());
            }
        }

        $saved = $this->shop->saveProduct($p === null ? null : (int) $p['id'], [
            'category_id'   => $categoryId > 0 ? $categoryId : null,
            'kind'          => $kind,
            'title'         => $title,
            'subtitle'      => mb_substr(trim($request->string('subtitle')), 0, 255) ?: null,
            'description'   => RichText::clean((string) $request->input('body_html', ''), '/media/shop/'),
            'features'      => array_slice($features, 0, 12),
            'tone'          => in_array($request->string('tone'), Shop::TONES, true) ? $request->string('tone') : 'indigo',
            'badge'         => mb_substr(trim($request->string('badge')), 0, 40) ?: null,
            'price'         => $price,
            'compare_price' => $compare > $price ? $compare : null,
            'package_id'    => $kind === 'package' ? $packageId : null,
            'access_days'   => $kind === 'package' && $request->int('access_days') > 0 ? min(3650, $request->int('access_days')) : null,
            'stock'         => trim($request->string('stock')) === '' ? null : max(0, (int) $this->latin($request->string('stock'))),
            'max_per_order' => $kind === 'package' ? 1 : max(1, min(99, $request->int('max_per_order', 1))),
            'featured'      => $request->bool('featured') ? 1 : 0,
            'status'        => in_array($request->string('status'), ['draft', 'published', 'archived'], true) ? $request->string('status') : 'draft',
            'sort_order'    => $request->int('sort_order'),
            'gallery'       => $gallery,
        ], (int) Auth::id());

        $cover = $request->file('cover');
        if ($cover !== null && (int) ($cover['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $store = new MediaStore('shop');
                $name = $store->store($cover);
                if (!empty($p['cover_path'])) {
                    $store->forget($p['cover_path']);
                }
                $this->shop->setCover((int) $saved['id'], $name);
            } catch (\RuntimeException $e) {
                $this->flash('error', 'تصویر اصلی: ' . $e->getMessage());
            }
        } elseif ($request->bool('remove_cover') && !empty($p['cover_path'])) {
            (new MediaStore('shop'))->forget($p['cover_path']);
            $this->shop->setCover((int) $saved['id'], null);
        }

        ActivityLogger::log($p === null ? 'shop.product_created' : 'shop.product_updated', Auth::id(), 'shop_product', (int) $saved['id'], ['price' => $price], 'info', $request);
        $this->flash('success', 'محصول ذخیره شد.');
        return $this->redirect($request->bool('stay') ? '/admin/shop/products/' . $saved['uuid'] . '/edit' : '/admin/shop/products');
    }

    /** @return list<array> the uploaded files of a multiple file input */
    private function files(Request $request, string $key): array
    {
        $raw = $request->file($key);
        if ($raw === null || !is_array($raw['name'] ?? null)) {
            return $raw !== null && (int) ($raw['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? [$raw] : [];
        }
        $out = [];
        foreach (array_keys($raw['name']) as $i) {
            if ((int) ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $out[] = ['name' => $raw['name'][$i], 'tmp_name' => $raw['tmp_name'][$i], 'size' => $raw['size'][$i], 'error' => $raw['error'][$i]];
            }
        }
        return $out;
    }

    private function productOr404(string $uuid): array
    {
        $this->ready();
        $p = $this->shop->findProduct($uuid);
        if ($p === null) {
            throw HttpException::notFound();
        }
        return $p;
    }

    private function orderOr404(string $uuid): array
    {
        $this->ready();
        $o = $this->shop->findOrder($uuid);
        if ($o === null) {
            throw HttpException::notFound();
        }
        return $o;
    }

    /** After a decision, straight on to the next receipt waiting. */
    private function nextReview(array $done): string
    {
        foreach ($this->shop->orders(['status' => 'review'], 5) as $o) {
            if ((int) $o['id'] !== (int) $done['id']) {
                return '/admin/shop/orders/' . $o['uuid'];
            }
        }
        return '/admin/shop/orders';
    }

    private function optInt(Request $request, string $key): ?int
    {
        $v = trim($this->latin(str_replace([',', '٬', '،'], '', $request->string($key))));
        return $v === '' || !ctype_digit($v) || (int) $v <= 0 ? null : (int) $v;
    }

    private function jalaliToDate(string $value, bool $endOfDay): ?string
    {
        $value = trim($this->latin($value));
        if ($value === '') {
            return null;
        }
        if (preg_match('~^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$~', $value, $m) !== 1) {
            return null;
        }
        [$gy, $gm, $gd] = \HeleXa\Services\Jalali::toGregorian((int) $m[1], (int) $m[2], (int) $m[3]);
        return sprintf('%04d-%02d-%02d %s', $gy, $gm, $gd, $endOfDay ? '23:59:59' : '00:00:00');
    }

    private function latin(string $s): string
    {
        return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                          '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }
}
