<?php
/**
 * The student's own standing, shown while the public leaderboard is off.
 *
 * @var array $rank MyRank::forStudent()
 */
use HeleXa\Services\Balin\MyRank;
use HeleXa\Services\StudyAnalytics;

$card = static function (string $icon, string $label, array $r, string $value): void {
    $medal = $r['rank'] === null ? '🌱' : ($r['rank'] === 1 ? '🥇' : ($r['rank'] === 2 ? '🥈' : ($r['rank'] === 3 ? '🥉' : '🏅')));
    ?>
    <div class="hx-rank-card">
        <div class="hx-rank-medal" aria-hidden="true"><?= e($medal) ?></div>
        <div class="hx-rank-label"><?= e($icon . ' ' . $label) ?></div>
        <?php if ($r['rank'] === null): ?>
            <div class="hx-rank-big">هنوز شروع نکرده‌ای</div>
            <div class="hx-rank-sub">با اولین فعالیت، رتبه‌ات این‌جا می‌آید.</div>
        <?php else: ?>
            <div class="hx-rank-big">نفر <?= e(MyRank::ordinal((int) $r['rank'])) ?></div>
            <div class="hx-rank-sub">از <?= e(fa((string) $r['of'])) ?> نفر · <?= e($value) ?></div>
            <?php $top = $r['of'] > 0 ? (int) round((1 - ($r['rank'] - 1) / max(1, $r['of'])) * 100) : 0; ?>
            <div class="hx-meter" style="--p: <?= $top ?>;"><i></i><b>بهتر از <?= e(fa((string) max(0, min(100, $top)))) ?>٪</b></div>
        <?php endif; ?>
    </div>
<?php };
?>
<div class="balin hx-page hx-anim">
    <nav class="balin-crumb" aria-label="مسیر">
        <a href="/student/balin">جزیره بالین</a><span aria-hidden="true">›</span><span>رتبه من</span>
    </nav>

    <section class="hx-hero hx-hero-violet">
        <div class="hx-hero-icon" aria-hidden="true">🏅</div>
        <div class="hx-hero-text">
            <h2>رتبه من</h2>
            <p>جایگاه تو بین دانشجویانی که امروز و این هفته مطالعه کرده‌اند — فقط برای خودت، بدون نام دیگران.</p>
        </div>
    </section>

    <h3 class="hx-section-title">امروز</h3>
    <div class="hx-rank-cards">
        <?php $card('⏱', 'زمان مطالعه', $rank['today']['study'], StudyAnalytics::humanDuration((int) $rank['today']['study']['value'])); ?>
        <?php $card('⚡', 'امتیاز (XP)', $rank['today']['xp'], fa((string) $rank['today']['xp']['value']) . ' XP'); ?>
    </div>

    <h3 class="hx-section-title">این هفته</h3>
    <div class="hx-rank-cards">
        <?php $card('⏱', 'زمان مطالعه', $rank['week']['study'], StudyAnalytics::humanDuration((int) $rank['week']['study']['value'])); ?>
        <?php $card('⚡', 'امتیاز (XP)', $rank['week']['xp'], fa((string) $rank['week']['xp']['value']) . ' XP'); ?>
    </div>
</div>
