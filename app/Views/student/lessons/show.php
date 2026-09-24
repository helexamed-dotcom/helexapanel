<?php
/**
 * Reading one page of a درسنامه, with the student's own highlighter. A slim
 * bar on top, the فهرست (زیردرس‌ها and pages) on the side, the page in the
 * middle and «خواندم» with the next page at its foot.
 *
 * @var array      $lesson
 * @var array|null $pageRow  the page being read (null: a درسنامه without pages)
 * @var array      $outline  زیردرس‌ها with their pages and is_read
 * @var array      $pages    the same pages, flat, in reading order
 * @var array|null $prev
 * @var array|null $next
 * @var int        $done     pages finished
 * @var string     $body
 * @var array      $path     درس titles
 * @var array      $toc      [[level, text, block id]] of this page
 * @var array      $tags     this page's tags
 * @var array      $state    this student's lesson_reads row
 * @var array      $pageState this student's row for the page (highlights, read_at)
 * @var array      $related  questions, qbank_link, mindmaps, figures, siblings
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$highlights = json_decode((string) ($pageState['highlights'] ?? '[]'), true) ?: [];
$isRead = !empty($pageState['read_at']);
$base = '/student/lessons/' . $lesson['uuid'];
$pageUrl = static fn (array $p): string => $base . '/p/' . $p['uuid'];
$total = count($pages);
$percent = $total > 0 ? (int) round($done * 100 / $total) : ($isRead ? 100 : 0);
?>
<div class="lr tone-<?= e($lesson['color']) ?>" data-lesson-reader data-uuid="<?= e($lesson['uuid']) ?>" data-page="<?= e($pageRow['uuid'] ?? '') ?>"
     data-read="<?= $isRead ? '1' : '0' ?>" data-bookmarked="<?= !empty($state['bookmarked']) ? '1' : '0' ?>" data-progress="<?= (int) ($state['progress'] ?? 0) ?>">
    <div class="lr-progress" aria-hidden="true"><i data-lr-bar></i></div>

    <header class="lr-bar">
        <span class="lr-bar-ic app-ic"><?php $icon('lesson', 18); ?></span>
        <div class="lr-bar-text">
            <nav class="lr-crumbs"><a href="/student/lessons">درسنامه‌ها</a><a href="<?= e($base) ?>"><?= e($lesson['title']) ?></a><?php if ($pageRow): ?><span><?= e($pageRow['section_title']) ?></span><?php endif; ?></nav>
            <h1><?= e($pageRow['title'] ?? $lesson['title']) ?></h1>
        </div>
        <div class="lr-bar-meta">
            <?php if ($pageRow): ?><span class="lr-pos">صفحه <b><?= e(fa((string) ($pageRow['index'] + 1))) ?></b> از <?= e(fa((string) $total)) ?></span><?php endif; ?>
            <span><?php $icon('clock', 13); ?> <?= e(fa((string) ($pageRow['reading_minutes'] ?? $lesson['reading_minutes']))) ?> دقیقه</span>
        </div>
        <div class="lr-actions">
            <button type="button" class="lr-btn lr-outline-btn" data-lr-outline-open aria-label="فهرست"><?php $icon('list', 17); ?><span>فهرست</span></button>
            <button type="button" class="lr-btn" data-lr-bookmark aria-pressed="<?= !empty($state['bookmarked']) ? 'true' : 'false' ?>" title="نشان کردن درسنامه"><?php $icon('bookmark', 17); ?></button>
            <div class="lr-size" role="group" aria-label="اندازه متن">
                <button type="button" data-lr-size="-1" aria-label="کوچک‌تر">A−</button>
                <button type="button" data-lr-size="1" aria-label="بزرگ‌تر">A+</button>
            </div>
            <button type="button" class="lr-btn" data-lr-theme title="حالت کاغذی"><?php $icon('sun', 17); ?></button>
            <button type="button" class="lr-btn" data-lr-mine title="هایلایت‌های من"><?php $icon('highlighter', 17); ?><b data-lr-count><?= e(fa((string) count($highlights))) ?></b></button>
            <?php View::partial('partials.study_mark_button', ['kind' => 'lesson', 'refId' => (int) $lesson['id'], 'title' => '📘 ' . $lesson['title'], 'url' => $base]); ?>
        </div>
    </header>

    <div class="lr-layout">
        <aside class="lr-side tone-<?= e($lesson['color']) ?>" data-lr-outline aria-label="فهرست درسنامه">
            <?php if ($outline !== []): ?>
                <nav class="lr-card lr-outline">
                    <div class="lr-outline-head">
                        <b><?= e($lesson['title']) ?></b>
                        <button type="button" class="lr-sheet-x" data-lr-outline-close aria-label="بستن"><?php $icon('close', 18); ?></button>
                    </div>
                    <div class="lr-meter"><i style="width: <?= $percent ?>%"></i></div>
                    <small class="lr-meter-note"><?= e(fa((string) $done)) ?> از <?= e(fa((string) $total)) ?> صفحه خوانده‌شده</small>
                    <?php foreach ($outline as $si => $sec): $open = false;
                        foreach ($sec['pages'] as $p) { if ($pageRow && (int) $p['id'] === (int) $pageRow['id']) { $open = true; } }
                        $secDone = count(array_filter($sec['pages'], static fn ($p) => $p['is_read'])); ?>
                        <details class="lr-sec" <?= $open ? 'open' : '' ?>>
                            <summary>
                                <span class="lr-sec-n"><?= e(fa((string) ($si + 1))) ?></span>
                                <span class="lr-sec-t"><?= e($sec['title']) ?></span>
                                <small><?= e(fa((string) $secDone)) ?>/<?= e(fa((string) count($sec['pages']))) ?></small>
                            </summary>
                            <?php foreach ($sec['pages'] as $p): $on = $pageRow && (int) $p['id'] === (int) $pageRow['id']; ?>
                                <a class="lr-pg<?= $on ? ' is-on' : '' ?><?= $p['is_read'] ? ' is-read' : '' ?>" href="<?= e($pageUrl($p)) ?>" <?= $on ? 'aria-current="page" data-lr-current' : '' ?>>
                                    <i aria-hidden="true"></i><span><?= e($p['title']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </details>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php if (count($toc) > 1): ?>
                <nav class="lr-card lr-toc" aria-label="در این صفحه">
                    <b>در این صفحه</b>
                    <?php foreach ($toc as [$lvl, $text, $id]): ?>
                        <a class="lvl-<?= (int) $lvl ?>" href="#b-<?= e($id) ?>" data-lr-toc="<?= e($id) ?>"><?= e($text) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <?php if ($tags !== [] || $related['questions'] > 0 || $related['mindmaps'] !== [] || $related['figures'] !== []): ?>
                <section class="lr-card lr-related">
                    <b>تمرین همین مبحث</b>
                    <?php if ($tags !== []): ?>
                        <div class="lr-tags"><?php foreach ($tags as $t): ?><a class="stat-chip <?= e($t['color'] ?: 'chip-gray') ?>" href="/student/lessons?tag=<?= (int) $t['id'] ?>">#<?= e($t['title']) ?></a><?php endforeach; ?></div>
                    <?php endif; ?>
                    <?php if ($related['questions'] > 0 && $related['qbank_link']): ?>
                        <a class="lr-rel tone-violet" href="<?= e($related['qbank_link']) ?>"><span class="app-ic"><?php $icon('qbank', 15); ?></span><span><?= e(fa((string) $related['questions'])) ?> سوال مرتبط</span></a>
                    <?php endif; ?>
                    <?php foreach ($related['mindmaps'] as $m): ?>
                        <a class="lr-rel tone-teal" href="/student/mindmaps/<?= e($m['uuid']) ?>"><span class="app-ic"><?php $icon('mindmap', 15); ?></span><span><?= e($m['title']) ?></span></a>
                    <?php endforeach; ?>
                    <?php foreach ($related['figures'] as $f): ?>
                        <a class="lr-rel tone-amber" href="/student/figures/<?= e($f['uuid']) ?>"><span class="app-ic"><?php $icon('figure', 15); ?></span><span><?= e($f['title']) ?></span></a>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </aside>

        <div class="lr-main">
            <article class="lx-doc lr-doc" data-lr-doc lang="fa">
                <?= $body /* sanitised by RichText on save */ ?>
            </article>

            <footer class="lr-foot">
                <?php if ($prev): ?>
                    <a class="lr-nav is-prev" href="<?= e($pageUrl($prev)) ?>"><small>→ قبلی</small><span><?= e($prev['title']) ?></span></a>
                <?php else: ?><span></span><?php endif; ?>
                <button type="button" class="lr-done<?= $isRead ? ' is-done' : '' ?>" data-lr-read <?= $isRead ? 'disabled' : '' ?>>
                    <?php $icon('check', 18); ?> <span><?= $isRead ? 'خوانده‌ای ✓' : 'خواندم!' ?></span>
                </button>
                <?php if ($next): ?>
                    <a class="lr-nav is-next" href="<?= e($pageUrl($next)) ?>" data-lr-next><small>بعدی ←</small><span><?= e($next['title']) ?></span></a>
                <?php else: ?><span></span><?php endif; ?>
            </footer>
        </div>
    </div>

    <?php /* the floating toolbar for a selection */ ?>
    <div class="lr-pop" data-lr-pop hidden role="toolbar" aria-label="ابزار هایلایت">
        <div class="lr-pop-row">
            <?php foreach (['yellow', 'green', 'pink', 'blue', 'orange', 'violet'] as $c): ?>
                <button type="button" class="lr-dot hl-<?= $c ?>" data-lr-hl="<?= $c ?>" aria-label="هایلایت"></button>
            <?php endforeach; ?>
        </div>
        <div class="lr-pop-row">
            <?php foreach (['red', 'blue', 'teal', 'violet'] as $c): ?>
                <button type="button" class="lr-a tc-<?= $c ?>" data-lr-tc="<?= $c ?>" aria-label="رنگ متن">A</button>
            <?php endforeach; ?>
            <button type="button" class="lr-a" data-lr-kind="ul" aria-label="زیرخط"><u>U</u></button>
            <button type="button" class="lr-a" data-lr-kind="bold" aria-label="پررنگ"><b>B</b></button>
            <button type="button" class="lr-a" data-lr-note aria-label="یادداشت"><?php $icon('note', 16); ?></button>
            <button type="button" class="lr-a" data-lr-erase aria-label="پاک کردن"><?php $icon('eraser', 16); ?></button>
        </div>
    </div>

    <script type="application/json" data-lr-initial><?= json_encode($highlights, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>
