<div class="card">
    <h3 class="card-title">انتشار اطلاعیه</h3>
    <form method="post" action="/admin/notifications">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <div class="form-grid">
            <div class="field">
                <label class="label">عنوان</label>
                <input class="input" name="title" required>
            </div>
            <div class="field">
                <label class="label">نوع</label>
                <select class="input" name="notif_type">
                    <?php foreach (['system' => 'سیستم', 'content' => 'محتوا', 'schedule' => 'برنامه', 'exam' => 'امتحان', 'course' => 'دوره', 'package' => 'پکیج'] as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label">مخاطب</label>
                <select class="input" name="audience" id="notif-audience">
                    <option value="all">همه دانشجویان</option>
                    <option value="term">یک ترم</option>
                    <option value="group">یک گروه</option>
                    <option value="university">یک دانشگاه</option>
                    <option value="major">یک رشته</option>
                    <option value="course">دانشجویان یک دوره</option>
                    <option value="package">دانشجویان یک پکیج</option>
                    <option value="user">یک دانشجو</option>
                </select>
            </div>
            <div class="field" data-audience-field="term">
                <label class="label">ترم (برای مخاطب ترمی)</label>
                <select class="input" name="term_id">
                    <option value="">—</option>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?= (int) $term['id'] ?>"><?= e($term['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" data-audience-field="group">
                <label class="label">گروه (برای مخاطب گروهی)</label>
                <select class="input" name="group_id">
                    <option value="">—</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>"><?= e($group['term_title']) ?> — <?= e($group['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" data-audience-field="university">
                <label class="label">دانشگاه (برای مخاطب دانشگاهی)</label>
                <select class="input" name="university_id">
                    <option value="">—</option>
                    <?php foreach ($universities as $university): ?>
                        <option value="<?= (int) $university['id'] ?>"><?= e($university['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" data-audience-field="major">
                <label class="label">رشته (برای مخاطب رشته‌ای)</label>
                <select class="input" name="major_id">
                    <option value="">—</option>
                    <?php foreach ($majors as $major): ?>
                        <option value="<?= (int) $major['id'] ?>"><?= e($major['university_title']) ?> — <?= e($major['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" data-audience-field="course">
                <label class="label">دوره (برای مخاطب دوره‌ای)</label>
                <select class="input" name="course_id">
                    <option value="">—</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= (int) $course['id'] ?>"><?= e($course['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="color:var(--ink-3); font-size:11px; margin-top:4px;">فقط دانشجویانی که همین الان دسترسی فعال دارند.</div>
            </div>
            <div class="field" data-audience-field="package">
                <label class="label">پکیج (برای مخاطب پکیجی)</label>
                <select class="input" name="package_id">
                    <option value="">—</option>
                    <?php foreach ($packages as $package): ?>
                        <option value="<?= (int) $package['id'] ?>"><?= e($package['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" data-audience-field="user">
                <label class="label">دانشجو (برای مخاطب تکی)</label>
                <select class="input" name="student_uuid">
                    <option value="">—</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= e($student['uuid']) ?>"><?= e($student['full_name']) ?> — <?= e($student['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label">لینک داخلی (اختیاری)</label>
                <input class="input" name="link_url" dir="ltr" placeholder="/student/courses">
            </div>
            <div class="field">
                <label class="label">انقضا (اختیاری)</label>
                <input class="input" type="date" name="expires_at" dir="ltr">
            </div>
        </div>
        <div class="field">
            <label class="label">متن</label>
            <textarea class="input" name="body" rows="3"></textarea>
        </div>
        <button class="btn btn-primary" type="submit">انتشار</button>
    </form>
</div>

<div class="card" style="margin-top:16px;">
    <h3 class="card-title">اطلاعیه‌های منتشرشده</h3>
    <?php if ($notifications === []): ?>
        <div class="empty">اطلاعیه‌ای منتشر نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>عنوان</th><th>مخاطب</th><th>گیرندگان</th><th>خوانده‌شده</th><th>تاریخ</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($notifications as $item): ?>
                    <tr>
                        <td><?= e($item['title']) ?></td>
                        <td>
                            <?= e(['all' => 'همه', 'term' => 'ترم', 'group' => 'گروه', 'university' => 'دانشگاه',
                                   'major' => 'رشته', 'course' => 'دوره', 'package' => 'پکیج', 'user' => 'یک دانشجو'][$item['audience']] ?? $item['audience']) ?>
                            <?= $item['term_title'] ? ' · ' . e($item['term_title']) : '' ?>
                            <?= $item['group_title'] ? ' · ' . e($item['group_title']) : '' ?>
                            <?= $item['university_title'] ? ' · ' . e($item['university_title']) : '' ?>
                            <?= $item['major_title'] ? ' · ' . e($item['major_title']) : '' ?>
                            <?= $item['course_title'] ? ' · ' . e($item['course_title']) : '' ?>
                            <?= $item['package_title'] ? ' · ' . e($item['package_title']) : '' ?>
                        </td>
                        <td><?= e(fa((string) $item['recipients'])) ?></td>
                        <td><?= e(fa((string) $item['read_count'])) ?></td>
                        <td><?= e(jdate($item['published_at'])) ?></td>
                        <td>
                            <form method="post" action="/admin/notifications/<?= (int) $item['id'] ?>/delete"
                                  data-confirm="این اطلاعیه حذف شود؟" style="margin:0;">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
