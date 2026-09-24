<?php
/**
 * The درسنامه library.
 *
 * @var array $rows
 * @var array $held      package ids the student holds
 * @var array $states    lesson id => progress / bookmarked / read_at
 * @var array $filters
 * @var array $subjects
 * @var array $tags
 * @var array $counts     lesson id => [pages, sections]
 * @var array $pagesRead  lesson id => pages finished
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$done = count(array_filter($states, static fn ($s) => !empty($s['read_at'])));
$marked = array_values(array_filter($rows, static fn ($l) => !empty($states[(int) $l['id']]['bookmarked'])));
?>
<div class="lb">
    <section class="hx-pagebar">
        <span class="app-ic tone-indigo"><?php $icon('lesson', 18); ?></span>
        <div class="hx-pagebar-text">
            <h2>درسنامه‌ها</h2>
            <small><?= e(fa((string) count($rows))) ?> درسنامه · <?= e(fa((string) $done)) ?> تمام‌شده</small>
        </div>
        <form class="hx-pagebar-search" method="get" action="/student/lessons" role="search">
            <?php $icon('search', 16); ?>
            <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="جستجو در درسنامه‌ها…" aria-label="جستجو">
            <?php if ($filters['subject']): ?><input type="hidden" name="subject" value="<?= (int) $filters['subject'] ?>"><?php endif; ?>
        </form>
    </section>

    <?php if ($subjects !== [] || $tags !== []): ?>
        <nav class="lb-chips" aria-label="فیلتر">
            <a class="lb-chip<?= !$filters['subject'] && !$filters['tag'] ? ' is-on' : '' ?>" href="/student/lessons">همه</a>
            <?php foreach ($subjects as $s): ?>
                <a class="lb-chip<?= $filters['subject'] === $s['id'] ? ' is-on' : '' ?>" href="/student/lessons?subject=<?= (int) $s['id'] ?>"><?= e($s['title']) ?></a>
            <?php endforeach; ?>
            <?php foreach (array_slice($tags, 0, 12) as $t): ?>
                <a class="lb-chip is-tag<?= $filters['tag'] === (int) $t['id'] ? ' is-on' : '' ?>" href="/student/lessons?tag=<?= (int) $t['id'] ?>">#<?= e($t['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($marked !== [] && !$filters['q'] && !$filters['subject'] && !$filters['tag']): ?>
        <h3 class="lb-title"><?php $icon('bookmark', 17); ?> نشان‌شده‌ها</h3>
        <div class="lb-shelf">
            <?php foreach ($marked as $l): ?>
                <a class="lb-mini tone-<?= e($l['color']) ?> hx-zoom" href="/student/lessons/<?= e($l['uuid']) ?>"><span class="app-ic"><?php $icon('lesson', 18); ?></span><b><?= e($l['title']) ?></b></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <div class="lb-empty"><span class="app-ic tone-indigo"><?php $icon('lesson'); ?></span><b>درسنامه‌ای پیدا نشد</b><small>به‌زودی درسنامه‌های جدید اضافه می‌شود.</small></div>
    <?php else: ?>
        <div class="lb-grid">
            <?php foreach ($rows as $i => $l):
                $st = $states[(int) $l['id']] ?? null;
                $locked = !empty($l['package_id']) && !isset($held[(int) $l['package_id']]);
                $pc = $counts[(int) $l['id']] ?? ['pages' => 0, 'sections' => 0];
                $progress = $pc['pages'] > 0 ? (int) round(($pagesRead[(int) $l['id']] ?? 0) * 100 / $pc['pages']) : (int) ($st['progress'] ?? 0); ?>
                <a class="lb-card tone-<?= e($l['color']) ?><?= $locked ? ' is-locked' : '' ?> hx-zoom" href="<?= $locked ? '/shop' : '/student/lessons/' . e($l['uuid']) ?>" style="--i: <?= min($i, 12) ?>">
                    <span class="lb-cover">
                        <?php if (!empty($l['cover_path'])): ?>
                            <img src="/media/lessons/<?= e($l['cover_path']) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="lb-cover-art"><?php $icon('lesson', 34); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($st['read_at'])): ?><em class="lb-done">✓ خوانده شد</em><?php elseif ($locked): ?><em class="lb-done is-lock"><?php $icon('lock', 13); ?> پکیج</em><?php endif; ?>
                    </span>
                    <span class="lb-body">
                        <small><?= e(implode(' › ', array_filter([$l['parent_title'] ?? '', $l['subject_title'] ?? '']))) ?: 'درسنامه' ?></small>
                        <b><?= e($l['title']) ?></b>
                        <?php if (!empty($l['summary'])): ?><span class="lb-sum"><?= e($l['summary']) ?></span><?php endif; ?>
                        <span class="lb-meta">
                            <span><?php $icon('clock', 13); ?> <?= e(fa((string) $l['reading_minutes'])) ?> دقیقه</span>
                            <?php if ($pc['pages'] > 0): ?><span><?= e(fa((string) $pc['sections'])) ?> زیردرس، <?= e(fa((string) $pc['pages'])) ?> صفحه</span><?php endif; ?>
                            <?php if ($progress > 0 && $progress < 100): ?><span class="lb-prog"><i style="width: <?= $progress ?>%"></i></span><?php endif; ?>
                        </span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
