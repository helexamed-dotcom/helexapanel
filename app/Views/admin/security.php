<?php
$failed = array_values(array_filter($checks, static fn (array $c): bool => !$c['ok']));
$critical = array_values(array_filter($failed, static fn (array $c): bool => $c['severity'] === 'critical'));
?>

<?php if ($cleanup !== null): ?>
    <div class="alert alert-success">
        نتیجه پاک‌سازی:
        <?php foreach ($cleanup as $key => $value): ?>
            <span class="mono"><?= e($key) ?>=<?= e(fa((string) $value)) ?></span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid grid-4">
    <div class="card">
        <div class="stat-label">بررسی‌های انجام‌شده</div>
        <div class="stat-value"><?= e(fa((string) count($checks))) ?></div>
    </div>
    <div class="card">
        <div class="stat-label">موارد بحرانی</div>
        <div class="stat-value" style="color: <?= $critical === [] ? 'var(--green)' : 'var(--red)' ?>">
            <?= e(fa((string) count($critical))) ?>
        </div>
    </div>
    <div class="card">
        <div class="stat-label">مسیرهای ثبت‌شده</div>
        <div class="stat-value"><?= e(fa((string) $routes['total'])) ?></div>
    </div>
    <div class="card">
        <div class="stat-label">مسیر بدون محافظ</div>
        <div class="stat-value" style="color: <?= $routes['unguarded'] === [] ? 'var(--green)' : 'var(--red)' ?>">
            <?= e(fa((string) count($routes['unguarded']))) ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">سقف‌های آپلود و درخواست</h3>
        <?php $limitProblems = array_filter($limits, static fn (array $l): bool => !$l['ok']); ?>
        <span class="stat-chip <?= $limitProblems === [] ? 'chip-green' : 'chip-amber' ?>">
            <?= $limitProblems === [] ? 'برای محتوای بزرگ کافی است' : e(fa((string) count($limitProblems))) . ' مورد کم است' ?>
        </span>
    </div>
    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        این مقادیر مستقیماً از خود PHP خوانده می‌شوند، نه از فایل تنظیمات. اگر بعد از ویرایش
        <span class="mono">public_html/.user.ini</span> عدد اینجا عوض نشد، یعنی هاست آن را نادیده گرفته
        و باید از <span class="mono">Select PHP Version → Options</span> در دایرکت‌ادمین تغییرش دهید.
        خطای «Request Entity Too Large» تقریباً همیشه از <span class="mono">post_max_size</span> یا
        <span class="mono">LimitRequestBody</span> آپاچی می‌آید، نه از <span class="mono">upload_max_filesize</span>.
    </p>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>تنظیم</th><th>مقدار فعلی</th><th>وضعیت</th><th>توضیح</th></tr></thead>
            <tbody>
            <?php foreach ($limits as $limit): ?>
                <tr>
                    <td class="mono"><?= e($limit['label']) ?></td>
                    <td class="mono"><?= e(fa($limit['value'])) ?></td>
                    <td>
                        <span class="stat-chip <?= $limit['ok'] ? 'chip-green' : 'chip-amber' ?>">
                            <?= $limit['ok'] ? 'کافی' : 'کم' ?>
                        </span>
                    </td>
                    <td style="color:var(--ink-2); font-size:12.5px;"><?= e($limit['note']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3 class="card-title">وضعیت نصب</h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>بررسی</th><th>وضعیت</th><th>توضیح</th></tr></thead>
            <tbody>
            <?php foreach ($checks as $check): ?>
                <tr>
                    <td><?= e($check['label']) ?></td>
                    <td>
                        <?php if ($check['ok']): ?>
                            <span class="stat-chip chip-green">درست</span>
                        <?php else: ?>
                            <span class="stat-chip <?= $check['severity'] === 'critical' ? 'chip-red' : 'chip-amber' ?>">
                                <?= $check['severity'] === 'critical' ? 'بحرانی' : 'هشدار' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--ink-2); font-size:13px;"><?= e($check['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">ممیزی مسیرها</h3>
        <span class="stat-chip <?= $routes['unguarded'] === [] ? 'chip-green' : 'chip-red' ?>">
            <?= $routes['unguarded'] === [] ? 'همه مسیرها محافظت‌شده‌اند' : 'نیاز به بررسی' ?>
        </span>
    </div>
    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        هر مسیر باید یا عمومی باشد (ورود و خروج) یا هم احراز هویت داشته باشد و هم پوشش CSRF.
        این جدول مستقیماً از جدول مسیرهای زنده خوانده می‌شود، نه از یک فهرست دستی.
    </p>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>روش</th><th>مسیر</th><th>میان‌افزارها</th><th>وضعیت</th></tr></thead>
            <tbody>
            <?php foreach ($routes['rows'] as $row): ?>
                <tr>
                    <td class="mono"><?= e($row['method']) ?></td>
                    <td class="mono"><?= e($row['path']) ?></td>
                    <td style="font-size:11.5px; color:var(--ink-3);"><?= e(implode('، ', $row['middleware'])) ?></td>
                    <td>
                        <?php if ($row['public']): ?>
                            <span class="stat-chip chip-gray">عمومی</span>
                        <?php elseif ($row['ok']): ?>
                            <span class="stat-chip chip-green">محافظت‌شده</span>
                        <?php else: ?>
                            <span class="stat-chip chip-red">بررسی شود</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">وضعیت داده‌ها</h3>
        <form method="post" action="/admin/security/cleanup" style="margin:0;">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <button class="btn btn-primary btn-sm" type="submit">اجرای پاک‌سازی</button>
        </form>
    </div>
    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 14px;">
        پاک‌سازی به‌صورت خودکار حداکثر هر یک ساعت روی یکی از درخواست‌ها اجرا می‌شود؛
        این دکمه فقط آن را زودتر اجرا می‌کند. رویدادهای هشدار و بحرانی هیچ‌وقت حذف نمی‌شوند.
    </p>
    <table class="data" style="min-width:auto;">
        <?php foreach ($hygiene as $label => $value): ?>
            <tr><th><?= e($label) ?></th><td><?= e(fa((string) $value)) ?></td></tr>
        <?php endforeach; ?>
    </table>
</div>
