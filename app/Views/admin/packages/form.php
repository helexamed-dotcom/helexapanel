<div class="card" style="max-width:680px;">
    <h3 class="card-title">افزودن پکیج</h3>

    <form method="post" action="/admin/packages" novalidate>
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <div class="field">
            <label class="label" for="title">عنوان پکیج</label>
            <input class="input<?= isset($errors['title']) ? ' has-error' : '' ?>" id="title" name="title"
                   placeholder="مثلاً پکیج ترم ۴ پزشکی" value="<?= e($old['title'] ?? '') ?>" required>
            <?php if (!empty($errors['title'])): ?><div class="field-error"><?= e($errors['title']) ?></div><?php endif; ?>
        </div>

        <div class="field">
            <label class="label" for="description">توضیحات</label>
            <textarea class="input" id="description" name="description" rows="3"><?= e($old['description'] ?? '') ?></textarea>
        </div>

        <div class="form-grid">
            <div class="field">
                <label class="label" for="status">وضعیت</label>
                <select class="input" id="status" name="status">
                    <?php foreach (['published' => 'فعال', 'draft' => 'پیش‌نویس', 'archived' => 'بایگانی'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($old['status'] ?? 'published') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label" for="color">رنگ شاخص</label>
                <input class="input" type="color" id="color" name="color" value="<?= e($old['color'] ?? '#7c6cf3') ?>">
            </div>
            <div class="field">
                <label class="label" for="sort_order">ترتیب نمایش</label>
                <input class="input" type="number" id="sort_order" name="sort_order" dir="ltr"
                       value="<?= (int) ($old['sort_order'] ?? 0) ?>">
            </div>
        </div>

        <label class="switch-row">
            <input type="checkbox" name="auto_grant_new_courses" value="1"
                <?= (int) ($old['auto_grant_new_courses'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span>
                دوره‌هایی که بعداً به این پکیج اضافه می‌شوند، خودکار برای دانشجویان فعلی هم فعال شوند
                <span style="display:block; color:var(--ink-3); font-size:11.5px;">
                    پیش‌فرض خاموش است. باز کردن دسترسی برای کسانی که قبلاً پکیج را گرفته‌اند
                    بهتر است یک تصمیم باشد، نه یک اتفاق جانبی.
                </span>
            </span>
        </label>

        <label class="switch-row">
            <input type="checkbox" name="is_full_access" value="1"
                <?= (int) ($old['is_full_access'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span>
                پکیج کامل (فول آپشن): همه بخش‌های سایت و همه محتوا — دوره‌ها، بانک سوال، جزیره بالین، فلش‌کارت، درسنامه‌ها، نقشه‌های ذهنی و بازی با شکل
                <span style="display:block; color:var(--ink-3); font-size:11.5px;">
                    محتوایی که بعداً اضافه شود هم خودکار برای دارندگان این پکیج باز می‌شود.
                    هنگام فعال‌سازی می‌توانید تاریخ پایان بگذارید.
                </span>
            </span>
        </label>

        <label class="switch-row">
            <input type="checkbox" name="is_free" value="1"
                <?= (int) ($old['is_free'] ?? 0) === 1 ? 'checked' : '' ?>>
            <span>
                پکیج رایگان: برای همه دانشجویان
                <span style="display:block; color:var(--ink-3); font-size:11.5px;">
                    هر کسی ثبت‌نام کند (خودش یا توسط مدیر) این پکیج خودکار برایش فعال می‌شود.
                    بعد از انتخاب محتوا، در صفحه پکیج دکمه «فعال برای همه دانشجویان فعلی» را بزنید.
                </span>
            </span>
        </label>

        <div style="display:flex; gap:10px; margin-top:14px;">
            <button class="btn btn-primary" type="submit">ثبت پکیج</button>
            <a class="btn btn-ghost" href="/admin/packages">انصراف</a>
        </div>
    </form>
</div>
