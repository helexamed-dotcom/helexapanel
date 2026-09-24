<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ShopRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\MediaStore;
use HeleXa\Services\Shop\Gateway;
use HeleXa\Services\Shop\Shop;

/**
 * «فروشگاه» for the student: the store front, a product, the cart, checkout,
 * the student's orders, the card-to-card receipt and the gateway's return.
 */
final class ShopController extends Controller
{
    private const COUPON_KEY = '_shop_coupon';

    private ShopRepository $shop;

    public function __construct()
    {
        $this->shop = new ShopRepository();
    }

    /* ============================================================ browse */

    public function index(Request $request, array $params = []): Response
    {
        $this->ready();
        $filters = [
            'q'        => mb_substr(trim($request->string('q')), 0, 80),
            'category' => $request->int('category'),
            'sort'     => in_array($request->string('sort'), ['cheap', 'popular', 'new'], true) ? $request->string('sort') : '',
        ];
        $products = $this->shop->products($filters, true);
        $browsing = $filters['q'] !== '' || $filters['category'] > 0 || $filters['sort'] !== '';

        return $this->page('layouts.app', 'shop.index', [
            'title'      => Shop::theme()['title'],
            'theme'      => Shop::theme(),
            'products'   => $products,
            'featured'   => $browsing ? [] : array_values(array_filter($products, static fn (array $p): bool => (int) $p['featured'] === 1)),
            'categories' => array_values(array_filter($this->shop->categories(), static fn (array $c): bool => (int) $c['products'] > 0)),
            'filters'    => $filters,
            'inCart'     => $this->cartIds(),
            'extraCss'   => ['shop'],
            'extraJs'    => ['shop'],
        ]);
    }

    public function product(Request $request, array $params = []): Response
    {
        $this->ready();
        $p = $this->shop->findProduct((string) ($params['uuid'] ?? ''));
        if ($p === null || ($p['status'] !== 'published' && !$this->isShopAdmin())) {
            throw HttpException::notFound();
        }
        $related = array_slice(array_values(array_filter(
            $this->shop->products(['category' => (int) ($p['category_id'] ?? 0)], true),
            static fn (array $r): bool => (int) $r['id'] !== (int) $p['id']
        )), 0, 4);

        return $this->page('layouts.app', 'shop.product', [
            'title'    => $p['title'],
            'theme'    => Shop::theme(),
            'p'        => $p,
            'related'  => $p['category_id'] !== null ? $related : [],
            'inCart'   => $this->cartIds(),
            'blocked'  => Auth::isStudent() ? Shop::canAdd($p, (int) Auth::id(), 1) : null,
            'extraCss' => ['lessons', 'shop'],
            'extraJs'  => ['shop'],
        ]);
    }

    /* ============================================================== cart */

    public function cart(Request $request, array $params = []): Response
    {
        $this->ready();
        $userId = (int) Auth::id();
        $priced = Shop::price($this->shop->cart($userId), $this->couponCode(), $userId);

        return $this->page('layouts.app', 'shop.cart', [
            'title'     => 'سبد خرید',
            'theme'     => Shop::theme(),
            'priced'    => $priced,
            'code'      => $this->couponCode(),
            'shipping'  => Shop::needsShipping($priced['lines']),
            'gateway'   => Gateway::enabled(),
            'card'      => Shop::cardEnabled(),
            'user'      => (array) Auth::user(),
            'extraCss'  => ['shop'],
            'extraJs'   => ['shop'],
        ]);
    }

    /** POST /shop/cart — add one (or set a quantity) from anywhere. */
    public function add(Request $request, array $params = []): Response
    {
        $this->ready();
        $userId = (int) Auth::id();
        $p = $this->shop->findProduct($request->string('product'));
        if ($p === null) {
            return $this->answer($request, false, 'این محصول پیدا نشد.');
        }
        $explicit = $request->input('qty') !== null;
        $current = $this->shop->cartQty($userId, (int) $p['id']);
        $qty = $explicit ? max(0, min(99, $request->int('qty'))) : $current + 1;
        if (!$explicit && $current > 0 && $qty > max(1, (int) $p['max_per_order'])) {
            return $this->answer($request, true, '«' . $p['title'] . '» در سبد توست.');
        }
        if ($qty > 0) {
            $problem = Shop::canAdd($p, $userId, $qty);
            if ($problem !== null) {
                return $this->answer($request, false, $problem);
            }
        }
        $this->shop->setCartQty($userId, (int) $p['id'], $qty);

        return $this->answer($request, true, $qty > 0 ? '«' . $p['title'] . '» به سبد اضافه شد.' : 'از سبد برداشته شد.');
    }

