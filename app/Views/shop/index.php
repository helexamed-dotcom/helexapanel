<?php
/**
 * The store front. Everything about its look comes from the admin's
 * «ظاهر فروشگاه» settings ($theme).
 *
 * @var array $theme
 * @var array $products
 * @var array $featured
 * @var array $categories
 * @var array $filters
 * @var array $inCart   product id => qty
 */
use HeleXa\Core\View;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$isStudent = \HeleXa\Services\Auth::isStudent();
$query = static function (array $change) use ($filters): string {
    $q = array_filter(array_merge($filters, $change), static fn ($v) => $v !== '' && $v !== 0 && $v !== null);
    return '/shop' . ($q === [] ? '' : '?' . http_build_query($q));
};
?>
<div class="sh sh-card-<?= e($theme['card']) ?> tone-<?= e($theme['tone']) ?>" style="--sh-cols: <?= (int) $theme['columns'] ?>; --sh-r: <?= (int) $theme['radius'] ?>px">
    <?php if ($theme['notice'] !== ''): ?>
        <div class="sh-notice"><?php $icon('gift', 16); ?> <span><?= e($theme['notice']) ?></span></div>
    <?php endif; ?>

    <section class="sh-hero is-<?= e($theme['hero']) ?>"<?= $theme['hero'] === 'image' && $theme['banner'] !== '' ? ' style="--sh-banner: url(\'/media/shop/' . e($theme['banner']) . '\')"' : '' ?>>
        <div class="sh-hero-text">
            <h2><?= e($theme['title']) ?></h2>
            <?php if ($theme['subtitle'] !== ''): ?><p><?= e($theme['subtitle']) ?></p><?php endif; ?>
            <?php if ($theme['trust'] !== []): ?>
                <ul class="sh-trust">
                    <?php foreach ($theme['trust'] as $t): ?><li><?php $icon('check', 14); ?> <?= e($t) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php if ($isStudent): ?>
            <div class="sh-hero-actions">
                <a class="sh-pill" href="/shop/orders"><?php $icon('receipt', 16); ?> سفارش‌های من</a>
                <a class="sh-pill is-solid" href="/shop/cart"><?php $icon('cart', 16); ?> سبد خرید <b data-cart-count><?= $inCart !== [] ? e(fa((string) array_sum($inCart))) : '' ?></b></a>
            </div>
        <?php endif; ?>
        <span class="sh-hero-art" aria-hidden="true"><?php $icon('store', 120); ?></span>
    </section>

    <?php if ($theme['show_search'] || $theme['show_categories']): ?>
        <div class="sh-tools">
            <?php if ($theme['show_search']): ?>
                <form class="sh-search" method="get" action="/shop" role="search">
                    <?php $icon('search', 18); ?>
                    <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="جستجو در محصولات…" aria-label="جستجو">
                    <?php if ($filters['category']): ?><input type="hidden" name="category" value="<?= (int) $filters['category'] ?>"><?php endif; ?>
                </form>
            <?php endif; ?>
            <div class="sh-sort" role="group" aria-label="مرتب‌سازی">
                <?php foreach (['' => 'پیشنهادی', 'popular' => 'پرفروش', 'new' => 'جدید', 'cheap' => 'ارزان‌تر'] as $k => $label): ?>
                    <a class="<?= $filters['sort'] === $k ? 'is-on' : '' ?>" href="<?= e($query(['sort' => $k])) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($theme['show_categories'] && $categories !== []): ?>
            <nav class="sh-cats" aria-label="دسته‌ها">
                <a class="sh-cat<?= !$filters['category'] ? ' is-on' : '' ?>" href="<?= e($query(['category' => 0])) ?>">
                    <span class="app-ic tone-slate"><?php $icon('apps', 20); ?></span><b>همه</b>
                </a>
                <?php foreach ($categories as $c): ?>
                    <a class="sh-cat<?= $filters['category'] === (int) $c['id'] ? ' is-on' : '' ?>" href="<?= e($query(['category' => (int) $c['id']])) ?>">
                        <span class="app-ic tone-<?= e($c['tone']) ?>"><?php $icon($c['icon'], 20); ?></span><b><?= e($c['title']) ?></b>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($featured !== []): ?>
        <h3 class="sh-title"><?php $icon('star', 18); ?> ویژه</h3>
        <div class="sh-featured">
            <?php foreach ($featured as $i => $p): $off = Shop::discountPercent($p); ?>
                <a class="sh-feature tone-<?= e($p['tone']) ?> hx-zoom" href="/shop/p/<?= e($p['uuid']) ?>" style="--i: <?= $i ?>">
                    <span class="sh-feature-media">
                        <?php if (!empty($p['cover_path'])): ?><img src="/media/shop/<?= e($p['cover_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="sh-art"><?php $icon($p['kind'] === 'package' ? 'package' : 'bag', 56); ?></span><?php endif; ?>
                    </span>
                    <span class="sh-feature-body">
                        <?php if (!empty($p['badge'])): ?><em class="sh-badge"><?= e($p['badge']) ?></em><?php endif; ?>
                        <b><?= e($p['title']) ?></b>
                        <?php if (!empty($p['subtitle'])): ?><small><?= e($p['subtitle']) ?></small><?php endif; ?>
                        <span class="sh-price">
                            <?php if ($off > 0 && $theme['show_compare']): ?><s><?= e(fa(number_format((int) $p['compare_price']))) ?></s><i class="sh-off">٪<?= e(fa((string) $off)) ?></i><?php endif; ?>
                            <strong><?= (int) $p['price'] === 0 ? 'رایگان' : e(Shop::money((int) $p['price'])) ?></strong>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($products === []): ?>
        <div class="sh-empty">
            <span class="app-ic tone-orange"><?php $icon('bag'); ?></span>
            <b><?= $filters['q'] !== '' ? 'چیزی با این جستجو پیدا نشد' : 'فعلاً محصولی برای فروش نیست' ?></b>
            <small>به‌زودی محصولات تازه اضافه می‌شود.</small>
        </div>
    <?php else: ?>
        <h3 class="sh-title"><?php $icon('bag', 18); ?> <?= $filters['q'] !== '' ? 'نتایج «' . e($filters['q']) . '»' : 'همه محصولات' ?></h3>
        <div class="sh-grid is-<?= e($theme['layout']) ?>">
            <?php foreach ($products as $i => $p):
                $off = Shop::discountPercent($p);
                $out = $p['stock'] !== null && (int) $p['stock'] <= 0;
                $has = isset($inCart[(int) $p['id']]); ?>
                <article class="sh-item tone-<?= e($p['tone']) ?><?= $out ? ' is-out' : '' ?>" style="--i: <?= min($i, 12) ?>">
                    <a class="sh-item-media hx-zoom" href="/shop/p/<?= e($p['uuid']) ?>">
                        <?php if (!empty($p['cover_path'])): ?><img src="/media/shop/<?= e($p['cover_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="sh-art"><?php $icon($p['kind'] === 'package' ? 'package' : 'bag', 44); ?></span><?php endif; ?>
                        <?php if (!empty($p['badge'])): ?><em class="sh-badge"><?= e($p['badge']) ?></em><?php endif; ?>
                        <?php if ($off > 0 && $theme['show_compare']): ?><i class="sh-off is-float">٪<?= e(fa((string) $off)) ?></i><?php endif; ?>
                    </a>
                    <div class="sh-item-body">
                        <small class="sh-kind"><?= e($p['category_title'] ?? \HeleXa\Models\ShopRepository::KINDS[$p['kind']]) ?></small>
                        <a class="sh-item-title" href="/shop/p/<?= e($p['uuid']) ?>"><?= e($p['title']) ?></a>
                        <?php if (!empty($p['subtitle'])): ?><p><?= e($p['subtitle']) ?></p><?php endif; ?>
                        <div class="sh-item-foot">
                            <span class="sh-price">
                                <?php if ($off > 0 && $theme['show_compare']): ?><s><?= e(fa(number_format((int) $p['compare_price']))) ?></s><?php endif; ?>
                                <strong><?= (int) $p['price'] === 0 ? 'رایگان' : e(Shop::money((int) $p['price'])) ?></strong>
                                <?php if ($theme['show_sold'] && (int) $p['sold_count'] > 0): ?><small><?= e(fa((string) $p['sold_count'])) ?> خرید</small><?php endif; ?>
                            </span>
                            <?php if ($isStudent): ?>
                                <?php if ($out): ?>
                                    <span class="sh-add is-off">ناموجود</span>
                                <?php else: ?>
                                    <form method="post" action="/shop/cart" data-add-cart>
                                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                        <input type="hidden" name="product" value="<?= e($p['uuid']) ?>">
                                        <input type="hidden" name="back" value="/shop">
                                        <button class="sh-add<?= $has ? ' is-in' : '' ?>" type="submit" aria-label="افزودن به سبد">
                                            <span class="sh-add-plus"><?php $icon('plus', 18); ?></span>
                                            <span class="sh-add-ok"><?php $icon('check', 18); ?></span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($theme['footer'] !== ''): ?>
        <p class="sh-footer"><?= nl2br(e($theme['footer'])) ?></p>
    <?php endif; ?>
</div>
