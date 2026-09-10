<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">دانشجویان «<?= e($course['title']) ?>»</h3>
        <a class="btn btn-ghost btn-sm" href="/admin/courses">بازگشت</a>
    </div>

    <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/students" class="filters">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <select class="input" name="student_uuid" required style="min-width:220px;">
            <option value="">انتخاب دانشجو</option>
            <?php foreach ($candidates as $candidate): ?>
                <option value="<?= e($candidate['uuid']) ?>"><?= e($candidate['full_name']) ?> — <?= e($candidate['username']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="label" style="margin:0;">از تاریخ</label>
        <input class="input" type="date" name="starts_at" dir="ltr">
        <label class="label" style="margin:0;">تا تاریخ</label>
        <input class="input" type="date" name="ends_at" dir="ltr">
        <button class="btn btn-primary btn-sm" type="submit">افزودن دسترسی</button>
    </form>
    <p style="color:var(--ink-3); font-size:12.5px; margin:-8px 0 16px;">
        تاریخ‌ها میلادی وارد می‌شوند و در پنل دانشجو شمسی نمایش داده می‌شوند.
        پس از تاریخ پایان، دسترسی خودکار قطع می‌شود و تغییر URL یا JavaScript آن را دور نمی‌زند.
    </p>

    <?php if ($enrollments === []): ?>
        <div class="empty">هنوز دانشجویی به این دوره دسترسی ندارد.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>دانشجو</th><th>وضعیت</th><th>شروع</th><th>پایان</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($enrollments as $row): ?>
                    <tr>
                        <td><?= e($row['full_name']) ?><br><span class="mono" style="color:var(--ink-3)"><?= e($row['username']) ?></span></td>
                        <td>
                            <span class="stat-chip <?= $row['status'] === 'active' ? 'chip-green' : 'chip-gray' ?>"><?= e($row['status']) ?></span>
                        </td>
                        <td><?= e($row['starts_at'] ? jdate($row['starts_at']) : 'بدون محدودیت') ?></td>
                        <td><?= e($row['ends_at'] ? jdate($row['ends_at']) : 'بدون محدودیت') ?></td>
                        <td>
                            <form method="post" action="/admin/courses/<?= e($course['uuid']) ?>/students/<?= e($row['user_uuid']) ?>/remove"
                                  data-confirm="دسترسی این دانشجو حذف شود؟" style="margin:0;">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف دسترسی</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
