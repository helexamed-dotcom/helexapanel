<?php
/**
 * Mobile bottom bar for students.
 *
 * داشبورد · مطالعه · [منو] · تقویم · پروفایل
 *
 * The raised round button in the middle opens the full menu as a pop-up
 * (partials/student_menu.php); the four around it are the places a student
 * goes most often.
 *
 * @var string $currentPath
 * @var array  $unreadCounts
 */
$items = [
    ['/student',          'داشبورد', 'home'],
    ['/student/courses',  'مطالعه',  'book'],
    null,                                   // the menu button
    ['/student/calendar', 'تقویم',   'calendar'],
    ['/account/profile',  'پروفایل', 'user'],
];
$badge = (int) ($unreadCounts['notifications'] ?? 0) + (int) ($unreadCounts['messages'] ?? 0);
?>
<nav class="tabbar" aria-label="ناوبری اصلی">
    <?php foreach ($items as $item): ?>
        <?php if ($item === null): ?>
            <button type="button" class="tab-link tab-main tab-menu" data-student-menu-toggle
                    aria-haspopup="dialog" aria-expanded="false" aria-label="<?= e(t('منو')) ?>">
                <span class="tab-icon">
                    <span class="tab-menu-glyph" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                    <?php if ($badge > 0): ?><span class="tab-badge"><?= e(fa((string) min(99, $badge))) ?></span><?php endif; ?>
                </span>
                <span class="tab-label"><?= e(t('منو')) ?></span>
            </button>
            <?php continue; ?>
        <?php endif; ?>
        <?php
        [$href, $label, $icon] = $item;
        // «داشبورد» is only /student itself; the profile tab also covers the
        // password and session pages under /account.
        $active = match ($href) {
            '/student'        => ($currentPath === '/student' || $currentPath === '/student/') ? ' is-active' : '',
            '/account/profile' => active_when($currentPath, '/account'),
            default            => active_when($currentPath, $href),
        };
        ?>
        <a class="tab-link<?= $active ?>" href="<?= e($href) ?>" <?= $active !== '' ? 'aria-current="page"' : '' ?>>
            <span class="tab-icon">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => $icon]); ?>
            </span>
            <span class="tab-label"><?= e(t($label)) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
