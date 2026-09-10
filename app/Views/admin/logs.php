<div class="card">
    <h3 class="card-title">گزارش فعالیت (<?= e(fa((string) $total)) ?>)</h3>

    <form method="get" action="/admin/logs" class="filters">
        <input class="input" type="search" name="user" placeholder="کاربر" value="<?= e($filters['user'] ?? '') ?>">
        <select class="input" name="action">
            <option value="">همه رویدادها</option>
            <?php foreach ($actions as $action): ?>
                <option value="<?= e($action) ?>" <?= ($filters['action'] ?? '') === $action ? 'selected' : '' ?>><?= e($action) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="input" name="severity">
            <option value="">همه سطوح</option>
            <?php foreach (['info' => 'اطلاع', 'notice' => 'توجه', 'warning' => 'هشدار', 'critical' => 'بحرانی'] as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($filters['severity'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" type="submit">فیلتر</button>
        <a class="btn btn-ghost btn-sm" href="/admin/logs">پاک کردن</a>
    </form>

    <?php if ($logs === []): ?>
        <div class="empty">رکوردی یافت نشد.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>زمان</th><th>کاربر</th><th>رویداد</th><th>سطح</th><th>IP</th><th>جزئیات</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $severityChip = ['info' => 'chip-gray', 'notice' => 'chip-blue', 'warning' => 'chip-red', 'critical' => 'chip-red'];
                    ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= e(jdate($log['created_at'])) ?></td>
                        <td><?= e($log['full_name'] ?? 'سیستم') ?></td>
                        <td class="mono"><?= e($log['action']) ?></td>
                        <td><span class="stat-chip <?= e($severityChip[$log['severity']] ?? 'chip-gray') ?>"><?= e($log['severity']) ?></span></td>
                        <td class="mono"><?= e($log['ip_address'] ?? '—') ?></td>
                        <td class="mono" style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <?= e($log['metadata'] ?? '—') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php \HeleXa\Core\View::partial('partials.pagination', ['paginator' => $paginator]); ?>
    <?php endif; ?>
</div>
