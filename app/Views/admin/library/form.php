<?php
/**
 * Add or edit one library item. Fields for the chosen kind are shown by
 * library.js; without script every field is visible and the server ignores
 * the ones that do not apply.
 *
 * @var array|null $item
 * @var array      $kinds
 * @var array      $courses
 * @var int        $maxMb
 * @var string     $phpMax
 */
$isEdit = $item !== null;
$kind   = $item['kind'] ?? 'video';
$action = $isEdit ? '/admin/library/' . $item['uuid'] : '/admin/library';
?>
<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="qb-page" style="max-width:860px;" data-lib-form>
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

    <section class="qb-section">
        <div class="qb-section-head"><h3><span class="qb-step">۱</span> نوع و عنوان</h3></div>
        <div class="qb-seg lib-kinds" role="radiogroup" aria-label="نوع محتوا">
            <?php foreach ($kinds as $k => $label): ?>
                <label class="medium"><input type="radio" name="kind" value="<?= e($k) ?>" <?= $kind === $k ? 'checked' : '' ?> data-lib-kind><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="field" style="margin-top:14px;"><label class="label" for="lb-title">عنوان</label>
            <input class="input" id="lb-title" name="title" maxlength="191" required value="<?= e($item['title'] ?? '') ?>"></div>
        <div class="field"><label class="label" for="lb-sum">خلاصه (در کارت و جستجو نمایش داده می‌شود)</label>
            <textarea class="input" id="lb-sum" name="summary" rows="2" maxlength="500"><?= e($item['summary'] ?? '') ?></textarea></div>
    </section>

    <section class="qb-section">
        <div class="qb-section-head"><h3><span class="qb-step">۲</span> محتوا</h3></div>

        <div data-lib-for="video image file">
            <div class="field"><label class="label" for="lb-file">فایل</label>
                <input class="input" id="lb-file" type="file" name="file">
                <p class="qb-hint">ویدیو: MP4/WEBM · تصویر: JPG/PNG/WEBP/GIF · فایل: PDF، Word، PowerPoint، Excel، ZIP یا MP3.
                   سقف: <?= e(fa((string) $maxMb)) ?> مگابایت و سقف سرور <?= e($phpMax) ?> — برای ویدیوهای بزرگ‌تر از نوع «لینک» استفاده کنید.</p>
                <?php if ($isEdit && !empty($item['file_path'])): ?>
                    <p class="qb-hint">فایل فعلی: <a href="/admin/library/<?= e($item['uuid']) ?>/media/file" target="_blank" rel="noopener"><?= e($item['file_name'] ?? 'مشاهده') ?></a> — برای جایگزینی فایل جدید انتخاب کنید.</p>
                <?php endif; ?>
            </div>
        </div>

        <div data-lib-for="article">
            <div class="field"><label class="label" for="lb-body">متن مقاله</label>
                <textarea class="input" id="lb-body" name="body" rows="14" dir="auto"><?= e($item['body'] ?? '') ?></textarea>
                <p class="qb-hint">متن ساده؛ خط خالی پاراگراف جدید می‌سازد.</p></div>
        </div>

        <div data-lib-for="link">
            <div class="field"><label class="label" for="lb-url">آدرس لینک</label>
                <input class="input" id="lb-url" name="url" dir="ltr" placeholder="https://…" value="<?= e($item['url'] ?? '') ?>"></div>
        </div>
    </section>

    <section class="qb-section">
        <div class="qb-section-head"><h3><span class="qb-step">۳</span> تصویر پیش‌نمایش (۴:۳)</h3></div>
        <div class="lib-cover-edit">
            <div class="lib-cover" data-lib-cover-preview>
                <?php if ($isEdit && ($item['cover_path'] || $item['kind'] === 'image')): ?>
                    <img src="/admin/library/<?= e($item['uuid']) ?>/media/cover" alt="">
                <?php else: ?>
                    <span class="lib-glyph">🖼</span>
                <?php endif; ?>
            </div>
            <div>
                <input class="input" type="file" name="cover" accept="image/jpeg,image/png,image/webp,image/gif" data-lib-cover>
                <p class="qb-hint">هر ابعادی قبول است؛ تصویر بدون کشیدگی در قاب افقی ۴:۳ برش می‌خورد — همین پیش‌نمایش همان چیزی است که دانشجو می‌بیند.</p>
                <?php if ($isEdit && $item['cover_path']): ?>
                    <label class="remember-row"><input type="checkbox" name="remove_cover" value="1"><span>حذف تصویر فعلی</span></label>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="qb-section">
        <div class="qb-section-head"><h3><span class="qb-step">۴</span> مخاطب و انتشار</h3></div>
        <div class="qb-grid-3">
            <div class="field" style="margin:0;"><label class="label" for="lb-course">نمایش برای</label>
                <select class="input" id="lb-course" name="course_id">
                    <option value="">همه دانشجویان</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) ($item['course_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>فقط دانشجویان «<?= e($c['title']) ?>»</option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field" style="margin:0;"><label class="label" for="lb-sort">ترتیب</label>
                <input class="input" id="lb-sort" type="number" name="sort_order" value="<?= (int) ($item['sort_order'] ?? 0) ?>" dir="ltr"></div>
            <div class="field" style="margin:0;"><label class="label" for="lb-status">وضعیت</label>
                <select class="input" id="lb-status" name="status">
                    <option value="draft" <?= ($item['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
                    <option value="published" <?= ($item['status'] ?? '') === 'published' ? 'selected' : '' ?>>منتشرشده (اطلاعیه ارسال می‌شود)</option>
                </select></div>
        </div>
    </section>

    <div class="qb-savebar">
        <a class="btn btn-ghost" href="/admin/library">انصراف</a>
        <button class="btn btn-primary" type="submit" data-lock-on-submit><?= $isEdit ? 'ذخیره تغییرات' : 'افزودن' ?></button>
    </div>
</form>