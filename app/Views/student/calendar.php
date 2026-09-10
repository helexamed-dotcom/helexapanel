<?php
$typeChip = [
    'exam' => 'chip-red', 'midterm' => 'chip-amber', 'holiday' => 'chip-green',
    'deadline' => 'chip-red', 'reminder' => 'chip-blue', 'event' => 'chip-blue', 'custom' => 'chip-gray',
];
$typeLabel = [
    'exam' => 'امتحان', 'midterm' => 'میان‌ترم', 'holiday' => 'تعطیلی',
    'deadline' => 'مهلت', 'reminder' => 'یادآوری', 'event' => 'رویداد', 'custom' => 'سایر',
];
?>
<div class="card">
    <div class="card-head">
        <h3 class="card-title" style="margin:0;">
            <?= e($grid['monthName']) ?> <?= e(fa((string) $grid['year'])) ?>
        </h3>
        <div class="row-actions">
            <a class="btn btn-ghost btn-sm" href="/student/calendar?year=<?= (int) $prev['year'] ?>&month=<?= (int) $prev['month'] ?>">ماه قبل</a>
            <a class="btn btn-ghost btn-sm" href="/student/calendar">این ماه</a>
            <a class="btn btn-ghost btn-sm" href="/student/calendar?year=<?= (int) $next['year'] ?>&month=<?= (int) $next['month'] ?>">ماه بعد</a>
        </div>
    </div>

    <div class="cal-grid">
        <?php foreach ($weekdays as $label): ?>
            <div class="cal-head"><?= e($label) ?></div>
        <?php endforeach; ?>

        <?php for ($blank = 0; $blank < (int) $grid['leadingBlanks']; $blank++): ?>
            <div class="cal-cell is-blank"></div>
        <?php endfor; ?>

        <?php foreach ($grid['days'] as $day): ?>
            <?php $items = $byDate[$day['date']] ?? []; ?>
            <a class="cal-cell<?= $day['isToday'] ? ' is-today' : '' ?><?= $day['date'] === $selected ? ' is-selected' : '' ?>"
               href="/student/calendar?year=<?= (int) $grid['year'] ?>&month=<?= (int) $grid['month'] ?>&day=<?= e($day['date']) ?>">
                <span class="cal-num"><?= e(fa((string) $day['day'])) ?></span>
                <?php if ($items !== []): ?>
                    <span class="cal-dots">
                        <?php foreach (array_slice($items, 0, 3) as $item): ?>
                            <i class="cal-dot dot-<?= e($item['type']) ?>"></i>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3 class="card-title"><?= e($selectedFa) ?></h3>

    <?php if ($dayClasses === [] && $dayItems === []): ?>
        <div class="empty">برای این روز کلاس یا رویدادی ثبت نشده است.</div>
    <?php else: ?>
        <div class="tree">
            <?php foreach ($dayClasses as $item): ?>
                <div class="tree-leaf">
                    <span class="leaf-icon">🎓</span>
                    <div style="min-width:0; flex:1;">
                        <div class="leaf-title"><?= e($item['title']) ?></div>
                        <div class="leaf-meta">
                            <?= e(fa(substr((string) $item['start_time'], 0, 5))) ?> — <?= e(fa(substr((string) $item['end_time'], 0, 5))) ?>
                            <?php if (!empty($item['term_title'])): ?> · <?= e($item['term_title']) ?><?php endif; ?>
                            <?php if ($item['location']): ?> · <?= e($item['location']) ?><?php endif; ?>
                        </div>
                    </div>
                    <span class="stat-chip chip-teal">کلاس</span>
                </div>
            <?php endforeach; ?>

            <?php foreach ($dayItems as $item): ?>
                <div class="tree-leaf">
                    <span class="leaf-icon">📌</span>
                    <div style="min-width:0; flex:1;">
                        <div class="leaf-title"><?= e($item['title']) ?></div>
                        <?php if ($item['time'] || $item['note']): ?>
                            <div class="leaf-meta">
                                <?= $item['time'] ? e(fa((string) $item['time'])) : '' ?>
                                <?= $item['note'] ? ' · ' . e($item['note']) : '' ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <span class="stat-chip <?= e($typeChip[$item['type']] ?? 'chip-gray') ?>">
                        <?= e($typeLabel[$item['type']] ?? $item['type']) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
