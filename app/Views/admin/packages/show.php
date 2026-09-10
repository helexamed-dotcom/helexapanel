<div class="card">
    <div class="card-head">
        <div>
            <h3 class="card-title" style="margin:0;"><?= e($package['title']) ?></h3>
            <div class="leaf-meta">
                <?= e(fa((string) count($courses))) ?> دوره ·
                افزودن خودکار: <?= (int) $package['auto_grant_new_courses'] === 1 ? 'روشن' : 'خاموش' ?>
            </div>
        </div>
        <a class="btn btn-ghost btn-sm" href="/admin/packages">بازگشت</a>
    </div>

    <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>" class="filters">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <input class="input" name="title" value="<?= e($package['title']) ?>" required>
        <select class="input" name="status">
            <?php foreach (['published' => 'فعال', 'draft' => 'پیش‌نویس', 'archived' => 'بایگانی'] as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $package['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" name="description" placeholder="توضیح" value="<?= e($package['description'] ?? '') ?>">
        <input class="input" type="color" name="color" value="<?= e($package['color'] ?? '#7c6cf3') ?>" style="max-width:70px;">
        <label class="switch-row" style="border:0; padding:0;">
            <input type="checkbox" name="auto_grant_new_courses" value="1"
                <?= (int) $package['auto_grant_new_courses'] === 1 ? 'checked' : '' ?>>
            <span>افزودن خودکار</span>
        </label>
        <button class="btn btn-primary btn-sm" type="submit">ذخیره</button>
    </form>
</div>

<div class="grid grid-2" style="margin-top:16px;">
    <div class="card">
        <h3 class="card-title">دوره‌های داخل پکیج</h3>

        <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/courses" class="filters">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <select class="input" name="course_id" required>
                <option value="">انتخاب دوره</option>
                <?php foreach ($available as $course): ?>
                    <option value="<?= (int) $course['id'] ?>"><?= e($course['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary btn-sm" type="submit">افزودن</button>
        </form>

        <?php if ($courses === []): ?>
            <div class="empty">هنوز دوره‌ای داخل این پکیج نیست.</div>
        <?php else: ?>
            <div class="tree">
                <?php foreach ($courses as $course): ?>
                    <div class="tree-leaf">
                        <span class="leaf-icon">▤</span>
                        <div style="min-width:0; flex:1;">
                            <div class="leaf-title"><?= e($course['title']) ?></div>
                            <div class="leaf-meta"><?= e(jdate($course['added_at'])) ?></div>
                        </div>
                        <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/courses/<?= (int) $course['id'] ?>/remove"
                              data-confirm="این دوره از پکیج حذف شود؟ دسترسی دانشجویان فعلی تغییر نمی‌کند." style="margin:0;">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 class="card-title">فعال‌سازی برای دانشجو</h3>

        <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/activate" class="filters">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <select class="input" name="student_uuid" required style="min-width:200px;">
                <option value="">انتخاب دانشجو</option>
                <?php foreach ($students as $student): ?>
                    <option value="<?= e($student['uuid']) ?>"><?= e($student['full_name']) ?> — <?= e($student['username']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="label" style="margin:0;">از</label>
            <input class="input" type="date" name="starts_at" dir="ltr">
            <label class="label" style="margin:0;">تا</label>
            <input class="input" type="date" name="ends_at" dir="ltr">
            <button class="btn btn-primary btn-sm" type="submit">فعال‌سازی</button>
        </form>

        <?php if ($members === []): ?>
            <div class="empty">هنوز برای کسی فعال نشده است.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>دانشجو</th><th>وضعیت</th><th>دوره‌ها</th><th>پایان</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($members as $member): ?>
                        <tr>
                            <td><?= e($member['full_name']) ?><br><span class="mono" style="color:var(--ink-3)"><?= e($member['username']) ?></span></td>
                            <td>
                                <span class="stat-chip <?= $member['status'] === 'active' ? 'chip-green' : 'chip-gray' ?>">
                                    <?= e($member['status']) ?>
                                </span>
                            </td>
                            <td><?= e(fa((string) $member['course_count'])) ?></td>
                            <td><?= e($member['ends_at'] ? jdate($member['ends_at']) : 'بدون محدودیت') ?></td>
                            <td>
                                <form method="post" action="/admin/packages/<?= e($package['uuid']) ?>/members/<?= (int) $member['id'] ?>/status"
                                      style="margin:0;">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="status" value="<?= $member['status'] === 'active' ? 'suspended' : 'active' ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit">
                                        <?= $member['status'] === 'active' ? 'تعلیق' : 'فعال‌سازی' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="color:var(--ink-3); font-size:11.5px; margin-top:10px;">
                تعلیق پکیج، دوره‌های داخل آن را هم برای همان دانشجو معلق می‌کند و بلافاصله اثر می‌گذارد.
            </p>
        <?php endif; ?>
    </div>
</div>
