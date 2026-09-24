<?php
/**
 * The report card: the score (with and without negative marking), the
 * topic-by-topic analysis of this exam, and every question reviewed with
 * the key, the explanation, the درسنامه and a report button.
 *
 * @var array $exam
 * @var array $questions    with options and is_correct
 * @var array $answers
 * @var array $performance  this exam only, weakest first
 * @var array $advice       ExamAdvisor::advise()
 * @var array $difficulties
 */
$letters = ['الف', 'ب', 'ج', 'د', 'ه', 'و', 'ز', 'ح'];
$img     = static fn (?string $name): string => '/student/qbank/image/' . rawurlencode((string) $name);
$score   = (float) $exam['score_percent'];
$raw     = (float) $exam['raw_percent'];
$neg     = (int) $exam['negative_marking'] === 1;
$tone    = $score >= 70 ? 'good' : ($score >= 40 ? 'mid' : 'bad');
$minutes = $exam['finished_at'] ? max(1, (int) round((strtotime((string) $exam['finished_at']) - strtotime((string) $exam['started_at'])) / 60)) : null;
$verdict = match (true) {
    $score >= 85 => ['🏆', 'عالی بود! این بخش را خوب بلدی.'],
    $score >= 70 => ['🌟', 'خیلی خوب — چند نکته را مرور کن و کامل می‌شود.'],
    $score >= 40 => ['💪', 'نیمه راهی. بخش‌های قرمز پایین را بخوان و دوباره آزمون بده.'],
    default      => ['📚', 'این بخش را باید بخوانی. از تحلیل پایین شروع کن.'],
};
$practiceUrl = static function (array $g): string {
    if ($g['subject_uuid'] === '') {
        return '/student/qbank';
    }
    $q = array_filter(['sub' => $g['sub_id'] ?: null, 'topic' => $g['topic_id'] ?: null]);
    return '/student/qbank/' . rawurlencode($g['subject_uuid']) . ($q !== [] ? '?' . http_build_query($q) : '');
};
?>
<div class="hx-page hx-anim">
    <section class="hx-card hx-scorecard is-<?= e($tone) ?>">
        <div class="hx-ring hx-ring-xl" style="--p: <?= max(0, min(100, (int) round($score))) ?>;">
            <div>
                <b><?= e(fa((string) round($score, 1))) ?>٪</b>
                <small><?= $neg ? 'با نمره منفی' : 'نمره' ?></small>
            </div>
        </div>
        <div class="hx-scorecard-text">
            <div class="hx-verdict"><span aria-hidden="true"><?= e($verdict[0]) ?></span> <?= e($verdict[1]) ?></div>
            <h2><?= e($exam['title']) ?></h2>
            <div class="hx-stats">
                <div class="hx-stat is-good"><b><?= e(fa((string) $exam['correct_count'])) ?></b><span>درست</span></div>
                <div class="hx-stat is-bad"><b><?= e(fa((string) $exam['wrong_count'])) ?></b><span>غلط</span></div>
                <div class="hx-stat"><b><?= e(fa((string) $exam['blank_count'])) ?></b><span>نزده</span></div>
                <?php if ($neg): ?>
                    <div class="hx-stat"><b><?= e(fa((string) round($raw, 1))) ?>٪</b><span>بدون نمره منفی</span></div>
                <?php endif; ?>
                <?php if ($minutes !== null): ?>
                    <div class="hx-stat"><b><?= e(fa((string) $minutes)) ?></b><span>دقیقه</span></div>
                <?php endif; ?>
            </div>
            <div class="hx-actions">
                <a class="btn btn-primary" href="/student/my-exams">آزمون تازه</a>
                <form method="post" action="/student/my-exams/<?= e($exam['uuid']) ?>/delete" data-confirm="این آزمون و کارنامه‌اش حذف شود؟" style="margin:0;">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <button class="btn btn-ghost" type="submit">حذف این آزمون</button>
                </form>
            </div>
        </div>
    </section>

    <?php if ($advice['plan'] !== [] && ((int) $exam['wrong_count'] + (int) $exam['blank_count']) > 0): ?>
        <section class="hx-card xa-plan">
            <div class="hx-card-head"><h3>🧭 برنامه بعدی تو</h3></div>
            <ol class="xa-steps">
                <?php foreach ($advice['plan'] as $step): ?><li><?= e($step) ?></li><?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <?php if ($advice['strong'] !== [] || $advice['weak'] !== []): ?>
        <div class="xa-sw">
            <section class="hx-card xa-col is-good">
                <div class="hx-card-head"><h3>💪 نقاط قوت</h3></div>
                <?php if ($advice['strong'] === []): ?><p class="hx-muted">هنوز بخشی با ۸۰٪ یا بیشتر نیست.</p><?php endif; ?>
                <?php foreach ($advice['strong'] as $g): ?>
                    <div class="xa-chip is-good"><b><?= e($g['title']) ?></b><span><?= e(fa((string) $g['percent'])) ?>٪</span></div>
                <?php endforeach; ?>
            </section>
            <section class="hx-card xa-col is-bad">
                <div class="hx-card-head"><h3>🎯 نقاط ضعف</h3></div>
                <?php if ($advice['weak'] === []): ?><p class="hx-muted">بخش ضعیفی نداری 👏</p><?php endif; ?>
                <?php foreach ($advice['weak'] as $g): ?>
                    <a class="xa-chip is-bad" href="<?= e($practiceUrl($g)) ?>"><b><?= e($g['title']) ?></b><span><?= e(fa((string) $g['percent'])) ?>٪</span></a>
                <?php endforeach; ?>
            </section>
        </div>
    <?php endif; ?>

    <?php if ($advice['read'] !== []): ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>📘 درسنامه‌های پیشنهادی</h3><span class="hx-muted">به ترتیب اهمیت برای همین آزمون</span></div>
            <div class="xa-lessons">
                <?php foreach ($advice['read'] as $i => $r): $l = $r['lesson']; ?>
                    <div class="xa-lesson<?= $i === 0 ? ' is-top' : '' ?>">
                        <span class="xa-rank"><?= e(fa((string) ($i + 1))) ?></span>
                        <div class="xa-lesson-main">
                            <a href="/student/lessons/<?= e($l['uuid']) ?>"><?= e($l['title']) ?></a>
                            <small><?= e(fa((string) $r['missed'])) ?> سوال از دست‌رفته را توضیح می‌دهد · سوال‌های <?= e(implode('، ', array_map(static fn ($n) => fa((string) $n), array_slice($r['numbers'], 0, 8)))) ?></small>
                        </div>
                        <?php \HeleXa\Core\View::partial('partials.study_mark_button', ['kind' => 'lesson', 'refId' => (int) $l['id'], 'title' => '📘 ' . $l['title'], 'url' => '/student/lessons/' . $l['uuid']]); ?>
                        <a class="btn btn-primary btn-sm" href="/student/lessons/<?= e($l['uuid']) ?>">بخوان</a>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($advice['skip'] !== []): ?>
                <div class="xa-skip">
                    <b>فعلاً لازم نیست بخوانی:</b>
                    <?php foreach ($advice['skip'] as $l): ?><a class="xa-chip is-good" href="/student/lessons/<?= e($l['uuid']) ?>"><?= e($l['title']) ?></a><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($performance !== []): ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>🎯 چی بخونم، چی نخونم؟ — بخش به بخش</h3></div>
            <div class="hx-topics">
                <?php foreach ($performance as $g):
                    $t = $g['percent'] >= 80 ? 'good' : ($g['percent'] >= 50 ? 'mid' : 'bad');
                    $advice = ['good' => 'مسلطی — فعلاً لازم نیست بخوانی', 'mid' => 'یک مرور سریع کافی است', 'bad' => 'این بخش را کامل بخوان'][$t];
                ?>
                    <div class="hx-topic is-<?= e($t) ?>">
                        <div class="hx-topic-main">
                            <a href="<?= e($practiceUrl($g)) ?>"><?= e($g['title']) ?></a>
                            <small><?= e($advice) ?> · <?= e(fa((string) $g['correct'])) ?> از <?= e(fa((string) $g['total'])) ?></small>
                        </div>
                        <div class="hx-meter" style="--p: <?= (int) $g['percent'] ?>;"><i></i><b><?= e(fa((string) $g['percent'])) ?>٪</b></div>
                        <?php if ($t !== 'good'): ?>
                            <form method="post" action="/student/study" class="hx-inline" data-study-add>
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="kind" value="qbank_topic">
                                <input type="hidden" name="ref_id" value="<?= (int) $g['node_id'] ?>">
                                <input type="hidden" name="title" value="<?= e($g['title'] . ($g['path'] !== '' ? ' — ' . $g['path'] : '')) ?>">
                                <input type="hidden" name="url" value="<?= e($practiceUrl($g)) ?>">
                                <input type="hidden" name="back" value="/student/my-exams/<?= e($exam['uuid']) ?>">
                                <button class="hx-mini-btn" type="submit" title="افزودن به درس‌های من">📌 باید بخونم</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="hx-card">
        <div class="hx-card-head">
            <h3>🔍 مرور پاسخ‌ها</h3>
            <div class="hx-pills hx-review-filter" role="tablist">
                <button type="button" class="hx-pill-btn is-on" data-review-filter="all">همه</button>
                <button type="button" class="hx-pill-btn" data-review-filter="wrong">غلط‌ها</button>
                <button type="button" class="hx-pill-btn" data-review-filter="blank">نزده‌ها</button>
            </div>
        </div>

        <?php foreach ($questions as $n => $q):
            $a      = $answers[(int) $q['id']] ?? ['option_id' => null, 'is_correct' => null];
            $state  = $a['option_id'] === null ? 'blank' : ((int) $a['is_correct'] === 1 ? 'right' : 'wrong');
        ?>
            <article class="hx-q is-review is-<?= e($state) ?>" data-review="<?= e($state) ?>">
                <div class="hx-q-head">
                    <span class="hx-q-num"><?= e(fa((string) ($n + 1))) ?></span>
                    <span class="hx-q-state"><?= ['right' => '✓ درست', 'wrong' => '✗ غلط', 'blank' => '— نزده'][$state] ?></span>
                    <span class="hx-q-path"><?= e(implode(' › ', array_filter([$q['sub_title'], $q['topic_title']]))) ?></span>
                </div>
                <?php if (!empty($q['stem_text'])): ?>
                    <div class="hx-q-stem"><?= nl2br(e($q['stem_text'])) ?></div>
                <?php endif; ?>
                <?php if (!empty($q['stem_image'])): ?>
                    <figure class="qb-figure"><img src="<?= e($img($q['stem_image'])) ?>" alt="تصویر سوال" loading="lazy"></figure>
                <?php endif; ?>
                <div class="hx-q-options">
                    <?php foreach ($q['options'] as $i => $o):
                        $cls = (int) $o['is_correct'] === 1 ? ' is-key' : ($a['option_id'] === (int) $o['id'] ? ' is-picked-wrong' : '');
                    ?>
                        <div class="hx-opt is-static<?= $cls ?>">
                            <span class="hx-opt-letter"><?= e($letters[$i] ?? (string) ($i + 1)) ?></span>
                            <span class="hx-opt-body">
                                <?= e((string) ($o['body_text'] ?? '')) ?>
                                <?php if (!empty($o['body_image'])): ?><img src="<?= e($img($o['body_image'])) ?>" alt="" loading="lazy"><?php endif; ?>
                            </span>
                            <?php if ($a['option_id'] === (int) $o['id']): ?><span class="hx-opt-you">پاسخ تو</span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($q['explanation_text']) || !empty($q['explanation_image'])): ?>
                    <details class="hx-explain">
                        <summary>پاسخ تشریحی</summary>
                        <?php if (!empty($q['explanation_text'])): ?><div><?= nl2br(e($q['explanation_text'])) ?></div><?php endif; ?>
                        <?php if (!empty($q['explanation_image'])): ?>
                            <figure class="qb-figure"><img src="<?= e($img($q['explanation_image'])) ?>" alt="تصویر پاسخ تشریحی" loading="lazy"></figure>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>
                <?php if ($state !== 'right' && !empty($advice['perQuestion'][(int) $q['id']])): ?>
                    <div class="xa-qlinks">
                        <?php foreach ($advice['perQuestion'][(int) $q['id']] as $l): ?>
                            <a href="/student/lessons/<?= e($l['uuid']) ?>">📘 <?= e($l['title']) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="qb-tools">
                    <button type="button" class="qb-tool qb-tool-lesson" data-qb-lesson data-url="/student/qbank/lesson/<?= e($q['uuid']) ?>">📘 درسنامه</button>
                    <button type="button" class="qb-tool qb-tool-report" data-qb-report data-url="/student/qbank/report/<?= e($q['uuid']) ?>">⚠️ گزارش اشکال</button>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</div>
