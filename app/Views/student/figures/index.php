<?php
/**
 * The shelf of figures.
 *
 * @var array $rows
 * @var array $best     figure id => best percent / rounds
 * @var array $held
 * @var array $filters
 * @var array $subjects
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
?>
<div class="ml">
    <section class="ml-hero fg-hero">
        <div>
            <h2>بازی با شکل</h2>
            <p>ساختار را روی تصویر پیدا کن، یا به نقطه‌ای که نشانت می‌دهیم جواب بده. هر پاسخ درست امتیاز دارد ⚡</p>
        </div>
        <form class="lb-search" method="get" action="/student/figures" role="search">
            <?php $icon('search', 18); ?>
            <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="جستجو…" aria-label="جستجو">
        </form>
    </section>

    <?php if ($subjects !== []): ?>
        <nav class="lb-chips" aria-label="فیلتر">
            <a class="lb-chip<?= !$filters['subject'] ? ' is-on' : '' ?>" href="/student/figures">همه</a>
            <?php foreach ($subjects as $s): ?>
                <a class="lb-chip<?= $filters['subject'] === $s['id'] ? ' is-on' : '' ?>" href="/student/figures?subject=<?= (int) $s['id'] ?>"><?= e($s['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <div class="lb-empty"><span class="app-ic tone-amber"><?php $icon('figure'); ?></span><b>هنوز شکلی منتشر نشده</b><small>به‌زودی اضافه می‌شود.</small></div>
    <?php else: ?>
        <div class="ml-grid">
            <?php foreach ($rows as $i => $f):
                $locked = !empty($f['package_id']) && !isset($held[(int) $f['package_id']]);
                $b = $best[(int) $f['id']] ?? null; ?>
                <a class="ml-card tone-<?= e($f['tone']) ?> hx-zoom" href="<?= $locked ? '/shop' : '/student/figures/' . e($f['uuid']) ?>" style="--i: <?= min($i, 12) ?>">
                    <span class="ml-art fg-art"><img src="/media/figures/<?= e($f['image_path']) ?>" alt="" loading="lazy">
                        <?php if ($locked): ?><em class="is-lock">🔒 پکیج</em><?php elseif ($b): ?><em>بهترین: ٪<?= e(fa((string) $b['best'])) ?></em><?php endif; ?></span>
                    <span class="ml-body">
                        <small><?= e(implode(' › ', array_filter([$f['parent_title'] ?? '', $f['subject_title'] ?? '']))) ?: 'بازی با شکل' ?></small>
                        <b><?= e($f['title']) ?></b>
                        <span><?= e(fa((string) $f['spot_count'])) ?> ساختار<?= $b ? ' · ' . e(fa((string) $b['plays'])) . ' بار بازی' : '' ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
