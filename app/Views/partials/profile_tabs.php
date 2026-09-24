<?php
/**
 * The profile's own tab strip. Everything personal — the profile itself,
 * editing it, appearance and language, privacy, signed-in devices and the
 * password — reads as one place with one strip, however many routes it is.
 *
 * @var string $currentPath
 * @var array  $currentUser
 */
$isStudent = ($currentUser['role_slug'] ?? '') === 'student';
$tabs = [
    ['/account/profile',  'پروفایل',      'user'],
    ['/account/edit',     'ویرایش',       'pencil'],
    ['/account/settings', 'ظاهر و زبان',  'palette'],
];
if ($isStudent) {
    $tabs[] = ['/account/privacy', 'حریم خصوصی', 'eye'];
}
$tabs[] = ['/account/sessions', 'نشست‌ها', 'shield'];
$tabs[] = ['/account/password', 'رمز عبور', 'key'];
?>
<nav class="ptabs" aria-label="<?= e(t('بخش‌های پروفایل')) ?>">
    <?php foreach ($tabs as [$href, $label, $icon]):
        $on = $currentPath === $href || ($href === '/account/sessions' && $currentPath === '/student/sessions'); ?>
        <a class="ptab<?= $on ? ' is-active' : '' ?>" href="<?= e($href) ?>" <?= $on ? 'aria-current="page"' : '' ?>>
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => $icon, 'size' => 17]); ?>
            <span><?= e(t($label)) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
