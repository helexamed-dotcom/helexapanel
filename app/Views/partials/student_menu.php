<?php
/**
 * The student's menu, as a pop-up.
 *
 * One list of destinations, two presentations:
 *   phone / tablet — a sheet that rises from the round menu button in the
 *                    middle of the tab bar: coloured icon tiles in a grid;
 *   laptop         — a wide launcher that rises from the round button at the
 *                    bottom centre: the sections as coloured cards, each row
 *                    with a short description, and a search box on top.
 *
 * Icons are the site's own line icons on coloured badges — no emoji.
 *
 * @var string $currentPath
 * @var array  $unreadCounts
 * @var array  $currentUser
 */
$showBalin = \HeleXa\Services\Balin\Access::menuVisible();
$showQbank = \HeleXa\Services\QuestionBank\QbAccess::menuVisible();
$showNotes = \HeleXa\Services\Settings::bool('notes_enabled', true);
$showRank  = $showBalin && \HeleXa\Services\Balin\MyRank::mode() !== 'off';
$unread    = (int) ($unreadCounts['notifications'] ?? 0);
$messages  = (int) ($unreadCounts['messages'] ?? 0);

// [href, label, description, icon, tone, badge]
$groups = [
    'مطالعه' => array_values(array_filter([
        ['/student/today',      'امروز من',        'کارهای امروز در یک نگاه',        'sparkle', 'amber',  0],
        ['/student',            'داشبورد',         'خلاصه پیشرفت و ادامه مطالعه',     'home',    'blue',   0],
        ['/student/courses',    'دوره‌های من',     'جزوه‌ها و ویدیوهای دوره',         'book',    'sky',    0],
        $showQbank ? ['/student/qbank', 'بانک سوال', 'تمرین سوال درس به درس', 'qbank', 'indigo', 0] : null,
        $showQbank ? ['/student/my-exams', 'آزمون‌های من', 'آزمون از سوال‌های منتخب', 'target', 'violet', 0] : null,
        ['/student/flashcards', 'فلش‌کارت',        'مرور هوشمند کارت‌ها',             'cards',   'rose',   0],
        $showBalin ? ['/student/balin', 'جزیره بالین', 'کیس‌های بالینی مرحله به مرحله', 'island', 'teal', 0] : null,
        ['/student/study',      'درس‌های من',      'چیزهایی که باید بخوانی',          'marker',  'orange', 0],
        ['/student/library',    'کتابخانه',        'منابع، مقاله‌ها و فایل‌ها',        'folder',  'green',  0],
        $showNotes ? ['/student/notes', 'یادداشت‌ها', 'دست‌نویس و یادداشت‌های من', 'note', 'pink', 0] : null,
        ['/offline',            'محتوای آفلاین',   'مطالعه بدون اینترنت',             'download', 'slate', 0],
    ])),
    'برنامه و آزمون' => array_values(array_filter([
        ['/student/schedule',   'برنامه هفتگی',    'کلاس‌های هفته',                   'calendar', 'blue',  0],
        ['/student/calendar',   'تقویم درسی',      'رویدادها و مهلت‌ها',              'layers',   'sky',   0],
        ['/student/exams',      'امتحانات',        'برنامه امتحان‌های پایان‌ترم',      'exam',     'red',   0],
        ['/student/midterms',   'میان‌ترم‌ها',     'تاریخ میان‌ترم‌ها',                'clock',    'amber', 0],
        ['/student/analytics',  'تحلیل عملکرد',    'نمودار زمان مطالعه',              'chart',    'green', 0],
        $showRank ? ['/student/balin/leaderboard', 'رتبه من', 'جایگاه امروز و این هفته', 'trophy', 'violet', 0] : null,
    ])),
    'حساب و پشتیبانی' => [
        ['/student/notifications', 'اعلان‌ها',      'اطلاعیه‌های آموزشی',              'bell',     'amber',  $unread],
        ['/student/messages',      'پیام‌ها',       'پیام‌های مدیر',                    'message',  'sky',    $messages],
        ['/student/support',       'پشتیبانی',      'سوال یا مشکل را بپرس',            'support',  'teal',   0],
        ['/student/activate',      'خرید و فعال‌سازی', 'کد فعال‌سازی پکیج',            'key',      'orange', 0],
        ['/account/profile',       'پروفایل',       'مشخصات و درس‌های اخذشده',         'user',     'indigo', 0],
        ['/account/settings',      'ظاهر و زبان',   'رنگ، حالت شب و زبان',             'palette',  'pink',   0],
        ['/student/sessions',      'نشست‌ها',       'دستگاه‌های وارد شده',              'shield',   'slate',  0],
    ],
];

