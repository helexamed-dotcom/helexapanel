<?php
/**
 * One ready-made course: its sessions, with the student's progress in each.
 *
 * @var array $course
 * @var array $decks
 * @var array $deckStats
 */
$totalDue = 0;
foreach ($deckStats as $s) {
    $totalDue += $s['due'];
}
?>
<div class="fc-page">
    <section class="fc-hero fc-c-<?= e($course['color']) ?>"
             style="background: radial-gradient(80% 120% at 100% 0%, rgba(255,255,255,.22), transparent 60%), linear-gradient(135deg, var(--fc-a), var(--fc-b));">
        <div style="position:relative; z-index:1;">
            <h2><?= e($course['icon'] ?: '📘') ?> <?= e($course['title']) ?></h2>
            <?php if (!empty($course['description'])): ?><p><?= e($course['description']) ?></p><?php endif; ?>
            <div class="fc-pills">
                <span class="fc-pill">📚 <b><?= e(fa((string) count($decks))) ?></b> جلسه</span>
                <span class="fc-pill">⏰ موعد مرور <b><?= e(fa((string) $totalDue)) ?></b></span>
            </div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; position:relative; z-index:1;">
            <a class="btn btn-light" href="/student/flashcards/study/course/<?= e($course['uuid']) ?>">مطالعه کل درس</a>
            <a class="btn btn-ghost" href="/student/flashcards">بازگشت</a>
        </div>
    </section>

    <?php if ($decks === []): ?>
        <div class="fc-panel fc-empty"><div class="fc-big">🗂️</div><strong>هنوز جلسه‌ای در این درس نیست.</strong></div>
    <?php else: ?>
        <div class="fc-decks">
            <?php foreach ($decks as $i => $deck):
                $s     = $deckStats[(int) $deck['id']] ?? ['seen' => 0, 'mastered' => 0, 'due' => 0];
                $count = (int) $deck['card_count'];
                $pct   = $count > 0 ? (int) round($s['seen'] * 100 / $count) : 0;
                $done  = $count > 0 && $s['mastered'] >= $count;
            ?>
                <a class="fc-deck fc-c-<?= e($course['color']) ?>" href="/student/flashcards/deck/<?= e($deck['uuid']) ?>">
                    <div class="fc-deck-row">
                        <span class="fc-deck-num"><?= $done ? '✓' : e(fa((string) ($i + 1))) ?></span>
                        <?php if ($s['due'] > 0): ?><span class="fc-due-chip"><?= e(fa((string) $s['due'])) ?> مرور</span><?php endif; ?>
                    </div>
                    <h4><?= e($deck['title']) ?></h4>
                    <span class="fc-deck-meta"><?= e(fa((string) $count)) ?> کارت · دیده‌شده <?= e(fa((string) $pct)) ?>٪</span>
                    <div class="fc-progress"><i style="width: <?= $pct ?>%;"></i></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
