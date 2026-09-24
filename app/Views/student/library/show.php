<?php
/**
 * @var array $item
 * @var array $kinds
 */
$base = '/student/library/' . $item['uuid'] . '/media/';
$hasCover = $item['cover_path'] !== null && $item['cover_path'] !== '';
$paragraphs = $item['kind'] === 'article'
    ? preg_split('/\R{2,}/u', trim((string) $item['body'])) ?: []
    : [];
?>
<article class="lib-page lib-show">
    <a class="lib-back" href="/student/library">→ بازگشت به کتابخانه</a>

    <header class="card lib-show-head">
        <span class="stat-chip"><?= e($kinds[$item['kind']] ?? '') ?></span>
        <h2><?= e($item['title']) ?></h2>
        <?php if ($item['summary']): ?><p class="muted"><?= e($item['summary']) ?></p><?php endif; ?>
        <small class="muted"><?= $item['course_title'] ? e($item['course_title']) . ' · ' : '' ?><?= e(\HeleXa\Services\Jalali::longDate((int) strtotime((string) $item['created_at']))) ?></small>
        <div style="margin-top:10px;"><?php \HeleXa\Core\View::partial('partials.study_mark_button', [
            'kind' => 'library', 'refId' => (int) $item['id'], 'title' => (string) $item['title'],
            'url' => '/student/library/' . $item['uuid'],
        ]); ?></div>
    </header>

    <div class="card lib-show-body">
        <?php switch ($item['kind']):
            case 'video': ?>
                <video class="lib-media" controls preload="metadata" playsinline controlslist="nodownload"
                       <?= $hasCover ? 'poster="' . e($base . 'cover') . '"' : '' ?>>
                    <source src="<?= e($base . 'file') ?>" type="<?= e($item['file_mime']) ?>">
                    مرورگر شما پخش ویدیو را پشتیبانی نمی‌کند.
                </video>
                <?php break;

            case 'image': ?>
                <img class="lib-media" src="<?= e($base . 'file') ?>" alt="<?= e($item['title']) ?>">
                <?php break;

            case 'article': ?>
                <?php if ($hasCover): ?><img class="lib-media lib-article-cover" src="<?= e($base . 'cover') ?>" alt=""><?php endif; ?>
                <div class="lib-article" dir="auto">
                    <?php foreach ($paragraphs as $p): ?><p><?= nl2br(e($p)) ?></p><?php endforeach; ?>
                </div>
                <?php break;

            case 'link': ?>
                <?php if ($hasCover): ?><img class="lib-media" src="<?= e($base . 'cover') ?>" alt=""><?php endif; ?>
                <p class="lib-link-url" dir="ltr"><?= e($item['url']) ?></p>
                <a class="btn btn-primary" href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer">🔗 باز کردن لینک</a>
                <?php break;

            default: // file
                if ($hasCover): ?><img class="lib-media" src="<?= e($base . 'cover') ?>" alt=""><?php endif;
                if ($item['file_mime'] === 'audio/mpeg'): ?>
                    <audio class="lib-audio" controls preload="metadata" src="<?= e($base . 'file') ?>"></audio>
                <?php endif; ?>
                <div class="lib-file">
                    <span class="lib-glyph">📎</span>
                    <div>
                        <strong dir="auto"><?= e($item['file_name'] ?? 'فایل') ?></strong>
                        <small class="muted"><?= e(fa(number_format(((int) $item['file_size']) / 1048576, 1))) ?> مگابایت</small>
                    </div>
                    <a class="btn btn-primary" href="<?= e($base . 'file') ?>"<?= $item['file_mime'] === 'application/pdf' ? ' target="_blank" rel="noopener"' : '' ?>>
                        <?= $item['file_mime'] === 'application/pdf' ? 'مشاهده PDF' : 'دریافت فایل' ?>
                    </a>
                </div>
        <?php endswitch; ?>
    </div>
</article>