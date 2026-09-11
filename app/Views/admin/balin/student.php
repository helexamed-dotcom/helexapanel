<?php
/**
 * One student's clinical record.
 *
 * This is individual-level teaching data, which is why the route behind it
 * needs balin.view_statistics rather than plain admin access.
 *
 * @var array $student
 * @var array $profile
 * @var float $cps
 * @var array $attempts
 * @var array $activity
 */
$level = $profile['level'];
$rank  = $profile['rank'];
$stats = $profile['stats'];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;"><?= e($student['full_name']) ?></h3>
        <a class="btn btn-ghost btn-sm" href="/admin/balin/analytics">بازگشت</a>
    </div>

    <div class="balin-inline-meta">
        <span aria-hidden="true"><?= e($rank['icon'] ?: '🏝️') ?></span>
        <strong><?= e($rank['title']) ?></strong>
        <span class="leaf-meta">سطح <?= e(fa($level['level'])) ?> · <?= e(fa($level['xp'])) ?> امتیاز</span>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><span class="stat-value"><?= e(fa(round((float) $stats['accuracy_percent']))) ?>٪</span><span class="stat-label">دقت</span></div>
        <div class="stat-card"><span class="stat-value"><?= e(fa((int) $stats['answered_count'])) ?></span><span class="stat-label">پاسخ</span></div>
        <div class="stat-card"><span class="stat-value"><?= e(fa((int) $stats['stages_completed'])) ?></span><span class="stat-label">مرحله</span></div>
        <div class="stat-card"><span class="stat-value"><?= e(fa((int) $stats['lessons_completed'])) ?></span><span class="stat-label">درس</span></div>
        <div class="stat-card"><span class="stat-value"><?= e(fa((int) $profile['streak']['current_streak'])) ?></span><span class="stat-label">روز پیاپی</span></div>
        <div class="stat-card"><span class="stat-value"><?= e(fa(round($cps))) ?></span><span class="stat-label">نمره عملکرد بالینی</span></div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">مهارت‌های بالینی</h3>
    <ul class="balin-skill-list" role="list">
        <?php foreach ($profile['skills'] as $skill): ?>
            <li>
                <div class="balin-skill-row">
                    <span class="balin-skill-name">
                        <span aria-hidden="true"><?= e($skill['track']['icon'] ?: '🩺') ?></span>
                        <?= e($skill['track']['name']) ?>
                    </span>
                    <span class="balin-skill-score">
                        <?php if ($skill['reliable']): ?>
                            <?= e(fa(round((float) $skill['mastery_percent']))) ?>٪
                        <?php else: ?>
                            <span class="balin-skill-thin">
                                داده کافی نیست (<?= e(fa((int) $skill['answered_count'])) ?>/<?= e(fa((int) $skill['minimum_required'])) ?>)
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($skill['reliable']): ?>
                    <div class="balin-progress" role="progressbar"
                         aria-valuenow="<?= (int) $skill['mastery_percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                        <span style="width:<?= (float) $skill['mastery_percent'] ?>%"></span>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="card">
    <h3 class="card-title">تاریخچه آزمون‌ها</h3>
    <?php if ($attempts === []): ?>
        <div class="empty">هنوز آزمونی نداده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>آزمون</th><th>درس</th><th>تلاش</th><th>نمره</th><th>نتیجه</th><th>تاریخ</th></tr></thead>
                <tbody>
                <?php foreach ($attempts as $attempt): ?>
                    <tr>
                        <td>🎯 <?= e($attempt['exam_title']) ?></td>
                        <td><?= e($attempt['lesson_title']) ?></td>
                        <td><?= e(fa((int) $attempt['attempt_number'])) ?></td>
                        <td><?= $attempt['score_percent'] === null ? '—' : e(fa(round((float) $attempt['score_percent']))) . '٪' ?></td>
                        <td>
                            <?php if ($attempt['completed_at'] === null): ?>
                                <span class="stat-chip chip-gray">ناتمام</span>
                            <?php elseif ((int) $attempt['passed'] === 1): ?>
                                <span class="stat-chip chip-green">قبول</span>
                            <?php else: ?>
                                <span class="stat-chip chip-red">مردود</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(jdate($attempt['completed_at'] ?? $attempt['started_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card-title">آخرین امتیازها</h3>
    <?php if ($activity === []): ?>
        <div class="empty">هنوز امتیازی ثبت نشده است.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>مقدار</th><th>نوع</th><th>زمان</th></tr></thead>
                <tbody>
                <?php
                $typeNames = [
                    'question_correct'       => 'پاسخ صحیح',
                    'stage_complete'         => 'تکمیل مرحله',
                    'lesson_complete'        => 'تکمیل درس',
                    'final_case'             => 'کیس نهایی',
                    'checkpoint_exam_passed' => 'قبولی آزمون',
                    'daily_mission'          => 'مأموریت روزانه',
                    'weekly_mission'         => 'مأموریت هفتگی',
                    'achievement'            => 'نشان',
                    'admin_adjustment'       => 'اصلاح مدیر',
                ];
                foreach ($activity as $row): ?>
                    <tr>
                        <td><?= e(fa((int) $row['amount'])) ?></td>
                        <td><?= e($typeNames[$row['type']] ?? $row['type']) ?></td>
                        <td><?= e(jdate($row['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
