<div class="card">
    <h3 class="card-title">نشست‌های فعال <?= e($student['full_name']) ?></h3>

    <?php if (can('terminate_sessions')): ?>
        <form method="post" action="/admin/sessions/user/<?= e($student['uuid']) ?>/terminate-all"
              data-confirm="همه نشست‌های این کاربر بسته شود؟" style="margin:0 0 16px;">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <button class="btn btn-danger btn-sm" type="submit">بستن همه نشست‌ها</button>
        </form>
    <?php endif; ?>

    <?php if ($active === []): ?>
        <div class="empty">نشست فعالی وجود ندارد.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>دستگاه</th><th>IP</th><th>ورود</th><th>آخرین فعالیت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($active as $row): ?>
                    <tr>
                        <td><?= e($row['operating_system'] ?? '—') ?> / <?= e($row['browser'] ?? '—') ?></td>
                        <td class="mono"><?= e($row['ip_address']) ?></td>
                        <td><?= e(jdate($row['login_at'])) ?></td>
                        <td><?= e(jdate($row['last_activity'])) ?></td>
                        <td>
                            <?php if (can('terminate_sessions')): ?>
                                <form method="post" action="/admin/sessions/<?= (int) $row['id'] ?>/terminate" style="margin:0;">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">پایان</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:16px;">
    <h3 class="card-title">تاریخچه ورود</h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>دستگاه</th><th>IP</th><th>ورود</th><th>خروج</th><th>علت پایان</th></tr></thead>
            <tbody>
            <?php foreach ($history as $row): ?>
                <tr>
                    <td><?= e($row['operating_system'] ?? '—') ?> / <?= e($row['browser'] ?? '—') ?></td>
                    <td class="mono"><?= e($row['ip_address']) ?></td>
                    <td><?= e(jdate($row['login_at'])) ?></td>
                    <td><?= e(jdate($row['logout_at'])) ?></td>
                    <td class="mono"><?= e($row['termination_reason'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
