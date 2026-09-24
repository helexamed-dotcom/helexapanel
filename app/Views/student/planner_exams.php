<?php
/**
 * @var array  $upcoming   finals and midterms, nearest first
 * @var array  $past
 * @var bool   $hasTerm
 * @var string $kindFilter '' | final | midterm
 */
use HeleXa\Core\View;

$icon  = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$today = strtotime(date('Y-m-d'));
$list  = $kindFilter === '' ? $upcoming : array_values(array_filter($upcoming, static fn ($e) => ($e['exam_kind'] ?? 'final') === $kindFilter));
$byMonth = [];
foreach ($list as $e) {
    $ts = strtotime((string) $e['exam_date']);
    [$jy, $jm] = \HeleXa\Services\Jalali::fromGregorian((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    $byMonth[\HeleXa\Services\Jalali::MONTHS[$jm] . ' ' . fa((string) $jy)][] = $e;
}
?>
<?php if (!$hasTerm): ?>
    <div class="pl-empty"><span class="app-ic tone-slate"><?php $icon('exam'); ?></span><b>ترم شما مشخص نشده است</b><small>برنامه امتحانی بر اساس ترم نمایش داده می‌شود.</small></div>
<?php else: ?>
    <div class="pl-filter">
        <?php foreach (['' => 'همه', 'final' => 'پایان‌ترم', 'midterm' => 'میان‌ترم'] as $k => $label): ?>
            <a class="pl-chip<?= $kindFilter === $k ? ' is-on' : '' ?>" href="/student/planner?tab=exams<?= $k !== '' ? '&kind=' . e($k) : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($list === []): ?>
        <div class="pl-empty"><span class="app-ic tone-green"><?php $icon('check'); ?></span><b>امتحانی در پیش نیست</b><small>هر وقت مدیر امتحانی ثبت کند، این‌جا با شمارش روز می‌آید.</small></div>
    <?php endif; ?>

    <?php foreach ($byMonth as $month => $exams): ?>
        <h4 class="pl-month"><?= e($month) ?></h4>
        <div class="pl-exams">
            <?php foreach ($exams as $exam):
                $days = (int) round((strtotime((string) $exam['exam_date']) - $today) / 86400);
                $kind = ($exam['exam_kind'] ?? 'final') === 'midterm';
                $ts = strtotime((string) $exam['exam_date']); ?>
                <article class="pl-exam<?= $days <= 3 ? ' is-soon' : '' ?>">
                    <div class="pl-exam-date">
                        <b><?= e(fa((string) \HeleXa\Services\Jalali::fromGregorian((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts))[2])) ?></b>
                        <small><?= e(\HeleXa\Services\Jalali::WEEKDAYS[\HeleXa\Services\Jalali::weekdayIndex($ts)]) ?></small>
                    </div>
                    <div class="pl-exam-main">
                        <span class="pl-kind <?= $kind ? 'is-mid' : '' ?>"><?= $kind ? 'میان‌ترم' : 'پایان‌ترم' ?></span>
                        <b><?= e($exam['title']) ?></b>
                        <small><?= e(implode(' · ', array_filter([
                            $exam['course_title'] ?? '',
                            $exam['start_time'] ? 'ساعت ' . fa(substr((string) $exam['start_time'], 0, 5)) : '',
                            $exam['location'] ?? '',
                        ]))) ?></small>
                        <?php if (!empty($exam['description'])): ?><p><?= e($exam['description']) ?></p><?php endif; ?>
                    </div>
                    <div class="pl-exam-left">
                        <b><?= $days <= 0 ? 'امروز' : ($days === 1 ? 'فردا' : e(fa((string) $days))) ?></b>
                        <?php if ($days > 1): ?><small>روز مانده</small><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($past !== []): ?>
        <details class="pl-card pl-all">
            <summary><?php $icon('clock', 17); ?> برگزارشده‌ها</summary>
            <ul class="pl-past">
                <?php foreach ($past as $exam): ?>
                    <li><b><?= e($exam['title']) ?></b><small><?= e(jdate($exam['exam_date'])) ?> · <?= ($exam['exam_kind'] ?? '') === 'midterm' ? 'میان‌ترم' : 'پایان‌ترم' ?></small></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
<?php endif; ?>
