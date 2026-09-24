<?php
/**
 * Desk flip clock. The server's clock is handed to the script so the time is
 * right even on a device whose own clock has drifted.
 *
 * @var string $dateText  today's date in Persian
 */
$pad = static function (string $unit): void { ?>
    <div class="flip" data-flip="<?= e($unit) ?>" aria-hidden="true">
        <span class="flip-half flip-static flip-top"><b>۰۰</b></span>
        <span class="flip-half flip-static flip-bottom"><b>۰۰</b></span>
        <span class="flip-leaf">
            <span class="flip-half flip-top leaf-front"><b>۰۰</b></span>
            <span class="flip-half leaf-back"><b>۰۰</b></span>
        </span>
    </div>
<?php };
?>
<div class="flipclock" data-flipclock data-server-ms="<?= (int) round(microtime(true) * 1000) ?>"
     data-zone="<?= e(date_default_timezone_get()) ?>" role="timer" aria-label="ساعت">
    <div class="flip-unit"><?php $pad('h'); ?><small>ساعت</small></div>
    <span class="flip-sep" aria-hidden="true">:</span>
    <div class="flip-unit"><?php $pad('m'); ?><small>دقیقه</small></div>
    <span class="flip-sep" aria-hidden="true">:</span>
    <div class="flip-unit"><?php $pad('s'); ?><small>ثانیه</small></div>
    <?php if (!empty($dateText)): ?>
        <div class="flip-date"><strong>امروز</strong><span><?= e($dateText) ?></span></div>
    <?php endif; ?>
</div>