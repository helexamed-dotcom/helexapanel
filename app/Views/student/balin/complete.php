<?php
/**
 * Finishing a stage: what was earned, and where to go next.
 *
 * @var array $stage
 * @var array $lesson
 * @var array $result
 * @var array $summary
 * @var array $profile
 */
$rank = $profile['rank'];
?>
<div class="balin balin-complete">
    <section class="balin-complete-card">
        <div class="balin-complete-mark" aria-hidden="true">🎉</div>

        <h2>
            <?php if ($result['lesson_completed']): ?>
                مسیر «<?= e($lesson['title']) ?>» را کامل کردی
            <?php else: ?>
                مرحله «<?= e($stage['title']) ?>» کامل شد
            <?php endif; ?>
        </h2>

        <?php if (!$result['first_time']): ?>
            <p class="balin-complete-note">این مرحله را قبلاً کامل کرده بودی، پس امتیاز جدیدی ثبت نشد.</p>
        <?php endif; ?>

        <div class="balin-complete-stats">
            <?php if ($result['xp_awarded'] > 0): ?>
                <div><strong><?= e(fa((int) $result['xp_awarded'])) ?>+</strong><span>امتیاز مرحله</span></div>
            <?php endif; ?>
            <?php if ($result['lesson_xp'] > 0): ?>
                <div><strong><?= e(fa((int) $result['lesson_xp'])) ?>+</strong><span>امتیاز درس</span></div>
            <?php endif; ?>
            <div><strong><?= e(fa(round((float) $summary['accuracy']))) ?>٪</strong><span>دقت</span></div>
            <div><strong><?= e(fa(round((float) $summary['mastery']))) ?>٪</strong><span>تسلط</span></div>
        </div>

        <div class="balin-complete-rank">
            <span aria-hidden="true"><?= e($rank['icon'] ?: '🏝️') ?></span>
            <?= e($rank['title']) ?> · <?= e(fa($profile['level']['xp'])) ?> امتیاز
        </div>

        <div class="balin-complete-actions">
            <a class="btn btn-primary" href="/student/balin/lesson/<?= e($lesson['uuid']) ?>">ادامه مسیر</a>
            <a class="btn btn-ghost" href="/student/balin/stage/<?= e($stage['uuid']) ?>">مرور دوباره</a>
        </div>
    </section>
</div>
