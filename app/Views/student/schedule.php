<?php
/**
 * Every weekly plan of the student's terms, stacked: for each term, the
 * student's own group first and the other groups below it.
 *
 * @var array $plans     from StudentSchedule::plans()
 * @var bool  $custom    the student has chosen their classes
 * @var bool  $canPick
 * @var array $weekdays
 * @var int   $todayIndex
 * @var bool  $hasTerms
 */
?>
<?php if (!$hasTerms): ?>
    <div class="card">
        <div class="empty">
            ترمی برای حساب شما ثبت نشده است.<br>
            دانشگاه، رشته و ترم را مدیر سامانه تعیین می‌کند؛ با پشتیبانی تماس بگیرید.
        </div>
    </div>
<?php elseif ($plans === []): ?>
    <div class="card"><div class="empty">برنامه‌ای یافت نشد.</div></div>
<?php else: ?>
    <?php if ($canPick): ?>
        <div class="card pick-callout" style="margin-bottom:16px;">
            <div>
                <strong>درس‌های اخذشده</strong>
                <div class="muted" style="font-size:12.5px;">
                    <?= $custom
                        ? 'کلاس‌هایی که اخذ کرده‌اید با «✓ اخذ شده» مشخص شده‌اند و فقط همین‌ها در تقویم و داشبورد می‌آیند.'
                        : 'برنامه همه گروه‌ها در زیر آمده است. در پروفایل مشخص کنید کدام درس‌ها را از کدام گروه برداشته‌اید تا فقط همان‌ها در تقویم و داشبورد بیایند.' ?>
                </div>
            </div>
            <a class="btn btn-primary btn-sm" href="/account/profile#classes">
                <?= $custom ? 'ویرایش در پروفایل' : 'انتخاب در پروفایل' ?>
            </a>
        </div>
    <?php endif; ?>

    <?php foreach ($plans as $plan):
        $schedule = $plan['schedule'];
        $label    = $schedule === null ? '' : ($schedule['group_title'] ? $schedule['group_title'] : 'کل ترم');
    ?>
        <div class="card plan-card<?= $plan['own'] ? ' is-own' : '' ?>" style="margin-bottom:16px;">
            <div class="card-head">
                <div>
                    <h3 class="card-title" style="margin:0;">
                        <?= $schedule === null ? e($plan['term_title']) : e($plan['term_title'] . ' — ' . $label) ?>
                    </h3>
                    <?php if ($schedule !== null): ?>
                        <div class="leaf-meta"><?= e($schedule['title']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="row-actions" style="margin:0;">
                    <?php if ($plan['own']): ?><span class="stat-chip chip-blue">گروه شما</span><?php endif; ?>
                    <?php if ($schedule !== null && !empty($schedule['academic_year'])): ?>
                        <span class="stat-chip chip-gray"><?= e(fa((string) $schedule['academic_year'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($schedule === null): ?>
                <div class="empty">برای این ترم هنوز برنامه‌ای ثبت نشده است.</div>
            <?php elseif ($plan['count'] === 0): ?>
                <div class="empty">هنوز جلسه‌ای در این برنامه ثبت نشده است.</div>
            <?php else: ?>
                <div class="week-grid">
                    <?php foreach ($weekdays as $index => $dayLabel): ?>
                        <div class="week-day<?= $index === $todayIndex ? ' is-today' : '' ?>">
                            <div class="week-day-head">
                                <?= e($dayLabel) ?>
                                <?php if ($index === $todayIndex): ?><span class="today-dot">امروز</span><?php endif; ?>
                            </div>
                            <?php if (empty($plan['byDay'][$index])): ?>
                                <div class="week-empty">کلاسی نیست</div>
                            <?php else: ?>
                                <?php foreach ($plan['byDay'][$index] as $item): ?>
                                    <div class="class-card<?= !empty($item['picked']) ? ' is-picked' : '' ?>"
                                         style="border-right-color: <?= e($item['color'] ?: ($item['subject_color'] ?? '#2563eb')) ?>">
                                        <div class="class-time">
                                            <?= e(fa(substr((string) $item['start_time'], 0, 5))) ?> — <?= e(fa(substr((string) $item['end_time'], 0, 5))) ?>
                                        </div>
                                        <div class="class-title"><?= e($item['title']) ?></div>
                                        <?php if (!empty($item['picked'])): ?>
                                            <span class="stat-chip chip-green" style="font-size:10.5px;">✓ اخذ شده</span>
                                        <?php endif; ?>
                                        <?php if ($item['teacher'] || $item['location']): ?>
                                            <div class="class-meta">
                                                <?= e(trim(($item['teacher'] ?? '') . ' ' . ($item['location'] ? '· ' . $item['location'] : ''))) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($item['course_uuid']): ?>
                                            <a class="class-link" href="/student/courses/<?= e($item['course_uuid']) ?>">منابع درس ←</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>