<?php
/**
 * The student's home — dashboard, «امروز من» and «درس‌های من» in one.
 *
 * @var array  $user
 * @var string $todayText
 * @var array  $countdowns   Countdowns::forStudent()
 * @var int    $serverMs
 * @var int    $seconds      studied today
 * @var int    $goal         today's goal in seconds
 * @var array  $week         StudyAnalytics::week()
 * @var int    $lastWeek
 * @var array  $classes
 * @var array  $exams
 * @var int    $fcDue
 * @var ?array $qb
 * @var array  $marks        items, counts
 * @var array  $kinds
 * @var array  $continue
 * @var int    $courseCount
 * @var ?array $rank
 * @var ?array $level        Points::summary()
 * @var array  $shortcuts
 * @var array  $securityFlags
 * @var array  $typeState
 * @var array  $typeOptions
 */
use HeleXa\Core\View;
use HeleXa\Services\Balin\MyRank;
use HeleXa\Services\Modules;
use HeleXa\Services\StudyAnalytics;

$icon    = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$first   = trim(explode(' ', trim((string) ($user['full_name'] ?? '')))[0] ?? '');
$hour    = (int) date('G');
$hello   = $hour < 5 ? 'شب بخیر' : ($hour < 12 ? 'صبح بخیر' : ($hour < 17 ? 'روز بخیر' : 'عصر بخیر'));
$percent = (int) min(100, round($seconds * 100 / max(1, $goal)));
$open    = array_values(array_filter($marks['items'], static fn (array $m): bool => $m['done_at'] === null));
$done    = array_values(array_filter($marks['items'], static fn (array $m): bool => $m['done_at'] !== null));
$today   = date('Y-m-d');
$weekTotal = (int) $week['total'];
$delta   = $weekTotal - $lastWeek;
?>
<div class="home hx-anim">

    <?php if (!empty($securityFlags)): ?>
        <div class="alert alert-error" role="alert">
            <div>⚠ در روزهای اخیر به حساب شما از چند شبکه‌ی مختلف وارد شده‌اند.
                <a href="/account/sessions">جزئیات ورودها</a>.</div>
        </div>
    <?php endif; ?>

    <?php /* ------------------------------------------ hello + countdown */ ?>
    <div class="home-top">
        <section class="home-hello">
            <div class="home-hello-text">
                <small><?= e($todayText) ?></small>
                <h2><?= e($hello) ?><?= $first !== '' ? '، ' . e($first) : '' ?> <span class="wave" aria-hidden="true">👋</span></h2>
                <p>
                    <?php if ($percent >= 100): ?>هدف امروزت را کامل کردی! 🎉
                    <?php elseif ($seconds > 0): ?>امروز <?= e(StudyAnalytics::humanDuration($seconds)) ?> مطالعه کرده‌ای — ادامه بده.
                    <?php else: ?>بیا امروز را با یک قدم کوچک شروع کنیم.<?php endif; ?>
                </p>
                <?php if ($level !== null): ?>
                    <a class="home-level" href="/account/profile">
                        <span class="home-level-league tone-<?= e($level['league']['color']) ?>"><?= e($level['league']['emoji']) ?></span>
                        <span class="home-level-text">
                            <b>سطح <?= e(fa((string) $level['progress']['level'])) ?> · <?= e($level['league']['title']) ?></b>
                            <span class="home-level-bar"><i style="width: <?= (float) $level['progress']['percent'] ?>%"></i></span>
                            <small><?= e(fa((string) $level['progress']['xp_for_next'])) ?> امتیاز تا سطح بعد<?= $level['streak'] > 0 ? ' · 🔥 ' . e(fa((string) $level['streak'])) . ' روز پشت‌سرهم' : '' ?></small>
                        </span>
                    </a>
                <?php endif; ?>
            </div>
            <div class="home-goal" style="--p: <?= $percent ?>" role="img" aria-label="هدف امروز <?= e(fa((string) $percent)) ?> درصد">
                <svg viewBox="0 0 120 120" aria-hidden="true">
                    <circle cx="60" cy="60" r="52" class="home-goal-track"/>
                    <circle cx="60" cy="60" r="52" class="home-goal-fill" pathLength="100"/>
                </svg>
                <div><b><?= e(fa((string) $percent)) ?>٪</b><small>هدف امروز</small></div>
            </div>
        </section>

        <?php if ($countdowns !== []): ?>
            <section class="cd-card" data-countdowns data-server-ms="<?= (int) $serverMs ?>">
                <?php foreach ($countdowns as $i => $c): ?>
                    <div class="cd tone-<?= e($c['color'] ?? 'violet') ?>" data-cd data-at="<?= e(str_replace(' ', 'T', (string) $c['at'])) ?>" <?= $i === 0 ? '' : 'hidden' ?>>
                        <div class="cd-head">
                            <span class="cd-ic"><?php $icon('hourglass'); ?></span>
                            <div>
                                <b><?= e($c['title']) ?></b>
                                <small><?= e(($c['subtitle'] ?? '') !== '' ? $c['subtitle'] : jdate($c['at'])) ?></small>
                            </div>
                        </div>
                        <div class="cd-units" aria-live="off">
                            <?php foreach (['d' => 'روز', 'h' => 'ساعت', 'm' => 'دقیقه', 's' => 'ثانیه'] as $u => $label): ?>
                                <div class="cd-unit">
                                    <div class="flip" data-flip="<?= $u ?>">
                                        <span class="flip-half flip-static flip-top"><b>۰۰</b></span>
                                        <span class="flip-half flip-static flip-bottom"><b>۰۰</b></span>
                                        <span class="flip-leaf"><span class="flip-half flip-top leaf-front"><b>۰۰</b></span><span class="flip-half leaf-back"><b>۰۰</b></span></span>
                                    </div>
                                    <small><?= e($label) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="cd-done" data-cd-done hidden>🎉 رسید! موفق باشی.</p>
                    </div>
                <?php endforeach; ?>
                <?php if (count($countdowns) > 1): ?>
                    <div class="cd-dots" role="tablist" aria-label="روزشمارها">
                        <?php foreach ($countdowns as $i => $c): ?>
                            <button type="button" role="tab" data-cd-dot="<?= $i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="<?= e($c['title']) ?>"></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <?php /* ------------------------------------------- student type ask */ ?>
    <?php if ($typeOptions !== [] && $typeState['type'] === null && ($typeState['request']['status'] ?? '') !== 'pending'): ?>
        <section class="home-card home-type">
            <div class="home-card-head">
                <h3><?php $icon('school', 18); ?> چه نوع دانشجویی هستی؟</h3>
            </div>
            <p class="home-muted">انتخاب کن تا بخش‌های مخصوص خودت برایت فعال شود. مدیر درخواستت را بررسی می‌کند.</p>
            <form method="post" action="/student/type" class="type-pick">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <?php foreach ($typeOptions as $t): ?>
                    <label class="type-opt tone-<?= e($t['color']) ?>">
                        <input type="radio" name="type_id" value="<?= (int) $t['id'] ?>" required>
                        <span class="app-ic"><?php $icon($t['icon'] ?: 'school'); ?></span>
                        <b><?= e($t['title']) ?></b>
                        <?php if (!empty($t['description'])): ?><small><?= e($t['description']) ?></small><?php endif; ?>
                    </label>
                <?php endforeach; ?>
                <div class="type-pick-foot">
                    <input class="input" name="note" maxlength="300" placeholder="توضیح (اختیاری) — مثلاً ترم و دانشگاه">
                    <button class="btn btn-primary" type="submit">ارسال درخواست</button>
                </div>
            </form>
        </section>
    <?php elseif (($typeState['request']['status'] ?? '') === 'pending'): ?>
        <div class="home-note">
            <?php $icon('clock', 18); ?>
            درخواست «<?= e($typeState['request']['type_title']) ?>» در انتظار تأیید مدیر است.
        </div>
    <?php endif; ?>

    <?php /* ------------------------------------------------ shortcuts */ ?>
    <?php if ($shortcuts !== []): ?>
        <nav class="home-apps" aria-label="میان‌برها">
            <?php foreach ($shortcuts as $s): ?>
                <a class="home-app hx-zoom" href="<?= e($s['href']) ?>">
                    <span class="app-ic tone-<?= e($s['tone']) ?>"><?php $icon($s['icon']); ?></span>
                    <b><?= e($s['label']) ?></b>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php /* --------------------------------------------- today's to-dos */ ?>
    <div class="home-stats">
        <?php if (Modules::enabled('flashcards')): ?>
            <a class="home-stat tone-rose" href="/student/flashcards/study">
                <span class="app-ic"><?php $icon('cards'); ?></span>
                <b><?= e(fa((string) $fcDue)) ?></b><small>کارت آماده مرور</small>
            </a>
        <?php endif; ?>
        <?php if ($qb !== null): ?>
            <a class="home-stat tone-red" href="/student/qbank">
                <span class="app-ic"><?php $icon('refresh'); ?></span>
                <b><?= e(fa((string) $qb['wrong'])) ?></b><small>سوال غلط برای رفع</small>
            </a>
        <?php endif; ?>
        <a class="home-stat tone-amber" href="#my-study">
            <span class="app-ic"><?php $icon('bookmark'); ?></span>
            <b><?= e(fa((string) $marks['counts']['open'])) ?></b>
            <small>درس برای خواندن<?= $marks['counts']['due'] > 0 ? ' · ' . e(fa((string) $marks['counts']['due'])) . ' امروز' : '' ?></small>
        </a>
        <?php if (Modules::enabled('planner')): ?>
            <a class="home-stat tone-sky" href="/student/planner">
                <span class="app-ic"><?php $icon('calendar'); ?></span>
                <b><?= e(fa((string) count($classes))) ?></b><small>کلاس امروز</small>
            </a>
        <?php endif; ?>
        <div class="home-stat tone-green">
            <span class="app-ic"><?php $icon('chart'); ?></span>
            <b><?= e(StudyAnalytics::humanDuration($weekTotal)) ?></b>
            <small>این هفته<?php if ($lastWeek > 0 || $weekTotal > 0): ?> · <?= $delta >= 0 ? '▲' : '▼' ?> <?= e(StudyAnalytics::humanDuration(abs($delta))) ?><?php endif; ?></small>
        </div>
    </div>

    <div class="home-grid">
        <?php /* ------------------------------------------ my reading list */ ?>
        <section class="home-card home-study" id="my-study">
            <div class="home-card-head">
                <h3><?php $icon('bookmark', 18); ?> درس‌های من</h3>
                <span class="home-count"><?= e(fa((string) count($open))) ?> مانده</span>
            </div>
            <form method="post" action="/student/study" class="study-add" data-study-form>
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <input type="hidden" name="kind" value="custom">
                <input type="hidden" name="back" value="/student">
                <input class="input" name="title" maxlength="191" placeholder="چه چیزی باید بخوانی؟" required>
                <input class="input" type="date" name="due_date" dir="ltr" title="تا چه روزی (اختیاری)">
                <button class="btn btn-primary" type="submit" aria-label="افزودن"><?php $icon('plus'); ?></button>
            </form>
            <?php if ($open === []): ?>
                <p class="home-muted home-empty-line">چیزی در فهرست نیست. هر جا «📌 باید بخونم» دیدی بزن، یا همین‌جا بنویس.</p>
            <?php endif; ?>
            <ul class="study-list" data-study-list>
                <?php foreach ($open as $m):
                    [$kIcon, $kLabel] = $kinds[$m['kind']] ?? ['✍️', ''];
                    $late = $m['due_date'] !== null && $m['due_date'] < $today;
                    $due  = $m['due_date'] !== null && $m['due_date'] === $today; ?>
                    <li class="study-item<?= $late ? ' is-late' : ($due ? ' is-due' : '') ?>" data-study-item>
                        <form method="post" action="/student/study/<?= (int) $m['id'] ?>/toggle" data-study-toggle>
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <input type="hidden" name="back" value="/student">
                            <button class="study-check" type="submit" aria-label="خواندم"></button>
                        </form>
                        <span class="study-main">
                            <?php if ($m['url']): ?><a href="<?= e($m['url']) ?>"><?= e($m['title']) ?></a><?php else: ?><b><?= e($m['title']) ?></b><?php endif; ?>
                            <small><?= e($kIcon . ' ' . $kLabel) ?><?php if ($m['due_date']): ?> · <?= $late ? 'عقب افتاده — ' : ($due ? 'امروز — ' : 'تا ') ?><?= e(jdate($m['due_date'])) ?><?php endif; ?></small>
                        </span>
                        <form method="post" action="/student/study/<?= (int) $m['id'] ?>/delete" data-study-delete>
                            <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                            <input type="hidden" name="back" value="/student">
                            <button class="study-x" type="submit" aria-label="حذف"><?php $icon('close', 16); ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($done !== []): ?>
                <details class="study-done">
                    <summary>خوانده‌شده (<?= e(fa((string) count($done))) ?>)</summary>
                    <ul class="study-list">
                        <?php foreach (array_slice($done, 0, 20) as $m): ?>
                            <li class="study-item is-done">
                                <form method="post" action="/student/study/<?= (int) $m['id'] ?>/toggle">
                                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="back" value="/student">
                                    <button class="study-check is-on" type="submit" aria-label="برگرداندن"></button>
                                </form>
                                <span class="study-main"><s><?= e($m['title']) ?></s><small><?= e(jdate($m['done_at'])) ?></small></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post" action="/student/study/clear-done" data-confirm="همه موارد خوانده‌شده پاک شوند؟">
                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                        <input type="hidden" name="back" value="/student">
                        <button class="btn btn-ghost btn-sm" type="submit">پاک کردن خوانده‌شده‌ها</button>
                    </form>
                </details>
            <?php endif; ?>
        </section>

        <div class="home-col">
            <?php if (Modules::enabled('planner')): ?>
                <section class="home-card">
                    <div class="home-card-head">
                        <h3><?php $icon('calendar', 18); ?> امروز</h3>
                        <a class="home-link" href="/student/planner">برنامه کامل</a>
                    </div>
                    <?php if ($classes === []): ?>
                        <p class="home-muted">امروز کلاسی نداری — وقت خوبی برای مرور است 😉</p>
                    <?php else: ?>
                        <ol class="home-timeline">
                            <?php foreach ($classes as $c):
                                $from = substr((string) $c['start_time'], 0, 5);
                                $to   = substr((string) $c['end_time'], 0, 5);
                                $now  = date('H:i');
                                $state = $now >= $to ? 'is-past' : ($now >= $from ? 'is-now' : ''); ?>
                                <li class="<?= $state ?>">
                                    <span class="home-time"><?= e(fa($from)) ?></span>
                                    <span class="home-tl-main">
                                        <b><?= e($c['title']) ?></b>
                                        <small><?= e(implode(' · ', array_filter([$c['teacher'] ?? '', $c['location'] ?? '']))) ?></small>
                                    </span>
                                    <?php if ($state === 'is-now'): ?><span class="home-live">در حال برگزاری</span><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>

                    <?php if ($exams !== []): ?>
                        <div class="home-sub">امتحان‌های پیش رو</div>
                        <div class="home-exams">
                            <?php foreach ($exams as $exam):
                                $days = (int) floor((strtotime((string) $exam['exam_date']) - strtotime($today)) / 86400); ?>
                                <div class="home-exam<?= $days <= 3 ? ' is-soon' : '' ?>">
                                    <b><?= $days <= 0 ? 'امروز' : ($days === 1 ? 'فردا' : e(fa((string) $days))) ?></b>
                                    <?php if ($days > 1): ?><small>روز</small><?php endif; ?>
                                    <span><?= e($exam['title']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($continue !== []): ?>
                <section class="home-card">
                    <div class="home-card-head">
                        <h3><?php $icon('play', 18); ?> ادامه مطالعه</h3>
                        <a class="home-link" href="/student/courses"><?= e(fa((string) $courseCount)) ?> دوره</a>
                    </div>
                    <div class="home-continue">
                        <?php foreach ($continue as $item): ?>
                            <a class="home-cont hx-zoom" href="/content/<?= e($item['content_uuid']) ?>" style="--c: <?= e($item['color'] ?: '#2563eb') ?>">
                                <span class="home-cont-bar"></span>
                                <span class="home-cont-main">
                                    <b><?= e($item['content_title']) ?></b>
                                    <small><?= e($item['course_title']) ?> · <?= e(StudyAnalytics::humanDuration((int) $item['total_seconds'])) ?></small>
                                </span>
                                <span class="home-cont-go"><?php $icon('play', 16); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>

    <div class="home-grid is-even">
        <section class="home-card">
            <div class="home-card-head">
                <h3><?php $icon('chart', 18); ?> مطالعه این هفته</h3>
                <?php if (Modules::enabled('analytics')): ?><a class="home-link" href="/student/analytics">تحلیل کامل</a><?php endif; ?>
            </div>
            <?php View::partial('partials.week_chart', ['week' => $week]); ?>
        </section>

        <?php if ($rank !== null || ($qb['last'] ?? null)): ?>
            <section class="home-card">
                <div class="home-card-head">
                    <h3><?php $icon('trophy', 18); ?> رتبه و آزمون</h3>
                    <a class="home-link" href="/account/profile#league">لیگ من</a>
                </div>
                <?php if ($rank !== null):
                    $line = static fn (array $r): string => $r['rank'] === null ? '—' : MyRank::ordinal((int) $r['rank']) . ' از ' . fa((string) $r['of']); ?>
                    <div class="home-ranks">
                        <div><small>مطالعه امروز</small><b><?= e($line($rank['today']['study'])) ?></b></div>
                        <div><small>امتیاز امروز</small><b><?= e($line($rank['today']['xp'])) ?></b></div>
                        <div><small>مطالعه هفته</small><b><?= e($line($rank['week']['study'])) ?></b></div>
                        <div><small>امتیاز هفته</small><b><?= e($line($rank['week']['xp'])) ?></b></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($qb['last'])): $score = (int) round((float) $qb['last']['score_percent']); ?>
                    <a class="home-last" href="/student/my-exams/<?= e($qb['last']['uuid']) ?>">
                        <span class="hx-score <?= $score >= 70 ? 'is-good' : ($score >= 40 ? 'is-mid' : 'is-bad') ?>"><?= e(fa((string) $score)) ?>٪</span>
                        <span><small>آخرین آزمون</small><b><?= e($qb['last']['title']) ?></b></span>
                        <span class="home-link">تحلیل ←</span>
                    </a>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>
