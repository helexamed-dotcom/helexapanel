<?php
$moduleLabels = [
    'students' => 'دانشجویان', 'courses' => 'دوره‌ها', 'content' => 'محتوا',
    'schedule' => 'برنامه هفتگی', 'exams' => 'امتحانات', 'calendar' => 'تقویم',
    'comms' => 'ارتباطات', 'security' => 'امنیت', 'system' => 'سیستم',
];
$isSuperAdminTarget = ($admin['role_slug'] ?? '') === 'super_admin';
?>
<div class="card" style="max-width:820px;">
    <h3 class="card-title"><?= $admin === null ? 'افزودن مدیر' : 'ویرایش مدیر' ?></h3>

    <form method="post" action="<?= $admin === null ? '/admin/admins' : '/admin/admins/' . e($admin['uuid']) ?>" novalidate>
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <div class="form-grid">
            <div class="field">
                <label class="label" for="full_name">نام کامل</label>
                <input class="input<?= isset($errors['full_name']) ? ' has-error' : '' ?>" id="full_name" name="full_name"
                       value="<?= e($old['full_name'] ?? '') ?>" required>
                <?php if (!empty($errors['full_name'])): ?><div class="field-error"><?= e($errors['full_name']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="username">نام کاربری</label>
                <input class="input<?= isset($errors['username']) ? ' has-error' : '' ?>" id="username" name="username" dir="ltr"
                       value="<?= e($old['username'] ?? '') ?>" required>
                <?php if (!empty($errors['username'])): ?><div class="field-error"><?= e($errors['username']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="mobile">شماره موبایل</label>
                <input class="input" id="mobile" name="mobile" dir="ltr" value="<?= e($old['mobile'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="label" for="status">وضعیت</label>
                <select class="input<?= isset($errors['status']) ? ' has-error' : '' ?>" id="status" name="status">
                    <?php foreach (['active' => 'فعال', 'inactive' => 'غیرفعال', 'suspended' => 'معلق'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($old['status'] ?? 'active') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['status'])): ?><div class="field-error"><?= e($errors['status']) ?></div><?php endif; ?>
            </div>
        </div>

        <?php if ($admin === null && ($currentUser['role_slug'] ?? '') === 'super_admin'): ?>
            <div class="field">
                <label class="label" for="role">نقش</label>
                <select class="input" id="role" name="role">
                    <option value="admin">مدیر (با دسترسی محدود)</option>
                    <option value="super_admin">مدیر ارشد (دسترسی کامل)</option>
                </select>
            </div>
        <?php endif; ?>

        <?php if ($isSuperAdminTarget): ?>
            <div class="alert alert-success" style="background:#f5f3ff;color:#4c1d95;border-color:#ddd6fe;">
                مدیر ارشد به‌صورت ذاتی همه دسترسی‌ها را دارد و فهرست زیر برای او اعمال نمی‌شود.
            </div>
        <?php else: ?>
            <h4 class="card-title" style="margin-top:22px;">دسترسی‌ها</h4>
            <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
                فقط دسترسی‌هایی نمایش داده می‌شوند که خودتان دارید. موارد بدون تیک، در سرور هم رد می‌شوند.
            </p>

            <?php foreach ($allPerms as $module => $permissions): ?>
                <div class="perm-group">
                    <div class="perm-group-title"><?= e($moduleLabels[$module] ?? $module) ?></div>
                    <div class="perm-list">
                        <?php foreach ($permissions as $permission): ?>
                            <?php $allowed = in_array($permission['slug'], $grantable, true); ?>
                            <label class="perm-item<?= $allowed ? '' : ' is-disabled' ?>">
                                <input type="checkbox" name="permissions[]" value="<?= e($permission['slug']) ?>"
                                       <?= in_array($permission['slug'], $granted, true) ? 'checked' : '' ?>
                                       <?= $allowed ? '' : 'disabled' ?>>
                                <span><?= e($permission['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div style="display:flex; gap:10px; margin-top:18px;">
            <button class="btn btn-primary" type="submit"><?= $admin === null ? 'ثبت مدیر' : 'ذخیره تغییرات' ?></button>
            <a class="btn btn-ghost" href="/admin/admins">انصراف</a>
        </div>
    </form>
</div>
