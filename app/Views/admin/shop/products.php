<?php
/**
 * Products and the shelves they sit on.
 *
 * @var array $rows
 * @var array $filters
 * @var array $categories
 * @var array $icons
 * @var array $tones
 */
use HeleXa\Core\View;
use HeleXa\Models\ShopRepository;
use HeleXa\Services\Shop\Shop;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$statusLabel = ['draft' => ['پیش‌نویس', ''], 'published' => ['در ویترین', 'is-on'], 'archived' => ['بایگانی', 'is-muted']];
?>
<div class="ad-page sa">
    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-orange"><?php $icon('bag'); ?></span>
            <div><h3>محصولات</h3><p>هر محصول از نوع «پکیج آموزشی» بعد از پرداخت، پکیجِ وصل‌شده را خودکار برای دانشجو فعال می‌کند.</p></div>
            <a class="btn btn-primary" href="/admin/shop/products/create"><?php $icon('plus', 16); ?> محصول تازه</a>
        </header>

        <form class="sa-filter" method="get" action="/admin/shop/products">
            <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="جستجوی نام محصول…">
            <select class="input" name="status">
                <option value="">همه وضعیت‌ها</option>
                <?php foreach ($statusLabel as $k => [$l]): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
            <select class="input" name="category">
                <option value="0">همه دسته‌ها</option>
                <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $filters['category'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-ghost" type="submit"><?php $icon('filter', 16); ?> فیلتر</button>
        </form>

        <?php if ($rows === []): ?>
            <div class="ad-empty">محصولی پیدا نشد. <a class="hx-link" href="/admin/shop/products/create">اولین محصول را بسازید</a></div>
        <?php else: ?>
            <div class="sa-products">
                <?php foreach ($rows as $i => $p): [$sl, $sc] = $statusLabel[$p['status']]; $off = Shop::discountPercent($p); ?>
                    <article class="sa-product tone-<?= e($p['tone']) ?><?= $p['status'] !== 'published' ? ' is-dim' : '' ?>" style="--i: <?= min($i, 12) ?>">
                        <a class="sa-product-media" href="/admin/shop/products/<?= e($p['uuid']) ?>/edit">
                            <?php if (!empty($p['cover_path'])): ?><img src="/media/shop/<?= e($p['cover_path']) ?>" alt="" loading="lazy"><?php else: ?><?php $icon($p['kind'] === 'package' ? 'package' : 'bag', 30); ?><?php endif; ?>
                            <?php if ((int) $p['featured'] === 1): ?><em class="sa-star" title="ویژه"><?php $icon('star', 13); ?></em><?php endif; ?>
                        </a>
                        <div class="sa-product-body">
                            <a class="sa-product-title" href="/admin/shop/products/<?= e($p['uuid']) ?>/edit"><?= e($p['title']) ?></a>
                            <small><?= e(ShopRepository::KINDS[$p['kind']]) ?><?= $p['category_title'] ? ' · ' . e($p['category_title']) : '' ?><?= $p['package_title'] ? ' · ' . e($p['package_title']) : '' ?></small>
                            <div class="sa-product-meta">
                                <b><?= (int) $p['price'] === 0 ? 'رایگان' : e(Shop::money((int) $p['price'])) ?></b>
                                <?php if ($off > 0): ?><span class="ad-pill">٪<?= e(fa((string) $off)) ?> تخفیف</span><?php endif; ?>
                                <span class="ad-pill"><?= e(fa((string) $p['sold_count'])) ?> فروش</span>
                                <?php if ($p['stock'] !== null): ?><span class="ad-pill<?= (int) $p['stock'] <= 0 ? ' is-bad' : '' ?>">موجودی <?= e(fa((string) $p['stock'])) ?></span><?php endif; ?>
                            </div>
                        </div>
                        <div class="sa-product-actions">
                            <form method="post" action="/admin/shop/products/<?= e($p['uuid']) ?>/status">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="status" value="<?= $p['status'] === 'published' ? 'draft' : 'published' ?>">
                                <button class="sa-toggle <?= e($sc) ?>" type="submit" title="<?= $p['status'] === 'published' ? 'برداشتن از ویترین' : 'نمایش در فروشگاه' ?>"><i></i><span><?= e($sl) ?></span></button>
                            </form>
                            <a class="ad-icon-btn" href="/shop/p/<?= e($p['uuid']) ?>" target="_blank" title="پیش‌نمایش"><?php $icon('eye', 16); ?></a>
                            <a class="ad-icon-btn" href="/admin/shop/products/<?= e($p['uuid']) ?>/edit" title="ویرایش"><?php $icon('pen', 16); ?></a>
                            <form method="post" action="/admin/shop/products/<?= e($p['uuid']) ?>/delete" data-confirm="«<?= e($p['title']) ?>» حذف شود؟ سفارش‌های قبلی دست نمی‌خورد.">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="ad-icon-btn is-danger" type="submit" title="حذف"><?php $icon('trash', 16); ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="ad-card" id="categories">
        <header class="ad-card-head">
            <span class="app-ic tone-violet"><?php $icon('grid'); ?></span>
            <div><h3>دسته‌ها</h3><p>قفسه‌های فروشگاه؛ با آیکن و رنگ خودشان بالای ویترین نمایش داده می‌شوند.</p></div>
        </header>
        <form class="ad-form-grid sa-cat-form" method="post" action="/admin/shop/categories">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <input type="hidden" name="id" value="0" data-cat-id>
            <label class="hx-field">نام دسته<input class="input" name="title" maxlength="120" required data-cat-title></label>
            <label class="hx-field">ترتیب<input class="input" type="number" name="sort_order" value="0" data-cat-sort></label>
            <div class="hx-field is-wide">آیکن
                <div class="sa-icons">
                    <?php foreach ($icons as $n): ?>
                        <label><input type="radio" name="icon" value="<?= e($n) ?>" <?= $n === 'bag' ? 'checked' : '' ?>><span><?php $icon($n, 18); ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="hx-field is-wide">رنگ
                <div class="ad-swatches">
                    <?php foreach ($tones as $t): ?>
                        <label class="ad-swatch tone-<?= e($t) ?>"><input type="radio" name="tone" value="<?= e($t) ?>" <?= $t === 'orange' ? 'checked' : '' ?>><span></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="is-wide"><button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> ذخیره دسته</button></div>
        </form>
        <?php if ($categories !== []): ?>
            <ul class="ad-list">
                <?php foreach ($categories as $c): ?>
                    <li class="ad-row">
                        <span class="app-ic tone-<?= e($c['tone']) ?>"><?php $icon($c['icon'], 18); ?></span>
                        <span class="ad-row-main"><b><?= e($c['title']) ?></b><small><?= e(fa((string) $c['products'])) ?> محصول منتشرشده</small></span>
                        <button class="ad-icon-btn" type="button" title="ویرایش"
                                data-cat-edit='<?= e((string) json_encode(['id' => (int) $c['id'], 'title' => $c['title'], 'icon' => $c['icon'], 'tone' => $c['tone'], 'sort' => (int) $c['sort_order']], JSON_UNESCAPED_UNICODE)) ?>'><?php $icon('pen', 16); ?></button>
                        <form method="post" action="/admin/shop/categories/<?= (int) $c['id'] ?>/delete" data-confirm="دسته «<?= e($c['title']) ?>» حذف شود؟">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="ad-icon-btn is-danger" type="submit"><?php $icon('trash', 16); ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<script nonce="<?= e($cspNonce) ?>">
document.querySelectorAll('[data-cat-edit]').forEach(function (b) {
    b.addEventListener('click', function () {
        var c = JSON.parse(b.getAttribute('data-cat-edit'));
        var f = document.querySelector('.sa-cat-form');
        f.querySelector('[data-cat-id]').value = c.id;
        f.querySelector('[data-cat-title]').value = c.title;
        f.querySelector('[data-cat-sort]').value = c.sort;
        var i = f.querySelector('input[name="icon"][value="' + c.icon + '"]'); if (i) { i.checked = true; }
        var t = f.querySelector('input[name="tone"][value="' + c.tone + '"]'); if (t) { t.checked = true; }
        f.scrollIntoView({ behavior: 'smooth', block: 'center' });
        f.querySelector('[data-cat-title]').focus();
    });
});
</script>
