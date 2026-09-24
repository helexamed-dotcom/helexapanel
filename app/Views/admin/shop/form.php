<?php
/**
 * A product: the description in the same Word-like editor as درسنامه‌ها,
 * and everything about selling it in the side panel.
 *
 * @var array|null $product
 * @var array      $categories
 * @var array      $packages
 * @var array      $kinds
 * @var array      $tones
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$p = $product ?? ['uuid' => '', 'title' => '', 'subtitle' => '', 'description' => '', 'features' => [], 'kind' => 'package', 'category_id' => null,
    'tone' => 'indigo', 'badge' => '', 'price' => 0, 'compare_price' => null, 'package_id' => null, 'access_days' => 365, 'stock' => null,
    'max_per_order' => 1, 'featured' => 0, 'status' => 'draft', 'sort_order' => 0, 'cover_path' => null, 'gallery' => []];
$action = $product === null ? '/admin/shop/products' : '/admin/shop/products/' . $p['uuid'];
$money = static fn ($v) => $v === null || (int) $v === 0 ? '' : number_format((int) $v);
?>
<form class="le sa-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data" data-lesson-form data-upload="/admin/shop/media"
      data-draft-key="product-draft-<?= e($p['uuid'] ?: 'new') ?>" data-product-form>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <input type="hidden" name="body_html" data-le-output>

    <div class="le-main">
        <input class="le-title" name="title" maxlength="191" value="<?= e($p['title']) ?>" placeholder="نام محصول" required data-pv="title">
        <input class="le-summary" name="subtitle" maxlength="255" value="<?= e($p['subtitle'] ?? '') ?>" placeholder="یک جمله کوتاه زیر نام (در کارت محصول)" data-pv="subtitle">

        <section class="ad-card sa-features">
            <label class="hx-field">ویژگی‌ها <small class="hx-muted">(هر خط یک ویژگی — با تیک کنار نام نمایش داده می‌شود)</small>
                <textarea class="input" name="features" rows="4" placeholder="۱۲۰۰ سوال با پاسخ تشریحی&#10;۴۰ درسنامه تصویری&#10;پشتیبانی تا روز آزمون"><?= e(implode("\n", $p['features'] ?? [])) ?></textarea>
            </label>
        </section>

        <h3 class="sa-sub"><?php $icon('info', 17); ?> توضیحات کامل</h3>
        <?php View::partial('partials.rich_editor', ['html' => (string) ($p['description'] ?? ''), 'placeholder' => 'توضیح کامل محصول: چه چیزهایی دارد، برای چه کسی است، سرفصل‌ها…']); ?>
    </div>

    <aside class="le-side">
        <section class="ad-card">
            <div class="hx-field">وضعیت
                <div class="seg-pick">
                    <label><input type="radio" name="status" value="draft" <?= $p['status'] === 'draft' ? 'checked' : '' ?>><span>پیش‌نویس</span></label>
                    <label><input type="radio" name="status" value="published" <?= $p['status'] === 'published' ? 'checked' : '' ?>><span>در ویترین</span></label>
                    <label><input type="radio" name="status" value="archived" <?= $p['status'] === 'archived' ? 'checked' : '' ?>><span>بایگانی</span></label>
                </div>
            </div>
            <label class="sa-check"><input type="checkbox" name="featured" value="1" <?= (int) $p['featured'] === 1 ? 'checked' : '' ?>> <span><?php $icon('star', 15); ?> محصول ویژه (بالای فروشگاه)</span></label>
            <div class="le-actions">
                <button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> ذخیره</button>
                <button class="btn btn-ghost" type="submit" name="stay" value="1">ذخیره و ادامه</button>
            </div>
            <?php if ($product !== null): ?><a class="hx-link" href="/shop/p/<?= e($p['uuid']) ?>" target="_blank">دیدن در فروشگاه ←</a><?php endif; ?>
        </section>

        <section class="ad-card sa-preview-card">
            <b class="sa-mini-t">پیش‌نمایش کارت</b>
            <div class="sa-preview tone-<?= e($p['tone']) ?>" data-preview>
                <span class="sa-preview-media" data-pv-media>
                    <?php if (!empty($p['cover_path'])): ?><img src="/media/shop/<?= e($p['cover_path']) ?>" alt=""><?php else: ?><?php $icon('package', 34); ?><?php endif; ?>
                    <em class="sa-preview-badge" data-pv-badge <?= empty($p['badge']) ? 'hidden' : '' ?>><?= e((string) $p['badge']) ?></em>
                </span>
                <span class="sa-preview-body">
                    <b data-pv-title><?= e($p['title'] ?: 'نام محصول') ?></b>
                    <small data-pv-subtitle><?= e((string) ($p['subtitle'] ?? '')) ?></small>
                    <span class="sa-preview-price"><s data-pv-compare></s> <strong data-pv-price></strong></span>
                </span>
            </div>
        </section>

        <section class="ad-card">
            <div class="hx-field">نوع محصول
                <div class="sa-kinds">
                    <?php foreach ($kinds as $k => $label): ?>
                        <label class="sa-kind"><input type="radio" name="kind" value="<?= e($k) ?>" <?= $p['kind'] === $k ? 'checked' : '' ?> data-kind>
                            <span><?php $icon(['package' => 'package', 'physical' => 'send', 'service' => 'calendar'][$k], 18); ?><?= e($label) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div data-for-kind="package">
                <label class="hx-field">پکیجی که بعد از پرداخت فعال می‌شود
                    <select class="input" name="package_id">
                        <option value="0">— انتخاب پکیج —</option>
                        <?php foreach ($packages as $k): ?><option value="<?= (int) $k['id'] ?>" <?= (int) ($p['package_id'] ?? 0) === (int) $k['id'] ? 'selected' : '' ?>><?= e($k['title']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <?php if ($packages === []): ?><p class="sa-hint">هنوز پکیجی نساخته‌اید. <a class="hx-link" href="/admin/packages">ساخت پکیج</a></p><?php endif; ?>
                <label class="hx-field">مدت دسترسی (روز) <small class="hx-muted">خالی = بدون محدودیت</small>
                    <input class="input" type="number" min="0" max="3650" name="access_days" value="<?= e((string) ($p['access_days'] ?? '')) ?>" placeholder="مثلاً ۳۶۵">
                </label>
            </div>
            <div data-for-kind="physical service">
                <label class="hx-field">حداکثر تعداد در هر سفارش<input class="input" type="number" min="1" max="99" name="max_per_order" value="<?= (int) $p['max_per_order'] ?>"></label>
            </div>
            <label class="hx-field">موجودی <small class="hx-muted">خالی = نامحدود</small>
                <input class="input" name="stock" inputmode="numeric" value="<?= e($p['stock'] === null ? '' : (string) $p['stock']) ?>" placeholder="نامحدود">
            </label>
        </section>

        <section class="ad-card">
            <label class="hx-field">قیمت (تومان)
                <input class="input sa-money-in" name="price" inputmode="numeric" dir="ltr" value="<?= e($money($p['price'])) ?>" placeholder="0 = رایگان" data-money data-pv="price">
            </label>
            <label class="hx-field">قیمت قبل از تخفیف <small class="hx-muted">(خط‌خورده نمایش داده می‌شود)</small>
                <input class="input sa-money-in" name="compare_price" inputmode="numeric" dir="ltr" value="<?= e($money($p['compare_price'])) ?>" placeholder="اختیاری" data-money data-pv="compare">
            </label>
            <label class="hx-field">برچسب روی کارت<input class="input" name="badge" maxlength="40" value="<?= e((string) ($p['badge'] ?? '')) ?>" placeholder="مثلاً: پرفروش، جدید، ۲۴ ساعت تخفیف" data-pv="badge"></label>
        </section>

        <section class="ad-card">
            <label class="hx-field">دسته
                <select class="input" name="category_id">
                    <option value="0">— بدون دسته —</option>
                    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($p['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <div class="hx-field">رنگ کارت
                <div class="ad-swatches">
                    <?php foreach ($tones as $t): ?>
                        <label class="ad-swatch tone-<?= e($t) ?>"><input type="radio" name="tone" value="<?= e($t) ?>" <?= $p['tone'] === $t ? 'checked' : '' ?> data-tone><span></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <label class="hx-field">ترتیب نمایش<input class="input" type="number" name="sort_order" value="<?= (int) $p['sort_order'] ?>"></label>
        </section>

        <section class="ad-card">
            <div class="hx-field">تصویر اصلی
                <label class="sa-upload">
                    <input type="file" name="cover" accept="image/*" data-cover-input>
                    <?php $icon('upload', 20); ?><span>انتخاب تصویر (نسبت ۴ به ۳ بهتر است)</span>
                </label>
                <?php if (!empty($p['cover_path'])): ?>
                    <label class="sa-check"><input type="checkbox" name="remove_cover" value="1"> <span>حذف تصویر فعلی</span></label>
                <?php endif; ?>
            </div>
            <div class="hx-field">گالری <small class="hx-muted">(تا ۸ تصویر)</small>
                <?php if (($p['gallery'] ?? []) !== []): ?>
                    <div class="sa-gallery">
                        <?php foreach ($p['gallery'] as $g): ?>
                            <label class="sa-gal"><img src="/media/shop/<?= e($g) ?>" alt=""><input type="checkbox" name="remove_gallery[]" value="<?= e($g) ?>"><span title="حذف"><?php $icon('trash', 13); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <label class="sa-upload"><input type="file" name="gallery[]" accept="image/*" multiple><?php $icon('image', 20); ?><span>افزودن تصویر به گالری</span></label>
            </div>
        </section>
    </aside>
</form>