    private function couponCode(): string
    {
        return (string) ($_SESSION[self::COUPON_KEY] ?? '');
    }

    /** POST /shop/cart/coupon — apply or remove a discount code. */
    public function applyCoupon(Request $request, array $params = []): Response
    {
        $this->ready();
        $userId = (int) Auth::id();
        $code = mb_strtoupper(mb_substr(trim($request->string('code')), 0, 40));
        if ($request->bool('remove') || $code === '') {
            unset($_SESSION[self::COUPON_KEY]);
            $priced = Shop::price($this->shop->cart($userId), null, $userId);
            return $this->couponAnswer($request, true, 'کد تخفیف برداشته شد.', $priced);
        }
        $priced = Shop::price($this->shop->cart($userId), $code, $userId);
        if ($priced['couponError'] !== null) {
            unset($_SESSION[self::COUPON_KEY]);
            return $this->couponAnswer($request, false, $priced['couponError'], $priced);
        }
        $_SESSION[self::COUPON_KEY] = $code;
        return $this->couponAnswer($request, true, 'کد تخفیف اعمال شد: ' . Shop::money($priced['discount']) . ' کمتر 🎉', $priced);
    }

    /* ========================================================== checkout */

    public function checkout(Request $request, array $params = []): Response
    {
        $this->ready();
        $userId = (int) Auth::id();
        $user = (array) Auth::user();
        $lines = $this->shop->cart($userId);
        if ($lines === []) {
            $this->flash('error', 'سبد خریدت خالی است.');
            return $this->redirect('/shop');
        }
        foreach ($lines as $l) {
            $problem = Shop::canAdd($l, $userId, (int) $l['qty']);
            if ($problem !== null) {
                $this->flash('error', '«' . $l['title'] . '»: ' . $problem);
                return $this->redirect('/shop/cart');
            }
        }
        $priced = Shop::price($lines, $this->couponCode(), $userId);
        if ($priced['couponError'] !== null) {
            unset($_SESSION[self::COUPON_KEY]);
            $this->flash('error', $priced['couponError']);
            return $this->redirect('/shop/cart');
        }

        $shipping = null;
        if (Shop::needsShipping($priced['lines'])) {
            $shipping = [
                'name'    => mb_substr(trim($request->string('ship_name')), 0, 120),
                'phone'   => mb_substr(preg_replace('/[^\d+]/', '', $this->latinDigits($request->string('ship_phone'))) ?? '', 0, 20),
                'city'    => mb_substr(trim($request->string('ship_city')), 0, 80),
                'address' => mb_substr(trim($request->string('ship_address')), 0, 500),
                'postal'  => mb_substr(preg_replace('/\D/', '', $this->latinDigits($request->string('ship_postal'))) ?? '', 0, 10),
            ];
            if ($shipping['name'] === '' || strlen($shipping['phone']) < 10 || $shipping['address'] === '' || $shipping['city'] === '') {
                $this->flash('error', 'برای ارسال کالا، نام، شماره تماس، شهر و نشانی را کامل بنویس.');
                return $this->redirect('/shop/cart');
            }
        }

        $method = $priced['total'] === 0 ? 'free' : $request->string('method');
        if ($method === 'gateway' && !Gateway::enabled()) {
            $method = '';
        }
        if ($method === 'card' && !Shop::cardEnabled()) {
            $method = '';
        }
        if (!in_array($method, ['free', 'gateway', 'card'], true)) {
            $this->flash('error', 'یک روش پرداخت انتخاب کن.');
            return $this->redirect('/shop/cart');
        }

        $order = $this->shop->createOrder($userId, $priced,
            array_map(static fn (array $l): array => ['product' => $l, 'qty' => (int) $l['qty']], $priced['lines']),
            $method, $priced['coupon'], $shipping);
        unset($_SESSION[self::COUPON_KEY]);

        if ($method === 'free') {
            Shop::markPaid($order, null);
            $this->flash('success', 'سفارش ثبت شد و همین حالا فعال است 🎉');
            return $this->redirect('/shop/orders/' . $order['uuid']);
        }
        // The cart is emptied now for card-to-card (the order holds the
        // lines); for the gateway only once the payment is verified.
        if ($method === 'card') {
            $this->shop->clearCart($userId);
            return $this->redirect('/shop/orders/' . $order['uuid']);
        }
        return $this->startGateway($order + ['user' => $user]);
    }

