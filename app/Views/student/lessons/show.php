<?php
/**
 * Reading one درسنامه, with the student's own highlighter.
 *
 * @var array $lesson
 * @var array $path     درس titles
 * @var array $toc      [[level, text, block id]]
 * @var array $tags
 * @var array $state    this student's lesson_reads row
 * @var array $related  questions, qbank_link, mindmaps, figures, siblings
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$highlights = json_decode((string) ($state['highlights'] ?? '[]'), true) ?: [];
$isRead = !empty($state['read_at']);
?>
<div class="lr tone-<?= e($lesson['color']) ?>" data-lesson-reader data-uuid="<?= e($lesson['uuid']) ?>"
     data-read="<?= $isRead ? '1' : '0' ?>" data-bookmarked="<?= !empty($state['bookmarked']) ? '1' : '0' ?>" data-progress="<?= (int) ($state['progress'] ?? 0) ?>">
    <div class="lr-progress" aria-hidden="true"><i data-lr-bar></i></div>

    <header class="lr-head">
        <nav class="lr-crumbs"><a href="/student/lessons">درسنامه‌ها</a><?php foreach ($path as $p): ?><span><?= e($p) ?></span><?php endforeach; ?></nav>
        <h1><?= e($lesson['title']) ?></h1>
        <?php if (!empty($lesson['summary'])): ?><p class="lr-sum"><?= e($lesson['summary']) ?></p><?php endif; ?>
        <div class="lr-meta">
            <span><?php $icon('clock', 15); ?> <?= e(fa((string) $lesson['reading_minutes'])) ?> دقیقه مطالعه</span>
            <?php foreach ($tags as $t): ?><a class="stat-chip <?= e($t['color'] ?: 'chip-gray') ?>" href="/student/lessons?tag=<?= (int) $t['id'] ?>">#<?= e($t['title']) ?></a><?php endforeach; ?>
        </div>
        <div class="lr-actions">
            <button type="button" class="lr-btn" data-lr-bookmark aria-pressed="<?= !empty($state['bookmarked']) ? 'true' : 'false' ?>"><?php $icon('bookmark', 17); ?><span>نشان</span></button>
            <?php View::partial('partials.study_mark_button', ['kind' => 'lesson', 'refId' => (int) $lesson['id'], 'title' => '📘 ' . $lesson['title'], 'url' => '/student/lessons/' . $lesson['uuid']]); ?>
            <div class="lr-size" role="group" aria-label="اندازه متن">
                <button type="button" data-lr-size="-1" aria-label="کوچک‌تر">A−</button>
                <button type="button" data-lr-size="1" aria-label="بزرگ‌تر">A+</button>
            </div>
            <button type="button" class="lr-btn" data-lr-theme title="حالت مطالعه"><?php $icon('sun', 17); ?><span>کاغذی</span></button>
            <button type="button" class="lr-btn" data-lr-mine><?php $icon('highlighter', 17); ?><span>هایلایت‌های من</span><b data-lr-count><?= e(fa((string) count($highlights))) ?></b></button>
        </div>
    </header>

    <div class="lr-layout">
        <article class="lx-doc lr-doc" data-lr-doc lang="fa">
            <?= $lesson['body_html'] /* sanitised by RichText on save */ ?>
        </article>

        <aside class="lr-side">
            <?php if (count($toc) > 1): ?>
                <nav class="lr-card lr-toc" aria-label="فهرست">
                    <b>فهرست</b>
                    <?php foreach ($toc as [$lvl, $text, $id]): ?>
                        <a class="lvl-<?= (int) $lvl ?>" href="#b-<?= e($id) ?>" data-lr-toc="<?= e($id) ?>"><?= e($text) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <section class="lr-card lr-help">
                <b><?php $icon('highlighter', 16); ?> شخصی‌سازی</b>
                <p>هر جای متن را انتخاب کن تا هایلایت کنی، رنگ متن را عوض کنی یا یادداشت بگذاری. فقط خودت آن‌ها را می‌بینی.</p>
            </section>

            <?php if ($related['questions'] > 0 || $related['mindmaps'] !== [] || $related['figures'] !== []): ?>
                <section class="lr-card lr-related">
                    <b>تمرین همین مبحث</b>
                    <?php if ($related['questions'] > 0 && $related['qbank_link']): ?>
                        <a class="lr-rel tone-violet" href="<?= e($related['qbank_link']) ?>"><span class="app-ic"><?php $icon('qbank', 16); ?></span><span><?= e(fa((string) $related['questions'])) ?> سوال مرتبط</span></a>
                    <?php endif; ?>
                    <?php foreach ($related['mindmaps'] as $m): ?>
                        <a class="lr-rel tone-teal" href="/student/mindmaps/<?= e($m['uuid']) ?>"><span class="app-ic"><?php $icon('mindmap', 16); ?></span><span><?= e($m['title']) ?></span></a>
                    <?php endforeach; ?>
                    <?php foreach ($related['figures'] as $f): ?>
                        <a class="lr-rel tone-amber" href="/student/figures/<?= e($f['uuid']) ?>"><span class="app-ic"><?php $icon('figure', 16); ?></span><span><?= e($f['title']) ?></span></a>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if ($related['siblings'] !== []): ?>
                <section class="lr-card">
                    <b>درسنامه‌های همین درس</b>
                    <?php foreach (array_slice($related['siblings'], 0, 5) as $s): ?>
                        <a class="lr-sib" href="/student/lessons/<?= e($s['uuid']) ?>"><?= e($s['title']) ?></a>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </aside>
    </div>

    <footer class="lr-foot">
        <button type="button" class="lr-done<?= $isRead ? ' is-done' : '' ?>" data-lr-read <?= $isRead ? 'disabled' : '' ?>>
            <?php $icon('check', 20); ?> <span><?= $isRead ? 'این درسنامه را خوانده‌ای' : 'خواندم!' ?></span>
        </button>
    </footer>

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