$isActive = static function (string $href) use ($currentPath): bool {
    if ($href === '/student') {
        return $currentPath === '/student' || $currentPath === '/student/';
    }
    return $currentPath === $href || str_starts_with($currentPath, $href . '/');
};
$first = trim(explode(' ', trim((string) ($currentUser['full_name'] ?? '')))[0] ?? '');
$icon  = static fn (string $name) => \HeleXa\Core\View::partial('partials.icon', ['name' => $name]);
$index = 0;
?>
<div class="sm" data-student-menu hidden>
    <div class="sm-backdrop" data-student-menu-close></div>

    <section class="sm-panel" role="dialog" aria-modal="true" aria-labelledby="sm-title" tabindex="-1">
        <div class="sm-grip" aria-hidden="true"></div>

        <header class="sm-head">
            <div class="sm-user">
                <span class="sm-avatar"><?php \HeleXa\Core\View::partial('partials.avatar', ['person' => $currentUser ?? []]); ?></span>
                <span class="sm-hello">
                    <small>منوی اصلی</small>
                    <strong id="sm-title"><?= $first !== '' ? 'سلام ' . e($first) : 'منوی اصلی' ?></strong>
                </span>
            </div>
            <div class="sm-head-actions">
                <button type="button" class="sm-icon-btn" data-theme-toggle aria-label="حالت روشن / شب">
                    <span class="theme-icon-sun"><?php $icon('sun'); ?></span>
                    <span class="theme-icon-moon"><?php $icon('moon'); ?></span>
                </button>
                <a class="sm-icon-btn" href="/student/notifications" aria-label="اعلان‌ها">
                    <?php $icon('bell'); ?>
                    <?php if ($unread + $messages > 0): ?><i class="sm-dot"><?= e(fa((string) min(99, $unread + $messages))) ?></i><?php endif; ?>
                </a>
                <button type="button" class="sm-icon-btn sm-close" data-student-menu-close aria-label="بستن"><?php $icon('close'); ?></button>
            </div>
        </header>

        <label class="sm-search">
            <?php $icon('search'); ?>
            <input type="search" placeholder="جستجو در بخش‌ها…" data-student-menu-search autocomplete="off" aria-label="جستجو در بخش‌ها">
        </label>

        <div class="sm-body">
            <?php foreach ($groups as $groupLabel => $items): ?>
                <div class="sm-group" data-sm-group>
                    <div class="sm-group-title"><?= e($groupLabel) ?></div>
                    <div class="sm-grid">
                        <?php foreach ($items as [$href, $label, $desc, $iconName, $tone, $badge]): $active = $isActive($href); ?>
                            <a class="sm-item tone-<?= e($tone) ?><?= $active ? ' is-active' : '' ?>" href="<?= e($href) ?>"
                               style="--i: <?= $index++ ?>;" data-sm-item data-search="<?= e($label . ' ' . $desc) ?>"
                               <?= $active ? 'aria-current="page"' : '' ?>>
                                <span class="sm-badge">
                                    <?php $icon($iconName); ?>
                                    <?php if ($badge > 0): ?><i class="sm-count"><?= e(fa((string) min(99, $badge))) ?></i><?php endif; ?>
                                </span>
                                <span class="sm-text">
                                    <b><?= e($label) ?></b>
                                    <small><?= e($desc) ?></small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="sm-empty" data-sm-empty hidden>بخشی با این نام پیدا نشد.</p>
        </div>

        <footer class="sm-foot">
            <form method="post" action="/logout">
                <input type="hidden" name="_token" value="<?= e($csrf_token ?? '') ?>">
                <button type="submit" class="sm-logout"><?php $icon('logout'); ?> خروج از حساب</button>
            </form>
        </footer>
    </section>
</div>
