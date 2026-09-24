<?php
/**
 * A lesson as a game map: the stages sit on a winding road, the student's
 * avatar stands on the stage they are at and walks on after each one.
 *
 * Without JavaScript the map is still a readable, clickable column of
 * stages; the road and the walking avatar are drawn by balin-map.js.
 *
 * @var array  $lesson
 * @var array  $stages  each carrying state, lock_reason and gating_exam
 * @var array  $exams   grouped by position and anchor stage
 * @var array  $summary
 * @var array  $avatar  ['image' => ?string, 'emoji' => string]
 * @var string $player
 */
use HeleXa\Services\Balin\StageGate;

$labels = [
    StageGate::LOCKED      => 'قفل',
    StageGate::AVAILABLE   => 'آماده',
    StageGate::IN_PROGRESS => 'در جریان',
    StageGate::COMPLETED   => 'کامل',
];

// The road, in order: exams before a stage, the stage, exams after it, and
// the finish at the end.
$track = [];
$examStep = static function (array $exam): array {
    return [
        'kind'  => 'exam',
        'state' => $exam['passed'] ? 'passed' : 'open',
        'title' => (string) $exam['title'],
        'sub'   => (int) $exam['is_gating'] === 1 && !$exam['passed'] ? 'برای باز شدن مرحله بعد لازم است' : ($exam['passed'] ? 'قبول شدی ✓' : 'آزمون مهارتی'),
        'url'   => '/student/balin/exam/' . $exam['uuid'],
    ];
};
$number = 0;
foreach ($stages as $stage) {
    $sid = (int) $stage['id'];
    foreach ($exams['before'][$sid] ?? [] as $exam) {
        $track[] = $examStep($exam);
    }
    $number++;
    $track[] = [
        'kind'   => 'stage',
        'state'  => $stage['state'],
        'number' => $number,
        'title'  => (string) $stage['title'],
        'sub'    => (string) ($stage['subtitle'] ?? ''),
        'url'    => $stage['state'] === StageGate::LOCKED ? null : '/student/balin/stage/' . $stage['uuid'],
        'lock'   => (string) ($stage['lock_reason'] ?? ''),
        'final'  => (int) $stage['is_final_case'] === 1,
        'xp'     => (int) $stage['xp_reward'],
        'min'    => (int) $stage['estimated_minutes'],
    ];
    foreach ($exams['after'][$sid] ?? [] as $exam) {
        $track[] = $examStep($exam);
    }
}
foreach ($exams['end'][0] ?? [] as $exam) {
    $track[] = $examStep($exam);
}

$total     = max(1, (int) $summary['stages_total']);
$done      = (int) $summary['stages_completed'];
$percent   = (int) round($done * 100 / $total);
$allDone   = $stages !== [] && $done >= (int) $summary['stages_total'];
$track[]   = ['kind' => 'goal', 'state' => $allDone ? 'reached' : 'ahead', 'title' => $allDone ? 'درس را تمام کردی! 🎉' : 'پایان درس', 'sub' => $allDone ? 'همه مرحله‌ها کامل شد' : 'همه مرحله‌ها را کامل کن'];

// Where the avatar stands: the first stage still to do, or the finish.
$current = count($track) - 1;
foreach ($track as $i => $step) {
    if ($step['kind'] === 'stage' && in_array($step['state'], [StageGate::AVAILABLE, StageGate::IN_PROGRESS], true)) {
        $current = $i;
        break;
    }
}
$currentUrl = $track[$current]['url'] ?? null;

