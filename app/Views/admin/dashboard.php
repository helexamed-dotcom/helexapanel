<h2 class="greet">سلام <?= e($currentUser['full_name'] ?? '') ?> 👋</h2>
<p class="greet-sub"><?= e($todayText) ?></p>

<div class="grid grid-4">
    <div class="card">
        <div class="stat-label">کل دانشجویان</div>
        <div class="stat-value"><?= e(fa((string) $totalStudents)) ?></div>
        <span class="stat-chip chip-blue">ثبت‌شده</span>
    </div>
    <div class="card">
        <div class="stat-label">دانشجویان فعال</div>
        <div class="stat-value"><?= e(fa((string) $activeStudents)) ?></div>
        <span class="stat-chip chip-green">وضعیت active</span>
    </div>
    <div class="card">
        <div class="stat-label">آنلاین در ۵ دقیقه اخیر</div>
        <div class="stat-value"><?= e(fa((string) $onlineNow)) ?></div>
        <span class="stat-chip chip-teal">نشست فعال</span>
    </div>
    <div class="card">
        <div class="stat-label">دوره‌های فعال</div>
        <div class="stat-value"><?= e(fa('0')) ?></div>
        <span class="stat-chip chip-gray">در فاز ۳ فعال می‌شود</span>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3 class="card-title">آخرین ورودها</h3>
    <?php if ($recentLogins === []): ?>
        <div class="empty">هنوز ورودی ثبت نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th>کاربر</th><th>نقش</th><th>دستگاه</th><th>IP</th><th>زمان</th><th>وضعیت</th></tr>
                </thead>
                <tbody>
                <?php foreach ($recentLogins as $row): ?>
                    <tr>
                        <td><?= e($row['full_name']) ?><br><span class="mono" style="color:var(--ink-3)"><?= e($row['username']) ?></span></td>
                        <td><?= e($row['role_slug']) ?></td>
                        <td><?= e($row['operating_system'] ?? '—') ?> / <?= e($row['browser'] ?? '—') ?></td>
                        <td class="mono"><?= e($row['ip_address']) ?></td>
                        <td><?= e(jdate($row['login_at'])) ?></td>
                        <td>
                            <?php if ((int) $row['is_active'] === 1): ?>
                                <span class="stat-chip chip-green">فعال</span>
                            <?php else: ?>
                                <span class="stat-chip chip-gray">بسته</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($recentActivity !== []): ?>
<div class="card" style="margin-top:16px;">
    <h3 class="card-title">فعالیت‌های اخیر</h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>کاربر</th><th>رویداد</th><th>IP</th><th>زمان</th></tr></thead>
            <tbody>
            <?php foreach ($recentActivity as $log): ?>
                <tr>
                    <td><?= e($log['full_name'] ?? 'سیستم') ?></td>
                    <td class="mono"><?= e($log['action']) ?></td>
                    <td class="mono"><?= e($log['ip_address'] ?? '—') ?></td>
                    <td><?= e(jdate($log['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
