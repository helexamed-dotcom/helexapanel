<div class="card" style="max-width:680px;">
    <h3 class="card-title"><?= $course === null ? 'افزودن دوره' : 'ویرایش دوره' ?></h3>

    <?php if ($course !== null): ?>
        <div class="brand-slot" style="max-width:260px; margin-bottom:18px;">
            <div class="brand-preview">
                <?php if (!empty($course['thumbnail_path'])): ?>
                    <img src="/assets/<?= e($course['thumbnail_path']) ?>" alt="">
                <?php else: ?>
                    <span class="brand-preview-empty">بدون آیکون</span>
                <?php endif; ?>
            </div>
            <strong style="font-size:12.5px;">آیکون دوره</strong>
            <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/icon"
                  enctype="multipart/form-data" class="brand-form">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <input class="input" type="file" name="icon" accept="image/png,image/jpeg,image/webp,.svg">
                <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
            </form>
            <?php if (!empty($course['thumbnail_path'])): ?>
                <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/icon/reset" style="margin:0;">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">حذف آیکون</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $course === null ? '/admin/courses' : '/admin/courses/' . e($course['uuid']) ?>" novalidate>
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <div class="field">
            <label class="label" for="title">عنوان دوره</label>
            <input class="input<?= isset($errors['title']) ? ' has-error' : '' ?>" id="title" name="title"
                   placeholder="مثلاً آناتومی اعصاب" value="<?= e($old['title'] ?? '') ?>" required>
            <?php if (!empty($errors['title'])): ?><div class="field-error"><?= e($errors['title']) ?></div><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="description">توضیحات</label>
            <textarea class="input" id="description" name="description" rows="3"><?= e($old['description'] ?? '') ?></textarea>
        </div>

        <div class="form-grid">
            <div class="field">
                <label class="label" for="slug">نامک (اختیاری)</label>
                <input class="input<?= isset($errors['slug']) ? ' has-error' : '' ?>" id="slug" name="slug" dir="ltr"
                       value="<?= e($old['slug'] ?? '') ?>">
                <?php if (!empty($errors['slug'])): ?><div class="field-error"><?= e($errors['slug']) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="status">وضعیت</label>
                <select class="input" id="status" name="status">
                    <?php foreach (['draft' => 'پیش‌نویس', 'published' => 'منتشرشده', 'archived' => 'بایگانی'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($old['status'] ?? 'draft') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label" for="color">رنگ شاخص</label>
                <input class="input" type="color" id="color" name="color" value="<?= e($old['color'] ?? '#2563eb') ?>">
            </div>
            <div class="field">
                <label class="label" for="sort_order">ترتیب نمایش</label>
                <input class="input" type="number" id="sort_order" name="sort_order" dir="ltr"
                       value="<?= (int) ($old['sort_order'] ?? 0) ?>">
            </div>
        </div>

        <div style="display:flex; gap:10px; margin-top:8px;">
            <button class="btn btn-primary" type="submit"><?= $course === null ? 'ثبت دوره' : 'ذخیره' ?></button>
            <a class="btn btn-ghost" href="/admin/courses">انصراف</a>
        </div>
    </form>
</div>
