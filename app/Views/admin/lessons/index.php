<?php
/**
 * @var array $rows
 * @var array $filters
 * @var array $subjects
 * @var array $counts   lesson id => [pages, sections]
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
?>
<div class="ad-page">
    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-indigo"><?php $icon('lesson'); ?></span>
            <div>
                <h3>درسنامه‌ها</h3>
                <p>هر درسنامه (مثلاً باکتری‌شناسی) چند زیردرس دارد و هر زیردرس چند صفحه؛ هر صفحه برچسب‌های خودش را دارد — همان برچسب‌های بانک سوال، فلش‌کارت، بازی با شکل و بالین.</p>
            </div>
            <div class="ad-actions">
                <a class="btn btn-ghost btn-sm" href="/admin/lessons/transfer"><?php $icon('download', 15); ?> JSON</a>
                <a class="btn btn-primary btn-sm" href="/admin/lessons/create"><?php $icon('plus', 15); ?> درسنامه جدید</a>
            </div>
        </header>

        <form class="ad-form-grid" method="get" action="/admin/lessons">
            <label class="hx-field">جستجو<input class="input" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="عنوان یا خلاصه"></label>
            <label class="hx-field">درس
                <select class="input" name="subject">
                    <option value="0">همه</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) ($filters['subject'] ?? 0) === $s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="hx-field">وضعیت
                <select class="input" name="status">
                    <option value="">همه</option>
                    <option value="published" <?= ($filters['status'] ?? '') === 'published' ? 'selected' : '' ?>>منتشرشده</option>
                    <option value="draft" <?= ($filters['status'] ?? '') === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
                </select>
            </label>
            <div><button class="btn btn-ghost" type="submit"><?php $icon('filter', 15); ?> فیلتر</button></div>
        </form>

        <?php if ($rows === []): ?>
            <div class="ad-empty">درسنامه‌ای پیدا نشد. <a href="/admin/lessons/create">اولین درسنامه را بنویسید</a> یا از <a href="/admin/lessons/transfer">JSON</a> وارد کنید.</div>
        <?php else: ?>
            <ul class="ad-list">
                <?php foreach ($rows as $l): ?>
                    <li class="ad-row">
                        <span class="app-ic tone-<?= e($l['color']) ?>"><?php $icon('lesson', 18); ?></span>
                        <span class="ad-row-main">
                            <b><?= e($l['title']) ?></b>
                            <small><?= e(implode(' › ', array_filter([$l['parent_title'] ?? '', $l['subject_title'] ?? '']))) ?: 'بدون درس' ?> · <?= e(fa((string) ($counts[(int) $l['id']]['sections'] ?? 0))) ?> زیردرس · <?= e(fa((string) ($counts[(int) $l['id']]['pages'] ?? 0))) ?> صفحه · <?= e(fa((string) $l['reading_minutes'])) ?> دقیقه · <?= e(jdate($l['updated_at'] ?? $l['created_at'])) ?></small>
                        </span>
                        <form method="post" action="/admin/lessons/<?= e($l['uuid']) ?>/status">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <input type="hidden" name="status" value="<?= $l['status'] === 'published' ? 'draft' : 'published' ?>">
                            <button class="ad-pill <?= $l['status'] === 'published' ? 'is-on' : 'is-warn' ?>" type="submit" style="border:0;cursor:pointer" title="تغییر وضعیت"><?= $l['status'] === 'published' ? 'منتشرشده' : 'پیش‌نویس' ?></button>
                        </form>
                        <a class="ad-icon-btn" href="/student/lessons/<?= e($l['uuid']) ?>?preview=1" target="_blank" aria-label="پیش‌نمایش"><?php $icon('eye', 17); ?></a>
                        <a class="ad-icon-btn" href="/admin/lessons/<?= e($l['uuid']) ?>/edit" aria-label="ویرایش"><?php $icon('pencil', 17); ?></a>
                        <form method="post" action="/admin/lessons/<?= e($l['uuid']) ?>/delete" data-confirm="این درسنامه حذف شود؟">
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <button class="ad-icon-btn is-danger" type="submit" aria-label="حذف"><?php $icon('trash', 17); ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
