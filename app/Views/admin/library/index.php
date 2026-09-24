<?php
/**
 * @var array $items
 * @var array $filters
 * @var array $kinds
 */
$kindIcon = ['video' => '🎬', 'article' => '📰', 'image' => '🖼', 'file' => '📎', 'link' => '🔗'];
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'folder']); ?></div>
            <div>
                <h2>محتوای آموزشی</h2>
                <p>ویدیو، مقاله، تصویر، فایل و لینک — هر مورد با تصویر پیش‌نمایش. دانشجویان در کتابخانه جستجو و مشاهده می‌کنند.</p>
            </div>
        </div>
        <div class="qb-hero-actions">
            <a class="btn btn-ghost" href="/admin/content">جزوه‌های دوره‌ها</a>
            <a class="btn btn-primary" href="/admin/library/create">+ افزودن محتوا</a>
        </div>
    </section>

    <form method="get" action="/admin/library" class="qb-section qb-filters">
        <div class="field" style="flex:1 1 220px;"><label class="label" for="lf-q">جستجو</label>
            <input class="input" id="lf-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="عنوان، خلاصه یا متن…"></div>
        <div class="field" style="flex:0 1 140px;"><label class="label" for="lf-k">نوع</label>
            <select class="input" id="lf-k" name="kind"><option value="">همه</option>
                <?php foreach ($kinds as $k => $label): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
        <div class="field" style="flex:0 1 140px;"><label class="label" for="lf-s">وضعیت</label>
            <select class="input" id="lf-s" name="status"><option value="">همه</option>
                <option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>منتشرشده</option>
                <option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
            </select></div>
        <div class="row-actions" style="margin:0;"><button class="btn btn-primary btn-sm" type="submit">اعمال</button></div>
    </form>

    <?php if ($items === []): ?>
        <div class="empty">محتوایی پیدا نشد.</div>
    <?php else: ?>
        <div class="lib-grid">
            <?php foreach ($items as $item): ?>
                <article class="lib-card">
                    <a class="lib-cover" href="/admin/library/<?= e($item['uuid']) ?>/edit">
                        <?php if ($item['cover_path'] || $item['kind'] === 'image'): ?>
                            <img src="/admin/library/<?= e($item['uuid']) ?>/media/cover" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="lib-glyph"><?= $kindIcon[$item['kind']] ?? '📄' ?></span>
                        <?php endif; ?>
                        <span class="lib-kind"><?= $kindIcon[$item['kind']] ?? '' ?> <?= e($kinds[$item['kind']] ?? '') ?></span>
                    </a>
                    <div class="lib-body">
                        <h4><?= e($item['title']) ?></h4>
                        <div class="qb-row-meta">
                            <span class="stat-chip <?= $item['status'] === 'published' ? 'chip-green' : 'chip-gray' ?>"><?= $item['status'] === 'published' ? 'منتشرشده' : 'پیش‌نویس' ?></span>
                            <span><?= $item['course_title'] ? e($item['course_title']) : 'همه دانشجویان' ?></span>
                            <span>👁 <?= e(fa((string) $item['view_count'])) ?></span>
                        </div>
                        <div class="row-actions" style="margin:8px 0 0;">
                            <a class="btn btn-ghost btn-sm" href="/admin/library/<?= e($item['uuid']) ?>/edit">ویرایش</a>
                            <form method="post" action="/admin/library/<?= e($item['uuid']) ?>/delete" data-confirm="این محتوا حذف شود؟">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>