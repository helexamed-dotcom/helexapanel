<?php
/**
 * The mind map library.
 *
 * @var array $rows
 * @var array $reads   map id => opened_at / done_at
 * @var array $held    package ids the student holds
 * @var array $filters
 * @var array $subjects
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$done = count(array_filter($reads, static fn ($r) => !empty($r['done_at'])));
?>
<div class="ml">
    <section class="ml-hero">
        <div>
            <h2>نقشه‌های ذهنی</h2>
            <p><?= e(fa((string) count($rows))) ?> نقشه · <?= e(fa((string) $done)) ?> مرورشده — بزرگ کن، شاخه‌ها را باز و بسته کن، روی هر موضوع بزن.</p>
        </div>
        <form class="lb-search" method="get" action="/student/mindmaps" role="search">
            <?php $icon('search', 18); ?>
            <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="جستجو در نقشه‌ها…" aria-label="جستجو">
        </form>
    </section>

    <?php if ($subjects !== []): ?>
        <nav class="lb-chips" aria-label="فیلتر">
            <a class="lb-chip<?= !$filters['subject'] ? ' is-on' : '' ?>" href="/student/mindmaps">همه</a>
            <?php foreach ($subjects as $s): ?>
                <a class="lb-chip<?= $filters['subject'] === $s['id'] ? ' is-on' : '' ?>" href="/student/mindmaps?subject=<?= (int) $s['id'] ?>"><?= e($s['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <div class="lb-empty"><span class="app-ic tone-violet"><?php $icon('mindmap'); ?></span><b>نقشه‌ای پیدا نشد</b><small>به‌زودی نقشه‌های تازه اضافه می‌شود.</small></div>
    <?php else: ?>
        <div class="ml-grid">
            <?php foreach ($rows as $i => $m):
                $locked = !empty($m['package_id']) && !isset($held[(int) $m['package_id']]);
                $r = $reads[(int) $m['id']] ?? null; ?>
                <a class="ml-card tone-<?= e($m['tone']) ?> hx-zoom" href="<?= $locked ? '/shop' : '/student/mindmaps/' . e($m['uuid']) ?>" style="--i: <?= min($i, 12) ?>">
                    <span class="ml-art"><?php View::partial('partials.mindmap_art', ['map' => $m]); ?>
                        <?php if (!empty($r['done_at'])): ?><em>✓ مرور شد</em><?php elseif ($locked): ?><em class="is-lock">🔒 پکیج</em><?php endif; ?></span>
                    <span class="ml-body">
                        <small><?= e(implode(' › ', array_filter([$m['parent_title'] ?? '', $m['subject_title'] ?? '']))) ?: 'نقشه ذهنی' ?></small>
                        <b><?= e($m['title']) ?></b>
                        <span><?= !empty($m['summary']) ? e($m['summary']) . ' · ' : '' ?><?= e(fa((string) $m['node_count'])) ?> موضوع</span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