// A gentle S-curve across the width, repeating every eight steps.
$wave = [50, 72, 84, 72, 50, 28, 16, 28];
$seaLife = ['⛵', '🐬', '🐠', '🚤', '🐳', '🦀'];
?>
<div class="balin bgame">
    <nav class="balin-crumb" aria-label="مسیر">
        <a href="/student/balin">جزیره بالین</a>
        <span aria-hidden="true">›</span>
        <span><?= e($lesson['title']) ?></span>
    </nav>

    <section class="bgame-hud" <?= $lesson['color'] ? 'style="--lesson-accent:' . e($lesson['color']) . '"' : '' ?>>
        <div class="bgame-hud-main">
            <span class="bgame-hud-icon" aria-hidden="true"><?= e($lesson['icon'] ?: '🩺') ?></span>
            <div class="bgame-hud-text">
                <h2><?= e($lesson['title']) ?></h2>
                <?php if ($lesson['description']): ?><p><?= e($lesson['description']) ?></p><?php endif; ?>
            </div>
            <div class="bgame-ring" style="--p: <?= $percent ?>;" role="img" aria-label="پیشرفت <?= e(fa($percent)) ?> درصد">
                <span><?= e(fa($percent)) ?>٪</span>
            </div>
        </div>
        <div class="bgame-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $percent ?>">
            <i style="width: <?= $percent ?>%;"></i>
        </div>
        <div class="bgame-hud-stats">
            <span>🏁 <?= e(fa($done)) ?> از <?= e(fa((int) $summary['stages_total'])) ?> مرحله</span>
            <span>✅ <?= e(fa((int) $summary['correct'])) ?> پاسخ درست</span>
            <span>🎯 دقت <?= e(fa(round((float) $summary['accuracy']))) ?>٪</span>
            <span>🧠 تسلط <?= e(fa(round((float) $summary['mastery']))) ?>٪</span>
            <?php \HeleXa\Core\View::partial('partials.study_mark_button', [
                'kind' => 'balin_lesson', 'refId' => (int) $lesson['id'], 'title' => '🏝️ ' . $lesson['title'],
                'url' => '/student/balin/lesson/' . $lesson['uuid'],
            ]); ?>
        </div>
    </section>

    <?php if ($stages === []): ?>
        <div class="empty">هنوز مرحله‌ای برای این درس منتشر نشده است.</div>
    <?php else: ?>
        <div class="bgame-world" data-bgame-world data-lesson="<?= e($lesson['uuid']) ?>" data-current="<?= (int) $current ?>">
            <div class="bgame-sky" aria-hidden="true">
                <span class="bgame-sun"></span>
                <span class="bgame-cloud c1"></span><span class="bgame-cloud c2"></span><span class="bgame-cloud c3"></span>
                <span class="bgame-start">🏁 شروع ماجرا</span>
            </div>

            <svg class="bgame-road" data-bgame-road aria-hidden="true" focusable="false">
                <path class="bgame-road-edge" data-road></path>
                <path class="bgame-road-fill" data-road></path>
                <path class="bgame-road-dash" data-road></path>
                <path class="bgame-road-done" data-road-done></path>
            </svg>

            <ol class="bgame-track">
                <?php foreach ($track as $i => $step):
                    $x      = $wave[$i % count($wave)];
                    $left   = $x > 50 || ($x === 50 && $i % 8 === 0);
                    $state  = $step['state'];
                    $isHere = $i === $current;
                    $url    = $step['url'] ?? null;
                    // The map shows badges only. Stage titles used to sit
                    // beside them and made the road hard to follow; the title
                    // is on the stage itself, and here in the aria-label.
                    $classes = 'bgame-step kind-' . $step['kind'] . ' is-' . $state
                        . ($isHere ? ' is-current' : '')
                        . (!empty($step['final']) ? ' is-final' : '');
                ?>
                    <li class="<?= e($classes) ?>" style="--x: <?= $x ?>;" data-step="<?= (int) $i ?>">
                        <?php if ($i % 3 === 1): ?>
                            <span class="bgame-sea <?= $left ? 'at-right' : 'at-left' ?>" aria-hidden="true"><?= e($seaLife[intdiv($i, 3) % count($seaLife)]) ?></span>
                        <?php endif; ?>

                        <?php
                        $face = match ($step['kind']) {
                            'exam'  => $state === 'passed' ? '🏅' : '🎯',
                            'goal'  => '🏆',
                            default => null,
                        };
                        $tag  = $url !== null ? 'a' : 'span';
                        $aria = $step['kind'] === 'stage'
                            ? 'مرحله ' . fa((int) $step['number']) . ': ' . $step['title'] . ' — ' . ($labels[$state] ?? '')
                            : $step['title'];
                        ?>
                        <<?= $tag ?> class="bgame-node"<?= $url !== null ? ' href="' . e($url) . '"' : ' aria-disabled="true"' ?>
                           aria-label="<?= e($aria) ?>" title="<?= e($aria) ?>">
                            <span class="bgame-node-face">
                                <?php if ($face !== null): ?>
                                    <span class="bgame-node-emoji"><?= e($face) ?></span>
                                <?php elseif ($state === StageGate::COMPLETED): ?>
                                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'check', 'size' => 30]); ?>
                                <?php elseif ($state === StageGate::LOCKED): ?>
                                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'lock', 'size' => 24]); ?>
                                <?php else: ?>
                                    <b><?= e(fa((int) $step['number'])) ?></b>
                                <?php endif; ?>
                            </span>
                            <?php if (!empty($step['final'])): ?><span class="bgame-crown" aria-hidden="true">👑</span><?php endif; ?>
                            <?php if ($state === StageGate::COMPLETED): ?><span class="bgame-stars" aria-hidden="true">⭐</span><?php endif; ?>
                        </<?= $tag ?>>

                    </li>
                <?php endforeach; ?>
            </ol>

            <<?= $currentUrl !== null ? 'a href="' . e($currentUrl) . '"' : 'span' ?> class="bgame-avatar" data-avatar hidden
               aria-label="<?= e(($player !== '' ? $player : 'شما') . ' اینجا هستید') ?>">
                <span class="bgame-avatar-body">
                    <span class="bgame-avatar-tag"><?= $allDone ? 'قهرمان! 🏆' : ($currentUrl !== null ? 'بزن بریم!' : 'شما') ?></span>
                    <span class="bgame-avatar-img">
                        <?php if (!empty($avatar['image'])): ?>
                            <img src="/assets/<?= e($avatar['image']) ?>" alt="" draggable="false">
                        <?php else: ?>
                            <span><?= e($avatar['emoji'] ?? '🧑‍⚕️') ?></span>
                        <?php endif; ?>
                    </span>
                </span>
                <span class="bgame-avatar-shadow"></span>
            </<?= $currentUrl !== null ? 'a' : 'span' ?>>
        </div>
    <?php endif; ?>
</div>
