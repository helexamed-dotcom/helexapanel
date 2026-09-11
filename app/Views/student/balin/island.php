<?php
/**
 * The island: every published clinical lesson, with this student's standing
 * across the top.
 *
 * @var array $lessons
 * @var array $profile
 */
$rank  = $profile['rank'];
$level = $profile['level'];
?>
<div class="balin">
    <section class="balin-hero">
        <div class="balin-hero-rank">
            <span class="balin-hero-icon" aria-hidden="true"><?= e($rank['icon'] ?: '🏝️') ?></span>
            <div>
                <div class="balin-hero-title"><?= e($rank['title']) ?></div>
                <div class="balin-hero-sub">
                    سطح <?= e(fa($level['level'])) ?> ·
                    <?= e(fa($level['xp'])) ?> امتیاز
                </div>
            </div>
        </div>

        <div class="balin-xpbar" role="progressbar"
             aria-valuenow="<?= (int) $level['percent'] ?>" aria-valuemin="0" aria-valuemax="100"
             aria-label="پیشرفت تا سطح بعد">
            <span style="width:<?= (float) $level['percent'] ?>%"></span>
        </div>
        <div class="balin-xpbar-note">
            <?= e(fa($level['xp_for_next'])) ?> امتیاز تا سطح <?= e(fa($level['level'] + 1)) ?>
        </div>

        <div class="balin-hero-stats">
            <div><strong><?= e(fa((int) $profile['stats']['stages_completed'])) ?></strong><span>مرحله</span></div>
            <div><strong><?= e(fa((int) $profile['streak']['current_streak'])) ?></strong><span>روز پیاپی</span></div>
            <div><strong><?= e(fa(round((float) $profile['stats']['accuracy_percent']))) ?>٪</strong><span>دقت</span></div>
        </div>

        <div class="balin-hero-links">
            <a class="btn btn-ghost btn-sm" href="/student/balin/profile">پروفایل بالینی</a>
            <a class="btn btn-ghost btn-sm" href="/student/balin/leaderboard">جدول رتبه‌بندی</a>
        </div>
    </section>

    <?php if ($lessons === []): ?>
        <div class="empty">هنوز درسی منتشر نشده است. به‌زودی سر می‌زنیم.</div>
    <?php else: ?>
        <div class="balin-lessons">
            <?php foreach ($lessons as $lesson):
                $total = (int) $lesson['stage_count'];
                $done  = (int) $lesson['completed_count'];
                $pct   = $total > 0 ? (int) round($done / $total * 100) : 0;
            ?>
                <a class="balin-lesson-card" href="/student/balin/lesson/<?= e($lesson['uuid']) ?>"
                   <?= $lesson['color'] ? 'style="--lesson-accent:' . e($lesson['color']) . '"' : '' ?>>
                    <div class="balin-lesson-icon" aria-hidden="true"><?= e($lesson['icon'] ?: '🩺') ?></div>

                    <div class="balin-lesson-body">
                        <h3 class="balin-lesson-title"><?= e($lesson['title']) ?></h3>
                        <?php if ($lesson['description']): ?>
                            <p class="balin-lesson-desc"><?= e(mb_substr((string) $lesson['description'], 0, 90, 'UTF-8')) ?></p>
                        <?php endif; ?>

                        <div class="balin-lesson-meta">
                            <span><?= e(fa($done)) ?>/<?= e(fa($total)) ?> مرحله</span>
                            <?php if ((int) $lesson['estimated_minutes'] > 0): ?>
                                <span>~<?= e(fa((int) $lesson['estimated_minutes'])) ?> دقیقه</span>
                            <?php endif; ?>
                            <?php if ((float) $lesson['mastery_percent'] > 0): ?>
                                <span>تسلط <?= e(fa(round((float) $lesson['mastery_percent']))) ?>٪</span>
                            <?php endif; ?>
                        </div>

                        <div class="balin-progress" role="progressbar"
                             aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                            <span style="width:<?= $pct ?>%"></span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
