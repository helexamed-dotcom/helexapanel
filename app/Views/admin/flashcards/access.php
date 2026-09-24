<?php
/**
 * @var array  $students
 * @var array  $grants   user id → list of درس titles
 * @var string $search
 * @var int    $page
 * @var int    $pages
 * @var int    $total
 */
?>
<div class="qb-page">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'cards']); ?></div>
            <div>
                <h2>دسترسی دانشجویان به فلش‌کارت</h2>
                <p>پیش‌فرض هر دانشجو <strong>بدون دسترسی</strong> است. برای هر نفر مشخص کن کدام درس‌های فلش‌کارت برایش باز باشد. جلسه‌های جدید هر درس خودکار به دارندگانش می‌رسد.</p>
            </div>
        </div>
    </section>

    <section class="qb-section">
        <form method="get" action="/admin/flashcards/access" class="qb-filters" style="margin-bottom:14px;">
            <div class="field" style="flex:1 1 260px;">
                <label class="label" for="qa-q">جستجوی دانشجو</label>
                <input class="input" id="qa-q" type="search" name="q" value="<?= e($search) ?>" placeholder="نام، نام کاربری یا موبایل">
            </div>
            <button class="btn btn-primary btn-sm" type="submit">جستجو</button>
        </form>

        <?php if ($students === []): ?>
            <div class="empty">دانشجویی پیدا نشد.</div>
        <?php else: ?>
            <div class="qb-list">
                <?php foreach ($students as $student):
                    $held = $grants[(int) $student['id']] ?? [];
                ?>
                    <div class="qb-row">
                        <div>
                            <div class="qb-row-stem" style="margin:0 0 4px;font-weight:600;"><?= e($student['full_name']) ?></div>
                            <div class="qb-row-meta">
                                <span class="mono"><?= e($student['username']) ?></span>
                                <?php if ($held === []): ?>
                                    <span class="stat-chip chip-gray">بدون دسترسی</span>
                                <?php else: ?>
                                    <?php foreach ($held as $title): ?>
                                        <span class="stat-chip chip-blue"><?= e($title) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="qb-row-actions">
                            <a class="btn btn-primary btn-sm" href="/admin/flashcards/access/<?= e($student['uuid']) ?>">تنظیم دسترسی</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="pager" aria-label="صفحه‌بندی">
                    <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                        <a class="pager-item<?= $p === $page ? ' is-current' : '' ?>"
                           href="/admin/flashcards/access?<?= e(http_build_query(array_filter(['q' => $search]) + ['page' => $p])) ?>"><?= e(fa((string) $p)) ?></a>
                    <?php endfor; ?>
                    <span class="pager-total"><?= e(fa((string) $total)) ?> دانشجو</span>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
