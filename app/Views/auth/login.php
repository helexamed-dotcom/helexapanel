<div class="auth-card">
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
    <h1 class="auth-title">ورود به <?= e($appName) ?></h1>
    <p class="auth-sub">برای دسترسی به منابع آموزشی وارد حساب خود شوید.</p>

    <?php if (!empty($flashError)): ?>
        <div class="alert alert-error"><?= e($flashError) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors['identifier'])): ?>
        <div class="alert alert-error"><?= e($errors['identifier']) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" autocomplete="off" novalidate>
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <div class="field">
            <label class="label" for="identifier">نام کاربری یا شماره موبایل</label>
            <input class="input<?= isset($errors['identifier']) ? ' has-error' : '' ?>"
                   type="text" id="identifier" name="identifier" dir="ltr"
                   value="<?= e($old['identifier'] ?? '') ?>"
                   autocomplete="username" required>
        </div>

        <div class="field">
            <label class="label" for="password">رمز عبور</label>
            <input class="input<?= isset($errors['password']) ? ' has-error' : '' ?>"
                   type="password" id="password" name="password"
                   autocomplete="current-password" required>
            <?php if (!empty($errors['password'])): ?>
                <div class="field-error"><?= e($errors['password']) ?></div>
            <?php endif; ?>
        </div>

        <label class="remember-row">
            <input type="checkbox" name="remember" value="1" <?= ($old['remember'] ?? true) ? 'checked' : '' ?>>
            <span>مرا به خاطر بسپار</span>
        </label>

        <button class="btn btn-primary btn-block" type="submit">ورود</button>
    </form>

    <p class="auth-sub" style="margin:20px 0 0; text-align:center; font-size:12.5px;">
        هر حساب تنها روی یک دستگاه فعال می‌ماند.<br>
        با «مرا به خاطر بسپار» تا ۳۰ روز روی همین دستگاه وارد می‌مانید.
    </p>
</div>
