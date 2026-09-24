<?php
/**
 * A student's tier with the four things that make it up.
 *
 * @var array $tier   from StudentTier
 * @var bool  $self   true on the student's own page (wording addresses them)
 */
$steps = [
    'courses'  => 'دوره',
    'packages' => 'پکیج',
    'balin'    => 'جزیره بالین',
    'qbank'    => 'بانک سوال',
];
$next = match ($tier['tier']) {
    'none'   => 'با فعال شدن اولین دوره، کاربر برنزی می‌شوی.',
    'bronze' => 'با فعال شدن یک پکیج، کاربر نقره‌ای می‌شوی.',
    'silver' => 'با فعال شدن جزیره بالین و بانک سوال، کاربر طلایی می‌شوی.',
    default  => 'همه بخش‌های سایت برایت فعال است.',
};
?>
<div class="tier-card tier-<?= e($tier['tier']) ?>">
    <div class="tier-card-head">
        <span class="tier-card-icon" aria-hidden="true"><?= e($tier['icon']) ?></span>
        <div>
            <h3><?= e($tier['label']) ?></h3>
            <?php if (!empty($self)): ?>
                <span class="leaf-meta"><?= e($next) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="tier-steps">
        <?php foreach ($steps as $key => $label): ?>
            <span class="tier-step<?= $tier['has'][$key] ? ' is-on' : '' ?>">
                <?= $tier['has'][$key] ? '✓' : '○' ?> <?= e($label) ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>
