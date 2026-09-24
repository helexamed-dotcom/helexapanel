<?php
/**
 * The avatar's panel: everything personal in one place — the profile, its
 * settings, appearance and language, signed-in devices and the password.
 *
 * @var array $currentUser
 * @var bool  $isAdminArea
 */
use HeleXa\Core\View;

$icon = static fn (string $n) => View::partial('partials.icon', ['name' => $n]);
$rows = [
    ['/account/profile',  'پروفایل من',          'user',    'indigo'],
    ['/account/edit',     'ویرایش مشخصات',        'pencil',  'sky'],
    ['/account/settings', 'ظاهر و زبان',          'palette', 'pink'],
];
if (!$isAdminArea) {
    $rows[] = ['/account/privacy',     'حریم خصوصی و نمایش', 'eye',    'teal'];
    $rows[] = ['/student/leaderboard', 'رتبه‌بندی و لیگ',     'trophy', 'amber'];
    $rows[] = ['/student/people',      'هم‌کلاسی‌ها',         'users',  'violet'];
}
$rows[] = ['/account/sessions', 'نشست‌ها و دستگاه‌ها', 'shield', 'slate'];
$rows[] = ['/account/password', 'رمز عبور',           'key',    'amber'];
?>
<div class="pm">
    <a class="pm-card" href="/account/profile">
        <span class="pm-avatar"><?php View::partial('partials.avatar', ['person' => $currentUser ?? []]); ?></span>
        <span class="pm-name">
            <b><?= e($currentUser['full_name'] ?? '') ?></b>
            <small dir="ltr">@<?= e($currentUser['username'] ?? '') ?></small>
        </span>
    </a>
    <div class="pm-rows">
        <?php foreach ($rows as [$href, $label, $ic, $tone]): ?>
            <a class="pm-row" href="<?= e($href) ?>">
                <span class="hub-row-ic tone-<?= e($tone) ?>"><?php $icon($ic); ?></span>
                <span><?= e(t($label)) ?></span>
            </a>
        <?php endforeach; ?>
        <button type="button" class="pm-row" data-theme-toggle>
            <span class="hub-row-ic tone-violet">
                <span class="theme-icon-sun"><?php $icon('sun'); ?></span>
                <span class="theme-icon-moon"><?php $icon('moon'); ?></span>
            </span>
            <span><?= e(t('حالت روشن / شب')) ?></span>
        </button>
    </div>
    <form method="post" action="/logout" class="pm-out">
        <input type="hidden" name="_token" value="<?= e($csrf_token ?? '') ?>">
        <button type="submit" class="pm-row is-danger">
            <span class="hub-row-ic tone-red"><?php $icon('logout'); ?></span>
            <span><?= e(t('خروج از حساب')) ?></span>
        </button>
    </form>
</div>
