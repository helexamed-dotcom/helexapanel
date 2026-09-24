<?php
/**
 * 🛒 — the cart at a glance.
 *
 * @var array $priced  Shop::price() of the cart, without a discount code
 */
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => \HeleXa\Core\View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$lines = $priced['lines'];
?>
<div class="hub">
    <div class="hub-hero tone-green">
        <span class="hub-hero-ic"><?php $icon('cart'); ?></span>
        <div>
            <b>سبد خرید</b>
            <small><?= $lines === [] ? 'هنوز چیزی برنداشته‌ای.' : e(fa((string) $priced['count'])) . ' قلم · ' . e(Shop::money((int) $priced['subtotal'])) ?></small>
        </div>
    </div>

    <?php if ($lines === []): ?>
        <div class="hub-empty-cart">
            <span class="app-ic tone-orange"><?php $icon('bag'); ?></span>
            <p>پکیج‌ها و محصولات آموزشی در فروشگاه منتظرت هستند.</p>
            <a class="btn btn-primary" href="/shop">رفتن به فروشگاه</a>
        </div>
    <?php else: ?>
        <ul class="hub-list is-compact hub-cart">
            <?php foreach ($lines as $l): ?>
                <li class="hub-row">
                    <a class="hub-cart-thumb tone-<?= e($l['tone']) ?>" href="/shop/p/<?= e($l['uuid']) ?>">
                        <?php if (!empty($l['cover_path'])): ?><img src="/media/shop/<?= e($l['cover_path']) ?>" alt="" loading="lazy"><?php else: ?><?php $icon($l['kind'] === 'package' ? 'package' : 'bag', 18); ?><?php endif; ?>
                    </a>
                    <span class="hub-row-text">
                        <b><?= e($l['title']) ?></b>
                        <small><?= e(Shop::money((int) $l['price'])) ?><?= (int) $l['qty'] > 1 ? ' × ' . e(fa((string) $l['qty'])) : '' ?></small>
                    </span>
                    <span class="hub-qty">
                        <?php if ((int) $l['max_per_order'] > 1): ?>
                            <button type="button" data-cart-post="/shop/cart" data-cart-body="product=<?= e($l['uuid']) ?>&qty=<?= (int) $l['qty'] + 1 ?>" aria-label="یکی بیشتر">+</button>
                            <b><?= e(fa((string) $l['qty'])) ?></b>
                        <?php endif; ?>
                        <button type="button" data-cart-post="/shop/cart" data-cart-body="product=<?= e($l['uuid']) ?>&qty=<?= (int) $l['qty'] - 1 ?>" aria-label="<?= (int) $l['qty'] > 1 ? 'یکی کمتر' : 'حذف از سبد' ?>"><?= (int) $l['qty'] > 1 ? '−' : '' ?><?php if ((int) $l['qty'] <= 1) { $icon('trash', 15); } ?></button>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="hub-cart-sum">
            <?php if ($priced['savings'] > 0): ?><span class="hub-cart-save">سود تو از این خرید: <?= e(Shop::money((int) $priced['savings'])) ?></span><?php endif; ?>
            <span>جمع سبد <b><?= e(Shop::money((int) $priced['subtotal'])) ?></b></span>
        </div>
        <a class="btn btn-primary hub-cart-go" href="/shop/cart">ادامه و پرداخت</a>
    <?php endif; ?>
    <div class="hub-foot">
        <a class="hub-link" href="/shop">فروشگاه</a>
        <a class="hub-link" href="/shop/orders">سفارش‌های من</a>
    </div>
</div>
