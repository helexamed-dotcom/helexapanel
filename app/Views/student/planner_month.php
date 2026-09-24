<?php
/**
 * @var array  $grid
 * @var array  $byDate
 * @var string $selected
 * @var string $selectedFa
 * @var array  $dayItems
 * @var array  $dayClasses
 * @var array  $prev
 * @var array  $next
 * @var array  $weekdays
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$typeLabel = ['exam' => 'امتحان', 'midterm' => 'میان‌ترم', 'holiday' => 'تعطیلی', 'deadline' => 'مهلت', 'reminder' => 'یادآوری', 'event' => 'رویداد', 'custom' => 'سایر'];
$typeTone  = ['exam' => 'red', 'midterm' => 'amber', 'holiday' => 'green', 'deadline' => 'rose', 'reminder' => 'blue', 'event' => 'sky', 'custom' => 'slate'];
$base = '/student/planner?tab=month';
?>
<div class="pl-month-wrap">
    <section class="pl-card">
        <header class="pl-head">
            <div><h3><?= e($grid['monthName']) ?> <?= e(fa((string) $grid['year'])) ?></h3></div>
            <div class="pl-nav">
                <a class="ad-icon-btn" href="<?= $base ?>&year=<?= (int) $prev['year'] ?>&month=<?= (int) $prev['month'] ?>" aria-label="ماه قبل"><?php $icon('chevron', 18); ?></a>
                <a class="pl-chip" href="<?= $base ?>">امروز</a>
                <a class="ad-icon-btn pl-flip" href="<?= $base ?>&year=<?= (int) $next['year'] ?>&month=<?= (int) $next['month'] ?>" aria-label="ماه بعد"><?php $icon('chevron', 18); ?></a>
            </div>
        </header>
        <div class="pl-cal">
            <?php foreach ($weekdays as $label): ?><div class="pl-cal-head"><?= e(mb_substr($label, 0, 1)) ?></div><?php endforeach; ?>
            <?php for ($b = 0; $b < (int) $grid['leadingBlanks']; $b++): ?><div></div><?php endfor; ?>
            <?php foreach ($grid['days'] as $day): $items = $byDate[$day['date']] ?? []; ?>
                <a class="pl-cal-cell<?= $day['isToday'] ? ' is-today' : '' ?><?= $day['date'] === $selected ? ' is-selected' : '' ?>"
                   href="<?= $base ?>&year=<?= (int) $grid['year'] ?>&month=<?= (int) $grid['month'] ?>&day=<?= e($day['date']) ?>">
                    <span><?= e(fa((string) $day['day'])) ?></span>
                    <?php if ($items !== []): ?>
                        <i class="pl-dots"><?php foreach (array_slice($items, 0, 3) as $it): ?><b class="tone-<?= e($typeTone[$it['type']] ?? 'slate') ?>"></b><?php endforeach; ?></i>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="pl-legend">
            <?php foreach (['exam', 'midterm', 'holiday', 'deadline', 'event'] as $t): ?>
                <span><b class="tone-<?= e($typeTone[$t]) ?>"></b><?= e($typeLabel[$t]) ?></span>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="pl-card">
        <header class="pl-head"><div><h3><?= e($selectedFa) ?></h3></div></header>
        <?php if ($dayClasses === [] && $dayItems === []): ?>
            <p class="pl-free">برای این روز کلاس یا رویدادی ثبت نشده است.</p>
        <?php else: ?>
            <ul class="pl-agenda">
                <?php foreach ($dayClasses as $c): ?>
                    <li><span class="app-ic tone-teal"><?php $icon('school', 16); ?></span>
                        <span><b><?= e($c['title']) ?></b><small><?= e(fa(substr((string) $c['start_time'], 0, 5))) ?> – <?= e(fa(substr((string) $c['end_time'], 0, 5))) ?><?= $c['location'] ? ' · ' . e($c['location']) : '' ?></small></span>
                        <em>کلاس</em></li>
                <?php endforeach; ?>
                <?php foreach ($dayItems as $it): ?>
                    <li><span class="app-ic tone-<?= e($typeTone[$it['type']] ?? 'slate') ?>"><?php $icon(in_array($it['type'], ['exam', 'midterm'], true) ? 'exam' : 'bookmark', 16); ?></span>
                        <span><b><?= e($it['title']) ?></b><?php if ($it['time'] || $it['note']): ?><small><?= $it['time'] ? e(fa((string) $it['time'])) : '' ?><?= $it['note'] ? ' · ' . e($it['note']) : '' ?></small><?php endif; ?></span>
                        <em><?= e($typeLabel[$it['type']] ?? $it['type']) ?></em></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
