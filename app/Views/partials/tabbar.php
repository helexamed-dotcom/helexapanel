<?php
/**
 * Mobile bottom bar for students: the five destinations that get used daily.
 * The drawer still holds the full menu.
 */
$items = [
    ['/student',               'داشبورد', 'home',     0],
    ['/student/courses',       'دوره‌ها',  'book',     0],
    ['/student/schedule',      'برنامه',  'calendar', 0],
    ['/offline',               'آفلاین',  'download', 0],
    ['/student/notifications', 'اطلاعیه', 'bell',     (int) ($unreadCounts['notifications'] ?? 0)],
];
?>
<nav class="tabbar" aria-label="ناوبری اصلی">
    <?php foreach ($items as [$href, $label, $icon, $badge]): ?>
        <a class="tab-link<?= active_when($currentPath, $href) ?>" href="<?= e($href) ?>">
            <span class="tab-icon">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => $icon]); ?>
                <?php if ($badge > 0): ?>
                    <span class="tab-badge"><?= e(fa((string) min($badge, 99))) ?></span>
                <?php endif; ?>
            </span>
            <span class="tab-label"><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
