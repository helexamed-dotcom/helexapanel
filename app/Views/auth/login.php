<?php
/**
 * Sign-in: a password, or a code by SMS.
 *
 * Both panels are rendered server-side and one is hidden, rather than one
 * being built by script on demand. With JavaScript off the password form is
 * still a plain form that posts and works; only the SMS panel, which is
 * inherently a two-step conversation with the server, needs script — and it
 * says so rather than silently doing nothing.
 */
$otpEnabled       = !empty($otpEnabled);
$registrationOpen = !empty($registrationOpen);
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

    <h1 class="auth-title"><?= $registrationOpen ? 'ورود یا ثبت‌نام' : 'ورود به ' . e($appName) ?></h1>
    <p class="auth-sub">
        <?= $registrationOpen
            ? 'با شماره موبایل خود وارد شو. اگر حساب نداشته باشی، همین‌جا ساخته می‌شود.'
            : 'برای دسترسی به منابع آموزشی وارد حساب خود شوید.' ?>
    </p>

    <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
        <div class="alert alert-error"><?= e($flashError) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['identifier'])): ?>
        <div class="alert alert-error"><?= e($errors['identifier']) ?></div>
    <?php endif; ?>

    <?php if ($otpEnabled): ?>
        <div class="auth-tabs" role="tablist">
            <button class="auth-tab is-active" type="button" role="tab" aria-selected="true"
                    data-auth-tab="password" id="tab-password" aria-controls="panel-password">
                ورود با رمز عبور
            </button>
            <button class="auth-tab" type="button" role="tab" aria-selected="false"
                    data-auth-tab="otp" id="tab-otp" aria-controls="panel-otp">
                ورود با کد پیامکی
            </button>
        </div>
    <?php endif; ?>

    <?php /* ------------------------------------------------ password ---- */ ?>
    <div class="auth-panel is-active" data-auth-panel="password" id="panel-password"
         role="tabpanel" aria-labelledby="tab-password">
        <form method="post" action="/login" autocomplete="off" novalidate>
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

            <div class="field">
                <label class="label" for="identifier">شماره موبایل یا نام کاربری</label>
                <input class="input<?= isset($errors['identifier']) ? ' has-error' : '' ?>"
                       type="text" id="identifier" name="identifier" dir="ltr"
                       inputmode="tel" placeholder="09123456789"
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

        <p class="auth-alt">
            <a href="/forgot-password">رمز عبورم را فراموش کرده‌ام</a>
        </p>
    </div>

    <?php /* ----------------------------------------------------- OTP ---- */ ?>
    <?php if ($otpEnabled): ?>
        <div class="auth-panel" data-auth-panel="otp" id="panel-otp" hidden
             role="tabpanel" aria-labelledby="tab-otp">

            <?php
            /**
             * data-otp carries the endpoints and the two timings the script
             * counts down. They come from the admin's settings, so hard-coding
             * 60 and 120 in the script would quietly ignore a change made in
             * the panel.
             */
            ?>
            <form class="otp-form" data-otp
                  data-request-url="/auth/request-otp"
                  data-verify-url="/auth/verify-otp"
                  data-resend="<?= (int) ($otpResend ?? 60) ?>"
                  data-ttl="<?= (int) ($otpTtl ?? 120) ?>"
                  autocomplete="off" novalidate>

                <div class="otp-alert" data-otp-message hidden role="status" aria-live="polite"></div>

                <div class="field" data-otp-step="phone">
                    <label class="label" for="otp-phone">شماره موبایل</label>
                    <input class="input" type="tel" id="otp-phone" name="phone" dir="ltr"
                           inputmode="tel" placeholder="09123456789"
                           autocomplete="tel" maxlength="20" required>
                </div>

                <div class="field" data-otp-step="code" hidden>
                    <label class="label" for="otp-code" data-otp-code-label>کد پیامک‌شده</label>
                    <?php /* maxlength is a starting point; the script resizes it to the
                             length the server reports once a code has actually been sent. */ ?>
                    <input class="input otp-code-input" type="text" id="otp-code" name="code" dir="ltr"
                           inputmode="numeric" pattern="[0-9]*" maxlength="6"
                           autocomplete="one-time-code" placeholder="------">
                    <div class="otp-meta">
                        <span class="otp-target" data-otp-target></span>
                        <button class="link-btn" type="button" data-otp-change>تغییر شماره</button>
                    </div>
                </div>

                <button class="btn btn-primary btn-block" type="submit" data-otp-submit>دریافت کد</button>

                <div class="otp-resend" data-otp-resend hidden>
                    <span data-otp-countdown></span>
                    <button class="link-btn" type="button" data-otp-resend-btn hidden>ارسال مجدد کد</button>
                </div>
            </form>

            <noscript>
                <div class="alert alert-error" style="margin-top:14px;">
                    ورود با کد پیامکی به جاوااسکریپت نیاز دارد. لطفاً از «ورود با رمز عبور» استفاده کن.
                </div>
            </noscript>
        </div>
    <?php endif; ?>

    <p class="auth-sub auth-foot">
        هر حساب تنها روی یک دستگاه فعال می‌ماند.<br>
        با «مرا به خاطر بسپار» تا ۳۰ روز روی همین دستگاه وارد می‌مانید.
    </p>
</div>
