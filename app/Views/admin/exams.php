<?php
$kindLabels = ['final' => 'پایان‌ترم', 'midterm' => 'میان‌ترم', 'quiz' => 'کوییز', 'practical' => 'عملی', 'other' => 'سایر'];
?>
<div class="card">
    <h3 class="card-title">ثبت <?= e($kindLabels[$kind] ?? '') ?> جدید</h3>
    <form method="post" action="/admin/exams">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
        <input type="hidden" name="exam_kind" value="<?= e($kind) ?>">
        <div class="form-grid">
            <div class="field">
                <label class="label">عنوان</label>
                <input class="input" name="title" id="item-title" required>
            </div>
            <div class="field">
                <label class="label">درس</label>
                <select class="input" name="subject_id" id="item-subject" data-title-source>
                    <option value="">بدون انتخاب از فهرست دروس</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= (int) $subject['id'] ?>" data-title="<?= e($subject['title']) ?>">
                            <?= e($subject['title']) ?><?= $subject['major_title'] ? ' — ' . e($subject['major_title']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label">اتصال به دوره سایت (اختیاری)</label>
                <select class="input" name="course_id">
                    <option value="">بدون اتصال</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= (int) $course['id'] ?>"><?= e($course['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label">ترم</label>
                <select class="input" name="term_id" required>
                    <option value="">انتخاب ترم</option>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?= (int) $term['id'] ?>"><?= e($term['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label">گروه</label>
                <select class="input" name="group_id">
                    <option value="">کل ترم</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>"><?= e($group['term_title']) ?> — <?= e($group['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label">تاریخ (میلادی)</label>
                <input class="input" type="date" name="exam_date" dir="ltr" required>
            </div>
            <div class="field">
                <label class="label">ساعت شروع</label>
                <input class="input" type="time" name="start_time" dir="ltr">
            </div>
            <div class="field">
                <label class="label">ساعت پایان</label>
                <input class="input" type="time" name="end_time" dir="ltr">
            </div>
            <div class="field">
                <label class="label">مکان</label>
                <input class="input" name="location">
            </div>
        </div>
        <div class="field">
            <label class="label">توضیحات</label>
            <input class="input" name="description">
        </div>
        <label class="switch-row">
            <input type="checkbox" name="is_published" value="1" checked>
            <span>برای دانشجویان نمایش داده شود</span>
        </label>
        <button class="btn btn-primary" style="margin-top:12px;" type="submit">ثبت</button>
    </form>
</div>

<div class="card" style="margin-top:16px;">
    <h3 class="card-title">فهرست</h3>
    <?php if ($exams === []): ?>
        <div class="empty">موردی ثبت نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>عنوان</th><th>درس</th><th>دامنه</th><th>تاریخ</th><th>ساعت</th><th>وضعیت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($exams as $exam): ?>
                    <tr>
                        <td><?= e($exam['title']) ?></td>
                        <td><?= e($exam['course_title'] ?? '—') ?></td>
                        <td><?= e($exam['term_title'] ?? '—') ?><?= $exam['group_title'] ? ' / ' . e($exam['group_title']) : '' ?></td>
                        <td><?= e(jdate($exam['exam_date'])) ?></td>
                        <td class="mono"><?= e($exam['start_time'] ? fa(substr((string) $exam['start_time'], 0, 5)) : '—') ?></td>
                        <td>
                            <span class="stat-chip <?= (int) $exam['is_published'] === 1 ? 'chip-green' : 'chip-gray' ?>">
                                <?= (int) $exam['is_published'] === 1 ? 'منتشرشده' : 'مخفی' ?>
                            </span>
                        </td>
                        <td>
                            <form method="post" action="/admin/exams/<?= (int) $exam['id'] ?>/delete"
                                  data-confirm="این مورد حذف شود؟" style="margin:0;">
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
