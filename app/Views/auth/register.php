<?php
/**
 * A student opening their own account.
 *
 * The university → major → terms → group cascade reuses the one app.js
 * already runs on the admin's student form (data-chain / #term-choices), so
 * the same choices are offered here as there.
 *
 * @var array $errors
 * @var array $old
 * @var array $universities
 * @var array $majors
 * @var array $terms
 * @var array $groups
 */
$err = static function (string $key) use ($errors): void {
    if (!empty($errors[$key])) {
        echo '<div class="field-error">' . e($errors[$key]) . '</div>';
    }
};
$bad = static fn (string $key): string => isset($errors[$key]) ? ' has-error' : '';
$selectedTerms = array_map('intval', $old['terms'] ?? []);
?>
<div class="auth-card auth-card-wide">
    <button class="icon-btn auth-theme" type="button" data-theme-toggle aria-label="تغییر حالت روشن و شب">
        <span class="theme-icon-sun"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'sun']); ?></span>
        <span class="theme-icon-moon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'moon']); ?></span>
    </button>

    <?php $siteLogo = \HeleXa\Services\Settings::get('site_logo_path', ''); ?>
    <?php if ($siteLogo !== ''): ?>
        <div class="auth-logo auth-logo-image"><img src="/assets/<?= e($siteLogo) ?>" alt="<?= e($appName ?? 'HeleXa Med') ?>"></div>
    <?php else: ?>
        <div class="auth-logo">H</div>
    <?php endif; ?>

    <h1 class="auth-title">ثبت‌نام در <?= e($appName) ?></h1>
    <p class="auth-sub">مشخصاتت را وارد کن، رمز بساز و مستقیم وارد پنل شو.</p>

    <?php if ($errors !== []): ?>
        <div class="alert alert-error">لطفاً موارد مشخص‌شده را اصلاح کنید.</div>
    <?php endif; ?>

    <form method="post" action="/register" autocomplete="on" novalidate>
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <?php /* Left empty by people; filled in by bots. */ ?>
        <div class="auth-hp" aria-hidden="true">
            <label>وب‌سایت <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div class="field">
            <label class="label" for="full_name">نام و نام خانوادگی</label>
            <input class="input<?= $bad('full_name') ?>" id="full_name" name="full_name" autocomplete="name"
                   value="<?= e($old['full_name'] ?? '') ?>" required>
            <?php $err('full_name'); ?>
        </div>

        <p class="leaf-meta" style="margin:0 0 8px;">شماره موبایل یا نام کاربری — یکی کافی است. با همان وارد می‌شوید.</p>
        <div class="form-grid">
            <div class="field">
                <label class="label" for="mobile">شماره موبایل</label>
                <input class="input<?= $bad('mobile') ?>" id="mobile" name="mobile" dir="ltr" type="tel"
                       inputmode="numeric" autocomplete="tel" placeholder="09123456789"
                       value="<?= e($old['mobile'] ?? '') ?>">
                <?php $err('mobile'); ?>
            </div>
            <div class="field">
                <label class="label" for="username">نام کاربری</label>
                <input class="input<?= $bad('username') ?>" id="username" name="username" dir="ltr"
                       autocapitalize="off" spellcheck="false" autocomplete="username" placeholder="مثلاً ali.rezaei"
                       value="<?= e(($old['username'] ?? '') === ($old['mobile'] ?? null) ? '' : ($old['username'] ?? '')) ?>">
                <?php $err('username'); ?>
            </div>
            <div class="field">
                <label class="label" for="gender">جنسیت</label>
                <select class="input" id="gender" name="gender">
                    <option value="">انتخاب کنید</option>
                    <option value="male"   <?= ($old['gender'] ?? '') === 'male'   ? 'selected' : '' ?>>مرد</option>
                    <option value="female" <?= ($old['gender'] ?? '') === 'female' ? 'selected' : '' ?>>زن</option>
                </select>
            </div>
        </div>

        <?php if ($universities !== []): ?>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="university_id">دانشگاه</label>
                    <select class="input<?= $bad('university_id') ?>" id="university_id" name="university_id" data-chain="university">
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($universities as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (int) ($old['university_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>>
                                <?= e($u['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php $err('university_id'); ?>
                </div>
                <div class="field">
                    <label class="label" for="major_id">رشته</label>
                    <select class="input<?= $bad('major_id') ?>" id="major_id" name="major_id" data-chain="major">
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($majors as $m): ?>
                            <option value="<?= (int) $m['id'] ?>" data-university="<?= (int) $m['university_id'] ?>"
                                <?= (int) ($old['major_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>>
                                <?= e($m['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php $err('major_id'); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($terms !== []): ?>
            <div class="field">
                <label class="label">ترم (اگر از چند ترم واحد داری، همه را انتخاب کن)</label>
                <div class="perm-list" id="term-choices">
                    <?php foreach ($terms as $term): ?>
                        <label class="perm-item" data-major="<?= $term['major_id'] === null ? '' : (int) $term['major_id'] ?>">
                            <input type="checkbox" name="terms[]" value="<?= (int) $term['id'] ?>"
                                <?= in_array((int) $term['id'], $selectedTerms, true) ? 'checked' : '' ?>>
                            <span><?= e($term['title']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php $err('terms'); ?>
            </div>

            <?php if ($groups !== []): ?>
                <div class="field">
                    <label class="label" for="group_id">گروه</label>
                    <select class="input" id="group_id" name="group_id" data-chain="group">
                        <option value="">انتخاب کنید</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int) $g['id'] ?>" data-term="<?= (int) $g['term_id'] ?>"
                                <?= (int) ($old['group_id'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>>
                                <?= e($g['term_title']) ?> — <?= e($g['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="form-grid">
            <div class="field">
                <label class="label" for="password">رمز عبور</label>
                <input class="input<?= $bad('password') ?>" type="password" id="password" name="password"
                       dir="ltr" autocomplete="new-password" required>
                <?php $err('password'); ?>
            </div>
            <div class="field">
                <label class="label" for="password_confirm">تکرار رمز عبور</label>
                <input class="input<?= $bad('password_confirm') ?>" type="password" id="password_confirm"
                       name="password_confirm" dir="ltr" autocomplete="new-password" required>
                <?php $err('password_confirm'); ?>
            </div>
        </div>
        <p class="auth-sub" style="margin:-6px 0 16px; font-size:12px;">
            رمز دست‌کم ۸ کاراکتر، ترکیبی از حروف انگلیسی و عدد.
        </p>

        <div class="field">
            <label class="label" for="activation_code">کد فعال‌سازی (اگر داری)</label>
            <input class="input" id="activation_code" name="activation_code" dir="ltr" autocapitalize="characters"
                   spellcheck="false" placeholder="HLX-XXXX-XXXX" value="<?= e($old['activation_code'] ?? '') ?>">
        </div>

        <button class="btn btn-primary btn-block" type="submit" data-lock-on-submit>ثبت‌نام و ورود</button>
    </form>

    <p class="auth-sub auth-foot">
        حساب داری؟ <a href="/login">وارد شو</a>
    </p>
</div>
