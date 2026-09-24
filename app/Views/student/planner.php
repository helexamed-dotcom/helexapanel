<?php
/**
 * «برنامه و امتحان» — one page, three tabs.
 *
 * @var string $tab   week | exams | month
 */
use HeleXa\Core\View;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$tabs = ['week' => ['هفته من', 'calendar'], 'exams' => ['امتحان‌ها', 'exam'], 'month' => ['تقویم ماه', 'layers']];
?>
<div class="pl">
    <nav class="pl-tabs" role="tablist" aria-label="برنامه و امتحان">
        <?php foreach ($tabs as $key => [$label, $ic]): ?>
            <a class="pl-tab<?= $tab === $key ? ' is-active' : '' ?>" href="/student/planner?tab=<?= e($key) ?>" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>">
                <?php $icon($ic, 17); ?><span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php View::partial('student.planner_' . $tab, get_defined_vars()); ?>
</div>
