<?php
/**
 * Admin navigation: modules as an accordion.
 *
 * Thirty-odd destinations in one scrolling list is where an admin gets lost,
 * so each module is one row with its own icon; it opens to show its pages,
 * and only the module you are in is open. The search box above filters every
 * page at once. A module is printed only when the admin can reach at least
 * one page in it.
 *
 * @var string $currentPath
 * @var array  $unreadCounts
 */
use HeleXa\Core\View;

$icon = static fn (string $n) => View::partial('partials.icon', ['name' => $n]);
$count = static function (string $sql): int {
    try {
        return (int) (\HeleXa\Core\Database::selectOne($sql)['c'] ?? 0);
    } catch (\Throwable) {
        return 0;
    }
};

// [href, label, icon, permission|null, badge, exact-match]
$groups = [
    ['کاربران', 'users', 'blue', [
        ['/admin/students',               'دانشجویان',         'users',   'manage_students', 0, false],
        ['/admin/student-types',          'انواع دانشجو',       'school',  'manage_students', can('manage_students') ? \HeleXa\Services\StudentTypes::pendingCount() : 0, false],
        ['/admin/access',                 'دسترسی‌ها',          'key',     'manage_students', 0, false],
        ['/admin/academic',               'ساختار آموزشی',      'layers',  'manage_students', 0, false],
        ['/admin/admins',                 'مدیران',             'user',    'manage_admins',   0, false],
    ]],
    ['محتوای آموزشی', 'lesson', 'indigo', [
        ['/admin/lessons',                'درسنامه‌ها',          'lesson',  'lessons.manage',  0, false],
        ['/admin/mindmaps',               'نقشه‌های ذهنی',       'mindmap', 'lessons.manage',  0, false],
        ['/admin/courses',                'دوره‌ها',             'book',    'manage_courses',  0, false],
        ['/admin/library',                'کتابخانه',            'folder',  'manage_content',  0, false],
        ['/admin/content',                'همه جزوه‌ها',         'note',    'manage_content',  0, true],
        ['/admin/lesson-tags',            'برچسب‌های مشترک',     'tag',     'lessons.manage',  0, false],
    ]],
    ['بانک سوال', 'qbank', 'violet', [
        ['/admin/qbank',                  'مرور بانک سوال',     'qbank',   'qbank.view', 0, true],
        ['/admin/qbank/questions',        'سوالات',             'exam',    'qbank.view', 0, false],
        ['/admin/qbank/subjects',         'دروس و زیردروس',     'layers',  'qbank.view', 0, false],
        ['/admin/qbank/tags',             'برچسب‌ها',           'tag',     'qbank.view', 0, false],
        ['/admin/qbank/reports',          'گزارشات اشکال',      'message', 'qbank.view', can('qbank.view') ? $count("SELECT COUNT(*) AS c FROM qb_reports WHERE status = 'open'") : 0, false],
        ['/admin/qbank/transfer',         'ورود و خروج JSON',   'download','qbank.view', 0, false],
        ['/admin/qbank/access',           'دسترسی دانشجویان',   'key',     'qbank.manage_students', 0, false],
    ]],
    ['فلش‌کارت و بازی', 'cards', 'rose', [
        ['/admin/flashcards',             'درس‌های فلش‌کارت',    'cards',   'flashcards.manage', 0, false],
        ['/admin/figures',                'بازی با شکل',         'figure',  'flashcards.manage', 0, false],
        ['/admin/flashcards/access',      'دسترسی دانشجویان',   'key',     'flashcards.manage_students', 0, false],
    ]],
    ['جزیره بالین', 'island', 'teal', [
        ['/admin/balin',                  'مرور جزیره',          'island',  'balin.view', 0, true],
        ['/admin/balin/lessons',          'درس‌های بالینی',      'stethoscope', 'balin.view', 0, false],
        ['/admin/balin/skill-tracks',     'مهارت‌های بالینی',    'route',   'balin.manage_skill_tracks', 0, false],
        ['/admin/balin/characters',       'شخصیت‌ها',            'users',   'balin.manage_characters', 0, false],
        ['/admin/balin/media',            'کتابخانه رسانه',      'image',   'balin.view', 0, false],
        ['/admin/balin/transfer',         'ورود و خروج JSON',   'download','balin.view', 0, false],
        ['/admin/balin/access',           'دسترسی دانشجویان',   'key',     'balin.manage_students', 0, false],
        ['/admin/balin/competitions',     'رقابت هفتگی',         'trophy',  'balin.manage_competition', 0, false],
        ['/admin/balin/rank-tiers',       'عنوان سطح‌ها',        'sparkle', 'balin.manage_rank_titles', 0, false],
        ['/admin/balin/analytics',        'آمار بالین',          'chart',   'balin.view_statistics', 0, false],
    ]],
    ['فروشگاه', 'bag', 'orange', [
        ['/admin/shop',                   'مرور فروشگاه',        'store',   'shop.manage', 0, true],
        ['/admin/shop/orders',            'سفارش‌ها',            'receipt', 'shop.orders', can('shop.orders') ? $count("SELECT COUNT(*) AS c FROM shop_orders WHERE status = 'review'") : 0, false],
        ['/admin/shop/products',          'محصولات',             'bag',     'shop.manage', 0, false],
        ['/admin/shop/coupons',           'کدهای تخفیف',         'percent', 'shop.manage', 0, false],
        ['/admin/shop/settings',          'پرداخت و ظاهر',       'creditcard', 'shop.manage', 0, false],
        ['/admin/packages',               'پکیج‌ها',             'package', 'manage_packages', 0, false],
        ['/admin/activation-codes',       'کدهای فعال‌سازی',      'key',     'manage_packages', 0, false],
    ]],
    ['برنامه و آزمون', 'calendar', 'sky', [
        ['/admin/schedule',               'برنامه هفتگی',        'calendar','manage_schedule', 0, false],
        ['/admin/exams',                  'امتحانات',            'exam',    'manage_exams',    0, false],
        ['/admin/midterms',               'میان‌ترم‌ها',          'clock',   'manage_exams',    0, false],
        ['/admin/calendar',               'تقویم',               'layers',  'manage_calendar', 0, false],
    ]],
    ['ارتباطات', 'chat', 'green', [
        ['/admin/notifications',          'اطلاعیه‌ها',          'bell',    'manage_notifications', 0, false],
        ['/admin/messages',               'پیام‌ها',             'message', 'manage_messages', 0, false],
        ['/admin/support',                'پشتیبانی',           'support', 'manage_messages', (int) ($unreadCounts['support_open'] ?? 0), false],
    ]],
    ['تنظیمات و امنیت', 'settings', 'slate', [
        ['/admin/home-screen',            'صفحه اصلی دانشجو',    'apps',    'manage_settings', 0, false],
        ['/admin/settings',               'تنظیمات سایت',        'settings','manage_settings', 0, false],
        ['/admin/points',                 'امتیاز، لیگ و پست‌ها', 'trophy',  'points.manage', 0, false],
        ['/admin/sessions',               'نشست‌ها',             'clock',   'view_sessions',   0, false],
        ['/admin/security/flags',         'ورود مشکوک',          'shield',  'manage_students', can('manage_students') ? \HeleXa\Services\IpWatch::openCount() : 0, false],
        ['/admin/security',               'بازبینی امنیت',       'shield',  'manage_settings', 0, true],
        ['/admin/logs',                   'گزارش فعالیت',        'list',    'view_logs',       0, false],
        ['/admin/backup',                 'پشتیبان‌گیری',         'download','manage_settings', 0, false],
    ]],
];

