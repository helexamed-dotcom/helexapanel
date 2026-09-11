<?php
/**
 * The exam paper.
 *
 * One plain form, submitted once. No per-question round trip and no verdict
 * until the whole paper is in — an exam is graded as a whole, and showing
 * each answer as it is given would turn it into a practice drill.
 *
 * @var array $exam
 * @var array $lesson
 * @var array $attempt
 * @var array $paper  each entry: question + options (no answer key)
 */
$deadline = $attempt['expires_at'] !== null ? strtotime((string) $attempt['expires_at']) : null;
?>
<div class="balin balin-exam-paper">
    <header class="balin-exam-paper-head">
        <div>
            <h2><?= e($exam['title']) ?></h2>
            <p><?= e($lesson['title']) ?> · تلاش <?= e(fa((int) $attempt['attempt_number'])) ?></p>
        </div>
        <div class="balin-exam-paper-meta">
            <span><?= e(fa(count($paper))) ?> سؤال</span>
            <span>قبولی از <?= e(fa((int) $exam['pass_threshold_percent'])) ?>٪</span>
            <?php if ($deadline !== null): ?>
                <span class="balin-deadline">تا <?= e(jdate((string) $attempt['expires_at'])) ?></span>
            <?php endif; ?>
        </div>
    </header>

    <form method="post" action="/student/balin/exam/<?= e($exam['uuid']) ?>/submit" class="balin-exam-form">
        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">

        <?php foreach ($paper as $index => $entry):
            $question = $entry['question'];
            $qid      = (int) $question['id'];
        ?>
            <fieldset class="balin-exam-question">
                <legend>
                    <span class="balin-question-number"><?= e(fa($index + 1)) ?></span>
                    <?= nl2br(e($question['prompt'])) ?>
                </legend>

                <ul class="balin-options is-form" role="list">
                    <?php foreach ($entry['options'] as $option): ?>
                        <li>
                            <label class="balin-option">
                                <input type="radio"
                                       name="answers[<?= $qid ?>]"
                                       value="<?= (int) $option['id'] ?>">
                                <span class="balin-option-label"><?= e($option['label']) ?></span>
                                <span class="balin-option-body"><?= e($option['body']) ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </fieldset>
        <?php endforeach; ?>

        <footer class="balin-exam-foot">
            <p class="balin-foot-note">
                بعد از ثبت، پاسخ‌ها قابل تغییر نیستند و گزارش تفکیکی نمایش داده می‌شود.
            </p>
            <button class="btn btn-primary" type="submit"
                    data-confirm="آزمون ثبت شود؟ بعد از ثبت امکان تغییر پاسخ‌ها نیست.">
                ثبت و مشاهده نتیجه
            </button>
        </footer>
    </form>
</div>
