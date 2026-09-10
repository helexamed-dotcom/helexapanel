<?php
/**
 * Weekly study chart. Plain CSS bars rather than a charting library:
 * no CDN, no extra payload, and it renders correctly under the panel CSP.
 *
 * @var array $week from StudyAnalytics::week()
 */
$maxSeconds = max(1, max(array_column($week['days'], 'seconds')));
?>
<div class="chart">
    <?php foreach ($week['days'] as $day): ?>
        <?php
        $height  = $day['seconds'] > 0 ? max(4, (int) round($day['seconds'] / $maxSeconds * 100)) : 0;
        $minutes = intdiv($day['seconds'], 60);
        ?>
        <div class="chart-col<?= !empty($day['isToday']) ? ' is-today' : '' ?>">
            <div class="chart-value"><?= $minutes > 0 ? e(fa((string) $minutes)) : '' ?></div>
            <div class="chart-track" title="<?= e($day['jalali']) ?>">
                <div class="chart-bar" style="height: <?= $height ?>%"></div>
            </div>
            <div class="chart-label"><?= e($day['label']) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<div class="chart-caption">عدد بالای هر ستون، دقیقه مطالعه‌ی همان روز است.</div>
