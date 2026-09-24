<?php
/**
 * @var array    $sessions  newest first
 * @var array    $flags     this student's recent multi-network days
 * @var int|null $currentId the session making this request
 */
$reasons = [
    'user_logout'      => 'خروج توسط خودتان',
    'idle_timeout'     => 'پایان به‌خاطر بی‌فعالیتی',
    'absolute_timeout' => 'پایان زمان مجاز نشست',
    'admin_force'      => 'بسته‌شده توسط مدیر',
    'new_device'       => 'ورود از دستگاه دیگر',
    'password_change'  => 'تغییر رمز عبور',
    'security'         => 'بسته‌شده به دلایل امنیتی',
];
$devices = ['desktop' => '🖥 رایانه', 'tablet' => '📱 تبلت', 'mobile' => '📱 موبایل', 'bot' => '🤖', 'unknown' => '❔'];
$flagDays = [];
foreach ($flags as $flag) {
    $flagDays[(string) $flag['flag_day']] = true;
}
?>
<?php if ($flags !== []): ?>
    <div class="alert alert-error" role="alert" style="margin-bottom:16px; line-height:2;">
        <?php /* One wrapper: .alert is a flex row, and loose text nodes would
                 each become a column. */ ?>
        <div>
        <strong>⚠ ورود از چند شبکه مختلف در یک روز</strong><br>
        در روز
        <?php foreach ($flags as $i => $flag): ?>
            <?= $i > 0 ? '، ' : '' ?><?= e(jdate($flag['flag_day'] . ' 00:00:00')) ?>
            (<?= e(fa((string) $flag['networks'])) ?> شبکه)
        <?php endforeach; ?>
        به حساب شما از چند مکان مختلف وارد شده‌اند. حساب کاربری شخصی است و استفاده‌ی مشترک از آن
        ممکن است به تعلیق حساب منجر شود.
        اگر این ورودها کار شما نبوده، همین حالا <a href="/account/password">رمز عبورتان را عوض کنید</a>
        و به <a href="/student/support">پشتیبانی</a> خبر دهید.
        <?php if (array_filter($flags, static fn ($f) => $f['status'] === 'warned')): ?>
            <br><strong>مدیر سایت این مورد را بررسی کرده و به شما اخطار داده است.</strong>
        <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">نشست‌های من</h3>
        <span class="leaf-meta">آخرین <?= e(fa((string) count($sessions))) ?> ورود</span>
    </div>
    <p class="leaf-meta" style="margin:-4px 0 14px;">
        هر بار که وارد حساب می‌شوید اینجا ثبت می‌شود. ردیف‌های قرمز مربوط به روزهایی‌اند که از چند شبکه وارد شده‌اید.
    </p>

    <?php if ($sessions === []): ?>
        <div class="empty">هنوز نشستی ثبت نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>ورود</th><th>خروج</th><th>آدرس IP</th><th>دستگاه</th><th>وضعیت</th></tr></thead>
                <tbody>
                <?php foreach ($sessions as $s):
                    $day     = substr((string) $s['login_at'], 0, 10);
                    $isNow   = $currentId !== null && (int) $s['id'] === (int) $currentId;
                ?>
                    <tr<?= isset($flagDays[$day]) ? ' class="is-flagged"' : '' ?>>
                        <td><?= e(jdate($s['login_at'])) ?></td>
                        <td><?= (int) $s['is_active'] === 1 ? '—' : e(jdate($s['logout_at'] ?? $s['last_activity'])) ?></td>
                        <td><span class="mono" dir="ltr"><?= e((string) $s['ip_address']) ?></span></td>
                        <td>
                            <?= e($devices[$s['device_type']] ?? '') ?>
                            <div class="leaf-meta"><?= e(implode(' · ', array_filter([(string) ($s['browser'] ?? ''), (string) ($s['operating_system'] ?? '')]))) ?></div>
                        </td>
                        <td>
                            <?php if ($isNow): ?>
                                <span class="stat-chip chip-green">همین دستگاه</span>
                            <?php elseif ((int) $s['is_active'] === 1): ?>
                                <span class="stat-chip chip-teal">فعال</span>
                            <?php else: ?>
                                <span class="leaf-meta"><?= e($reasons[$s['termination_reason'] ?? ''] ?? 'پایان‌یافته') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
