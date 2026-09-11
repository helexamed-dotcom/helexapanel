<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">دانشجویان (<?= e(fa((string) $total)) ?>)</h3>
        <a class="btn btn-primary btn-sm" href="/admin/students/create">+ افزودن دانشجو</a>
    </div>

    <form method="get" action="/admin/students" class="filters">
        <input class="input" type="search" name="q" placeholder="نام، نام کاربری یا موبایل"
               value="<?= e($filters['search'] ?? '') ?>">
        <select class="input" name="status">
            <option value="">همه وضعیت‌ها</option>
            <?php foreach (['active' => 'فعال', 'inactive' => 'غیرفعال', 'suspended' => 'معلق'] as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="input" name="term_id">
            <option value="">همه ترم‌ها</option>
            <?php foreach ($terms as $term): ?>
                <option value="<?= (int) $term['id'] ?>" <?= (int) ($filters['term_id'] ?? 0) === (int) $term['id'] ? 'selected' : '' ?>>
                    <?= e($term['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" type="submit">فیلتر</button>
        <a class="btn btn-ghost btn-sm" href="/admin/students">پاک کردن</a>
    </form>

    <?php if ($students === []): ?>
        <div class="empty">دانشجویی با این شرایط یافت نشد.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr>
                    <th>نام</th><th>شماره موبایل</th><th>دانشگاه / رشته</th><th>ترم / گروه</th>
                    <th>وضعیت</th><th>نشست فعال</th><th>ثبت‌نام</th><th>آخرین ورود</th><th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><?= e($student['full_name']) ?></td>
                        <?php
                        /* The number is now the identifier students actually sign in
                           with, so it leads; the username stays underneath because
                           admin-created accounts are still found by it. */
                        $verified = !empty($student['phone_verified_at']);
                        ?>
                        <td>
                            <?php if (!empty($student['mobile'])): ?>
                                <span class="mono" dir="ltr"><?= e(fa((string) $student['mobile'])) ?></span>
                                <span class="stat-chip <?= $verified ? 'chip-green' : 'chip-gray' ?>" style="margin:0 6px 0 0;">
                                    <?= $verified ? 'تأیید شده' : 'تأیید نشده' ?>
                                </span>
                            <?php else: ?>
                                <span class="leaf-meta">بدون شماره</span>
                            <?php endif; ?>
                            <div class="leaf-meta mono"><?= e($student['username']) ?></div>
                        </td>
                        <td>
                            <?= e($student['university_title'] ?? '—') ?>
                            <?php if (!empty($student['major_title'])): ?>
                                <div class="leaf-meta"><?= e($student['major_title']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($student['term_title'] ?? '—') ?><?= $student['group_title'] ? ' / ' . e($student['group_title']) : '' ?></td>
                        <td>
                            <?php
                            $map = ['active' => ['chip-green', 'فعال'], 'inactive' => ['chip-gray', 'غیرفعال'], 'suspended' => ['chip-red', 'معلق']];
                            [$chip, $label] = $map[$student['status']] ?? ['chip-gray', $student['status']];
                            ?>
                            <span class="stat-chip <?= e($chip) ?>"><?= e($label) ?></span>
                            <?php if (!empty($student['locked_until']) && strtotime((string) $student['locked_until']) > time()): ?>
                                <span class="stat-chip chip-red">قفل ورود</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $student['active_sessions'] > 0): ?>
                                <a class="stat-chip chip-teal" href="/admin/sessions/user/<?= e($student['uuid']) ?>">
                                    <?= e(fa((string) $student['active_sessions'])) ?> نشست
                                </a>
                            <?php else: ?>
                                <span class="stat-chip chip-gray">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(jdate($student['created_at'])) ?></td>
                        <td><?= e(jdate($student['last_login_at'])) ?></td>
                        <td class="row-actions">
                            <a class="btn btn-ghost btn-sm" href="/admin/students/<?= e($student['uuid']) ?>/edit">ویرایش</a>
                            <form method="post" action="/admin/students/<?= e($student['uuid']) ?>/reset-password"
                                  data-confirm="رمز موقت جدید ساخته شود و همه نشست‌های کاربر بسته شود؟">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">رمز موقت</button>
                            </form>
                            <?php if (!empty($student['locked_until']) && strtotime((string) $student['locked_until']) > time()): ?>
                                <form method="post" action="/admin/students/<?= e($student['uuid']) ?>/unlock">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">رفع قفل</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="/admin/students/<?= e($student['uuid']) ?>/status">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="status" value="<?= $student['status'] === 'active' ? 'suspended' : 'active' ?>">
                                <button class="btn btn-ghost btn-sm" type="submit">
                                    <?= $student['status'] === 'active' ? 'تعلیق' : 'فعال‌سازی' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin/students/<?= e($student['uuid']) ?>/delete"
                                  data-confirm="این دانشجو حذف شود؟ سوابق فعالیت باقی می‌ماند.">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php \HeleXa\Core\View::partial('partials.pagination', ['paginator' => $paginator]); ?>
    <?php endif; ?>
</div>
