<?php
/**
 * A درس before its questions: the زیردرس‌ها and عنوان‌ها, how far the
 * student has come in each, and a way to practise exactly the part they
 * want — only the new ones, only the wrong ones, or everything.
 *
 * @var array $subject
 * @var array $outline  total, done, right, wrong, children
 * @var array $marks    saved, review
 * @var bool  $lesson   the درس has a درسنامه
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$base = '/student/qbank/' . rawurlencode((string) $subject['uuid']);
$pct  = static fn (int $a, int $b): int => $b > 0 ? (int) min(100, round($a * 100 / $b)) : 0;
$cover = $pct($outline['done'], $outline['total']);
$acc   = $outline['done'] > 0 ? $pct($outline['right'], $outline['done']) : null;
$tones = ['violet', 'blue', 'teal', 'rose', 'amber', 'indigo', 'green', 'orange', 'sky', 'pink'];
?>
<div class="qs">
    <section class="qs-hero">
        <div class="qs-ring" style="--p: <?= $cover ?>">
            <svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52"/><circle cx="60" cy="60" r="52" pathLength="100"/></svg>
            <div><b><?= e(fa((string) $cover)) ?>٪</b><small>پوشش</small></div>
        </div>
        <div class="qs-hero-text">
            <a class="qs-back" href="/student/qbank"><?php $icon('back', 15); ?> همه درس‌ها</a>
            <h2><?= e($subject['title']) ?></h2>
            <p><?= e(fa((string) $outline['done'])) ?> از <?= e(fa((string) $outline['total'])) ?> سوال را جواب داده‌ای<?= $acc !== null ? ' · دقت ' . e(fa((string) $acc)) . '٪' : '' ?></p>
            <div class="qs-actions">
                <a class="btn qs-go" href="<?= $base ?>?n=1"><?php $icon('play', 16); ?> تمرین همه</a>
                <a class="qs-pill" href="<?= $base ?>?mode=new">جدیدها <b><?= e(fa((string) max(0, $outline['total'] - $outline['done']))) ?></b></a>
                <a class="qs-pill is-red" href="<?= $base ?>?mode=wrong">غلط‌ها <b><?= e(fa((string) $outline['wrong'])) ?></b></a>
                <a class="qs-pill" href="<?= $base ?>?mode=saved">نشان‌شده <b><?= e(fa((string) $marks['saved'])) ?></b></a>
                <a class="qs-pill" href="<?= $base ?>?mode=review">برای مرور <b><?= e(fa((string) $marks['review'])) ?></b></a>
                <a class="qs-pill" href="<?= $base ?>/list"><?php $icon('list', 14); ?> فهرست سوال‌ها</a>
            </div>
        </div>
    </section>

    <?php if ($outline['children'] === []): ?>
        <div class="qs-empty">این درس هنوز زیردرس ندارد؛ <a href="<?= $base ?>?n=1">همه سوال‌ها را تمرین کن</a>.</div>
    <?php else: ?>
        <h3 class="qs-title">زیردرس‌ها</h3>
        <div class="qs-grid">
            <?php foreach ($outline['children'] as $i => $c):
                $cp = $pct($c['done'], $c['total']);
                $ca = $c['done'] > 0 ? $pct($c['right'], $c['done']) : null;
                $href = (int) $c['id'] > 0 ? $base . '?sub=' . (int) $c['id'] : $base . '?n=1'; ?>
                <article class="qs-card tone-<?= e($tones[$i % count($tones)]) ?><?= $c['total'] === 0 ? ' is-empty' : '' ?>" style="--i: <?= $i ?>">
                    <a class="qs-card-main" href="<?= e($href) ?>">
                        <span class="app-ic"><b><?= e(fa((string) ($i + 1))) ?></b></span>
                        <span class="qs-card-text">
                            <b><?= e($c['title']) ?></b>
                            <small><?= e(fa((string) $c['total'])) ?> سوال<?= $ca !== null ? ' · دقت ' . e(fa((string) $ca)) . '٪' : '' ?><?= $c['wrong'] > 0 ? ' · ' . e(fa((string) $c['wrong'])) . ' غلط' : '' ?></small>
                        </span>
                        <span class="qs-card-play"><?php $icon('play', 16); ?></span>
                    </a>
                    <div class="qs-bar" title="<?= e(fa((string) $cp)) ?>٪"><i style="width: <?= $cp ?>%"></i><?php if ($c['done'] > 0): ?><i class="is-right" style="width: <?= $pct($c['right'], $c['total']) ?>%"></i><?php endif; ?></div>
                    <?php if ($c['children'] !== []): ?>
                        <details class="qs-topics">
                            <summary><?= e(fa((string) count($c['children']))) ?> عنوان</summary>
                            <ul>
                                <?php foreach ($c['children'] as $t): $tp = $pct($t['done'], $t['total']); ?>
                                    <li>
                                        <a href="<?= $base ?>?sub=<?= (int) $c['id'] ?>&topic=<?= (int) $t['id'] ?>">
                                            <span class="qs-dot" style="--p: <?= $tp ?>"></span>
                                            <span><?= e($t['title']) ?></span>
                                            <small><?= e(fa((string) $t['done'])) ?>/<?= e(fa((string) $t['total'])) ?></small>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
