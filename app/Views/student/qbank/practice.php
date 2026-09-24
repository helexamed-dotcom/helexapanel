<?php
/**
 * One question at a time, with filters, history and personal marks.
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
$query    = static fn (array $extra = []): string => ($q = http_build_query($keep + $extra)) !== '' ? '?' . $q : '';
$link     = static fn (int $n): string => $base . $query(['n' => $n]);
$accuracy = $stats['answered'] > 0 ? (int) round($stats['correct'] * 100 / $stats['answered']) : null;
?>
<div class="qb-page" style="max-width:900px;">
    <section class="qb-hero">
        <div class="qb-hero-main">
            <div class="qb-hero-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'qbank']); ?></div>
            <div>
                <h2><?= e($subject['title']) ?></h2>
                <p>
                    <?= e(fa((string) $stats['distinct'])) ?> سوال تمرین‌شده
                    <?php if ($accuracy !== null): ?> · دقت <?= e(fa((string) $accuracy)) ?>٪<?php endif; ?>
                </p>
            </div>
        </div>
        <div class="qb-hero-actions">
            <a class="btn btn-ghost" href="<?= e($base . '/list' . $query()) ?>">نمای فهرستی</a>
            <a class="btn btn-ghost" href="/student/qbank">همه درس‌ها</a>
        </div>
    </section>

    <?php \HeleXa\Core\View::partial('student.qbank._filters', [
        'action' => $base, 'filters' => $filters, 'filing' => $filing,
        'allTags' => $allTags, 'difficulties' => $difficulties, 'open' => $keep !== [],
    ]); ?>

    <?php if ($question === null): ?>
        <section class="qb-section qb-gate">
            <h3 style="margin:0;">
                <?= match ($filters['mode']) {
                    'wrong'  => 'سوال غلطی برای مرور نداری 🎉',
                    'new'    => 'همه سوال‌های این بخش را تمرین کرده‌ای 🎉',
                    'saved'  => 'هنوز سوالی را نشان نکرده‌ای.',
                    'review' => 'سوالی در فهرست «نیاز به مرور» نیست.',
                    default  => 'سوالی با این فیلترها پیدا نشد.',
                } ?>
            </h3>
        </section>
    <?php else: ?>
        <section class="qb-section qb-player" data-qb-player
                 data-answer-url="/student/qbank/answer/<?= e($question['uuid']) ?>"
                 data-mark-url="/student/qbank/mark/<?= e($question['uuid']) ?>">
            <div class="qb-counter">
                <strong>سوال <?= e(fa((string) $position)) ?> از <?= e(fa((string) $total)) ?></strong>
                <div class="qb-progress qb-counter-bar" aria-hidden="true">
                    <i style="width: <?= (int) round($position * 100 / max(1, $total)) ?>%;"></i>
                </div>
                <div class="qb-marks">
                    <button type="button" class="qb-mark<?= in_array('saved', $marks, true) ? ' is-on' : '' ?>"
                            data-qb-mark="saved" aria-pressed="<?= in_array('saved', $marks, true) ? 'true' : 'false' ?>">⭐ نشان</button>
                    <button type="button" class="qb-mark<?= in_array('review', $marks, true) ? ' is-on' : '' ?>"
                            data-qb-mark="review" aria-pressed="<?= in_array('review', $marks, true) ? 'true' : 'false' ?>">🔁 نیاز به مرور</button>
                    <button type="button" class="qb-mark qb-mark-exam<?= in_array('exam', $marks, true) ? ' is-on' : '' ?>"
                            data-qb-mark="exam" data-on-label="✓ در آزمون‌های من" data-off-label="➕ آزمون‌های من"
                            aria-pressed="<?= in_array('exam', $marks, true) ? 'true' : 'false' ?>">
                        <?= in_array('exam', $marks, true) ? '✓ در آزمون‌های من' : '➕ آزمون‌های من' ?>
                    </button>
                </div>
            </div>

            <div class="qb-history<?= $last === null ? '' : ($last['is_correct'] ? ' is-right' : ' is-wrong') ?>"
                 style="display:flex; width:100%; justify-content:center; text-align:center; flex-wrap:wrap; padding:8px 12px; margin:8px 0 4px; border-radius:12px; font-size:13.5px; line-height:1.8;">
                <?php if ($last === null): ?>
                    این سوال را هنوز پاسخ نداده‌اید
                <?php elseif ($last['is_correct']): ?>
                    ✓ این سوال را قبلاً درست پاسخ داده‌اید
                <?php else: ?>
                    ✗ این سوال را قبلاً غلط پاسخ داده‌اید
                <?php endif; ?>
            </div>

            <div class="qb-row-meta">
                <span class="qb-diff <?= e($question['difficulty']) ?>"><?= e($difficulties[$question['difficulty']] ?? '') ?></span>
                <?php $path = array_filter([$question['sub_subject_title'], $question['topic_title']]); ?>
                <?php if ($path !== []): ?>
                    <span class="qb-path"><?php foreach ($path as $part): ?><span><?= e($part) ?></span><?php endforeach; ?></span>
                <?php endif; ?>
                <?php foreach ($tags as $tag): ?>
                    <span class="stat-chip <?= e($tag['color'] ?: 'chip-gray') ?>"><?= e($tag['title']) ?></span>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($question['stem_text'])): ?>
                <div class="qb-stem"><?= e($question['stem_text']) ?></div>
            <?php endif; ?>
            <?php if (!empty($question['stem_image'])): ?>
                <figure class="qb-figure"><img src="<?= e($img($question['stem_image'])) ?>" alt="تصویر سوال"></figure>
            <?php endif; ?>

            <div class="qb-answers" role="group" aria-label="گزینه‌ها">
                <?php foreach ($options as $i => $option): ?>
                    <button type="button" class="qb-answer" data-qb-answer="<?= e($option['uuid']) ?>">
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

            <div class="qb-verdict" data-qb-verdict role="status" aria-live="polite"></div>
            <p class="qb-hint" data-qb-share-note hidden></p>

            <div class="qb-explain" data-qb-explain>
                <h4>پاسخ تشریحی</h4>
                <div data-qb-explain-text></div>
                <figure class="qb-figure"><img data-qb-explain-img alt="تصویر پاسخ تشریحی" hidden></figure>
            </div>

            <?php /* The lesson opens once the question is answered; the report is always there. */ ?>
            <?php
            $markNode  = (int) ($question['topic_id'] ?: ($question['sub_subject_id'] ?: $subject['id']));
            $markTitle = implode(' › ', array_filter([$subject['title'], $question['sub_subject_title'] ?? null, $question['topic_title'] ?? null]));
            $markQuery = array_filter(['sub' => $question['sub_subject_id'] ?: null, 'topic' => $question['topic_id'] ?: null]);
            ?>
            <div class="qb-tools">
                <?php \HeleXa\Core\View::partial('partials.study_mark_button', [
                    'kind' => 'qbank_topic', 'refId' => $markNode, 'title' => '🧠 ' . $markTitle,
                    'url' => $base . ($markQuery !== [] ? '?' . http_build_query($markQuery) : ''),
                ]); ?>
                <button type="button" class="qb-tool qb-tool-lesson" data-qb-lesson
                        data-url="/student/qbank/lesson/<?= e($question['uuid']) ?>" <?= $last === null ? 'hidden' : '' ?>>
                    📘 درسنامه این بخش
                </button>
                <button type="button" class="qb-tool qb-tool-report" data-qb-report
                        data-url="/student/qbank/report/<?= e($question['uuid']) ?>">
                    ⚠️ گزارش اشکال سوال
                </button>
            </div>

            <nav class="qb-nav">
                <?php if ($position > 1): ?>
                    <a class="btn btn-ghost" href="<?= e($link($position - 1)) ?>">→ قبلی</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <?php
                /*
                 * Some filters drop a question the moment it is answered —
                 * "new" always, "wrong" when now right, "correct" when now
                 * wrong. The next question then sits at this same position.
                 * Which case applies is only known after answering, so
                 * qbank.js picks between the two hrefs then; skipping without
                 * answering keeps the plain one.
                 */
                $nextHref    = $position < $total ? $link($position + 1) : '/student/qbank';
                $nextLabel   = $position < $total ? 'سوال بعدی ←' : 'پایان تمرین';
                // At the end of the set there is no "same position" left, so
                // it goes back to the start of whatever remains — in "new"
                // that is exactly the questions skipped along the way.
                $removedHref = $position < $total ? $link($position) : $base . $query();
                ?>
                <a class="btn btn-primary" href="<?= e($nextHref) ?>" data-qb-next
                   data-mode="<?= e($filters['mode']) ?>"
                   data-removed-href="<?= e($removedHref) ?>"><?= e($nextLabel) ?></a>
            </nav>
        </section>
    <?php endif; ?>
</div>
