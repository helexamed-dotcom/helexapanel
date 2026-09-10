<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">مدیران</h3>
        <a class="btn btn-primary btn-sm" href="/admin/admins/create">+ افزودن مدیر</a>
    </div>

    <form method="get" action="/admin/admins" class="filters">
        <input class="input" type="search" name="q" placeholder="نام یا نام کاربری" value="<?= e($search ?? '') ?>">
        <button class="btn btn-ghost btn-sm" type="submit">جستجو</button>
    </form>

    <?php if ($admins === []): ?>
        <div class="empty">مدیری یافت نشد.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>نام</th><th>نام کاربری</th><th>نقش</th><th>وضعیت</th><th>آخرین ورود</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($admins as $admin): ?>
                    <tr>
                        <td><?= e($admin['full_name']) ?></td>
                        <td class="mono"><?= e($admin['username']) ?></td>
                        <td>
                            <?php if ($admin['role_slug'] === 'super_admin'): ?>
                                <span class="stat-chip chip-purple">مدیر ارشد</span>
                            <?php else: ?>
                                <span class="stat-chip chip-blue">مدیر</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="stat-chip <?= $admin['status'] === 'active' ? 'chip-green' : 'chip-gray' ?>">
                                <?= $admin['status'] === 'active' ? 'فعال' : 'غیرفعال' ?>
                            </span>
                        </td>
                        <td><?= e(jdate($admin['last_login_at'])) ?></td>
                        <td class="row-actions">
                            <a class="btn btn-ghost btn-sm" href="/admin/admins/<?= e($admin['uuid']) ?>/edit">ویرایش</a>
                            <form method="post" action="/admin/admins/<?= e($admin['uuid']) ?>/reset-password"
                                  data-confirm="رمز موقت جدید برای این مدیر ساخته شود؟">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">رمز موقت</button>
                            </form>
                            <?php if ((int) $admin['id'] !== (int) ($currentUser['id'] ?? 0)): ?>
                                <form method="post" action="/admin/admins/<?= e($admin['uuid']) ?>/delete"
                                      data-confirm="این مدیر حذف شود؟">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-danger btn-sm" type="submit">حذف</button>
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
