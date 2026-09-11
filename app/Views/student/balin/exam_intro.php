<?php
/**
 * A checkpoint exam before it starts: the rules, and whether it can be taken
 * right now.
 *
 * Being out of attempts or inside a cooldown is stated plainly, with the
 * time the next attempt opens — it is an ordinary outcome of the rules, not
 * a failure state to be apologised for.
 *
 * @var array $exam
 * @var array $lesson
 * @var array $gate
 * @var array $attempts
 * @var ?array $open
 */
?>
<div class="balin">
    <nav class="balin-crumb" aria-label="مسیر">
        <a href="/student/balin">جزیره بالین</a>
        <span aria-hidden="true">›</span>
        <a href="/student/balin/lesson/<?= e($lesson['uuid']) ?>"><?= e($lesson['title']) ?></a>
        <span aria-hidden="true">›</span>
        <span><?= e($exam['title']) ?></span>
    </nav>

    <section class="balin-exam-intro">
        <div class="balin-exam-intro-head">
            <span class="balin-exam-mark is-large" aria-hidden="true">🎯</span>
            <div>
                <h2><?= e($exam['title']) ?></h2>
                <?php if ($exam['description']): ?><p><?= e($exam['description']) ?></p><?php endif; ?>
            </div>
        </div>

        <dl class="balin-exam-rules">
            <div><dt>تعداد سؤال</dt><dd><?= e(fa((int) $exam['num_questions'])) ?></dd></div>
            <div><dt>نمره قبولی</dt><dd><?= e(fa((int) $exam['pass_threshold_percent'])) ?>٪</dd></div>
            <div>
                <dt>تلاش مجاز</dt>
                <dd><?= $exam['max_attempts'] === null ? 'نامحدود' : e(fa((int) $exam['max_attempts'])) ?></dd>
            </div>
            <?php if ((int) $exam['cooldown_hours_between_attempts'] > 0): ?>
                <div>
                    <dt>فاصله بین تلاش‌ها</dt>
                    <dd><?= e(fa((int) $exam['cooldown_hours_between_attempts'])) ?> ساعت</dd>
                </div>
            <?php endif; ?>
            <?php if ($exam['time_limit_minutes'] !== null): ?>
                <div><dt>مدت آزمون</dt><dd><?= e(fa((int) $exam['time_limit_minutes'])) ?> دقیقه</dd></div>
            <?php endif; ?>
            <?php if ((int) $exam['xp_reward'] > 0): ?>
                <div><dt>امتیاز قبولی</dt><dd><?= e(fa((int) $exam['xp_reward'])) ?> (فقط بار اول)</dd></div>
            <?php endif; ?>
        </dl>

        <?php if ((int) $exam['is_gating'] === 1): ?>
            <div class="balin-callout is-warning">
                <div class="balin-callout-label">آزمون دروازه‌ای</div>
                <div>تا وقتی این آزمون را قبول نشوی، مرحله بعد باز نمی‌شود.</div>
            </div>
        <?php endif; ?>

        <?php if ($gate['passed_already']): ?>
            <div class="balin-callout is-finding">
                <div class="balin-callout-label">قبول شده‌ای</div>
                <div>این آزمون را قبلاً با موفقیت گذرانده‌ای.</div>
            </div>
        <?php endif; ?>

        <?php if ($gate['allowed']): ?>
            <form method="post" action="/student/balin/exam/<?= e($exam['uuid']) ?>/start">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button class="btn btn-primary" type="submit">
                    <?= $open !== null ? 'ادامه آزمون' : 'شروع آزمون' ?>
                </button>
            </form>
        <?php else: ?>
            <div class="balin-exam-blocked">
                <p><?= e($gate['message']) ?></p>
                <a class="btn btn-ghost" href="/student/balin/lesson/<?= e($lesson['uuid']) ?>">بازگشت به نقشه</a>
            </div>
        <?php endif; ?>

        <?php if ($attempts !== []): ?>
            <h3 class="balin-subhead">تلاش‌های قبلی</h3>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>تلاش</th><th>نمره</th><th>نتیجه</th><th>تاریخ</th></tr></thead>
                    <tbody>
                    <?php foreach ($attempts as $attempt): ?>
                        <tr>
                            <td><?= e(fa((int) $attempt['attempt_number'])) ?></td>
                            <td><?= $attempt['score_percent'] === null ? '—' : e(fa(round((float) $attempt['score_percent']))) . '٪' ?></td>
                            <td>
                                <?php if ($attempt['completed_at'] === null): ?>
                                    <span class="stat-chip chip-gray">ناتمام</span>
                                <?php elseif ((int) $attempt['passed'] === 1): ?>
                                    <span class="stat-chip chip-green">قبول</span>
                                <?php else: ?>
                                    <span class="stat-chip chip-red">مردود</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(jdate($attempt['completed_at'] ?? $attempt['started_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($gate['passed_already'] || $attempts !== []): ?>
                <a class="btn btn-ghost btn-sm" href="/student/balin/exam/<?= e($exam['uuid']) ?>/result">
                    مشاهده آخرین نتیجه
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
