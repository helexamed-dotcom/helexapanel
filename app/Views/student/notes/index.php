<?php
/**
 * @var array $notes
 * @var array $filters
 * @var int   $page
 * @var int   $pages
 * @var int   $total
 */
$qs = static fn (array $over): string => '/student/notes?' . http_build_query(array_filter($over + $filters, static fn ($v) => $v !== '' && $v !== null && $v !== 1));
$scopes = ['' => 'همه', 'lessons' => 'روی جزوه‌ها', 'free' => 'آزاد'];
?>
<div class="notes-page">
    <section class="card notes-head">
        <div>
            <h2>📝 یادداشت‌های من</h2>
            <p class="muted">با قلم بنویسید، تایپ کنید یا PDF اضافه کنید و رویش بنویسید — <?= e(fa((string) $total)) ?> یادداشت</p>
        </div>
        <form method="get" action="/student/notes" class="notes-search" role="search">
            <?php if ($filters['scope'] !== ''): ?><input type="hidden" name="scope" value="<?= e($filters['scope']) ?>"><?php endif; ?>
            <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="جستجو در عنوان و متن…" aria-label="جستجو">
            <button class="btn btn-primary" type="submit">جستجو</button>
        </form>
    </section>

    <nav class="seg-tabs" aria-label="نوع یادداشت">
        <?php foreach ($scopes as $key => $label): ?>
            <a class="<?= $filters['scope'] === $key ? 'is-active' : '' ?>" href="<?= e($qs(['scope' => $key, 'page' => null])) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="notes-grid">
        <form method="post" action="/student/notes">
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <button class="note-new" type="submit" data-lock-on-submit>
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'note-plus']); ?>
                <span>یادداشت جدید</span>
            </button>
        </form>

        <?php foreach ($notes as $note): ?>
            <a class="note-card" data-color="<?= e($note['color']) ?>" href="/student/notes/<?= e($note['uuid']) ?>">
                <h4><?= e($note['title'] ?: 'بدون عنوان') ?></h4>
                <?php if ($note['snippet']): ?><p dir="auto"><?= e($note['snippet']) ?></p><?php endif; ?>
                <div class="note-card-meta">
                    <?php if ((int) $note['ink_count'] > 0): ?><span>✍️ دست‌نویس</span><?php endif; ?>
                    <?php if ((int) $note['file_count'] > 0): ?><span>📎 <?= e(fa((string) $note['file_count'])) ?> فایل</span><?php endif; ?>
                    <?php if ($note['content_title']): ?><span title="<?= e((string) $note['course_title']) ?>">📖 <?= e($note['content_title']) ?></span><?php endif; ?>
                    <span><?= e(\HeleXa\Services\Jalali::longDate((int) strtotime((string) ($note['updated_at'] ?? $note['created_at'])))) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($notes === [] && ($filters['q'] !== '' || $filters['scope'] !== '')): ?>
        <div class="empty">یادداشتی با این فیلتر پیدا نشد.</div>
    <?php elseif ($notes === []): ?>
        <div class="empty">هنوز یادداشتی ندارید. داخل هر جزوه هم با دکمه «افزودن یادداشت» می‌توانید روی خود جزوه یادداشت بگذارید.</div>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
        <nav class="lib-pager" aria-label="صفحه‌ها">
            <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($qs(['page' => $page - 1])) ?>">قبلی</a><?php endif; ?>
            <span class="muted">صفحه <?= e(fa((string) $page)) ?> از <?= e(fa((string) $pages)) ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($qs(['page' => $page + 1])) ?>">بعدی</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</div>