    /** POST /shop/orders/{uuid}/pay — try the gateway again. */
    public function pay(Request $request, array $params = []): Response
    {
        $order = $this->mine((string) ($params['uuid'] ?? ''));
        if ($order['status'] !== 'pending') {
            return $this->redirect('/shop/orders/' . $order['uuid']);
        }
        if ($request->string('method') === 'card' && Shop::cardEnabled()) {
            $this->shop->update((int) $order['id'], ['method' => 'card']);
            return $this->redirect('/shop/orders/' . $order['uuid']);
        }
        $this->shop->update((int) $order['id'], ['method' => 'gateway']);
        return $this->startGateway($order);
    }

    private function startGateway(array $order): Response
    {
        $res = Gateway::request($order, 'سفارش ' . $order['number'] . ' — ' . Shop::theme()['title']);
        if (!$res['ok']) {
            $this->flash('error', $res['message'] . ' می‌توانی دوباره امتحان کنی یا کارت به کارت پرداخت کنی.');
            return $this->redirect('/shop/orders/' . $order['uuid']);
        }
        $this->shop->update((int) $order['id'], ['gateway_authority' => $res['authority']]);
        return $this->redirect((string) $res['redirect']);
    }

    /**
     * GET /shop/pay/callback — the gateway sends the browser back here. The
     * order is found by the authority the gateway issued for it, and only a
     * verify call can mark it paid.
     */
    public function callback(Request $request, array $params = []): Response
    {
        $authority = preg_replace('/[^A-Za-z0-9]/', '', $request->string('Authority')) ?? '';
        $order = $authority !== '' ? $this->shop->findOrderByAuthority($authority) : null;
        if ($order === null || (string) $order['uuid'] !== $request->string('order')) {
            $this->flash('error', 'پرداخت پیدا نشد.');
            return $this->redirect('/shop/orders');
        }
        if ($order['status'] === 'paid') {
            return $this->redirect('/shop/orders/' . $order['uuid']);
        }
        if ($request->string('Status') !== 'OK') {
            $this->flash('error', 'پرداخت انجام نشد یا لغو شد. سفارش هنوز باز است؛ می‌توانی دوباره پرداخت کنی.');
            return $this->redirect('/shop/orders/' . $order['uuid']);
        }
        $v = Gateway::verify($order, $authority);
        if (!$v['ok']) {
            $this->flash('error', $v['message']);
            return $this->redirect('/shop/orders/' . $order['uuid']);
        }
        Shop::markPaid($order, null, ['gateway_ref' => mb_substr((string) ($v['ref'] ?? ''), 0, 64), 'gateway_card' => mb_substr((string) ($v['card'] ?? ''), 0, 32), 'method' => 'gateway']);
        $this->flash('success', 'پرداخت موفق بود 🎉 کد پیگیری: ' . fa((string) ($v['ref'] ?? '')));
        return $this->redirect('/shop/orders/' . $order['uuid']);
    }

    /* ============================================================ orders */

    public function orders(Request $request, array $params = []): Response
    {
        $this->ready();
        return $this->page('layouts.app', 'shop.orders', [
            'title'    => 'سفارش‌های من',
            'theme'    => Shop::theme(),
            'orders'   => $this->shop->ordersFor((int) Auth::id()),
            'extraCss' => ['shop'],
        ]);
    }

    public function order(Request $request, array $params = []): Response
    {
        $order = $this->mine((string) ($params['uuid'] ?? ''));
        return $this->page('layouts.app', 'shop.order', [
            'title'    => 'سفارش ' . fa((string) $order['number']),
            'theme'    => Shop::theme(),
            'order'    => $order,
            'items'    => $this->shop->items((int) $order['id']),
            'card'     => Shop::card(),
            'cardOn'   => Shop::cardEnabled(),
            'gateway'  => Gateway::enabled(),
            'extraCss' => ['shop'],
            'extraJs'  => ['shop'],
        ]);
    }

