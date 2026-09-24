<?php
/**
 * Phone bottom bar for students.
 *
 *     خانه · مطالعه · [ منو ] · برنامه · پروفایل
 *
 * The raised round button in the middle opens the menu (partials/launcher.php)
 * from exactly where it sits; the four around it are the places a student
 * goes most. A slot whose section is switched off for this student falls
 * back to the next best one, so the bar never offers a closed door.
 *
 * @var string $currentPath
 */
use HeleXa\Services\Modules;

$study = Modules::enabled('lessons') ? ['/student/lessons', 'درسنامه', 'lesson']
    : (Modules::enabled('courses') ? ['/student/courses', 'دوره‌ها', 'book'] : ['/student/qbank', 'تمرین', 'qbank']);
$plan = Modules::enabled('planner') ? ['/student/planner', 'برنامه', 'calendar']
    : (Modules::enabled('qbank') ? ['/student/qbank', 'تمرین', 'qbank'] : ['/student/analytics', 'تحلیل', 'chart']);

$items = [
    ['/student', 'خانه', 'home'],
    $study,
    null,
    $plan,
    ['/account/profile', 'پروفایل', 'user'],
];
?>
<nav class="tabbar hx-tabbar" aria-label="<?= e(t('ناوبری اصلی')) ?>">
    <?php foreach ($items as $item): ?>
        <?php if ($item === null): ?>
            <button type="button" class="tab-link tab-menu" data-launcher-toggle
                    aria-haspopup="dialog" aria-expanded="false" aria-label="<?= e(t('منو')) ?>">
                <span class="tab-orb"><span class="tab-orb-glyph" aria-hidden="true"><i></i><i></i><i></i><i></i></span></span>
            </button>
            <?php continue; ?>
        <?php endif; ?>
        <?php
        [$href, $label, $icon] = $item;
        $active = match ($href) {
            '/student'         => ($currentPath === '/student' || $currentPath === '/student/') ? ' is-active' : '',
            '/account/profile' => (str_starts_with($currentPath, '/account') || $currentPath === '/student/sessions') ? ' is-active' : '',
            default            => active_when($currentPath, $href),
        };
        ?>
        <a class="tab-link<?= $active ?>" href="<?= e($href) ?>" <?= $active !== '' ? 'aria-current="page"' : '' ?>>
            <span class="tab-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => $icon]); ?></span>
            <span class="tab-label"><?= e(t($label)) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
