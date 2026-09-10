<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">گیرندگان پیام</h3>
        <a class="btn btn-ghost btn-sm" href="/admin/messages">بازگشت</a>
    </div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>دانشجو</th><th>نام کاربری</th><th>وضعیت</th><th>زمان خواندن</th></tr></thead>
            <tbody>
            <?php foreach ($recipients as $row): ?>
                <tr>
                    <td><?= e($row['full_name']) ?></td>
                    <td class="mono"><?= e($row['username']) ?></td>
                    <td>
                        <span class="stat-chip <?= (int) $row['is_read'] === 1 ? 'chip-green' : 'chip-gray' ?>">
                            <?= (int) $row['is_read'] === 1 ? 'خوانده شد' : 'خوانده‌نشده' ?>
                        </span>
                    </td>
                    <td><?= e($row['read_at'] ? jdate($row['read_at']) : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
