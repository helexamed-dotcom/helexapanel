<?php
/**
 * A lesson's map: its stages in order, with the checkpoint exams drawn
 * between them rather than listed separately.
 *
 * @var array $lesson
 * @var array $stages  each carrying state, lock_reason and gating_exam
 * @var array $exams   grouped by position and anchor stage
 * @var array $summary
 */
use HeleXa\Services\Balin\StageGate;

$labels = [
    StageGate::LOCKED      => 'قفل',
    StageGate::AVAILABLE   => 'آماده',
    StageGate::IN_PROGRESS => 'در جریان',
    StageGate::COMPLETED   => 'کامل',
];

/** Renders the exam pills anchored to one stage. */
$renderExams = static function (array $list) use ($labels): void {
    foreach ($list as $exam): ?>
        <a class="balin-exam-node<?= $exam['passed'] ? ' is-passed' : '' ?>"
           href="/student/balin/exam/<?= e($exam['uuid']) ?>">
            <span class="balin-exam-mark" aria-hidden="true">🎯</span>
            <span class="balin-exam-body">
                <span class="balin-exam-title"><?= e($exam['title']) ?></span>
                <span class="balin-exam-meta">
                    آزمون مهارتی
                    <?php if ((int) $exam['is_gating'] === 1 && !$exam['passed']): ?>
                        · برای باز شدن مرحله بعد لازم است
                    <?php endif; ?>
                    <?php if ($exam['passed']): ?> · قبول شدی<?php endif; ?>
                </span>
            </span>
        </a>
    <?php endforeach;
};
?>
<div class="balin">
    <nav class="balin-crumb" aria-label="مسیر">
        <a href="/student/balin">جزیره بالین</a>
        <span aria-hidden="true">›</span>
        <span><?= e($lesson['title']) ?></span>
    </nav>

    <header class="balin-lesson-head" <?= $lesson['color'] ? 'style="--lesson-accent:' . e($lesson['color']) . '"' : '' ?>>
        <span class="balin-lesson-head-icon" aria-hidden="true"><?= e($lesson['icon'] ?: '🩺') ?></span>
        <div>
            <h2><?= e($lesson['title']) ?></h2>
            <?php if ($lesson['description']): ?>
                <p><?= e($lesson['description']) ?></p>
            <?php endif; ?>
        </div>
    </header>

    <div class="balin-summary">
        <div><strong><?= e(fa((int) $summary['stages_completed'])) ?>/<?= e(fa((int) $summary['stages_total'])) ?></strong><span>مرحله</span></div>
        <div><strong><?= e(fa((int) $summary['correct'])) ?></strong><span>پاسخ صحیح</span></div>
        <div><strong><?= e(fa(round((float) $summary['accuracy']))) ?>٪</strong><span>دقت</span></div>
        <div><strong><?= e(fa(round((float) $summary['mastery']))) ?>٪</strong><span>تسلط</span></div>
    </div>

    <?php if ($stages === []): ?>
        <div class="empty">هنوز مرحله‌ای برای این درس منتشر نشده است.</div>
    <?php else: ?>
        <ol class="balin-map">
            <?php foreach ($stages as $index => $stage):
                $state   = $stage['state'];
                $locked  = $state === StageGate::LOCKED;
                $stageId = (int) $stage['id'];
            ?>
                <?php $renderExams($exams['before'][$stageId] ?? []); ?>

                <li class="balin-node is-<?= e($state) ?>">
                    <div class="balin-node-rail" aria-hidden="true">
                        <span class="balin-node-dot">
                            <?php if ($state === StageGate::COMPLETED): ?>
                                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'check', 'size' => 16]); ?>
                            <?php elseif ($locked): ?>
                                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'lock', 'size' => 14]); ?>
                            <?php else: ?>
                                <?= e(fa($index + 1)) ?>
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php if ($locked): ?>
                        <div class="balin-node-card" aria-disabled="true">
                            <div class="balin-node-head">
                                <h3><?= e($stage['title']) ?></h3>
                                <span class="balin-chip chip-locked"><?= e($labels[$state]) ?></span>
                            </div>
                            <?php if ($stage['lock_reason']): ?>
                                <p class="balin-node-lock"><?= e($stage['lock_reason']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <a class="balin-node-card" href="/student/balin/stage/<?= e($stage['uuid']) ?>">
                            <div class="balin-node-head">
                                <h3><?= e($stage['title']) ?></h3>
                                <span class="balin-chip chip-<?= e($state) ?>"><?= e($labels[$state]) ?></span>
                            </div>
                            <?php if ($stage['subtitle']): ?>
                                <p class="balin-node-sub"><?= e($stage['subtitle']) ?></p>
                            <?php endif; ?>
                            <div class="balin-node-meta">
                                <?php if ((int) $stage['is_final_case'] === 1): ?>
                                    <span class="balin-chip chip-final">کیس نهایی</span>
                                <?php endif; ?>
                                <?php if ((int) $stage['xp_reward'] > 0): ?>
                                    <span><?= e(fa((int) $stage['xp_reward'])) ?> امتیاز</span>
                                <?php endif; ?>
                                <?php if ((int) $stage['estimated_minutes'] > 0): ?>
                                    <span>~<?= e(fa((int) $stage['estimated_minutes'])) ?> دقیقه</span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endif; ?>
                </li>

                <?php $renderExams($exams['after'][$stageId] ?? []); ?>
            <?php endforeach; ?>

            <?php $renderExams($exams['end'][0] ?? []); ?>
        </ol>
    <?php endif; ?>
</div>
