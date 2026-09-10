<?php
/**
 * Mobile bottom bar for students: the five destinations that get used daily.
 * The drawer still holds the full menu.
 */
$items = [
    ['/student',              'داشبورد',  '⌂', 0],
    ['/student/courses',      'دوره‌ها',   '▤', 0],
    ['/student/schedule',     'برنامه',   '▦', 0],
    ['/offline',              'آفلاین',   '⇩', 0],
    ['/student/notifications','اطلاعیه',  '◔', (int) ($unreadCounts['notifications'] ?? 0)],
];
?>
<nav class="tabbar" aria-label="ناوبری اصلی">
    <?php foreach ($items as [$href, $label, $icon, $badge]): ?>
        <a class="<?= trim(active_when($currentPath, $href)) ?>" href="<?= e($href) ?>">
            <span class="tab-icon" aria-hidden="true"><?= $icon ?></span>
            <?php if ($badge > 0): ?>
                <span class="tab-badge"><?= e(fa((string) min($badge, 99))) ?></span>
            <?php endif; ?>
            <span><?= e($label) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