$isOn = static function (string $href, bool $exact) use ($currentPath): bool {
    return $exact ? $currentPath === $href : ($currentPath === $href || str_starts_with($currentPath, $href . '/'));
};
?>
<label class="adn-search">
    <?php $icon('search'); ?>
    <input type="search" placeholder="<?= e(t('جستجوی صفحه…')) ?>" data-adn-search autocomplete="off" aria-label="<?= e(t('جستجو در منو')) ?>">
</label>

<a class="nav-item adn-home<?= $currentPath === '/admin' ? ' is-active' : '' ?>" href="/admin" data-tip="<?= e(t('داشبورد')) ?>">
    <?php $icon('home'); ?> <span class="nav-text"><?= e(t('داشبورد')) ?></span>
</a>

<?php foreach ($groups as [$label, $gIcon, $tone, $items]):
    $items = array_values(array_filter($items, static fn (array $i): bool => $i[3] === null || can($i[3])));
    if ($items === []) {
        continue;
    }
    $open  = false;
    $badge = 0;
    foreach ($items as $i) {
        $open = $open || $isOn($i[0], $i[5]);
        $badge += (int) $i[4];
    }
    ?>
    <details class="adn-group" data-adn-group <?= $open ? 'open' : '' ?>>
        <summary data-tip="<?= e(t($label)) ?>">
            <span class="app-ic tone-<?= e($tone) ?>"><?php $icon($gIcon); ?></span>
            <span class="adn-title"><?= e(t($label)) ?></span>
            <?php if ($badge > 0): ?><span class="adn-dot"><?= e(fa((string) min(99, $badge))) ?></span><?php endif; ?>
            <span class="adn-chev"><?php $icon('chevron-down'); ?></span>
        </summary>
        <div class="adn-items">
            <?php foreach ($items as [$href, $text, $ic, , $b, $exact]): ?>
                <a class="nav-item<?= $isOn($href, $exact) ? ' is-active' : '' ?>" href="<?= e($href) ?>" data-adn-item data-search="<?= e($text . ' ' . $label) ?>">
                    <?php $icon($ic); ?> <span class="nav-text"><?= e(t($text)) ?></span>
                    <?php if ($b > 0): ?><span class="nav-badge"><?= e(fa((string) min(99, $b))) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </details>
<?php endforeach; ?>
