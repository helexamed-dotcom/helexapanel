<?php
/**
 * One product.
 *
 * @var array       $theme
 * @var array       $p
 * @var array       $related
 * @var array       $inCart
 * @var string|null $blocked  why it cannot be bought right now
 */
use HeleXa\Core\View;
use HeleXa\Models\ShopRepository;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$isStudent = \HeleXa\Services\Auth::isStudent();
$off = Shop::discountPercent($p);
$images = array_values(array_filter(array_merge([$p['cover_path'] ?? ''], $p['gallery'] ?? [])));
$has = isset($inCart[(int) $p['id']]);
?>
<div class="sh sh-card-<?= e($theme['card']) ?> tone-<?= e($p['tone']) ?>" style="--sh-r: <?= (int) $theme['radius'] ?>px">
    <a class="sh-back" href="/shop"><?php $icon('chevron', 16); ?> <?= e($theme['title']) ?></a>

    <div class="sh-product">
        <div class="sh-gallery" data-gallery>
            <div class="sh-gallery-main">
                <?php if ($images !== []): ?>
                    <?php foreach ($images as $i => $img): ?>
                        <img src="/media/shop/<?= e($img) ?>" alt="" data-slide="<?= $i ?>"<?= $i > 0 ? ' hidden' : '' ?>>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="sh-art is-big"><?php $icon($p['kind'] === 'package' ? 'package' : 'bag', 96); ?></span>
                <?php endif; ?>
                <?php if (!empty($p['badge'])): ?><em class="sh-badge"><?= e($p['badge']) ?></em><?php endif; ?>
            </div>
            <?php if (count($images) > 1): ?>
                <div class="sh-thumbs">
                    <?php foreach ($images as $i => $img): ?>
                        <button type="button" class="<?= $i === 0 ? 'is-on' : '' ?>" data-thumb="<?= $i ?>"><img src="/media/shop/<?= e($img) ?>" alt=""></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="sh-buy">
            <small class="sh-kind"><?= e($p['category_title'] ?? ShopRepository::KINDS[$p['kind']]) ?></small>
            <h2><?= e($p['title']) ?></h2>
            <?php if (!empty($p['subtitle'])): ?><p class="sh-sub"><?= e($p['subtitle']) ?></p><?php endif; ?>

            <?php if ($p['features'] !== []): ?>
                <ul class="sh-features">
                    <?php foreach ($p['features'] as $f): ?><li><span class="sh-tick"><?php $icon('check', 14); ?></span><?= e((string) $f) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="sh-facts">
                <?php if ($p['kind'] === 'package'): ?>
                    <span><?php $icon('bolt', 15); ?> فعال‌سازی خودکار بعد از پرداخت</span>
                    <span><?php $icon('hourglass', 15); ?> <?= !empty($p['access_days']) ? 'دسترسی ' . e(fa((string) $p['access_days'])) . ' روزه' : 'دسترسی بدون محدودیت زمانی' ?></span>
                <?php elseif ($p['kind'] === 'physical'): ?>
                    <span><?php $icon('send', 15); ?> ارسال به نشانی تو</span>
                <?php endif; ?>
                <?php if ($p['stock'] !== null && (int) $p['stock'] > 0 && (int) $p['stock'] <= 10): ?>
                    <span class="is-warn"><?php $icon('flame', 15); ?> فقط <?= e(fa((string) $p['stock'])) ?> عدد باقی مانده</span>
                <?php endif; ?>
                <?php if ($theme['show_sold'] && (int) $p['sold_count'] > 0): ?>
                    <span><?php $icon('users', 15); ?> <?= e(fa((string) $p['sold_count'])) ?> نفر خریده‌اند</span>
                <?php endif; ?>
            </div>

            <div class="sh-buybox">
                <div class="sh-price is-big">
                    <?php if ($off > 0 && $theme['show_compare']): ?>
                        <span><s><?= e(fa(number_format((int) $p['compare_price']))) ?></s><i class="sh-off">٪<?= e(fa((string) $off)) ?> تخفیف</i></span>
                    <?php endif; ?>
                    <strong><?= (int) $p['price'] === 0 ? 'رایگان' : e(Shop::money((int) $p['price'])) ?></strong>
                </div>
                <?php if ($isStudent): ?>
                    <?php if ($blocked !== null && !$has): ?>
                        <p class="sh-blocked"><?php $icon('info', 16); ?> <?= e($blocked) ?></p>
                    <?php else: ?>
                        <form method="post" action="/shop/cart" data-add-cart data-go-cart>
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <input type="hidden" name="product" value="<?= e($p['uuid']) ?>">
                            <input type="hidden" name="back" value="/shop/p/<?= e($p['uuid']) ?>">
                            <button class="btn btn-primary sh-cta<?= $has ? ' is-in' : '' ?>" type="submit">
                                <?php $icon('cart', 18); ?> <span data-cta-text><?= $has ? 'در سبد است — افزودن دوباره' : 'افزودن به سبد خرید' ?></span>
                            </button>
                        </form>
                        <a class="sh-cta-alt" href="/shop/cart" <?= $has ? '' : 'hidden' ?> data-cart-link>مشاهده سبد و پرداخت ←</a>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="sh-blocked"><?php $icon('eye', 16); ?> پیش‌نمایش مدیر — خرید فقط برای دانشجو فعال است.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (trim((string) ($p['description'] ?? '')) !== ''): ?>
        <section class="sh-desc">
            <h3 class="sh-title"><?php $icon('info', 18); ?> توضیحات</h3>
            <div class="lx-doc"><?= $p['description'] /* sanitised by RichText on save */ ?></div>
        </section>
    <?php endif; ?>

    <?php if ($related !== []): ?>
        <h3 class="sh-title"><?php $icon('sparkle', 18); ?> از همین دسته</h3>
        <div class="sh-grid is-row">
            <?php foreach ($related as $r): ?>
                <a class="sh-mini tone-<?= e($r['tone']) ?> hx-zoom" href="/shop/p/<?= e($r['uuid']) ?>">
                    <span class="sh-mini-media"><?php if (!empty($r['cover_path'])): ?><img src="/media/shop/<?= e($r['cover_path']) ?>" alt="" loading="lazy"><?php else: ?><?php $icon('package', 26); ?><?php endif; ?></span>
                    <b><?= e($r['title']) ?></b>
                    <small><?= (int) $r['price'] === 0 ? 'رایگان' : e(Shop::money((int) $r['price'])) ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
