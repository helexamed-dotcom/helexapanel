<?php
/**
 * @var array $items
 * @var array $filters
 * @var array $kinds
 * @var int   $page
 * @var int   $pages
 * @var int   $total
 */
$kindIcon = ['video' => '🎬', 'article' => '📰', 'image' => '🖼', 'file' => '📎', 'link' => '🔗'];
$qs = static fn (array $over): string => '/student/library?' . http_build_query(array_filter($over + $filters, static fn ($v) => $v !== '' && $v !== null && $v !== 1));
?>
<div class="lib-page">
    <section class="lib-head card">
        <div>
            <h2>📚 کتابخانه</h2>
            <p class="muted">ویدیوها، مقاله‌ها، تصاویر و فایل‌های آموزشی — <?= e(fa((string) $total)) ?> مورد</p>
        </div>
        <form method="get" action="/student/library" class="lib-search" role="search">
            <?php if ($filters['kind'] !== ''): ?><input type="hidden" name="kind" value="<?= e($filters['kind']) ?>"><?php endif; ?>
            <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="جستجو در کتابخانه…" aria-label="جستجو">
            <button class="btn btn-primary" type="submit">جستجو</button>
        </form>
    </section>

    <nav class="seg-tabs lib-tabs" aria-label="نوع محتوا">
        <a class="<?= $filters['kind'] === '' ? 'is-active' : '' ?>" href="<?= e($qs(['kind' => '', 'page' => null])) ?>">همه</a>
        <?php foreach ($kinds as $k => $label): ?>
            <a class="<?= $filters['kind'] === $k ? 'is-active' : '' ?>" href="<?= e($qs(['kind' => $k, 'page' => null])) ?>"><?= $kindIcon[$k] ?> <?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($items === []): ?>
        <div class="empty"><?= $filters['q'] !== '' ? 'نتیجه‌ای برای «' . e($filters['q']) . '» پیدا نشد.' : 'هنوز محتوایی منتشر نشده است.' ?></div>
    <?php else: ?>
        <div class="lib-grid">
            <?php foreach ($items as $item): ?>
                <a class="lib-card" href="/student/library/<?= e($item['uuid']) ?>">
                    <span class="lib-cover">
                        <?php if ($item['cover_path'] || $item['kind'] === 'image'): ?>
                            <img src="/student/library/<?= e($item['uuid']) ?>/media/cover" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="lib-glyph"><?= $kindIcon[$item['kind']] ?? '📄' ?></span>
                        <?php endif; ?>
                        <span class="lib-kind"><?= $kindIcon[$item['kind']] ?? '' ?> <?= e($kinds[$item['kind']] ?? '') ?></span>
                        <?php if ($item['kind'] === 'video'): ?><span class="lib-play" aria-hidden="true">▶</span><?php endif; ?>
                    </span>
                    <span class="lib-body">
                        <h4><?= e($item['title']) ?></h4>
                        <?php if ($item['summary']): ?><p><?= e($item['summary']) ?></p><?php endif; ?>
                        <small class="muted"><?= $item['course_title'] ? e($item['course_title']) . ' · ' : '' ?><?= e(\HeleXa\Services\Jalali::longDate((int) strtotime((string) $item['created_at']))) ?></small>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="lib-pager" aria-label="صفحه‌ها">
                <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($qs(['page' => $page - 1])) ?>">قبلی</a><?php endif; ?>
                <span class="muted">صفحه <?= e(fa((string) $page)) ?> از <?= e(fa((string) $pages)) ?></span>
                <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($qs(['page' => $page + 1])) ?>">بعدی</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>