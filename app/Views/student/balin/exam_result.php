<?php
/**
 * The result of a checkpoint exam.
 *
 * A percentage alone tells a student nothing they can act on, so the score
 * is followed by which skills were weak and which questions went wrong,
 * with the explanation for each. Failing is written as a next step, not a
 * verdict.
 *
 * @var array $exam
 * @var array $lesson
 * @var array $result
 * @var array $skills
 * @var array $gate
 * @var bool  $archived
 */
$passed = (bool) $result['passed'];
?>
<div class="balin">
    <nav class="balin-crumb" aria-label="مسیر">
        <a href="/student/balin">جزیره بالین</a>
        <span aria-hidden="true">›</span>
        <a href="/student/balin/lesson/<?= e($lesson['uuid']) ?>"><?= e($lesson['title']) ?></a>
        <span aria-hidden="true">›</span>
        <span><?= e($exam['title']) ?></span>
    </nav>

    <section class="balin-result <?= $passed ? 'is-pass' : 'is-fail' ?>">
        <div class="balin-result-score">
            <div class="balin-result-mark" aria-hidden="true"><?= $passed ? '🎯' : '📘' ?></div>
            <div class="balin-result-percent"><?= e(fa(round((float) $result['score_percent']))) ?>٪</div>
            <div class="balin-result-count">
                <?= e(fa((int) $result['correct'])) ?> از <?= e(fa((int) $result['total'])) ?> پاسخ صحیح
            </div>
        </div>

        <div class="balin-result-verdict">
            <?php if ($passed): ?>
                <h2>آزمون «<?= e($exam['title']) ?>» را گذراندی</h2>
                <?php if ((int) $result['xp_awarded'] > 0): ?>
                    <p><?= e(fa((int) $result['xp_awarded'])) ?>+ امتیاز برای اولین قبولی ثبت شد.</p>
                <?php elseif (!empty($result['first_pass'])): ?>
                    <p>امتیاز این آزمون قبلاً ثبت شده بود.</p>
                <?php else: ?>
                    <p>امتیاز این آزمون فقط در اولین قبولی ثبت می‌شود.</p>
                <?php endif; ?>
            <?php else: ?>
                <h2>هنوز به حد نصاب نرسیدی</h2>
                <p>
                    برای قبولی <?= e(fa((int) $exam['pass_threshold_percent'])) ?>٪ لازم است.
                    <?php if ($gate['next_attempt_at'] !== null): ?>
                        <?= e($gate['message']) ?>
                    <?php elseif ($gate['attempts_left'] !== null): ?>
                        <?= e(fa((int) $gate['attempts_left'])) ?> تلاش دیگر داری.
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($result['expired'])): ?>
                <p class="balin-foot-note">زمان آزمون تمام شده بود؛ پاسخ‌های ثبت‌شده‌ات محاسبه شد.</p>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($skills !== []): ?>
        <section class="balin-skill-report">
            <h3 class="balin-subhead">مهارت‌ها در این آزمون</h3>
            <ul class="balin-skill-list" role="list">
                <?php foreach ($skills as $row): ?>
                    <li>
                        <div class="balin-skill-row">
                            <span class="balin-skill-name">
                                <span aria-hidden="true"><?= e($row['track']['icon'] ?: '🩺') ?></span>
                                <?= e($row['track']['name']) ?>
                            </span>
                            <span class="balin-skill-score">
                                <?= e(fa((int) $row['correct'])) ?>/<?= e(fa((int) $row['total'])) ?>
                            </span>
                        </div>
                        <div class="balin-progress" role="progressbar"
                             aria-valuenow="<?= (int) $row['percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                            <span style="width:<?= (float) $row['percent'] ?>%"></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($result['breakdown'] !== []): ?>
        <section class="balin-breakdown">
            <h3 class="balin-subhead">مرور سؤال‌ها</h3>
            <?php foreach ($result['breakdown'] as $index => $row): ?>
                <details class="balin-breakdown-item <?= $row['is_correct'] ? 'is-correct' : 'is-wrong' ?>">
                    <summary>
                        <span class="balin-question-number"><?= e(fa($index + 1)) ?></span>
                        <span class="balin-breakdown-prompt">
                            <?= e(mb_substr((string) $row['question']['prompt'], 0, 110, 'UTF-8')) ?>
                        </span>
                        <span class="balin-breakdown-verdict"><?= $row['is_correct'] ? '✅' : '❌' ?></span>
                    </summary>
                    <div class="balin-breakdown-body">
                        <p><?= nl2br(e($row['question']['prompt'])) ?></p>
                        <?php if (!empty($row['question']['explanation'])): ?>
                            <div class="balin-explanation"><?= nl2br(e($row['question']['explanation'])) ?></div>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <div class="balin-result-actions">
        <a class="btn btn-primary" href="/student/balin/lesson/<?= e($lesson['uuid']) ?>">بازگشت به نقشه</a>
        <?php if (!$passed && $gate['allowed']): ?>
            <form method="post" action="/student/balin/exam/<?= e($exam['uuid']) ?>/start">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-ghost" type="submit">تلاش دوباره</button>
            </form>
        <?php endif; ?>
    </div>
</div>
