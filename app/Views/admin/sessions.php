<div class="card">
    <h3 class="card-title">نشست‌های اخیر</h3>
    <p style="color:var(--ink-3); font-size:13px; margin:-8px 0 16px;">
        اگر دانشجویی به دلیل سیاست تک‌دستگاه نمی‌تواند وارد شود، از صفحه‌ی همان کاربر گزینه «بستن همه نشست‌ها» را بزنید.
    </p>

    <?php if ($sessions === []): ?>
        <div class="empty">نشستی ثبت نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th>کاربر</th><th>دستگاه</th><th>IP</th><th>ورود</th><th>وضعیت</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($sessions as $row): ?>
                    <tr>
                        <td><?= e($row['full_name']) ?><br><span class="mono" style="color:var(--ink-3)"><?= e($row['username']) ?></span></td>
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
                        <td>
                            <?php if ((int) $row['is_active'] === 1 && can('terminate_sessions')): ?>
                                <form method="post" action="/admin/sessions/<?= (int) $row['id'] ?>/terminate"
                                      data-confirm="این نشست بسته شود؟" style="margin:0;">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">خروج اجباری</button>
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
