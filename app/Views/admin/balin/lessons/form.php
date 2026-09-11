<?php
/**
 * @var ?array $lesson
 * @var array  $old
 * @var array  $errors
 * @var ?int   $learners
 */
$value = static fn (string $key, mixed $fallback = '') => e((string) ($old[$key] ?? $lesson[$key] ?? $fallback));
$action = $lesson === null ? '/admin/balin/lessons' : '/admin/balin/lessons/' . $lesson['uuid'];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;"><?= $lesson === null ? 'درس جدید' : 'ویرایش درس' ?></h3>
        <?php if ($lesson !== null): ?>
            <a class="btn btn-ghost btn-sm" href="/admin/balin/lessons/<?= e($lesson['uuid']) ?>">بازگشت</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($learners)): ?>
        <div class="balin-warning">
            <?= e(fa((int) $learners)) ?> دانشجو این درس را با نسخه فعلی گذرانده‌اند.
            ویرایش محتوا پیشرفت ثبت‌شده آن‌ها را تغییر نمی‌دهد.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <?php if ($lesson !== null): ?>
            <input type="hidden" name="version" value="<?= (int) $lesson['version'] ?>">
        <?php endif; ?>

        <label class="field span-2">
            <span>عنوان درس *</span>
            <input type="text" name="title" required maxlength="191" value="<?= $value('title') ?>">
            <?php if (isset($errors['title'])): ?><small class="err"><?= e($errors['title']) ?></small><?php endif; ?>
        </label>

        <label class="field">
            <span>نشانی یکتا (slug)</span>
            <input type="text" name="slug" maxlength="120" value="<?= $value('slug') ?>"
                   placeholder="از عنوان ساخته می‌شود">
            <?php if (isset($errors['slug'])): ?><small class="err"><?= e($errors['slug']) ?></small><?php endif; ?>
        </label>

        <label class="field">
            <span>آیکون (اموجی)</span>
            <input type="text" name="icon" maxlength="8" value="<?= $value('icon') ?>" placeholder="🫀">
        </label>

        <label class="field span-2">
            <span>توضیح کوتاه</span>
            <textarea name="description" rows="3"><?= $value('description') ?></textarea>
        </label>

        <label class="field">
            <span>رنگ تم</span>
            <input type="text" name="color" maxlength="16" value="<?= $value('color') ?>" placeholder="#2563eb">
        </label>

        <label class="field">
            <span>مدت تقریبی (دقیقه)</span>
            <input type="number" name="estimated_minutes" min="0" max="10000"
                   value="<?= $value('estimated_minutes', '0') ?>">
        </label>

        <label class="field">
            <span>امتیاز تکمیل درس</span>
            <input type="number" name="xp_reward" min="0" max="10000" value="<?= $value('xp_reward', '0') ?>">
        </label>

        <label class="field">
            <span>تصویر کاور</span>
            <input type="file" name="cover" accept="image/png,image/jpeg,image/webp,image/svg+xml">
            <?php if (!empty($lesson['cover_path'])): ?>
                <small>کاور فعلی ذخیره شده است؛ برای تغییر فایل جدید انتخاب کن.</small>
            <?php endif; ?>
        </label>

        <label class="field span-2">
            <span>یادداشت داخلی</span>
            <textarea name="extra_notes" rows="2"><?= $value('extra_notes') ?></textarea>
        </label>

        <div class="form-actions span-2">
            <button class="btn btn-primary" type="submit">ذخیره</button>
            <a class="btn btn-ghost" href="/admin/balin/lessons">انصراف</a>
        </div>
    </form>
</div>
