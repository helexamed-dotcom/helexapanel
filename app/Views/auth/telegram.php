<?php
/**
 * The page a Telegram one-time link opens: a new account (name, username,
 * password) or a new password for the account with this number.
 *
 * @var array|null  $link     null when the link is spent or expired
 * @var string      $secret   the link's secret, for the form's address
 * @var string      $phone
 * @var string      $suggest  a username suggestion (the Telegram @username, if free)
 * @var string|null $current  the account's username (reset)
 * @var array       $errors
 * @var array       $old
 * @var array       $studentTypes
 * @var string|null $botLink
 */
use HeleXa\Core\View;

$err = static function (string $key) use ($errors): void {
    if (!empty($errors[$key])) {
        echo '<div class="field-error">' . e($errors[$key]) . '</div>';
    }
};
$bad = static fn (string $key): string => isset($errors[$key]) ? ' has-error' : '';
$isNew = $link !== null && $link['purpose'] === 'register';
?>
<div class="auth-card">
    <button class="icon-btn auth-theme" type="button" data-theme-toggle aria-label="تغییر حالت روشن و شب">
        <span class="theme-icon-sun"><?php View::partial('partials.icon', ['name' => 'sun']); ?></span>
        <span class="theme-icon-moon"><?php View::partial('partials.icon', ['name' => 'moon']); ?></span>
    </button>
    <a class="auth-back" href="/login" aria-label="بازگشت"><?php View::partial('partials.icon', ['name' => 'chevron']); ?> ورود</a>

    <div class="auth-logo auth-logo-tg" aria-hidden="true"><?php View::partial('partials.icon', ['name' => 'send', 'size' => 30]); ?></div>

    <?php if ($link === null): ?>
        <h1 class="auth-title">این لینک دیگر معتبر نیست</h1>
        <p class="auth-sub">لینک‌های ربات یک‌بارمصرف‌اند و بعد از مدت کوتاهی منقضی می‌شوند. در ربات دوباره «📱 ارسال شماره من» یا «🔑 تعیین رمز تازه» را بزن.</p>
        <?php if ($botLink): ?><a class="btn btn-primary btn-block tg-btn" href="<?= e($botLink) ?>" target="_blank" rel="noopener">باز کردن ربات تلگرام</a><?php endif; ?>
    <?php else: ?>
        <h1 class="auth-title"><?= $isNew ? 'تکمیل ثبت‌نام' : 'تعیین رمز تازه' ?></h1>
        <p class="auth-sub">
            شماره <b dir="ltr"><?= e($phone) ?></b> از طریق تلگرام تأیید شد ✓<br>
            <?= $isNew ? 'یک نام کاربری و رمز عبور بساز؛ بعد با شماره یا نام کاربری و همین رمز وارد می‌شوی.' : 'رمز تازه بساز. اگر خواستی نام کاربری‌ات را هم عوض کن.' ?>
        </p>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">لطفاً موارد مشخص‌شده را اصلاح کن.</div>
        <?php endif; ?>

        <form method="post" action="/auth/telegram/<?= e($secret) ?>" autocomplete="on" novalidate>
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

            <?php if ($isNew): ?>
                <div class="field">
                    <label class="label" for="full_name">نام و نام خانوادگی</label>
                    <input class="input<?= $bad('full_name') ?>" id="full_name" name="full_name" autocomplete="name" maxlength="191"
                           value="<?= e($old['full_name'] ?? '') ?>" required>
                    <?php $err('full_name'); ?>
                </div>
                <div class="field">
                    <span class="label">جنسیت (برای تصویر پیش‌فرض پروفایل)</span>
                    <div class="seg-pick">
                        <label><input type="radio" name="gender" value="female" <?= ($old['gender'] ?? '') === 'female' ? 'checked' : '' ?>><span>خانم</span></label>
                        <label><input type="radio" name="gender" value="male" <?= ($old['gender'] ?? '') === 'male' ? 'checked' : '' ?>><span>آقا</span></label>
                    </div>
                </div>
            <?php endif; ?>

            <div class="field">
                <label class="label" for="username">نام کاربری <?= $isNew ? '' : '<small>(خالی = همان «' . e((string) $current) . '»)</small>' ?></label>
                <input class="input<?= $bad('username') ?>" id="username" name="username" dir="ltr" maxlength="64" autocomplete="username"
                       autocapitalize="off" spellcheck="false" pattern="[a-zA-Z0-9._\-]{3,64}"
                       value="<?= e($old['username'] ?? ($isNew ? $suggest : '')) ?>" placeholder="<?= $isNew ? 'sara.med' : e((string) $current) ?>" <?= $isNew ? 'required' : '' ?>>
                <span class="auth-hint">حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط — دست‌کم ۳ حرف.</span>
                <?php $err('username'); ?>
            </div>

            <div class="field">
                <label class="label" for="password">رمز عبور</label>
                <input class="input<?= $bad('password') ?>" type="password" id="password" name="password" autocomplete="new-password" required>
                <span class="auth-hint">دست‌کم ۸ نویسه، فقط حروف انگلیسی و عدد، و از هر کدام دست‌کم یکی.</span>
                <?php $err('password'); ?>
            </div>
            <div class="field">
                <label class="label" for="password_confirm">تکرار رمز عبور</label>
                <input class="input<?= $bad('password_confirm') ?>" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
                <?php $err('password_confirm'); ?>
            </div>

            <?php if ($isNew && !empty($studentTypes)): ?>
                <div class="field">
                    <label class="label" for="student_type_id">چه نوع دانشجویی هستی؟</label>
                    <select class="input" id="student_type_id" name="student_type_id">
                        <option value="">بعداً انتخاب می‌کنم</option>
                        <?php foreach ($studentTypes as $t): ?>
                            <option value="<?= (int) $t['id'] ?>"><?= e($t['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <button class="btn btn-primary btn-block" type="submit" data-lock-on-submit><?= $isNew ? 'ساختن حساب و ورود' : 'ثبت رمز و ورود' ?></button>
        </form>
    <?php endif; ?>
</div>
