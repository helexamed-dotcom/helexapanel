<?php
/**
 * Resetting a forgotten password.
 *
 * Three steps in one card — number, code, new password — because a reset that
 * navigates between pages loses the countdown and gives the student three
 * chances to abandon it. Each step replaces the last; only one is ever on
 * screen.
 */
$otpEnabled = !empty($otpEnabled);
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

    <h1 class="auth-title">بازیابی رمز عبور</h1>
    <p class="auth-sub">شماره موبایل حسابت را وارد کن تا کد بازیابی برایت پیامک شود.</p>

    <?php if (!$otpEnabled): ?>
        <div class="alert alert-error">
            بازیابی رمز با پیامک در حال حاضر در دسترس نیست. لطفاً با پشتیبانی تماس بگیر.
        </div>
    <?php else: ?>
        <form class="otp-form" data-otp data-mode="reset"
              data-request-url="/auth/forgot-password"
              data-verify-url="/auth/verify-reset"
              data-reset-url="/auth/reset-password"
              data-resend="<?= (int) ($otpResend ?? 60) ?>"
              data-ttl="<?= (int) ($otpTtl ?? 120) ?>"
              data-min-length="<?= (int) ($minLength ?? 10) ?>"
              autocomplete="off" novalidate>

            <div class="otp-alert" data-otp-message hidden role="status" aria-live="polite"></div>

            <div class="field" data-otp-step="phone">
                <label class="label" for="reset-phone">شماره موبایل</label>
                <input class="input" type="tel" id="reset-phone" name="phone" dir="ltr"
                       inputmode="tel" placeholder="09123456789"
                       autocomplete="tel" maxlength="20" required>
            </div>

            <div class="field" data-otp-step="code" hidden>
                <label class="label" for="reset-code" data-otp-code-label>کد پیامک‌شده</label>
                <?php /* maxlength is a starting point; the script resizes it to the
                         length the server reports once a code has actually been sent. */ ?>
                <input class="input otp-code-input" type="text" id="reset-code" name="code" dir="ltr"
                       inputmode="numeric" pattern="[0-9]*" maxlength="6"
                       autocomplete="one-time-code" placeholder="------">
                <div class="otp-meta">
                    <span class="otp-target" data-otp-target></span>
                    <button class="link-btn" type="button" data-otp-change>تغییر شماره</button>
                </div>
            </div>

            <div data-otp-step="password" hidden>
                <div class="field">
                    <label class="label" for="reset-password">رمز عبور جدید</label>
                    <input class="input" type="password" id="reset-password" name="password"
                           autocomplete="new-password" minlength="<?= (int) ($minLength ?? 10) ?>">
                </div>
                <div class="field">
                    <label class="label" for="reset-password-confirm">تکرار رمز عبور جدید</label>
                    <input class="input" type="password" id="reset-password-confirm"
                           name="password_confirmation" autocomplete="new-password">
                </div>
                <p class="auth-hint">
                    رمز عبور باید حداقل <?= e(fa((string) ($minLength ?? 10))) ?> کاراکتر و
                    فقط شامل حروف انگلیسی و عدد باشد و هر دو را داشته باشد.
                </p>
            </div>

            <button class="btn btn-primary btn-block" type="submit" data-otp-submit>ارسال کد بازیابی</button>

            <div class="otp-resend" data-otp-resend hidden>
                <span data-otp-countdown></span>
                <button class="link-btn" type="button" data-otp-resend-btn hidden>ارسال مجدد کد</button>
            </div>
        </form>

        <noscript>
            <div class="alert alert-error" style="margin-top:14px;">
                بازیابی رمز به جاوااسکریپت نیاز دارد.
            </div>
        </noscript>
    <?php endif; ?>

    <p class="auth-alt"><a href="/login">بازگشت به صفحه ورود</a></p>
</div>
