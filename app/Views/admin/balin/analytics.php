<?php
/**
 * Island analytics.
 *
 * @var array $totals
 * @var float $accuracy
 * @var array $skills
 * @var array $lessons
 * @var array $exams
 * @var array $questions
 * @var array $students
 */
?>
<div class="stat-grid">
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['students_with_access'] ?? 0))) ?></span><span class="stat-label">دانشجوی دارای دسترسی</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['active_students'] ?? 0))) ?></span><span class="stat-label">دانشجوی فعال</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['completed_stages'] ?? 0))) ?></span><span class="stat-label">مرحله تکمیل‌شده</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['answers'] ?? 0))) ?></span><span class="stat-label">پاسخ ثبت‌شده</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa(round($accuracy))) ?>٪</span><span class="stat-label">دقت کلی</span></div>
    <div class="stat-card"><span class="stat-value"><?= e(fa((int) ($totals['total_xp'] ?? 0))) ?></span><span class="stat-label">مجموع امتیاز</span></div>
</div>

<div class="card">
    <h3 class="card-title">میانگین تسلط در هر مهارت بالینی</h3>
    <?php if ($skills === []): ?>
        <div class="empty">هنوز داده‌ای ثبت نشده است.</div>
    <?php else: ?>
        <ul class="balin-skill-list" role="list">
            <?php foreach ($skills as $skill): ?>
                <li>
                    <div class="balin-skill-row">
                        <span class="balin-skill-name">
                            <span aria-hidden="true"><?= e($skill['icon'] ?: '🩺') ?></span>
                            <?= e($skill['name']) ?>
                            <span class="leaf-meta"><?= e(fa((int) $skill['student_count'])) ?> دانشجو</span>
                        </span>
                        <span class="balin-skill-score"><?= e(fa(round((float) $skill['average_percent']))) ?>٪</span>
                    </div>
                    <div class="balin-progress" role="progressbar"
                         aria-valuenow="<?= (int) $skill['average_percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                        <span style="width:<?= (float) $skill['average_percent'] ?>%"></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">درس‌ها</h3>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>درس</th><th>شروع‌کننده</th><th>تکمیل‌کننده</th><th>افت</th><th>پاسخ</th><th>دقت</th><th>میانگین تسلط</th></tr></thead>
            <tbody>
            <?php foreach ($lessons as $lesson):
                $starters  = (int) $lesson['starters'];
                $finishers = (int) $lesson['finishers'];
                $dropOff   = $starters > 0 ? round(($starters - $finishers) / $starters * 100) : 0;
                $answers   = (int) $lesson['answers'];
                $accuracyL = $answers > 0 ? round((int) $lesson['correct'] / $answers * 100) : 0;
            ?>
                <tr>
                    <td><span aria-hidden="true"><?= e($lesson['icon'] ?: '🩺') ?></span> <?= e($lesson['title']) ?></td>
                    <td><?= e(fa($starters)) ?></td>
                    <td><?= e(fa($finishers)) ?></td>
                    <td><?= e(fa($dropOff)) ?>٪</td>
                    <td><?= e(fa($answers)) ?></td>
                    <td><?= e(fa($accuracyL)) ?>٪</td>
                    <td><?= e(fa(round((float) $lesson['average_mastery']))) ?>٪</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">آزمون‌های بین‌مرحله‌ای</h3>
    <?php if ($exams === []): ?>
        <div class="empty">آزمونی تعریف نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>آزمون</th><th>درس</th><th>تلاش</th><th>نرخ قبولی</th><th>میانگین نمره</th><th>میانگین تلاش تا قبولی</th></tr></thead>
                <tbody>
                <?php foreach ($exams as $row): ?>
                    <tr>
                        <td>🎯 <?= e($row['exam']['title']) ?></td>
                        <td><?= e($row['lesson_title']) ?></td>
                        <td><?= e(fa((int) $row['stats']['attempts'])) ?></td>
                        <td><?= e(fa($row['pass_rate'])) ?>٪</td>
                        <td><?= e(fa($row['stats']['average_score'])) ?>٪</td>
                        <td><?= e(fa($row['stats']['average_attempts'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">سخت‌ترین سؤال‌ها</h3>
    <p style="color:var(--ink-3); font-size:12.5px; margin:-6px 0 12px;">
        سؤالاتی که حداقل ۵ بار پاسخ داده شده‌اند، از کم‌دقت‌ترین به بیشترین.
    </p>
    <?php if ($questions === []): ?>
        <div class="empty">هنوز داده کافی برای این تحلیل وجود ندارد.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>سؤال</th><th>درس</th><th>سختی</th><th>تلاش</th><th>دقت</th><th>میانگین زمان</th></tr></thead>
                <tbody>
                <?php foreach ($questions as $question): ?>
                    <tr>
                        <td><?= e(mb_substr((string) $question['prompt'], 0, 70, 'UTF-8')) ?></td>
                        <td><?= e($question['lesson_title']) ?></td>
                        <td><?= e(['easy' => 'آسان', 'medium' => 'متوسط', 'hard' => 'دشوار',
                                   'expert' => 'تخصصی'][$question['difficulty']] ?? '') ?></td>
                        <td><?= e(fa((int) $question['attempts'])) ?></td>
                        <td><?= e(fa((float) $question['accuracy'])) ?>٪</td>
                        <td><?= e(fa((float) $question['average_seconds'])) ?> ثانیه</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">دانشجویان برتر</h3>
    <?php if ($students === []): ?>
        <div class="empty">هنوز امتیازی ثبت نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>دانشجو</th><th>سطح</th><th>امتیاز</th><th>دقت</th><th>مرحله</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><?= e($student['full_name']) ?></td>
                        <td><?= e(fa((int) $student['cached_level'])) ?></td>
                        <td><?= e(fa((int) $student['total_xp'])) ?></td>
                        <td><?= e(fa(round((float) $student['accuracy_percent']))) ?>٪</td>
                        <td><?= e(fa((int) $student['stages_completed'])) ?></td>
                        <td class="row-actions">
                            <a class="btn btn-ghost btn-sm"
                               href="/admin/balin/analytics/student/<?= e($student['uuid']) ?>">کارنامه</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
