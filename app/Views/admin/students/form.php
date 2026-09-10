<div class="card" style="max-width:760px;">
    <h3 class="card-title"><?= $student === null ? 'افزودن دانشجو' : 'ویرایش دانشجو' ?></h3>

    <?php if ($student === null): ?>
        <div class="alert alert-success" style="background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe;">
            رمز عبور توسط سرور ساخته می‌شود و پس از ثبت یک بار نمایش داده می‌شود.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $student === null ? '/admin/students' : '/admin/students/' . e($student['uuid']) ?>" novalidate>
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
                <input class="input<?= isset($errors['mobile']) ? ' has-error' : '' ?>" id="mobile" name="mobile" dir="ltr"
                       placeholder="09121234567" value="<?= e($old['mobile'] ?? '') ?>">
                <?php if (!empty($errors['mobile'])): ?><div class="field-error"><?= e($errors['mobile']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label class="label" for="email">ایمیل (اختیاری)</label>
                <input class="input" id="email" name="email" dir="ltr" value="<?= e($old['email'] ?? '') ?>">
            </div>

            <div class="field">
                <label class="label" for="gender">جنسیت</label>
                <select class="input" id="gender" name="gender">
                    <option value="">نامشخص</option>
                    <option value="male"   <?= ($old['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>مرد</option>
                    <option value="female" <?= ($old['gender'] ?? '') === 'female' ? 'selected' : '' ?>>زن</option>
                </select>
            </div>

            <div class="field">
                <label class="label" for="university_id">دانشگاه</label>
                <select class="input" id="university_id" name="university_id" data-chain="university">
                    <option value="">انتخاب نشده</option>
                    <?php foreach ($universities as $university): ?>
                        <option value="<?= (int) $university['id'] ?>"
                            <?= (int) ($old['university_id'] ?? 0) === (int) $university['id'] ? 'selected' : '' ?>>
                            <?= e($university['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="major_id">رشته</label>
                <select class="input" id="major_id" name="major_id" data-chain="major">
                    <option value="">انتخاب نشده</option>
                    <?php foreach ($majors as $major): ?>
                        <option value="<?= (int) $major['id'] ?>" data-university="<?= (int) $major['university_id'] ?>"
                            <?= (int) ($old['major_id'] ?? 0) === (int) $major['id'] ? 'selected' : '' ?>>
                            <?= e($major['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="group_id">گروه</label>
                <select class="input" id="group_id" name="group_id" data-chain="group">
                    <option value="">انتخاب نشده</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>" data-term="<?= (int) $group['term_id'] ?>"
                            <?= (int) ($old['group_id'] ?? 0) === (int) $group['id'] ? 'selected' : '' ?>>
                            <?= e($group['term_title']) ?> — <?= e($group['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="label" for="status">وضعیت</label>
                <select class="input" id="status" name="status">
                    <?php foreach (['active' => 'فعال', 'inactive' => 'غیرفعال', 'suspended' => 'معلق'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= ($old['status'] ?? 'active') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label class="label">ترم‌ها</label>
            <p style="color:var(--ink-3); font-size:11.5px; margin:-2px 0 8px;">
                دانشجو می‌تواند هم‌زمان از چند ترم واحد داشته باشد. برنامه هفتگی و امتحانات
                همه ترم‌های انتخاب‌شده نمایش داده می‌شود. اولین ترم انتخاب‌شده، ترم اصلی حساب است.
            </p>
            <div class="perm-list" id="term-choices">
                <?php foreach ($terms as $term): ?>
                    <label class="perm-item" data-major="<?= $term['major_id'] === null ? '' : (int) $term['major_id'] ?>">
                        <input type="checkbox" name="terms[]" value="<?= (int) $term['id'] ?>"
                            <?= in_array((int) $term['id'], array_map('intval', $selectedTerms ?? []), true) ? 'checked' : '' ?>>
                        <span>
                            <?= e($term['title']) ?>
                            <?php if ($term['major_title']): ?>
                                <span class="leaf-meta"><?= e($term['major_title']) ?></span>
                            <?php else: ?>
                                <span class="leaf-meta">عمومی</span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if ($terms === []): ?>
                <div class="empty">ابتدا از «ترم‌ها و گروه‌ها» ترم تعریف کنید.</div>
            <?php endif; ?>
        </div>

        <div style="display:flex; gap:10px; margin-top:8px;">
            <button class="btn btn-primary" type="submit"><?= $student === null ? 'ثبت دانشجو' : 'ذخیره تغییرات' ?></button>
            <a class="btn btn-ghost" href="/admin/students">انصراف</a>
        </div>
    </form>
</div>
