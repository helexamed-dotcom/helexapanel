<?php
/**
 * @var array  $rows
 * @var array  $filters
 * @var array  $subjects
 * @var string $sample   a sample JSON document
 * @var string $prompt   a prompt for making one with an AI assistant
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
?>
<div class="ad-page sa">
    <section class="ad-hero tone-violet">
        <div>
            <h2>نقشه‌های ذهنی</h2>
            <p>مثل XMind: موضوع مرکزی، شاخه‌ها و زیرشاخه‌ها، رنگ، نشان، یادداشت، تصویر و پیوند به درسنامه. با JSON یا یک فهرست ساده هم وارد می‌شود.</p>
        </div>
        <form method="post" action="/admin/mindmaps/new" class="ad-quick">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <input class="input" name="title" maxlength="191" placeholder="موضوع مرکزی نقشه تازه" style="min-width:220px">
            <button class="ad-quick-btn" type="submit"><?php $icon('plus', 16); ?> ساخت نقشه</button>
        </form>
    </section>

    <section class="ad-card">
        <form class="sa-filter" method="get" action="/admin/mindmaps">
            <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="جستجوی عنوان…">
            <select class="input" name="status">
                <option value="">همه</option>
                <option value="published" <?= $filters['status'] === 'published' ? 'selected' : '' ?>>منتشرشده</option>
                <option value="draft" <?= $filters['status'] === 'draft' ? 'selected' : '' ?>>پیش‌نویس</option>
            </select>
            <select class="input" name="subject">
                <option value="0">همه درس‌ها</option>
                <?php foreach ($subjects as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $filters['subject'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['title']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-ghost" type="submit"><?php $icon('filter', 16); ?> فیلتر</button>
        </form>
        <?php if ($rows === []): ?>
            <div class="ad-empty">هنوز نقشه‌ای نساخته‌اید. بالا یک موضوع بنویسید یا پایین JSON وارد کنید.</div>
        <?php else: ?>
            <div class="ml-grid">
                <?php foreach ($rows as $i => $m): ?>
                    <article class="ml-card tone-<?= e($m['tone']) ?>" style="--i: <?= min($i, 12) ?>">
                        <a class="ml-art" href="/admin/mindmaps/<?= e($m['uuid']) ?>/edit"><?php View::partial('partials.mindmap_art', ['map' => $m]); ?>
                            <em class="<?= $m['status'] === 'published' ? '' : 'is-lock' ?>"><?= $m['status'] === 'published' ? 'منتشرشده' : 'پیش‌نویس' ?></em></a>
                        <div class="ml-body">
                            <small><?= e(implode(' › ', array_filter([$m['parent_title'] ?? '', $m['subject_title'] ?? '']))) ?: 'بدون درس' ?></small>
                            <b><?= e($m['title']) ?></b>
                            <span><?= e(fa((string) $m['node_count'])) ?> موضوع · <?= e(jdate($m['updated_at'] ?? $m['created_at'])) ?></span>
                            <div class="me-row" style="margin-top:8px">
                                <a class="btn btn-primary btn-sm" href="/admin/mindmaps/<?= e($m['uuid']) ?>/edit"><?php $icon('pen', 14); ?> ویرایش</a>
                                <form method="post" action="/admin/mindmaps/<?= e($m['uuid']) ?>/status">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="status" value="<?= $m['status'] === 'published' ? 'draft' : 'published' ?>">
                                    <button class="btn btn-ghost btn-sm" type="submit"><?= $m['status'] === 'published' ? 'پیش‌نویس کن' : 'منتشر کن' ?></button>
                                </form>
                                <a class="ad-icon-btn" href="/admin/mindmaps/<?= e($m['uuid']) ?>/export" title="دانلود JSON"><?php $icon('download', 15); ?></a>
                                <form method="post" action="/admin/mindmaps/<?= e($m['uuid']) ?>/delete" data-confirm="نقشه «<?= e($m['title']) ?>» حذف شود؟">
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

    <section class="ad-card">
        <header class="ad-card-head">
            <span class="app-ic tone-teal"><?php $icon('upload'); ?></span>
            <div><h3>ورود نقشه</h3><p>فایل JSON نقشه (از همین سایت یا ساخته‌شده با هوش مصنوعی) یا یک فهرست تو‌رفته — مثلاً سرفصل‌هایی که از Word کپی کرده‌اید. خط اول، موضوع مرکزی است.</p></div>
        </header>
        <form method="post" action="/admin/mindmaps/import" enctype="multipart/form-data" class="ad-form-grid">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <label class="hx-field">فایل JSON یا TXT<input class="input" type="file" name="file" accept=".json,.txt,application/json,text/plain"></label>
            <label class="hx-field is-wide">یا متن را بچسبانید<textarea class="input" name="text" rows="6" dir="auto" placeholder="قلب&#10;- دریچه‌ها&#10;  - میترال&#10;  - آئورت&#10;- عروق"></textarea></label>
            <div class="is-wide"><button class="btn btn-primary" type="submit"><?php $icon('upload', 16); ?> ساخت نقشه از این</button></div>
        </form>
        <details>
            <summary class="hx-link">نمونه JSON و دستور ساخت با هوش مصنوعی</summary>
            <div class="ad-two" style="margin-top:12px">
                <pre class="sa-pre" dir="ltr"><?= e($sample) ?></pre>
                <pre class="sa-pre"><?= e($prompt) ?></pre>
            </div>
        </details>
    </section>
</div>
