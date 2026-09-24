<?php
/**
 * «امروز من».
 *
 * @var array  $user
 * @var string $todayText
 * @var array  $classes
 * @var array  $exams
 * @var int    $seconds  studied today
 * @var int    $goal     today's goal, in seconds
 * @var int    $fcDue
 * @var array  $qb       wrong, review, collected, last
 * @var array  $marks    items, counts
 * @var ?array $rank     MyRank::forStudent(), or null when ranking is off
 * @var bool   $showBalin
 * @var bool   $showQbank
 */
use HeleXa\Services\Balin\MyRank;
use HeleXa\Services\StudyAnalytics;

$first   = trim(explode(' ', trim((string) ($user['full_name'] ?? '')))[0] ?? '');
$hour    = (int) date('G');
$hello   = $hour < 12 ? 'صبح بخیر' : ($hour < 17 ? 'روز بخیر' : 'عصر بخیر');
$percent = (int) min(100, round($seconds * 100 / max(1, $goal)));
$now     = time();
$rankLine = static function (array $r, string $unit): string {
    if ($r['rank'] === null) {
        return 'هنوز ثبت نشده';
    }
    return MyRank::ordinal((int) $r['rank']) . ' از ' . fa((string) $r['of']) . ' نفر';
};
?>
<div class="hx-page hx-anim hx-today">
    <section class="hx-hero hx-hero-sky">
        <div class="hx-hero-text">
            <small class="hx-date"><?= e($todayText) ?></small>
            <h2><?= e($hello) ?><?= $first !== '' ? '، ' . e($first) : '' ?> 👋</h2>
            <p>
                <?php if ($percent >= 100): ?>
                    هدف امروزت را کامل کردی! 🎉
                <?php elseif ($seconds > 0): ?>
                    امروز <?= e(StudyAnalytics::humanDuration($seconds)) ?> مطالعه کرده‌ای؛ تا هدف امروز چیزی نمانده.
                <?php else: ?>
                    بیا امروز را با یک قدم کوچک شروع کنیم.
                <?php endif; ?>
            </p>
        </div>
        <div class="hx-ring hx-ring-lg" style="--p: <?= $percent ?>;" role="img" aria-label="پیشرفت هدف امروز <?= e(fa((string) $percent)) ?> درصد">
            <div><b><?= e(fa((string) $percent)) ?>٪</b><small>هدف امروز</small></div>
        </div>
    </section>

    <?php /* ------------------------------------------------ what to do now */ ?>
    <div class="hx-tiles">
        <a class="hx-tile is-blue" href="/student/flashcards">
            <span class="hx-tile-icon">🃏</span>
            <b><?= e(fa((string) $fcDue)) ?></b>
            <span>فلش‌کارت برای مرور</span>
        </a>
        <?php if ($showQbank): ?>
            <a class="hx-tile is-red" href="/student/qbank">
                <span class="hx-tile-icon">🔁</span>
                <b><?= e(fa((string) $qb['wrong'])) ?></b>
                <span>سوالی که غلط زدی</span>
            </a>
            <a class="hx-tile is-violet" href="/student/my-exams">
                <span class="hx-tile-icon">📝</span>
                <b><?= e(fa((string) $qb['collected'])) ?></b>
                <span>سوال در آزمون‌های من</span>
            </a>
        <?php endif; ?>
        <a class="hx-tile is-amber" href="/student/study">
            <span class="hx-tile-icon">📌</span>
            <b><?= e(fa((string) $marks['counts']['open'])) ?></b>
            <span>درس که باید بخوانی<?= $marks['counts']['due'] > 0 ? ' · ' . e(fa((string) $marks['counts']['due'])) . ' مورد امروز' : '' ?></span>
        </a>
    </div>

    <div class="hx-grid-2">
        <?php /* ------------------------------------------------ classes */ ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>🏫 کلاس‌های امروز</h3><a class="hx-link" href="/student/schedule">برنامه هفتگی</a></div>
            <?php if ($classes === []): ?>
                <p class="hx-muted">امروز کلاسی نداری. وقت خوبی برای مرور است 😉</p>
            <?php else: ?>
                <ol class="hx-timeline">
                    <?php foreach ($classes as $c): ?>
                        <li>
                            <span class="hx-time"><?= e(fa(substr((string) $c['start_time'], 0, 5))) ?></span>
                            <span class="hx-timeline-main">
                                <b><?= e($c['title']) ?></b>
                                <small><?= e(implode(' · ', array_filter([$c['teacher'] ?? '', $c['location'] ?? '', $c['term_title'] ?? '']))) ?></small>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <?php /* ------------------------------------------------ exams */ ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>⏳ امتحان‌های پیش رو</h3><a class="hx-link" href="/student/exams">همه</a></div>
            <?php if ($exams === []): ?>
                <p class="hx-muted">امتحان نزدیکی ثبت نشده است.</p>
            <?php else: ?>
                <div class="hx-countdowns">
                    <?php foreach ($exams as $exam):
                        $days = (int) floor((strtotime((string) $exam['exam_date']) - strtotime(date('Y-m-d'))) / 86400);
                    ?>
                        <div class="hx-countdown<?= $days <= 3 ? ' is-soon' : '' ?>">
                            <b><?= $days <= 0 ? 'امروز' : e(fa((string) $days)) ?></b>
                            <small><?= $days <= 0 ? '' : 'روز مانده' ?></small>
                            <span><?= e($exam['title']) ?></span>
                            <em><?= e(jdate($exam['exam_date'])) ?></em>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php /* ------------------------------------------------ rank */ ?>
        <?php if ($rank !== null): ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>🏅 رتبه من</h3><?php if ($showBalin): ?><a class="hx-link" href="/student/balin/leaderboard">جزئیات</a><?php endif; ?></div>
            <div class="hx-rank-grid">
                <div class="hx-rank">
                    <span>⏱ مطالعه امروز</span>
                    <b><?= e($rankLine($rank['today']['study'], '')) ?></b>
                    <small><?= e(StudyAnalytics::humanDuration((int) $rank['today']['study']['value'])) ?></small>
                </div>
                <div class="hx-rank">
                    <span>⚡ XP امروز</span>
                    <b><?= e($rankLine($rank['today']['xp'], '')) ?></b>
                    <small><?= e(fa((string) $rank['today']['xp']['value'])) ?> XP</small>
                </div>
                <div class="hx-rank">
                    <span>⏱ مطالعه این هفته</span>
                    <b><?= e($rankLine($rank['week']['study'], '')) ?></b>
                    <small><?= e(StudyAnalytics::humanDuration((int) $rank['week']['study']['value'])) ?></small>
                </div>
                <div class="hx-rank">
                    <span>⚡ XP این هفته</span>
                    <b><?= e($rankLine($rank['week']['xp'], '')) ?></b>
                    <small><?= e(fa((string) $rank['week']['xp']['value'])) ?> XP</small>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php /* ------------------------------------------------ reading list */ ?>
        <section class="hx-card">
            <div class="hx-card-head"><h3>📌 درس‌های من</h3><a class="hx-link" href="/student/study">همه</a></div>
            <?php if ($marks['items'] === []): ?>
                <p class="hx-muted">چیزی در فهرست نیست. هر جا دکمه «📌 باید بخونم» را دیدی، بزن.</p>
            <?php else: ?>
                <ul class="hx-marks is-compact">
                    <?php foreach ($marks['items'] as $m): ?>
                        <li class="hx-mark">
                            <form method="post" action="/student/study/<?= (int) $m['id'] ?>/toggle" class="hx-inline">
                                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                                <input type="hidden" name="back" value="/student/today">
                                <button class="hx-check" type="submit" aria-label="خواندم"></button>
                            </form>
                            <span class="hx-mark-main">
                                <?php if ($m['url']): ?><a href="<?= e($m['url']) ?>"><?= e($m['title']) ?></a>
                                <?php else: ?><b><?= e($m['title']) ?></b><?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <?php if (!empty($qb['last'])): ?>
        <a class="hx-card hx-last-exam" href="/student/my-exams/<?= e($qb['last']['uuid']) ?>">
            <span>📝 آخرین آزمونت: <b><?= e($qb['last']['title']) ?></b></span>
            <span class="hx-score"><?= e(fa((string) round((float) $qb['last']['score_percent']))) ?>٪</span>
        </a>
    <?php endif; ?>
</div>
