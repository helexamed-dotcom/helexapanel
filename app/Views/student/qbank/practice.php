<?php
/**
 * One question at a time: a slim bar on top (درس, position, marks), search
 * and filters in a side panel, the question in the middle and its
 * explanation beside it — the whole question on one screen, no hero.
 *
 * The page carries no answer key: options are rendered without is_correct,
 * and qbank.js asks the server once the student has chosen. On a question the
 * student has already answered, only the outcome is shown (answered right,
 * answered wrong, or not answered) — never which option they picked.
 *
 * @var array      $subject
 * @var array|null $question
 * @var array      $options
 * @var array      $tags
 * @var int        $position   1-based
 * @var int        $total
 * @var array      $filters
 * @var array      $filing
 * @var array      $allTags
 * @var array      $difficulties
 * @var array      $stats
 * @var array|null $last       this student's latest attempt
 * @var array      $marks
 */
use HeleXa\Core\View;

$icon    = static fn (string $n, int $s = 16) => View::partial('partials.icon', ['name' => $n, 'size' => $s]);
$letters = ['الف', 'ب', 'ج', 'د', 'ه', 'و', 'ز', 'ح'];
$img     = static fn (?string $name): string => '/student/qbank/image/' . rawurlencode((string) $name);
$base    = '/student/qbank/' . rawurlencode((string) $subject['uuid']);
$keep    = array_filter([
    'q'          => $filters['q'] ?: null,
    'mode'       => $filters['mode'] ?: null,
    'sub'        => $filters['sub_subject_id'] ?: null,
    'topic'      => $filters['topic_id'] ?: null,
    'difficulty' => $filters['difficulty'] ?: null,
    'tag'        => $filters['tag_id'] ?: null,
]);
$query = static fn (array $extra = []): string => ($q = http_build_query($keep + $extra)) !== '' ? '?' . $q : '';
$link  = static fn (int $n): string => $base . $query(['n' => $n]);
$on    = static fn (string $m): bool => in_array($m, $marks, true);
$path  = $question !== null ? array_values(array_filter([$question['sub_subject_title'] ?? null, $question['topic_title'] ?? null])) : [];
?>
<div class="qx"<?php if ($question !== null): ?> data-qb-player
     data-answer-url="/student/qbank/answer/<?= e($question['uuid']) ?>"
     data-mark-url="/student/qbank/mark/<?= e($question['uuid']) ?>"<?php endif; ?>>

    <header class="qx-bar">
        <a class="qx-subject" href="<?= e($base) ?>" title="صفحه درس">
            <span class="app-ic tone-violet"><?php $icon('qbank', 18); ?></span>
            <span class="qx-subject-text">
                <b><?= e($subject['title']) ?></b>
                <small><?= $path !== [] ? e(implode(' › ', $path)) : 'بانک سوال' ?></small>
            </span>
        </a>

        <?php if ($question !== null): ?>
            <div class="qx-count" aria-label="پیشرفت">
                <span><b><?= e(fa((string) $position)) ?></b> از <?= e(fa((string) $total)) ?></span>
                <i class="qx-count-bar"><i style="width: <?= (int) round($position * 100 / max(1, $total)) ?>%"></i></i>
            </div>
            <div class="qx-marks">
                <button type="button" class="qx-mark qb-mark<?= $on('saved') ? ' is-on' : '' ?>" data-qb-mark="saved"
                        aria-pressed="<?= $on('saved') ? 'true' : 'false' ?>" title="نشان کردن">⭐<span> نشان</span></button>
                <button type="button" class="qx-mark qb-mark<?= $on('review') ? ' is-on' : '' ?>" data-qb-mark="review"
                        aria-pressed="<?= $on('review') ? 'true' : 'false' ?>" title="نیاز به مرور">🔁<span> مرور</span></button>
                <button type="button" class="qx-mark qb-mark qb-mark-exam<?= $on('exam') ? ' is-on' : '' ?>" data-qb-mark="exam"
                        data-on-label="✓ در آزمون‌ها" data-off-label="➕ آزمون‌های من"
                        aria-pressed="<?= $on('exam') ? 'true' : 'false' ?>"><?= $on('exam') ? '✓ در آزمون‌ها' : '➕ آزمون‌های من' ?></button>
            </div>
        <?php endif; ?>

        <div class="qx-bar-end">
            <a class="qx-iconbtn" href="<?= e($base . '/list' . $query()) ?>" title="نمای فهرستی" aria-label="نمای فهرستی"><?php $icon('list', 18); ?></a>
            <button type="button" class="qx-iconbtn qx-filter-btn" data-qx-filters-open aria-label="جستجو و فیلتر">
                <?php $icon('sliders', 18); ?><?php if ($keep !== []): ?><em><?= e(fa((string) count($keep))) ?></em><?php endif; ?>
            </button>
        </div>
    </header>

    <div class="qx-grid">
        <?php View::partial('student.qbank._sidebar', [
            'action' => $base, 'filters' => $filters, 'filing' => $filing, 'allTags' => $allTags,
            'difficulties' => $difficulties, 'stats' => $stats, 'total' => $total, 'jumpBase' => $base,
        ]); ?>

        <?php if ($question === null): ?>
            <section class="qx-q qx-empty">
                <div class="qx-empty-ic"><?php $icon('qbank', 30); ?></div>
                <h3>
                    <?= match ($filters['mode']) {
                        'wrong'  => 'سوال غلطی برای مرور نداری 🎉',
                        'new'    => 'همه سوال‌های این بخش را تمرین کرده‌ای 🎉',
                        'saved'  => 'هنوز سوالی را نشان نکرده‌ای.',
                        'review' => 'سوالی در فهرست «نیاز به مرور» نیست.',
                        default  => 'سوالی با این فیلترها پیدا نشد.',
                    } ?>
                </h3>
                <?php if ($keep !== []): ?><a class="btn btn-ghost btn-sm" href="<?= e($base . '?start=1') ?>">برداشتن فیلترها</a><?php endif; ?>
            </section>
        <?php else: ?>
            <main class="qx-q">
                <div class="qx-meta">
                    <span class="qb-diff <?= e($question['difficulty']) ?>"><?= e($difficulties[$question['difficulty']] ?? '') ?></span>
                    <?php foreach ($tags as $tag): ?>
                        <span class="stat-chip <?= e($tag['color'] ?: 'chip-gray') ?>"><?= e($tag['title']) ?></span>
                    <?php endforeach; ?>
                    <span class="qx-hist<?= $last === null ? '' : ($last['is_correct'] ? ' is-right' : ' is-wrong') ?>">
                        <?= $last === null ? 'پاسخ‌نداده' : ($last['is_correct'] ? '✓ قبلاً درست' : '✗ قبلاً غلط') ?>
                    </span>
                </div>

                <?php if (!empty($question['stem_text'])): ?>
                    <div class="qx-stem"><?= e($question['stem_text']) ?></div>
                <?php endif; ?>
                <?php if (!empty($question['stem_image'])): ?>
                    <figure class="qb-figure qx-figure"><img src="<?= e($img($question['stem_image'])) ?>" alt="تصویر سوال"></figure>
                <?php endif; ?>

                <div class="qx-answers" role="group" aria-label="گزینه‌ها">
                    <?php foreach ($options as $i => $option): ?>
                        <button type="button" class="qb-answer qx-answer" data-qb-answer="<?= e($option['uuid']) ?>" data-key="<?= $i + 1 ?>">
                            <span class="qb-option-letter"><?= e($letters[$i] ?? (string) ($i + 1)) ?></span>
                            <span class="qb-answer-body">
                                <?= e((string) ($option['body_text'] ?? '')) ?>
                                <?php if (!empty($option['body_image'])): ?>
                                    <img src="<?= e($img($option['body_image'])) ?>" alt="تصویر گزینه <?= e($letters[$i] ?? '') ?>" loading="lazy">
                                <?php endif; ?>
                            </span>
                            <span class="qb-share" data-qb-share hidden><i></i><b></b></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="qb-verdict qx-verdict" data-qb-verdict role="status" aria-live="polite"></div>
                <p class="qb-hint qx-note" data-qb-share-note hidden></p>

                <?php /* Below the side-by-side width the explanation opens as a sheet from here. */ ?>
                <button type="button" class="qb-explain-open" data-qb-explain-open hidden>
                    <?php $icon('lesson', 18); ?> نمایش پاسخ تشریحی
                </button>

                <?php
                $markNode  = (int) ($question['topic_id'] ?: ($question['sub_subject_id'] ?: $subject['id']));
                $markTitle = implode(' › ', array_filter([$subject['title'], $question['sub_subject_title'] ?? null, $question['topic_title'] ?? null]));
                $markQuery = array_filter(['sub' => $question['sub_subject_id'] ?: null, 'topic' => $question['topic_id'] ?: null]);
                // Some filters drop a question the moment it is answered —
                // "new" always, "wrong" when now right, "correct" when now
                // wrong. The next question then sits at this same position;
                // qbank.js picks between the two hrefs after answering.
                $nextHref    = $position < $total ? $link($position + 1) : '/student/qbank';
                $nextLabel   = $position < $total ? 'سوال بعدی ←' : 'پایان تمرین';
                $removedHref = $position < $total ? $link($position) : $base . $query();
                ?>
                <footer class="qx-foot-bar">
                    <div class="qx-tools">
                        <?php View::partial('partials.study_mark_button', [
                            'kind' => 'qbank_topic', 'refId' => $markNode, 'title' => '🧠 ' . $markTitle,
                            'url' => $base . ($markQuery !== [] ? '?' . http_build_query($markQuery) : ''),
                        ]); ?>
                        <button type="button" class="qb-tool qb-tool-lesson" data-qb-lesson
                                data-url="/student/qbank/lesson/<?= e($question['uuid']) ?>" <?= $last === null ? 'hidden' : '' ?>>📘 درسنامه</button>
                        <button type="button" class="qb-tool qb-tool-report" data-qb-report
                                data-url="/student/qbank/report/<?= e($question['uuid']) ?>" title="گزارش اشکال سوال">⚠️<span> گزارش اشکال</span></button>
                    </div>
                    <nav class="qx-nav">
                        <?php if ($position > 1): ?>
                            <a class="btn btn-ghost" href="<?= e($link($position - 1)) ?>" data-qx-prev>→ قبلی</a>
                        <?php endif; ?>
                        <a class="btn btn-primary" href="<?= e($nextHref) ?>" data-qb-next
                           data-mode="<?= e($filters['mode']) ?>"
                           data-removed-href="<?= e($removedHref) ?>"><?= e($nextLabel) ?></a>
                    </nav>
                </footer>
                <p class="qx-keys" aria-hidden="true">میان‌بر: <kbd>۱</kbd>–<kbd>۴</kbd> انتخاب گزینه · <kbd>←</kbd> بعدی · <kbd>→</kbd> قبلی</p>
            </main>

            <aside class="qb-side qx-side">
                <div class="qb-explain" data-qb-explain role="region" aria-label="پاسخ تشریحی">
                    <div class="qb-explain-head">
                        <span class="app-ic tone-violet"><?php $icon('lesson', 18); ?></span>
                        <h4>پاسخ تشریحی</h4>
                        <button type="button" class="qb-explain-x" data-qb-explain-close aria-label="بستن"><?php $icon('close', 18); ?></button>
                    </div>
                    <p class="qb-explain-wait" data-qb-explain-wait>یک گزینه را انتخاب کن؛ توضیح کامل پاسخ همین‌جا نمایش داده می‌شود.</p>
                    <div class="qb-explain-body" data-qb-explain-text></div>
                    <figure class="qb-figure"><img data-qb-explain-img alt="تصویر پاسخ تشریحی" hidden></figure>
                    <div class="qb-explain-links" data-qb-explain-links></div>
                </div>
            </aside>
        <?php endif; ?>
    </div>
</div>
