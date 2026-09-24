<?php
/**
 * «تحلیل عملکرد»: every module's results side by side under the shared
 * tags — what to read first, what can wait, a week's plan — and the study
 * time underneath.
 *
 * @var array $tags      TagMastery::forUser()
 * @var array $summary
 * @var array $focus     up to three tags with their steps
 * @var array $strong
 * @var array $skip      pages that can wait
 * @var array $plan      seven days
 * @var int   $budget
 * @var array $budgets
 * @var array $week
 * @var int   $weeksAgo
 * @var int   $previousTotal
 * @var array $courses
 * @var array $breakdown
 */
use HeleXa\Core\View;
use HeleXa\Services\StudyAnalytics;
use HeleXa\Services\TagMastery;

$icon = static fn (string $n, int $s = 16) => View::partial('partials.icon', ['name' => $n, 'size' => $s]);
$kinds = [
    'read'     => ['📘', 'بخوان'],
    'reread'   => ['🔁', 'دوباره بخوان'],
    'practice' => ['❓', 'تمرین'],
    'flash'    => ['🃏', 'فلش‌کارت'],
    'retest'   => ['🎯', 'آزمون دوباره'],
];
$sources = ['questions' => ['❓', 'سوال و آزمون'], 'flashcards' => ['🃏', 'فلش‌کارت'], 'figures' => ['🦴', 'بازی با شکل'], 'balin' => ['🏝', 'بالین']];
$pct = static fn (?float $s): string => $s === null ? '—' : '٪' . fa((string) (int) round($s * 100));
$planMinutes = array_sum(array_column($plan, 'minutes'));
?>
<div class="an">
    <section class="hx-pagebar">
        <span class="app-ic tone-violet"><?php $icon('chart', 18); ?></span>
        <div class="hx-pagebar-text">
            <h2>تحلیل عملکرد</h2>
            <small>بانک سوال، آزمون‌ها، فلش‌کارت، بازی با شکل، بالین و درسنامه — کنار هم، بر اساس برچسب هر مبحث</small>
        </div>
        <div class="hx-pagebar-stat"><b><?= $summary['accuracy'] === null ? '—' : '٪' . e(fa((string) $summary['accuracy'])) ?></b><small>دقت کل</small></div>
        <div class="hx-pagebar-stat is-green"><b><?= e(fa((string) $summary['strong'])) ?></b><small>مسلط</small></div>
        <div class="hx-pagebar-stat is-red"><b><?= e(fa((string) ($summary['weak'] + $summary['shaky']))) ?></b><small>نیاز به کار</small></div>
        <div class="hx-pagebar-stat"><b><?= e(fa((string) $summary['untested'])) ?></b><small>محک‌نخورده</small></div>
    </section>

    <?php if ($tags === []): ?>
        <section class="an-card an-empty">
            <span class="app-ic tone-violet"><?php $icon('chart', 26); ?></span>
            <b>هنوز داده‌ای برای تحلیل نیست</b>
            <p>چند سوال از بانک سوال بزن، فلش‌کارت مرور کن یا یک صفحه درسنامه بخوان؛ از همین‌جا می‌بینی در هر مبحث کجایی و چه چیزی را اول بخوانی.</p>
            <a class="btn btn-primary btn-sm" href="/student/qbank">شروع با بانک سوال</a>
        </section>
    <?php else: ?>

    <div class="an-top">
        <section class="an-card an-focus">
            <header class="an-head"><h3>🎯 اول این‌ها</h3><small>ضعیف‌ترین و مهم‌ترین مباحث، با قدم‌های بعدی</small></header>
            <?php if ($focus === []): ?>
                <p class="an-muted">چیزی عقب نیست 👏 برای مباحث مسلط فقط هر چند روز یک مرور کوتاه کافی است.</p>
            <?php endif; ?>
            <?php foreach ($focus as $i => $t): [$label, $tone] = TagMastery::LABELS[$t['status']]; ?>
                <article class="an-topic" style="--i: <?= $i ?>">
                    <div class="an-topic-head">
                        <span class="an-ring is-<?= e($t['status']) ?>" style="--p: <?= $t['score'] === null ? 0 : (int) round($t['score'] * 100) ?>"><b><?= e($pct($t['score'])) ?></b></span>
                        <div class="an-topic-text">
                            <b>#<?= e($t['title']) ?></b>
                            <span class="an-chip tone-<?= e($tone) ?>"><?= e($label) ?></span>
                            <small class="an-src">
                                <?php foreach ($t['sources'] as $k => $s): ?><span title="<?= e($sources[$k][1]) ?>"><?= $sources[$k][0] ?> <?= e(fa((string) $s['correct'])) ?>/<?= e(fa((string) $s['total'])) ?></span><?php endforeach; ?>
                                <?php if ($t['pages'] > 0): ?><span title="صفحه درسنامه">📘 <?= e(fa((string) $t['read'])) ?>/<?= e(fa((string) $t['pages'])) ?></span><?php endif; ?>
                            </small>
                        </div>
                    </div>
                    <ol class="an-steps">
                        <?php foreach ($t['steps'] as $s): ?>
                            <li><a href="<?= e($s['url']) ?>">
                                <span class="an-step-ic"><?= $kinds[$s['kind']][0] ?></span>
                                <span class="an-step-text"><b><?= e($s['title']) ?></b><small><?= e($kinds[$s['kind']][1]) ?> · <?= e($s['sub'] ?? '') ?></small></span>
                                <em><?= e(fa((string) $s['minutes'])) ?> د</em>
                            </a></li>
                        <?php endforeach; ?>
                    </ol>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="an-card an-skip">
            <header class="an-head"><h3>✅ فعلاً لازم نیست</h3><small>در این مباحث دقتت بالای ٪<?= e(fa('80')) ?> است</small></header>
            <?php if ($strong === []): ?>
                <p class="an-muted">هنوز مبحثی به تسلط نرسیده. وقتی در یک مبحث دست‌کم ٪۸۰ درست بزنی، این‌جا می‌آید و دیگر لازم نیست دوباره بخوانی‌اش.</p>
            <?php else: ?>
                <div class="an-strong">
                    <?php foreach ($strong as $t): ?><span class="an-chip tone-green">#<?= e($t['title']) ?> · <?= e($pct($t['score'])) ?></span><?php endforeach; ?>
                </div>
                <?php if ($skip !== []): ?>
                    <small class="an-label">صفحه‌هایی که می‌توانی رد شوی</small>
                    <ul class="an-skiplist">
                        <?php foreach ($skip as $p): ?><li><a href="<?= e($p['url']) ?>"><?= e($p['title']) ?></a><small>#<?= e($p['tag']) ?></small></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <section class="an-card an-plan" id="plan">
        <header class="an-head">
            <h3>🗓 برنامه ۷ روز آینده</h3>
            <small><?= e(fa((string) $planMinutes)) ?> دقیقه در کل · هر روز حداکثر</small>
            <nav class="an-budget" aria-label="زمان روزانه">
                <?php foreach ($budgets as $b): ?>
                    <a class="<?= $b === $budget ? 'is-on' : '' ?>" href="/student/analytics?budget=<?= $b ?>#plan"><?= e(fa((string) $b)) ?> د</a>
                <?php endforeach; ?>
            </nav>
            <?php if ($planMinutes > 0): ?>
                <form method="post" action="/student/analytics/plan" class="an-save">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="budget" value="<?= (int) $budget ?>">
                    <button class="btn btn-primary btn-sm" type="submit" data-lock-on-submit>📌 افزودن به درس‌های من</button>
                </form>
            <?php endif; ?>
        </header>
        <?php if ($planMinutes === 0): ?>
            <p class="an-muted">برای مباحثی که کار دارند هنوز درسنامه یا سوالی با برچسب نیست؛ وقتی اضافه شود، برنامه این‌جا ساخته می‌شود.</p>
        <?php else: ?>
            <div class="an-week">
                <?php foreach ($plan as $d => $day): ?>
                    <div class="an-day<?= $d === 0 ? ' is-today' : '' ?><?= $day['items'] === [] ? ' is-free' : '' ?>" title="<?= e($day['long']) ?>">
                        <div class="an-day-head"><b><?= e($day['label']) ?></b><small><?= $day['minutes'] > 0 ? e(fa((string) $day['minutes'])) . ' دقیقه' : 'استراحت / مرور آزاد' ?></small></div>
                        <?php foreach ($day['items'] as $it): ?>
                            <a class="an-task k-<?= e($it['kind']) ?>" href="<?= e($it['url']) ?>">
                                <span><?= $kinds[$it['kind']][0] ?></span>
                                <span class="an-task-text"><b><?= e($it['title']) ?></b><small>#<?= e($it['tag']) ?> · <?= e(fa((string) $it['minutes'])) ?> د</small></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="an-card an-map">
        <header class="an-head"><h3>🧭 نقشه تسلط همه مباحث</h3><small>مرتب از بیشترین نیاز به کمترین</small></header>
        <div class="an-rows">
            <?php foreach ($tags as $t): [$label, $tone] = TagMastery::LABELS[$t['status']]; ?>
                <div class="an-row">
                    <b class="an-row-title">#<?= e($t['title']) ?></b>
                    <span class="an-chip tone-<?= e($tone) ?>"><?= e($label) ?></span>
                    <span class="an-bar is-<?= e($t['status']) ?>"><i style="width: <?= $t['score'] === null ? 0 : (int) round($t['score'] * 100) ?>%"></i></span>
                    <span class="an-row-pct"><?= e($pct($t['score'])) ?></span>
                    <small class="an-src">
                        <?php foreach ($t['sources'] as $k => $s): ?><span title="<?= e($sources[$k][1]) ?>"><?= $sources[$k][0] ?> <?= e(fa((string) $s['correct'])) ?>/<?= e(fa((string) $s['total'])) ?></span><?php endforeach; ?>
                        <?php if ($t['pages'] > 0): ?><span title="صفحه‌های خوانده‌شده">📘 <?= e(fa((string) $t['read'])) ?>/<?= e(fa((string) $t['pages'])) ?></span><?php endif; ?>
                    </small>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <details class="an-card an-time" <?= $tags === [] ? 'open' : '' ?>>
        <summary class="an-head">
            <h3>⏱ زمان مطالعه</h3>
            <small><?= e(StudyAnalytics::humanDuration((int) $week['total'])) ?> <?= e(['این هفته', 'هفته گذشته', 'دو هفته قبل', 'سه هفته قبل'][$weeksAgo] ?? '') ?> · هفته قبلش <?= e(StudyAnalytics::humanDuration((int) $previousTotal)) ?></small>
        </summary>
        <form method="get" action="/student/analytics" class="an-weekpick">
            <select class="input" name="week" data-auto-submit>
                <?php foreach (['هفته جاری', 'هفته گذشته', 'دو هفته قبل', 'سه هفته قبل'] as $index => $label): ?>
                    <option value="<?= $index ?>" <?= $weeksAgo === $index ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-ghost btn-sm" type="submit">نمایش</button></noscript>
        </form>
        <?php View::partial('partials.week_chart', ['week' => $week]); ?>
        <div class="an-time-grid">
            <div>
                <b class="an-label">به تفکیک دوره</b>
                <?php if ($courses === []): ?>
                    <p class="an-muted">در این هفته مطالعه‌ای ثبت نشده است.</p>
                <?php else: $maxCourse = max(1, max(array_map('intval', array_column($courses, 'seconds')))); ?>
                    <?php foreach ($courses as $course): ?>
                        <div class="an-course"><span><?= e($course['title']) ?></span><small><?= e(StudyAnalytics::humanDuration((int) $course['seconds'])) ?></small>
                            <i class="an-bar"><i style="width: <?= (int) round(((int) $course['seconds']) / $maxCourse * 100) ?>%"></i></i></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div>
                <b class="an-label">پیشرفت دوره‌ها</b>
                <?php if ($breakdown === []): ?>
                    <p class="an-muted">دوره فعالی ندارید.</p>
                <?php else: foreach ($breakdown as $row):
                    $total = (int) $row['total'];
                    $percent = $total > 0 ? (int) round((int) $row['completed'] / $total * 100) : 0; ?>
                    <div class="an-course"><a href="/student/courses/<?= e($row['uuid']) ?>"><?= e($row['title']) ?></a><small>٪<?= e(fa((string) $percent)) ?></small>
                        <i class="an-bar"><i style="width: <?= $percent ?>%"></i></i></div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </details>
</div>
