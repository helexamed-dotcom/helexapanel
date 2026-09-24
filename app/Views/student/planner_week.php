<?php
/**
 * @var array $days        weekday => my classes
 * @var int   $todayIndex
 * @var int   $weekStart   timestamp of this Saturday
 * @var array $plans       every group's plan, for the folded section
 * @var bool  $custom
 * @var bool  $canPick
 * @var array $weekdays
 * @var bool  $hasTerms
 */
use HeleXa\Core\View;
use HeleXa\Services\Jalali;

$icon = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$total = array_sum(array_map('count', $days));
$now = date('H:i');
?>
<?php if (!$hasTerms): ?>
    <div class="pl-empty"><span class="app-ic tone-slate"><?php $icon('calendar'); ?></span>
        <b>ترمی برای حساب شما ثبت نشده است</b><small>دانشگاه، رشته و ترم را مدیر سامانه تعیین می‌کند؛ از پشتیبانی بپرسید.</small></div>
<?php else: ?>
    <section class="pl-card">
        <header class="pl-head">
            <div>
                <h3>کلاس‌های من</h3>
                <small><?= $custom ? 'فقط درس‌هایی که اخذ کرده‌ای' : 'برنامه گروه خودت' ?> · <?= e(fa((string) $total)) ?> جلسه در هفته</small>
            </div>
            <?php if ($canPick): ?>
                <a class="btn btn-ghost btn-sm" href="/student/schedule/choose"><?php $icon('pencil', 15); ?> <?= $custom ? 'ویرایش درس‌های اخذشده' : 'انتخاب درس‌های اخذشده' ?></a>
            <?php endif; ?>
        </header>

        <div class="pl-week" data-pl-week>
            <?php foreach ($weekdays as $i => $label):
                $date = $weekStart + $i * 86400; ?>
                <section class="pl-day<?= $i === $todayIndex ? ' is-today' : '' ?><?= empty($days[$i]) ? ' is-free' : '' ?>" data-pl-day="<?= $i ?>">
                    <header>
                        <b><?= e($label) ?></b>
                        <small><?= e(Jalali::digits((string) Jalali::fromGregorian((int) date('Y', $date), (int) date('n', $date), (int) date('j', $date))[2])) ?></small>
                        <?php if ($i === $todayIndex): ?><i>امروز</i><?php endif; ?>
                    </header>
                    <?php if (empty($days[$i])): ?>
                        <p class="pl-free">آزاد</p>
                    <?php else: ?>
                        <?php foreach ($days[$i] as $c):
                            $from = substr((string) $c['start_time'], 0, 5);
                            $to   = substr((string) $c['end_time'], 0, 5);
                            $live = $i === $todayIndex && $now >= $from && $now < $to;
                            $past = $i === $todayIndex && $now >= $to; ?>
                            <article class="pl-class<?= $live ? ' is-live' : '' ?><?= $past ? ' is-past' : '' ?>" style="--c: <?= e($c['color'] ?: ($c['subject_color'] ?? '#2563eb')) ?>">
                                <span class="pl-time"><?= e(fa($from)) ?> – <?= e(fa($to)) ?></span>
                                <b><?= e($c['title']) ?></b>
                                <?php if ($c['teacher'] || $c['location']): ?><small><?= e(implode(' · ', array_filter([$c['teacher'] ?? '', $c['location'] ?? '']))) ?></small><?php endif; ?>
                                <?php if ($live): ?><em class="pl-live">در حال برگزاری</em><?php endif; ?>
                                <?php if (!empty($c['course_uuid'])): ?><a class="pl-res" href="/student/courses/<?= e($c['course_uuid']) ?>">منابع ←</a><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (count($plans) > 0): ?>
        <details class="pl-card pl-all">
            <summary><?php $icon('layers', 17); ?> برنامه کامل همه گروه‌ها (<?= e(fa((string) count($plans))) ?> برنامه)</summary>
            <?php View::partial('student.schedule', get_defined_vars() + ['canPick' => false]); ?>
        </details>
    <?php endif; ?>
<?php endif; ?>
