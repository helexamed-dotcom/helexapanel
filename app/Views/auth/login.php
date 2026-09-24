<?php
/**
 * Sign-in with a username or mobile number and a password.
 *
 * A plain form that posts and works with JavaScript off. The texted-code tab
 * and the "forgot my password" link were removed with the SMS gateway; a
 * forgotten password is now reset by an admin, which is what the footer note
 * tells the student to do instead of leaving them at a dead end.
 */
?>
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

    <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
        <div class="alert alert-error"><?= e($flashError) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['identifier'])): ?>
        <div class="alert alert-error"><?= e($errors['identifier']) ?></div>
    <?php endif; ?>

    <div class="auth-panel is-active">
        <form method="post" action="/login" autocomplete="off" novalidate>
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

            <div class="field">
                <label class="label" for="identifier">شماره موبایل یا نام کاربری</label>
                <?php
                /**
                 * No inputmode here on purpose. This field takes a mobile
                 * number *or* a username, and inputmode="tel" gave phones a
                 * digits-only keypad — a student whose username has letters
                 * could only paste it in.
                 */
                ?>
                <input class="input<?= isset($errors['identifier']) ? ' has-error' : '' ?>"
                       type="text" id="identifier" name="identifier" dir="ltr"
                       placeholder="09123456789 یا نام کاربری"
                       enterkeyhint="next" autocapitalize="off" spellcheck="false"
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
        <?php if (\HeleXa\Services\Telegram\Bot::enabled() && \HeleXa\Services\Telegram\Bot::username() !== ''): ?>
            <div class="auth-or"><span>یا</span></div>
            <a class="btn btn-block tg-btn" href="<?= e(\HeleXa\Services\Telegram\Bot::startLink('login')) ?>" target="_blank" rel="noopener">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'send']); ?>
                <?= \HeleXa\Services\Telegram\TelegramAuth::registrationOpen() ? 'ثبت‌نام یا ورود با تلگرام' : 'رمز را فراموش کرده‌ای؟ از ربات تلگرام' ?>
            </a>
            <p class="auth-tg-note">ربات را استارت کن و با دکمه «📱 ارسال شماره من» شماره‌ات را بفرست؛ لینکی می‌گیری که با آن نام کاربری و رمز می‌سازی.
                رمزت را فراموش کرده‌ای؟ از همان ربات «🔑 تعیین رمز تازه» را بزن.</p>
        <?php endif; ?>
        <?php if (\HeleXa\Services\Settings::bool('registration_enabled', false)): ?>
            <a class="auth-register-link" href="/register">حساب نداری؟ ثبت‌نام کن</a>
        <?php endif; ?>
    </div>

    <p class="auth-sub auth-foot">
        <?php if (!\HeleXa\Services\Telegram\Bot::enabled()): ?>
        رمز عبورت را فراموش کرده‌ای؟ با پشتیبانی تماس بگیر تا رمز تازه برایت صادر شود.<br>
        <?php endif; ?>
        هر حساب تنها روی یک دستگاه فعال می‌ماند.<br>
        با «مرا به خاطر بسپار» تا ۳۰ روز روی همین دستگاه وارد می‌مانید.
    </p>
</div>
