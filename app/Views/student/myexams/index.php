<?php
/**
 * «آزمون‌های من»: the collection, filed by درس, the exam builder, the weak
 * spots across every finished exam, and the exam history.
 *
 * @var array $tree         درس → زیردرس → عنوان, with counts
 * @var int   $total
 * @var array $history
 * @var array $performance  weakest first
 */
$weak   = array_values(array_filter($performance, static fn ($g) => $g['percent'] < 50));
$review = array_values(array_filter($performance, static fn ($g) => $g['percent'] >= 50 && $g['percent'] < 80));
$strong = array_values(array_filter($performance, static fn ($g) => $g['percent'] >= 80));
$practiceUrl = static function (array $g): string {
    if ($g['subject_uuid'] === '') {
        return '/student/qbank';
    }
    $q = array_filter(['sub' => $g['sub_id'] ?: null, 'topic' => $g['topic_id'] ?: null]);
    return '/student/qbank/' . rawurlencode($g['subject_uuid']) . ($q !== [] ? '?' . http_build_query($q) : '');
};
?>
<div class="hx-page hx-anim">
    <section class="hx-hero hx-hero-violet">
        <div class="hx-hero-icon" aria-hidden="true">📝</div>
        <div class="hx-hero-text">
            <h2>آزمون‌های من</h2>
            <p>
                <?php if ($total > 0): ?>
                    <?= e(fa((string) $total)) ?> سوال جمع کرده‌ای، مرتب‌شده بر اساس درس. از هر بخش که خواستی آزمون بساز.
                <?php else: ?>
                    هر سوالی از بانک سوال که پسندیدی، با دکمه «➕ آزمون‌های من» این‌جا جمع کن.
                <?php endif; ?>
            </p>
        </div>
        <div class="hx-hero-actions">
            <a class="btn btn-ghost" href="/student/qbank">رفتن به بانک سوال</a>
        </div>
    </section>

    <?php if ($total === 0): ?>
        <section class="hx-empty">
            <div class="hx-empty-art" aria-hidden="true">🗂️</div>
            <h3>هنوز سوالی جمع نکرده‌ای</h3>
            <p>در بانک سوال، بالای هر سوال دکمه <b>«➕ آزمون‌های من»</b> را بزن. سوال خودکار زیر درس و بخش خودش این‌جا قرار می‌گیرد
               — مثلاً سوال‌های باکتری زیر «باکتری» — و بعد از هر بخش می‌توانی آزمون بدهی.</p>
            <a class="btn btn-primary" href="/student/qbank">شروع جمع کردن سوال</a>
        </section>
    <?php else: ?>

        <?php /* ------------------------------------------------ builder */ ?>
        <form method="post" action="/student/my-exams/start" class="hx-card hx-builder" data-exam-builder>
            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
            <div class="hx-card-head">
                <h3>🚀 ساخت آزمون</h3>
                <span class="hx-muted">سوال‌ها هر بار به ترتیب تصادفی می‌آیند.</span>
            </div>

            <div class="hx-label">از کدام بخش؟</div>
            <div class="hx-pills" role="radiogroup">
                <label class="hx-pill">
                    <input type="radio" name="scope" value="all" checked data-scope>
                    <span>همه درس‌ها <b><?= e(fa((string) $total)) ?></b></span>
                </label>
                <?php foreach ($tree as $subject): ?>
                    <label class="hx-pill">
                        <input type="radio" name="scope" value="<?= (int) $subject['id'] ?>" data-scope>
                        <span><?= e($subject['title']) ?> <b><?= e(fa((string) $subject['count'])) ?></b></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <?php foreach ($tree as $subject): if (count($subject['subs']) < 2) { continue; } ?>
                <div class="hx-subpick" data-subs-for="<?= (int) $subject['id'] ?>" hidden>
                    <div class="hx-label">زیردرس‌های <?= e($subject['title']) ?> <span class="hx-muted">(خالی = همه)</span></div>
                    <div class="hx-pills">
                        <?php foreach ($subject['subs'] as $sub): ?>
                            <label class="hx-pill hx-pill-soft">
                                <input type="checkbox" name="subs[]" value="<?= (int) $sub['id'] ?>" disabled>
                                <span><?= e($sub['title']) ?> <b><?= e(fa((string) $sub['count'])) ?></b></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="hx-builder-row">
                <label class="hx-field">
                    <span>تعداد سوال</span>
                    <input class="input" type="number" name="count" min="1" max="200" placeholder="همه" dir="ltr">
                </label>
                <label class="hx-switch">
                    <input type="checkbox" name="negative" value="1">
                    <span class="hx-switch-ui" aria-hidden="true"></span>
                    <span>
                        <b>نمره منفی</b>
                        <small>هر پاسخ غلط، یک‌سوم یک پاسخ درست را کم می‌کند (مثل کنکور)</small>
                    </span>
                </label>
                <label class="hx-switch">
                    <input type="checkbox" name="timed" value="1" data-timed>
                    <span class="hx-switch-ui" aria-hidden="true"></span>
                    <span><b>زمان‌دار</b><small>زمان که تمام شود، آزمون خودکار تحویل می‌شود</small></span>
                </label>
                <label class="hx-field" data-minutes hidden>
                    <span>زمان (دقیقه)</span>
                    <input class="input" type="number" name="minutes" min="1" max="600" value="20" dir="ltr">
                </label>
            </div>

            <button class="btn btn-primary hx-big-btn" type="submit" data-lock-on-submit>شروع آزمون ←</button>
        </form>

        <?php /* ------------------------------------------------ collection */ ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>🗂️ سوال‌های جمع‌شده</h3></div>
            <div class="hx-tree">
                <?php foreach ($tree as $subject): ?>
                    <details class="hx-tree-node" open>
                        <summary>
                            <span class="hx-tree-title">📘 <?= e($subject['title']) ?></span>
                            <span class="hx-count"><?= e(fa((string) $subject['count'])) ?> سوال</span>
                        </summary>
                        <div class="hx-tree-body">
                            <?php foreach ($subject['subs'] as $sub): ?>
                                <div class="hx-tree-sub">
                                    <div class="hx-tree-sub-head">
                                        <span>📂 <?= e($sub['title']) ?></span>
                                        <span class="hx-count"><?= e(fa((string) $sub['count'])) ?></span>
                                    </div>
                                    <?php if ($sub['topics'] !== []): ?>
                                        <div class="hx-chips">
                                            <?php foreach ($sub['topics'] as $topic): ?>
                                                <span class="hx-chip"><?= e($topic['title']) ?> · <?= e(fa((string) $topic['count'])) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php /* -------------------------------------------------- analysis */ ?>
    <?php if ($performance !== []): ?>
        <section class="hx-card">
            <div class="hx-card-head">
                <h3>🎯 تحلیل نقاط ضعف و قوت</h3>
                <span class="hx-muted">بر اساس آخرین پاسخ تو به هر سوال در همه آزمون‌ها</span>
            </div>
            <div class="hx-analysis">
                <?php foreach ([
                    ['bad', '📕 این‌ها را بخوان', 'کمتر از ۵۰٪ درست', $weak],
                    ['mid', '📙 مرور سریع کن', '۵۰ تا ۸۰٪ درست', $review],
                    ['good', '📗 مسلطی — فعلاً لازم نیست', 'بیشتر از ۸۰٪ درست', $strong],
                ] as [$tone, $label, $hint, $groups]): ?>
                    <div class="hx-bucket is-<?= e($tone) ?>">
                        <div class="hx-bucket-head"><b><?= e($label) ?></b><small><?= e($hint) ?></small></div>
                        <?php if ($groups === []): ?>
                            <p class="hx-muted">موردی نیست.</p>
                        <?php endif; ?>
                        <?php foreach ($groups as $g): ?>
                            <div class="hx-topic">
                                <div class="hx-topic-main">
                                    <a href="<?= e($practiceUrl($g)) ?>"><?= e($g['title']) ?></a>
                                    <small><?= e($g['path']) ?> · <?= e(fa((string) $g['correct'])) ?> از <?= e(fa((string) $g['total'])) ?></small>
                                </div>
                                <div class="hx-meter" style="--p: <?= (int) $g['percent'] ?>;"><i></i><b><?= e(fa((string) $g['percent'])) ?>٪</b></div>
                                <?php if ($tone !== 'good'): ?>
                                    <form method="post" action="/student/study" class="hx-inline" data-study-add>
                                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                        <input type="hidden" name="kind" value="qbank_topic">
                                        <input type="hidden" name="ref_id" value="<?= (int) $g['node_id'] ?>">
                                        <input type="hidden" name="title" value="<?= e($g['title'] . ($g['path'] !== '' ? ' — ' . $g['path'] : '')) ?>">
                                        <input type="hidden" name="url" value="<?= e($practiceUrl($g)) ?>">
                                        <input type="hidden" name="back" value="/student/my-exams">
                                        <button class="hx-mini-btn" type="submit" title="افزودن به درس‌های من">📌</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php /* --------------------------------------------------- history */ ?>
    <?php if ($history !== []): ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>🕘 آزمون‌های قبلی</h3></div>
            <div class="hx-list">
                <?php foreach ($history as $exam):
                    $count = count(json_decode((string) $exam['question_ids'], true) ?: []);
                    $done  = $exam['status'] === 'finished';
                    $score = $done ? (float) $exam['score_percent'] : null;
                    $tone  = $score === null ? '' : ($score >= 70 ? 'is-good' : ($score >= 40 ? 'is-mid' : 'is-bad'));
                ?>
                    <a class="hx-row" href="/student/my-exams/<?= e($exam['uuid']) ?>">
                        <span class="hx-score <?= e($tone) ?>">
                            <?= $done ? e(fa((string) round($score))) . '٪' : '⏳' ?>
                        </span>
                        <span class="hx-row-main">
                            <b><?= e($exam['title']) ?></b>
                            <small>
                                <?= e(fa((string) $count)) ?> سوال
                                · <?= (int) $exam['negative_marking'] === 1 ? 'با نمره منفی' : 'بدون نمره منفی' ?>
                                · <?= e(jdate($exam['started_at'])) ?>
                            </small>
                        </span>
                        <span class="hx-row-go"><?= $done ? 'کارنامه' : 'ادامه آزمون' ?> ←</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>
