<?php use HeleXa\Services\StudyAnalytics; ?>

<h2 class="greet">سلام <?= e($currentUser['full_name'] ?? '') ?> 👋</h2>
<p class="greet-sub"><?= e($todayText) ?></p>

<?php
$weekTotal = (int) $week['total'];
$delta     = $weekTotal - (int) $lastWeekTotal;
$activeCourses = count($courses);
$upcomingCount = count($todayClasses);
$today = strtotime(date('Y-m-d'));
?>

<div class="grid grid-4">
    <div class="card">
        <div class="stat-label">مطالعه امروز</div>
        <div class="stat-value"><?= e(StudyAnalytics::humanDuration((int) $todaySeconds)) ?></div>
        <span class="stat-chip chip-teal">ثبت‌شده توسط سرور</span>
    </div>
    <div class="card">
        <div class="stat-label">مطالعه این هفته</div>
        <div class="stat-value"><?= e(StudyAnalytics::humanDuration($weekTotal)) ?></div>
        <?php if ($lastWeekTotal > 0 || $weekTotal > 0): ?>
            <span class="stat-chip <?= $delta >= 0 ? 'chip-green' : 'chip-red' ?>">
                <?= $delta >= 0 ? '+' : '−' ?><?= e(StudyAnalytics::humanDuration(abs($delta))) ?> نسبت به هفته گذشته
            </span>
        <?php else: ?>
            <span class="stat-chip chip-gray">هنوز داده‌ای نیست</span>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="stat-label">دوره‌های فعال</div>
        <div class="stat-value"><?= e(fa((string) $activeCourses)) ?></div>
        <a class="stat-chip chip-blue" href="/student/courses">مشاهده دوره‌ها</a>
    </div>
    <div class="card">
        <div class="stat-label">کلاس‌های امروز</div>
        <div class="stat-value"><?= e(fa((string) $upcomingCount)) ?></div>
        <a class="stat-chip chip-blue" href="/student/schedule">برنامه هفتگی</a>
    </div>
</div>

<div class="grid grid-2" style="margin-top:16px;">
    <div class="card">
        <div class="card-head">
            <h3 class="card-title" style="margin:0;">مطالعه هفته جاری</h3>
            <a class="btn btn-ghost btn-sm" href="/student/analytics">تحلیل کامل</a>
        </div>
        <?php \HeleXa\Core\View::partial('partials.week_chart', ['week' => $week]); ?>
    </div>

    <div class="card">
        <h3 class="card-title">منابع ادامه‌دار</h3>
        <?php if ($continue === []): ?>
            <div class="empty">محتوایی در حال مطالعه ندارید.</div>
        <?php else: ?>
            <div class="tree">
                <?php foreach ($continue as $item): ?>
                    <a class="tree-leaf leaf-link" href="/content/<?= e($item['content_uuid']) ?>">
                        <span class="leaf-icon" style="color: <?= e($item['color'] ?: '#2563eb') ?>">▍</span>
                        <div style="min-width:0; flex:1;">
                            <div class="leaf-title"><?= e($item['content_title']) ?></div>
                            <div class="leaf-meta">
                                <?= e($item['course_title']) ?>
                                · <?= e(StudyAnalytics::humanDuration((int) $item['total_seconds'])) ?> مطالعه شده
                            </div>
                        </div>
                        <span class="stat-chip chip-amber">ادامه ←</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-2" style="margin-top:16px;">
    <div class="card">
        <div class="card-head">
            <h3 class="card-title" style="margin:0;">کلاس‌های امروز</h3>
            <a class="btn btn-ghost btn-sm" href="/student/schedule">کل هفته</a>
        </div>
        <?php if ($todayClasses === []): ?>
            <div class="empty">امروز کلاسی ندارید.</div>
        <?php else: ?>
            <div class="tree">
                <?php foreach ($todayClasses as $item): ?>
                    <div class="tree-leaf">
                        <span class="leaf-icon">🎓</span>
                        <div style="min-width:0; flex:1;">
                            <div class="leaf-title"><?= e($item['title']) ?></div>
                            <div class="leaf-meta">
                                <?php if (!empty($item['term_title'])): ?><?= e($item['term_title']) ?><?php endif; ?>
                                <?php if ($item['teacher']): ?> · <?= e($item['teacher']) ?><?php endif; ?>
                                <?php if ($item['location']): ?> · <?= e($item['location']) ?><?php endif; ?>
                            </div>
                        </div>
                        <span class="stat-chip chip-teal">
                            <?= e(fa(substr((string) $item['start_time'], 0, 5))) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <h3 class="card-title" style="margin:0;">امتحانات پیش‌رو</h3>
            <a class="btn btn-ghost btn-sm" href="/student/exams">همه</a>
        </div>
        <?php if ($upcomingExams === []): ?>
            <div class="empty">امتحانی در پیش نیست.</div>
        <?php else: ?>
            <div class="tree">
                <?php foreach ($upcomingExams as $exam): ?>
                    <?php
                    $days = (int) round((strtotime((string) $exam['exam_date']) - $today) / 86400);
                    $chip = $days <= 3 ? 'chip-red' : ($days <= 10 ? 'chip-amber' : 'chip-blue');
                    ?>
                    <div class="tree-leaf">
                        <span class="leaf-icon">📝</span>
                        <div style="min-width:0; flex:1;">
                            <div class="leaf-title"><?= e($exam['title']) ?></div>
                            <div class="leaf-meta"><?= e(jdate($exam['exam_date'])) ?></div>
                        </div>
                        <span class="stat-chip <?= e($chip) ?>">
                            <?= $days === 0 ? 'امروز' : ($days === 1 ? 'فردا' : e(fa((string) $days)) . ' روز') ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
