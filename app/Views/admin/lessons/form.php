<?php
/**
 * The درسنامه editor.
 *
 * @var array|null $lesson
 * @var array      $tagIds
 * @var array      $allTags
 * @var array      $subjects
 * @var array      $packages
 * @var array      $colors
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$l = $lesson ?? ['uuid' => '', 'title' => '', 'summary' => '', 'subject_id' => null, 'package_id' => null, 'color' => 'indigo',
    'cover_path' => null, 'body_html' => '', 'status' => 'draft', 'sort_order' => 0];
$action = $lesson === null ? '/admin/lessons' : '/admin/lessons/' . $l['uuid'];
?>
<form class="le" method="post" action="<?= e($action) ?>" enctype="multipart/form-data" data-lesson-form data-upload="/admin/lessons/media" data-draft-key="lesson-draft-<?= e($l['uuid'] ?: 'new') ?>">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
    <input type="hidden" name="body_html" data-le-output>

    <div class="le-main">
        <input class="le-title" name="title" maxlength="191" value="<?= e($l['title']) ?>" placeholder="عنوان درسنامه" required>
        <input class="le-summary" name="summary" maxlength="500" value="<?= e($l['summary'] ?? '') ?>" placeholder="خلاصه یک‌خطی (در کارت درسنامه نمایش داده می‌شود)">

        <?php View::partial('partials.rich_editor', ['html' => (string) $l['body_html'], 'placeholder' => 'متن درسنامه را این‌جا بنویسید یا از ورد بچسبانید…']); ?>
    </div>

    <aside class="le-side">
        <section class="ad-card">
            <div class="hx-field">وضعیت
                <div class="seg-pick">
                    <label><input type="radio" name="status" value="draft" <?= $l['status'] !== 'published' ? 'checked' : '' ?>><span>پیش‌نویس</span></label>
                    <label><input type="radio" name="status" value="published" <?= $l['status'] === 'published' ? 'checked' : '' ?>><span>منتشر</span></label>
                </div>
            </div>
            <div class="le-actions">
                <button class="btn btn-primary" type="submit"><?php $icon('check', 16); ?> ذخیره</button>
                <button class="btn btn-ghost" type="submit" name="stay" value="1">ذخیره و ادامه</button>
            </div>
            <?php if ($lesson !== null): ?>
                <a class="hx-link" href="/student/lessons/<?= e($l['uuid']) ?>?preview=1" target="_blank">پیش‌نمایش دانشجو ←</a>
            <?php endif; ?>
        </section>

        <section class="ad-card">
            <label class="hx-field">درس / زیردرس
                <select class="input" name="subject_id">
                    <option value="0">— بدون درس —</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) ($l['subject_id'] ?? 0) === $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="hx-field" style="margin-top:12px">برچسب‌ها <small class="hx-muted">(مشترک با سوال‌ها و بازی با شکل)</small>
                <div class="le-tags">
                    <?php foreach ($allTags as $t): ?>
                        <label class="le-tag"><input type="checkbox" name="tags[]" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], array_map('intval', $tagIds), true) ? 'checked' : '' ?>><span class="stat-chip <?= e($t['color'] ?: 'chip-gray') ?>"><?= e($t['title']) ?></span></label>
                    <?php endforeach; ?>
                    <?php if ($allTags === []): ?><small class="hx-muted">هنوز برچسبی نیست؛ از <a href="/admin/lesson-tags">برچسب‌های مشترک</a> بسازید.</small><?php endif; ?>
                </div>
            </div>
        </section>

        <section class="ad-card">
            <div class="hx-field">رنگ کارت
                <div class="ad-swatches">
                    <?php foreach ($colors as $c): ?>
                        <label class="ad-swatch tone-<?= e($c) ?>"><input type="radio" name="color" value="<?= e($c) ?>" <?= $l['color'] === $c ? 'checked' : '' ?>><span></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <label class="hx-field" style="margin-top:12px">تصویر جلد (اختیاری)
                <input class="input" type="file" name="cover" accept="image/*">
            </label>
            <?php if (!empty($l['cover_path'])): ?>
                <div class="le-cover"><img src="/media/lessons/<?= e($l['cover_path']) ?>" alt=""><label class="hx-switch"><input type="checkbox" name="remove_cover" value="1"><span class="hx-switch-ui"></span><span>حذف جلد</span></label></div>
            <?php endif; ?>
            <label class="hx-field" style="margin-top:12px">فقط برای دارندگان پکیج
                <select class="input" name="package_id">
                    <option value="0">همه دانشجویان</option>
                    <?php foreach ($packages as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) ($l['package_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hx-field" style="margin-top:12px">ترتیب نمایش<input class="input" type="number" name="sort_order" value="<?= (int) $l['sort_order'] ?>" dir="ltr"></label>
        </section>
    </aside>
</form>
