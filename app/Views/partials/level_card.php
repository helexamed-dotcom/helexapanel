<?php
/**
 * The student's site-wide level: Balin island + question bank XP.
 *
 * @var array $card from AccountController::levelCard()
 */
$lv    = $card['level'];
$rank  = $card['rank'];
$qb    = $card['qbank'];
$acc   = $qb['answered'] > 0 ? (int) round($qb['correct'] / $qb['answered'] * 100) : null;
$stats = $card['stats'];
?>
<section class="card level-card">
    <div class="level-ring" style="--p: <?= e((string) $lv['percent']) ?>;">
        <div>
            <small>سطح</small>
            <b><?= e(fa((string) $lv['level'])) ?></b>
        </div>
    </div>
    <div class="level-main">
        <div class="level-title">
            <?php if (!empty($rank['icon'])): ?><span class="level-icon"><?= e($rank['icon']) ?></span><?php endif; ?>
            <h3><?= e($rank['title'] !== '' ? $rank['title'] : 'سطح ' . fa((string) $lv['level'])) ?></h3>
        </div>
        <div class="level-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $lv['percent'] ?>">
            <i style="width: <?= e((string) $lv['percent']) ?>%;"></i>
        </div>
        <p class="muted level-sub">
            <?= e(fa(number_format($lv['xp']))) ?> XP ·
            <?= e(fa(number_format($lv['xp_for_next']))) ?> XP تا سطح <?= e(fa((string) ($lv['level'] + 1))) ?>
        </p>
        <div class="level-chips">
            <span title="رتبه کلی">🏆 <?= $card['place'] !== null ? 'رتبه ' . e(fa((string) $card['place'])) : 'بدون رتبه' ?></span>
            <span title="روزهای پیاپی">🔥 <?= e(fa((string) $card['streak'])) ?> روز</span>
            <span title="بانک سوال">🧠 <?= e(fa((string) $qb['correct'])) ?> / <?= e(fa((string) $qb['answered'])) ?> سوال<?= $acc !== null ? ' · ' . e(fa((string) $acc)) . '٪' : '' ?></span>
            <?php if ((int) ($stats['stages_completed'] ?? 0) > 0): ?>
                <span title="جزیره بالین">🏝 <?= e(fa((string) $stats['stages_completed'])) ?> مرحله</span>
            <?php endif; ?>
        </div>
        <p class="muted level-hint">XP از پاسخ‌های درست در جزیره بالین و پاسخ درست در اولین تلاش بانک سوال جمع می‌شود.</p>
    </div>
</section>