    /** POST /shop/orders/{uuid}/receipt — the card-to-card receipt. */
    public function receipt(Request $request, array $params = []): Response
    {
        $order = $this->mine((string) ($params['uuid'] ?? ''));
        if (!in_array($order['status'], ['pending', 'rejected', 'review'], true)) {
            $this->flash('error', 'برای این سفارش دیگر رسید لازم نیست.');
            return $this->redirect('/shop/orders/' . $order['uuid']);
        }
        $file = $request->file('receipt');
        $ref = mb_substr(preg_replace('/[^\p{L}\p{N}\-]/u', '', $this->latinDigits($request->string('ref'))) ?? '', 0, 64);
        if ($file === null && $ref === '') {
            $this->flash('error', 'تصویر رسید یا شماره پیگیری واریز را بفرست.');
            return $this->redirect('/shop/orders/' . $order['uuid']);
        }
        $name = $order['receipt_path'];
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $name = (new MediaStore('receipts'))->store($file);
            } catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
                return $this->redirect('/shop/orders/' . $order['uuid']);
            }
        }
        $this->shop->update((int) $order['id'], [
            'status'       => 'review',
            'method'       => 'card',
            'receipt_path' => $name,
            'receipt_ref'  => $ref !== '' ? $ref : $order['receipt_ref'],
            'receipt_note' => mb_substr(trim($request->string('note')), 0, 500) ?: null,
            'receipt_at'   => date('Y-m-d H:i:s'),
        ]);
        $this->flash('success', 'رسید رسید 🙌 بعد از بررسی، سفارش خودکار فعال می‌شود و خبرت می‌کنیم.');
        return $this->redirect('/shop/orders/' . $order['uuid']);
    }

    public function cancel(Request $request, array $params = []): Response
    {
        $order = $this->mine((string) ($params['uuid'] ?? ''));
        if ($this->shop->transition((int) $order['id'], ['pending'], 'cancelled')) {
            $this->flash('success', 'سفارش لغو شد.');
        }
        return $this->redirect('/shop/orders');
    }

    /* ============================================================= media */

    public function media(Request $request, array $params = []): Response
    {
        $res = (new MediaStore('shop'))->response((string) ($params['name'] ?? ''));
        if ($res === null) {
            throw HttpException::notFound();
        }
        return $res;
    }

    /** A receipt image: only its owner and whoever reviews orders. */
    public function receiptMedia(Request $request, array $params = []): Response
    {
        $name = (string) ($params['name'] ?? '');
        $owner = Database::selectOne('SELECT user_id FROM shop_orders WHERE receipt_path = :n LIMIT 1', ['n' => $name]);
        if ($owner === null || ((int) $owner['user_id'] !== (int) Auth::id() && !(!Auth::isStudent() && Auth::can('shop.orders')))) {
            throw HttpException::notFound();
        }
        $res = (new MediaStore('receipts'))->response($name);
        if ($res === null) {
            throw HttpException::notFound();
        }
        return $res->withHeader('Cache-Control', 'private, no-store');
    }

    /* ========================================================= internals */

    private function ready(): void
    {
        if (!ShopRepository::ready()) {
            throw HttpException::notFound();
        }
    }

    private function isShopAdmin(): bool
    {
        return !Auth::isStudent() && Auth::can('shop.manage');
    }

    private function mine(string $uuid): array
    {
        $this->ready();
        $order = $this->shop->findOrder($uuid);
        if ($order === null || (int) $order['user_id'] !== (int) Auth::id()) {
            throw HttpException::notFound();
        }
        return $order;
    }

    /** @return array<int,int> product id => qty */
    private function cartIds(): array
    {
        if (!Auth::isStudent()) {
            return [];
        }
        $out = [];
        foreach ($this->shop->cart((int) Auth::id()) as $l) {
            $out[(int) $l['id']] = (int) $l['qty'];
        }
        return $out;
    }

    private function answer(Request $request, bool $ok, string $message): Response
    {
        $count = $this->shop->cartCount((int) Auth::id());
        if ($request->isAjax()) {
            return $this->json(['ok' => $ok, 'message' => $message, 'count' => $count], $ok ? 200 : 422);
        }
        $this->flash($ok ? 'success' : 'error', $message);
        $back = $request->string('back');
        return $this->redirect(preg_match('~^/shop(/[\w\-/]*)?$~', $back) === 1 ? $back : '/shop/cart');
    }

    private function couponAnswer(Request $request, bool $ok, string $message, array $priced): Response
    {
        if ($request->isAjax()) {
            return $this->json([
                'ok' => $ok, 'message' => $message,
                'subtotal' => Shop::money($priced['subtotal']), 'discount' => Shop::money($priced['discount']),
                'total' => Shop::money($priced['total']), 'hasDiscount' => $priced['discount'] > 0, 'free' => $priced['total'] === 0,
            ], $ok ? 200 : 422);
        }
        $this->flash($ok ? 'success' : 'error', $message);
        return $this->redirect('/shop/cart');
    }

    private function latinDigits(string $s): string
    {
        return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                          '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }
}
