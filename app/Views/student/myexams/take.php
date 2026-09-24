<?php
/**
 * The answer sheet. Every tick is saved as it is made; the form also carries
 * the whole sheet, so it still works with JavaScript off. No answer key is
 * on this page — options are rendered without is_correct.
 *
 * @var array $exam
 * @var array $questions  with options (no key)
 * @var array $answers    question id → ['option_id' => ?int]
 * @var int   $deadline   unix time, 0 when untimed
 */
$letters  = ['الف', 'ب', 'ج', 'د', 'ه', 'و', 'ز', 'ح'];
$img      = static fn (?string $name): string => '/student/qbank/image/' . rawurlencode((string) $name);
$answered = count(array_filter($answers, static fn ($a) => $a['option_id'] !== null));
?>
<form method="post" action="/student/my-exams/<?= e($exam['uuid']) ?>/finish" class="hx-page hx-exam"
      data-exam-sheet data-save-url="/student/my-exams/<?= e($exam['uuid']) ?>/answer"
      data-deadline="<?= (int) $deadline ?>" data-now="<?= time() ?>">
    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

    <div class="hx-exam-bar">
        <div class="hx-exam-title">
            <b><?= e($exam['title']) ?></b>
            <small>
                <?= e(fa((string) count($questions))) ?> سوال
                · <?= (int) $exam['negative_marking'] === 1 ? 'با نمره منفی' : 'بدون نمره منفی' ?>
            </small>
        </div>
        <div class="hx-exam-progress" aria-live="polite">
            <span data-answered><?= e(fa((string) $answered)) ?></span> / <?= e(fa((string) count($questions))) ?>
            <div class="hx-exam-progress-bar"><i data-progress style="width: <?= (int) round($answered * 100 / max(1, count($questions))) ?>%;"></i></div>
        </div>
        <?php if ($deadline > 0): ?>
            <div class="hx-timer" data-timer role="timer" aria-label="زمان باقی‌مانده">--:--</div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit" data-finish>پایان و تحویل</button>
    </div>

    <?php foreach ($questions as $n => $q):
        $chosen = $answers[(int) $q['id']]['option_id'] ?? null;
    ?>
        <section class="hx-q<?= $chosen !== null ? ' is-answered' : '' ?>" id="q<?= $n + 1 ?>" data-question="<?= (int) $q['id'] ?>">
            <div class="hx-q-head">
                <span class="hx-q-num"><?= e(fa((string) ($n + 1))) ?></span>
                <span class="hx-q-path"><?= e(implode(' › ', array_filter([$q['subject_title'], $q['sub_title'], $q['topic_title']]))) ?></span>
                <button type="button" class="hx-q-clear" data-clear <?= $chosen === null ? 'hidden' : '' ?>>پاک کردن پاسخ</button>
            </div>
            <?php if (!empty($q['stem_text'])): ?>
                <div class="hx-q-stem"><?= nl2br(e($q['stem_text'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($q['stem_image'])): ?>
                <figure class="qb-figure"><img src="<?= e($img($q['stem_image'])) ?>" alt="تصویر سوال" loading="lazy"></figure>
            <?php endif; ?>
            <div class="hx-q-options">
                <?php foreach ($q['options'] as $i => $o): ?>
                    <label class="hx-opt">
                        <input type="radio" name="a[<?= (int) $q['id'] ?>]" value="<?= e($o['uuid']) ?>"
                               <?= $chosen === (int) $o['id'] ? 'checked' : '' ?>>
                        <span class="hx-opt-letter"><?= e($letters[$i] ?? (string) ($i + 1)) ?></span>
                        <span class="hx-opt-body">
                            <?= e((string) ($o['body_text'] ?? '')) ?>
                            <?php if (!empty($o['body_image'])): ?>
                                <img src="<?= e($img($o['body_image'])) ?>" alt="" loading="lazy">
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <nav class="hx-q-jump" aria-label="پرش به سوال">
        <?php foreach ($questions as $n => $q): ?>
            <a href="#q<?= $n + 1 ?>" data-jump="<?= (int) $q['id'] ?>"
               class="<?= ($answers[(int) $q['id']]['option_id'] ?? null) !== null ? 'is-done' : '' ?>"><?= e(fa((string) ($n + 1))) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="hx-exam-foot">
        <button class="btn btn-primary hx-big-btn" type="submit" data-finish>پایان آزمون و دیدن کارنامه</button>
    </div>
</form>
