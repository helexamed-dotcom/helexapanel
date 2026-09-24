<?php
/**
 * @var array $rows
 * @var array $filters
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
?>
<div class="ad-page sa">
    <section class="ad-hero tone-amber">
        <div>
            <h2>بازی با شکل</h2>
            <p>یک تصویر (مثلاً صفحه‌ای از اطلس) بارگذاری کنید، روی ساختارها نقطه بگذارید و نامشان را بنویسید. دانشجو یا باید «عصب مدین را پیدا کند» یا روی نقطه‌ای که نشانش می‌دهیم پاسخ بدهد.</p>
        </div>
        <form method="post" action="/admin/figures/new" enctype="multipart/form-data" class="ad-quick">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <input class="input" name="title" maxlength="191" placeholder="عنوان — مثلاً: عصب‌های اندام فوقانی" style="min-width:240px" required>
            <label class="ad-quick-btn" style="position:relative;cursor:pointer"><?php $icon('image', 16); ?> تصویر<input type="file" name="image" accept="image/*" style="position:absolute;inset:0;opacity:0;cursor:pointer"></label>
            <button class="ad-quick-btn" type="submit"><?php $icon('plus', 16); ?> ساخت</button>
        </form>
    </section>

    <section class="ad-card">
        <form class="sa-filter" method="get" action="/admin/figures">
            <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="جستجوی عنوان…">
            <select class="input" name="status">
                <option value="">همه</option>
                <option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>منتشرشده</option>
                <option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
            </select>
            <button class="btn btn-ghost" type="submit"><?php $icon('filter', 16); ?> فیلتر</button>
        </form>
        <?php if ($rows === []): ?>
            <div class="ad-empty">هنوز شکلی نساخته‌اید. بالا یک عنوان و تصویر بدهید.</div>
        <?php else: ?>
            <div class="ml-grid">
                <?php foreach ($rows as $i => $f): ?>
                    <article class="ml-card tone-<?= e($f['tone']) ?>" style="--i: <?= min($i, 12) ?>">
                        <a class="ml-art fg-art" href="/admin/figures/<?= e($f['uuid']) ?>/edit">
                            <?php if ($f['image_path']): ?><img src="/media/figures/<?= e($f['image_path']) ?>" alt="" loading="lazy"><?php else: ?><span class="fg-noimg"><?php $icon('image', 32); ?></span><?php endif; ?>
                            <em class="<?= $f['status'] === 'published' ? '' : 'is-lock' ?>"><?= $f['status'] === 'published' ? 'منتشرشده' : 'پیش‌نویس' ?></em>
                        </a>
                        <div class="ml-body">
                            <small><?= e(implode(' › ', array_filter([$f['parent_title'] ?? '', $f['subject_title'] ?? '']))) ?: 'بدون درس' ?></small>
                            <b><?= e($f['title']) ?></b>
                            <span><?= e(fa((string) $f['spot_count'])) ?> نقطه</span>
                            <div class="me-row" style="margin-top:8px">
                                <a class="btn btn-primary btn-sm" href="/admin/figures/<?= e($f['uuid']) ?>/edit"><?php $icon('pen', 14); ?> ویرایش</a>
                                <form method="post" action="/admin/figures/<?= e($f['uuid']) ?>/status">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="status" value="<?= $f['status'] === 'published' ? 'draft' : 'published' ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?= $f['status'] === 'published' ? 'پیش‌نویس کن' : 'منتشر کن' ?></button>
                                </form>
                                <form method="post" action="/admin/figures/<?= e($f['uuid']) ?>/delete" data-confirm="«<?= e($f['title']) ?>» حذف شود؟">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <button class="ad-icon-btn is-danger" type="submit" title="حذف"><?php $icon('trash', 15); ?></button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
