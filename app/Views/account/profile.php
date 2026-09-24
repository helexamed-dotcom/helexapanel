<?php if (!empty($tier)): ?>
    <div style="margin-bottom:16px;">
        <?php \HeleXa\Core\View::partial('partials.tier_card', ['tier' => $tier, 'self' => true]); ?>
    </div>
<?php endif; ?>
<?php if (!empty($levelCard)): ?>
    <div style="margin-bottom:16px;">
        <?php \HeleXa\Core\View::partial('partials.level_card', ['card' => $levelCard]); ?>
    </div>
<?php endif; ?>
<?php if (!empty($classPlan)): ?>
    <section class="card" id="classes" style="margin-bottom:16px;">
        <div class="card-head">
            <div>
                <h3 class="card-title" style="margin:0;">🗓 درس‌های اخذشده</h3>
                <div class="muted" style="font-size:12.5px;">
                    <?= $classPlan['custom']
                        ? 'فقط همین کلاس‌ها در تقویم و داشبورد شما می‌آیند. برنامه کامل همه گروه‌ها در «برنامه هفتگی» هست.'
                        : 'هنوز درسی انتخاب نکرده‌اید. از فهرست زیر، هر درسی را که دارید از همان گروه و ترمش تیک بزنید.' ?>
                </div>
            </div>
            <a class="btn btn-ghost btn-sm" href="/student/calendar?tab=classes">برنامه هفتگی همه گروه‌ها</a>
        </div>

        <?php if ($classPlan['custom'] && $classPlan['week'] !== []): ?>
            <div class="class-plan">
                <?php foreach ($classPlan['week'] as $day => $items): ?>
                    <div class="class-plan-day">
                        <b><?= e($classPlan['weekdays'][$day] ?? '') ?></b>
                        <div class="class-plan-list">
                            <?php foreach ($items as $item): ?>
                                <div class="class-plan-item">
                                    <strong><?= e($item['title']) ?></strong>
                                    <?php if (!empty($item['group_title'])): ?><span class="stat-chip chip-purple"><?= e($item['group_title']) ?></span><?php endif; ?>
                                    <span class="stat-chip chip-gray"><?= e($item['term_title'] ?? '') ?></span>
                                    <span class="muted" dir="ltr"><?= e(fa(substr((string) $item['start_time'], 0, 5))) ?>–<?= e(fa(substr((string) $item['end_time'], 0, 5))) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <details class="class-picker-box"<?= $classPlan['custom'] ? '' : ' open' ?>>
            <summary><?= $classPlan['custom'] ? '✏️ ویرایش درس‌های اخذشده' : '✅ انتخاب درس‌های اخذشده' ?></summary>
            <?php \HeleXa\Core\View::partial('partials.class_picker', [
                'choices'  => $classPlan['choices'],
                'weekdays' => $classPlan['weekdays'],
                'custom'   => $classPlan['custom'],
                'action'   => '/account/profile',
                'hidden'   => ['section' => 'classes'],
                'token'    => $csrf_token,
            ]); ?>
        </details>
    </section>
