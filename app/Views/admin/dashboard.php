<?php
/**
 * The admin home: what is waiting, the numbers that matter today, a way
 * into every module, and who signed in.
 *
 * @var string   $todayText
 * @var int      $totalStudents
 * @var int      $activeStudents
 * @var int      $onlineNow
 * @var int      $newToday
 * @var ?int     $salesMonth
 * @var int      $studyToday
 * @var array    $inbox
 * @var array    $recentLogins
 * @var array    $recentActivity
 */
use HeleXa\Core\View;

$icon  = static fn (string $n, int $s = 0) => View::partial('partials.icon', ['name' => $n] + ($s ? ['size' => $s] : []));
$first = trim(explode(' ', trim((string) ($currentUser['full_name'] ?? '')))[0] ?? '');
$waiting = array_sum(array_column($inbox, 'count'));
$quick = array_values(array_filter([
    can('lessons.manage')       ? ['/admin/lessons/create', 'درسنامه جدید', 'lesson', 'indigo'] : null,
    can('qbank.manage_questions') ? ['/admin/qbank/questions/create', 'سوال جدید', 'qbank', 'violet'] : null,
    can('flashcards.manage')    ? ['/admin/figures', 'بازی با شکل', 'figure', 'amber'] : null,
    can('shop.manage')          ? ['/admin/shop/products?new=1', 'محصول جدید', 'bag', 'orange'] : null,
    can('manage_notifications') ? ['/admin/notifications', 'ارسال اطلاعیه', 'bell', 'green'] : null,
    can('manage_settings')      ? ['/admin/home-screen', 'روزشمار', 'hourglass', 'rose'] : null,
    can('manage_students')      ? ['/admin/students/create', 'دانشجوی جدید', 'users', 'blue'] : null,
]));
?>
<div class="ad-page">
    <section class="ad-hero">
        <div>
            <small><?= e($todayText) ?></small>
            <h2>سلام <?= e($first) ?> 👋</h2>
            <p><?= $waiting > 0 ? e(fa((string) $waiting)) . ' کار منتظر شماست.' : 'همه صف‌ها خالی است؛ روز خوبی داشته باشید.' ?></p>
        </div>
        <?php if ($quick !== []): ?>
            <div class="ad-quick">
                <?php foreach ($quick as [$href, $label, $ic, $tone]): ?>
                    <a class="ad-quick-btn" href="<?= e($href) ?>"><span class="app-ic tone-<?= e($tone) ?>"><?php $icon($ic, 18); ?></span><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="ad-stats">
        <a class="ad-stat" href="/admin/students"><span class="app-ic tone-blue"><?php $icon('users'); ?></span><span><b><?= e(fa((string) $totalStudents)) ?></b><small>دانشجو · <?= e(fa((string) $activeStudents)) ?> فعال</small></span></a>
        <div class="ad-stat"><span class="app-ic tone-green"><?php $icon('bolt'); ?></span><span><b><?= e(fa((string) $onlineNow)) ?></b><small>آنلاین در ۵ دقیقه اخیر</small></span></div>
        <div class="ad-stat"><span class="app-ic tone-violet"><?php $icon('sparkle'); ?></span><span><b><?= e(fa((string) $newToday)) ?></b><small>ثبت‌نام امروز</small></span></div>
        <div class="ad-stat"><span class="app-ic tone-teal"><?php $icon('clock'); ?></span><span><b><?= e(\HeleXa\Services\StudyAnalytics::humanDuration($studyToday)) ?></b><small>مطالعه همه امروز</small></span></div>
        <?php if ($salesMonth !== null): ?>
            <a class="ad-stat" href="/admin/shop/orders"><span class="app-ic tone-orange"><?php $icon('receipt'); ?></span><span><b><?= e(fa(number_format($salesMonth))) ?></b><small>تومان فروش این ماه</small></span></a>
        <?php endif; ?>
    </div>

    <div class="ad-two">
        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-amber"><?php $icon('bell'); ?></span>
                <div><h3>کارهای در انتظار</h3><p>هر صف با یک کلیک باز می‌شود.</p></div>
            </header>
            <ul class="ad-list">
                <?php foreach ($inbox as $row): ?>
                    <li>
                        <a class="ad-row<?= $row['count'] === 0 ? ' is-muted' : '' ?>" href="<?= e($row['href']) ?>">
                            <span class="app-ic tone-<?= e($row['tone']) ?>"><?php $icon($row['icon'], 18); ?></span>
                            <span class="ad-row-main"><b><?= e($row['label']) ?></b></span>
                            <span class="ad-pill <?= $row['count'] > 0 ? 'is-bad' : 'is-on' ?>"><?= $row['count'] > 0 ? e(fa((string) $row['count'])) : '✓' ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-sky"><?php $icon('shield'); ?></span>
                <div><h3>آخرین ورودها</h3></div>
                <?php if (can('view_sessions')): ?><div class="ad-actions"><a class="btn btn-ghost btn-sm" href="/admin/sessions">همه</a></div><?php endif; ?>
            </header>
            <?php if ($recentLogins === []): ?>
                <div class="ad-empty">هنوز ورودی ثبت نشده است.</div>
            <?php else: ?>
                <ul class="ad-list">
                    <?php foreach (array_slice($recentLogins, 0, 6) as $row): ?>
                        <li class="ad-row">
                            <span class="ad-avatar"><?php View::partial('partials.avatar', ['person' => $row]); ?></span>
                            <span class="ad-row-main">
                                <b><?= e($row['full_name']) ?></b>
                                <small><?= e(($row['operating_system'] ?? '—') . ' · ' . ($row['browser'] ?? '—')) ?> · <?= e(jdate($row['login_at'])) ?></small>
                            </span>
                            <span class="ad-pill <?= (int) $row['is_active'] === 1 ? 'is-on' : '' ?>"><?= (int) $row['is_active'] === 1 ? 'آنلاین' : 'بسته' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($recentActivity !== []): ?>
        <section class="ad-card">
            <header class="ad-card-head">
                <span class="app-ic tone-slate"><?php $icon('list'); ?></span>
                <div><h3>فعالیت‌های اخیر</h3></div>
                <div class="ad-actions"><a class="btn btn-ghost btn-sm" href="/admin/logs">گزارش کامل</a></div>
            </header>
            <div class="ad-scroll">
                <table class="ad-table">
                    <thead><tr><th>کاربر</th><th>رویداد</th><th>زمان</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentActivity as $log): ?>
                        <tr><td><?= e($log['full_name'] ?? 'سیستم') ?></td><td class="mono"><?= e($log['action']) ?></td><td><?= e(jdate($log['created_at'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
