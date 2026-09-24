<?php
/**
 * The cart and checkout on one page.
 *
 * @var array  $theme
 * @var array  $priced   Shop::price()
 * @var string $code     the discount code in the session
 * @var bool   $shipping whether an address is needed
 * @var bool   $gateway
 * @var bool   $card
 * @var array  $user
 */
use HeleXa\Core\View;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$lines = $priced['lines'];
$free = $lines !== [] && $priced['total'] === 0;
?>
<div class="sh tone-<?= e($theme['tone']) ?>" style="--sh-r: <?= (int) $theme['radius'] ?>px">
    <a class="sh-back" href="/shop"><?php $icon('chevron', 16); ?> ادامه خرید</a>

    <?php if ($lines === []): ?>
        <div class="sh-empty is-cart">
            <span class="app-ic tone-green"><?php $icon('cart'); ?></span>
            <b>سبد خریدت خالی است</b>
            <small>پکیج‌ها و محصولات را از فروشگاه به سبد اضافه کن.</small>
            <a class="btn btn-primary" href="/shop">رفتن به فروشگاه</a>
        </div>
    <?php else: ?>
    <div class="sh-checkout">
        <div class="sh-lines">
            <h2 class="sh-h"><?php $icon('cart', 20); ?> سبد خرید <small><?= e(fa((string) $priced['count'])) ?> قلم</small></h2>
            <?php foreach ($lines as $i => $l): ?>
                <article class="sh-line tone-<?= e($l['tone']) ?>" style="--i: <?= $i ?>">
                    <a class="sh-line-media" href="/shop/p/<?= e($l['uuid']) ?>">
                        <?php if (!empty($l['cover_path'])): ?><img src="/media/shop/<?= e($l['cover_path']) ?>" alt=""><?php else: ?><?php $icon($l['kind'] === 'package' ? 'package' : 'bag', 26); ?><?php endif; ?>
                    </a>
                    <div class="sh-line-body">
                        <a class="sh-line-title" href="/shop/p/<?= e($l['uuid']) ?>"><?= e($l['title']) ?></a>
                        <small>
                            <?= e(\HeleXa\Models\ShopRepository::KINDS[$l['kind']]) ?>
                            <?php if ($l['kind'] === 'package'): ?> · <?= !empty($l['access_days']) ? e(fa((string) $l['access_days'])) . ' روز دسترسی' : 'بدون محدودیت زمانی' ?><?php endif; ?>
                        </small>
                        <div class="sh-line-foot">
                            <form class="sh-stepper" method="post" action="/shop/cart" data-qty-form>
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="product" value="<?= e($l['uuid']) ?>">
                                <input type="hidden" name="back" value="/shop/cart">
                                <?php if ((int) $l['max_per_order'] > 1): ?>
                                    <button type="submit" name="qty" value="<?= (int) $l['qty'] + 1 ?>" aria-label="یکی بیشتر">+</button>
                                    <b><?= e(fa((string) $l['qty'])) ?></b>
                                    <button type="submit" name="qty" value="<?= (int) $l['qty'] - 1 ?>" aria-label="یکی کمتر"><?= (int) $l['qty'] > 1 ? '−' : '' ?><?php if ((int) $l['qty'] <= 1) { $icon('trash', 15); } ?></button>
                                <?php else: ?>
                                    <button type="submit" name="qty" value="0" class="is-remove"><?php $icon('trash', 15); ?> حذف</button>
                                <?php endif; ?>
                            </form>
                            <span class="sh-price">
                                <?php if ((int) ($l['compare_price'] ?? 0) > (int) $l['price'] && $theme['show_compare']): ?><s><?= e(fa(number_format((int) $l['compare_price'] * (int) $l['qty']))) ?></s><?php endif; ?>
                                <strong><?= e(Shop::money((int) $l['line_total'])) ?></strong>
                            </span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <form class="sh-coupon" method="post" action="/shop/cart/coupon" data-coupon-form>
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <span class="sh-coupon-ic"><?php $icon('percent', 18); ?></span>
                <input name="code" value="<?= e($code) ?>" maxlength="40" dir="ltr" autocomplete="off" placeholder="کد تخفیف" aria-label="کد تخفیف">
                <button class="btn btn-ghost" type="submit" data-coupon-apply <?= $priced['coupon'] ? 'hidden' : '' ?>>اعمال</button>
                <button class="btn btn-ghost" type="submit" name="remove" value="1" data-coupon-remove <?= $priced['coupon'] ? '' : 'hidden' ?>>حذف کد</button>
                <p class="sh-coupon-msg<?= $priced['couponError'] ? ' is-bad' : ($priced['coupon'] ? ' is-ok' : '') ?>" data-coupon-msg role="status" aria-live="polite"><?= $priced['couponError'] ? e($priced['couponError']) : ($priced['coupon'] ? 'کد «' . e($priced['coupon']['code']) . '» اعمال شد.' : '') ?></p>
            </form>
        </div>

        <form class="sh-summary" method="post" action="/shop/checkout" data-checkout>
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <h3 class="sh-h"><?php $icon('receipt', 19); ?> خلاصه سفارش</h3>
            <dl class="sh-sum">
                <div><dt>جمع سبد</dt><dd data-sum-subtotal><?= e(Shop::money((int) $priced['subtotal'])) ?></dd></div>
                <div class="is-discount" data-sum-discount-row <?= $priced['discount'] > 0 ? '' : 'hidden' ?>><dt>تخفیف</dt><dd data-sum-discount>− <?= e(Shop::money((int) $priced['discount'])) ?></dd></div>
                <div class="is-total"><dt>مبلغ قابل پرداخت</dt><dd data-sum-total><?= e(Shop::money((int) $priced['total'])) ?></dd></div>
            </dl>
            <?php if ($priced['savings'] > 0): ?><p class="sh-save"><?php $icon('gift', 15); ?> سود تو از تخفیف محصولات: <?= e(Shop::money((int) $priced['savings'])) ?></p><?php endif; ?>

            <?php if ($shipping): ?>
                <fieldset class="sh-ship">
                    <legend><?php $icon('send', 16); ?> نشانی ارسال</legend>
                    <input class="input" name="ship_name" maxlength="120" value="<?= e((string) ($user['full_name'] ?? '')) ?>" placeholder="نام گیرنده" required>
                    <input class="input" name="ship_phone" maxlength="20" dir="ltr" value="<?= e((string) ($user['mobile'] ?? '')) ?>" placeholder="شماره تماس" inputmode="tel" required>
                    <input class="input" name="ship_city" maxlength="80" placeholder="استان و شهر" required>
                    <textarea class="input" name="ship_address" maxlength="500" rows="2" placeholder="نشانی کامل" required></textarea>
                    <input class="input" name="ship_postal" maxlength="12" dir="ltr" placeholder="کد پستی (اختیاری)" inputmode="numeric">
                </fieldset>
            <?php endif; ?>

            <div class="sh-methods" data-methods <?= $free ? 'hidden' : '' ?>>
                <b class="sh-methods-t">روش پرداخت</b>
                <?php if ($gateway): ?>
                    <label class="sh-method">
                        <input type="radio" name="method" value="gateway" checked>
                        <span class="app-ic tone-blue"><?php $icon('creditcard', 20); ?></span>
                        <span><b>پرداخت آنلاین</b><small>با همه کارت‌های عضو شتاب — فعال‌سازی فوری</small></span>
                    </label>
                <?php endif; ?>
                <?php if ($card): ?>
                    <label class="sh-method">
                        <input type="radio" name="method" value="card" <?= $gateway ? '' : 'checked' ?>>
                        <span class="app-ic tone-teal"><?php $icon('receipt', 20); ?></span>
                        <span><b>کارت به کارت</b><small>واریز و بارگذاری رسید — فعال‌سازی بعد از تأیید</small></span>
                    </label>
                <?php endif; ?>
                <?php if (!$gateway && !$card): ?>
                    <p class="sh-blocked"><?php $icon('info', 16); ?> پرداخت هنوز راه‌اندازی نشده است. برای خرید با پشتیبانی در تماس باش یا کد فعال‌سازی بگیر.</p>
                <?php endif; ?>
            </div>

            <button class="btn btn-primary sh-pay" type="submit" data-lock-on-submit <?= !$free && !$gateway && !$card ? 'disabled' : '' ?>>
                <span data-pay-text><?= $free ? 'ثبت سفارش رایگان' : 'پرداخت ' . e(Shop::money((int) $priced['total'])) ?></span>
            </button>
            <p class="sh-fine"><?php $icon('shield', 14); ?> پرداخت امن · بعد از پرداخت، دسترسی‌ها خودکار فعال می‌شود.</p>
        </form>
    </div>
    <?php endif; ?>
</div>