<?php endif; ?><div class="grid grid-2">
    <div class="card">
        <h3 class="card-title">اطلاعات شخصی</h3>

        <div class="profile-head">
            <div class="avatar avatar-lg">
                <?php \HeleXa\Core\View::partial('partials.avatar', ['person' => $profile]); ?>
            </div>
            <div>
                <form method="post" action="/account/avatar" enctype="multipart/form-data" class="filters" style="margin:0;">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input class="input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required style="max-width:220px;">
                    <button class="btn btn-primary btn-sm" type="submit">آپلود</button>
                </form>
                <?php if (!empty($profile['avatar_path'])): ?>
                    <form method="post" action="/account/avatar/delete" style="margin-top:6px;">
                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                        <button class="btn btn-ghost btn-sm" type="submit">حذف تصویر</button>
                    </form>
                <?php endif; ?>
                <div style="color:var(--ink-3); font-size:11.5px; margin-top:6px;">
                    JPG، PNG یا WEBP تا ۲ مگابایت. تصویر خارج از پوشه عمومی ذخیره می‌شود.
                </div>
            </div>
        </div>

        <form method="post" action="/account/profile" novalidate style="margin-top:18px;">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <div class="field">
                <label class="label" for="full_name">نام کامل</label>
                <input class="input<?= isset($errors['full_name']) ? ' has-error' : '' ?>" id="full_name" name="full_name"
                       value="<?= e($profile['full_name']) ?>" required>
                <?php if (!empty($errors['full_name'])): ?><div class="field-error"><?= e($errors['full_name']) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="mobile">شماره موبایل</label>
                <input class="input<?= isset($errors['mobile']) ? ' has-error' : '' ?>" id="mobile" name="mobile" dir="ltr"
                       value="<?= e($profile['mobile'] ?? '') ?>">
                <?php if (!empty($errors['mobile'])): ?><div class="field-error"><?= e($errors['mobile']) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="gender">جنسیت</label>
                <select class="input" id="gender" name="gender">
                    <option value="">ترجیح می‌دهم نگویم</option>
                    <option value="male"   <?= ($profile['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>مرد</option>
                    <option value="female" <?= ($profile['gender'] ?? '') === 'female' ? 'selected' : '' ?>>زن</option>
                </select>
                <div style="color:var(--ink-3); font-size:11.5px; margin-top:4px;">
                    فقط برای انتخاب تصویر پیش‌فرض پروفایل استفاده می‌شود.
                </div>
            </div>

            <div class="field">
                <label class="label" for="email">ایمیل (اختیاری)</label>
                <input class="input<?= isset($errors['email']) ? ' has-error' : '' ?>" id="email" name="email" dir="ltr"
                       value="<?= e($profile['email'] ?? '') ?>">
                <?php if (!empty($errors['email'])): ?><div class="field-error"><?= e($errors['email']) ?></div><?php endif; ?>
            </div>
            <button class="btn btn-primary" type="submit">ذخیره</button>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title">اطلاعات تحصیلی</h3>
        <table class="data" style="min-width:auto;">
            <tr><th>نام کاربری</th><td class="mono"><?= e($profile['username']) ?></td></tr>
            <tr><th>دانشگاه</th><td><?= e($profile['university_title'] ?? '—') ?></td></tr>
            <tr><th>رشته</th><td><?= e($profile['major_title'] ?? ($profile['major'] ?? '—')) ?></td></tr>
            <tr><th>ترم‌ها</th><td><?= $termNames === [] ? '—' : e(implode('، ', $termNames)) ?></td></tr>
            <tr><th>گروه</th><td><?= e($groupName ?? '—') ?></td></tr>
            <tr><th>عضویت از</th><td><?= e(jdate($profile['created_at'])) ?></td></tr>
            <tr><th>آخرین ورود</th><td><?= e(jdate($profile['last_login_at'])) ?></td></tr>
        </table>
        <p style="color:var(--ink-3); font-size:12px; margin-top:10px;">
            نام کاربری، رشته، ترم و گروه توسط مدیر تعیین می‌شوند و از این صفحه قابل تغییر نیستند.
        </p>

        <h3 class="card-title" style="margin-top:22px;">امنیت حساب</h3>

        <?php
        /**
         * An account created by a texted code has no password until its owner
         * chooses one, so the button has to say which of the two things it
         * does. Offering "change password" to someone who has none would send
         * them to a form asking for a current password they never had.
         */
        $hasPassword = is_string($profile['password_hash'] ?? null) && $profile['password_hash'] !== '';
        $verified    = !empty($profile['phone_verified_at']);
        ?>

        <table class="data" style="min-width:auto;">
            <tr>
                <th>شماره موبایل</th>
                <td class="mono" dir="ltr"><?= e(fa((string) ($profile['mobile'] ?? ''))) ?: '—' ?></td>
            </tr>
            <tr>
                <th>وضعیت شماره</th>
                <td>
                    <span class="stat-chip <?= $verified ? 'chip-green' : 'chip-gray' ?>" style="margin:0;">
                        <?= $verified ? 'تأیید شده' : 'تأیید نشده' ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>رمز عبور</th>
                <td><?= $hasPassword ? 'تنظیم شده است' : 'هنوز تنظیم نشده است' ?></td>
            </tr>
        </table>

        <a class="btn btn-ghost btn-sm" style="margin-top:12px;" href="/account/password">
            <?= $hasPassword ? 'تغییر رمز عبور' : 'ایجاد رمز عبور' ?>
        </a>

        <?php if (!$hasPassword): ?>
            <p style="color:var(--ink-3); font-size:12px; margin-top:10px;">
                تا وقتی رمز عبوری نساخته‌ای، فقط با کد پیامکی می‌توانی وارد شوی.
            </p>
        <?php endif; ?>

        <div style="margin-top:14px;">
            <div class="stat-label">دستگاه‌های فعال</div>
            <?php foreach ($sessions as $session): ?>
                <div class="leaf-meta" style="margin-top:6px;">
                    <?= e($session['operating_system'] ?? '—') ?> / <?= e($session['browser'] ?? '—') ?>
                    · <span class="mono"><?= e($session['ip_address']) ?></span>
                    · آخرین فعالیت <?= e(jdate($session['last_activity'])) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
