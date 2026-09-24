<?php
/**
 * The top bar.
 *
 *   start (right in RTL):  [menu]  brand · page title
 *   end   (left in RTL):   🛒  🔑  🔔  avatar           (student)
 *                          🔔  avatar                   (admin)
 *
 * Each icon opens its own panel. The panels' content is fetched from /hub/*
 * the first time they open (data-src), so a page view that never opens them
 * costs nothing. On a phone every panel becomes a bottom sheet.
 *
 * @var bool   $isAdminArea
 * @var array  $currentUser
 * @var array  $unreadCounts
 * @var string $title
 * @var string $appName
 */
use HeleXa\Core\View;
use HeleXa\Services\Modules;

$icon     = static fn (string $n) => View::partial('partials.icon', ['name' => $n]);
$siteLogo = \HeleXa\Services\Settings::get('site_logo_path', '');
$bell     = $isAdminArea
    ? (int) ($unreadCounts['support_open'] ?? 0)
    : (int) ($unreadCounts['notifications'] ?? 0) + (int) ($unreadCounts['messages'] ?? 0) + (int) ($unreadCounts['support_answered'] ?? 0);
$cartCount = (int) ($unreadCounts['cart'] ?? 0);
$shopOn    = !$isAdminArea && Modules::enabled('shop');

// «بازگشت» on every page but the two home pages. The button goes back in
// the browser's history when the previous page was ours; otherwise (a page
// opened from a link or a bookmark) it climbs to the parent address,
// skipping the parts that are not pages of their own (an id, «edit», …).
$home = $isAdminArea ? '/admin' : '/student';
$path = rtrim((string) ($currentPath ?? '/'), '/') ?: '/';
$backHref = null;
if (!in_array($path, ['/admin', '/student', '/'], true)) {
    $parts = array_values(array_filter(explode('/', $path), 'strlen'));
    $skip = ['p', 'edit', 'course', 'deck', 'u', 'study', 'media'];
    array_pop($parts);
    while ($parts !== [] && (preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27}$|^\d+$/i', end($parts)) === 1 || in_array(end($parts), $skip, true))) {
        array_pop($parts);
    }
    $backHref = $parts === [] ? $home : '/' . implode('/', $parts);
    $backHref = ['/account' => '/account/profile', '/hub' => $home][$backHref] ?? $backHref;
    if ($backHref === $path) {
        $backHref = $home;
    }
}
?>
<header class="topbar hx-top">
    <div class="hx-top-start">
        <?php if ($isAdminArea): ?>
            <button class="hx-ic menu-toggle" type="button" data-menu-toggle aria-expanded="false" aria-label="<?= e(t('باز و بسته کردن منو')) ?>">
                <?php $icon('menu'); ?>
            </button>
        <?php else: ?>
            <button class="hx-launch" type="button" data-launcher-toggle aria-haspopup="dialog" aria-expanded="false" aria-label="<?= e(t('منو')) ?>">
                <span class="hx-launch-glyph" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                <span class="hx-launch-label"><?= e(t('منو')) ?></span>
            </button>
        <?php endif; ?>
        <?php if ($backHref !== null): ?>
            <a class="hx-ic hx-backbtn" href="<?= e($backHref) ?>" data-back aria-label="<?= e(t('بازگشت')) ?>" title="<?= e(t('بازگشت')) ?>">
                <?php $icon('chevron'); ?>
            </a>
        <?php endif; ?>
        <a class="hx-brand" href="<?= $isAdminArea ? '/admin' : '/student' ?>" aria-label="<?= e($appName) ?>">
            <?php if ($siteLogo !== ''): ?>
                <span class="hx-brand-mark is-image"><img src="/assets/<?= e($siteLogo) ?>" alt=""></span>
            <?php else: ?>
                <span class="hx-brand-mark">H</span>
            <?php endif; ?>
        </a>
        <h1 class="hx-title"><?= e(t((string) ($title ?? ''))) ?></h1>
    </div>

    <div class="hx-top-end">
        <?php if ($shopOn): ?>
            <div class="hx-pop" data-pop>
                <button class="hx-ic" type="button" data-pop-trigger aria-haspopup="true" aria-expanded="false" aria-label="<?= e(t('فروشگاه و سبد خرید')) ?>">
                    <?php $icon('cart'); ?>
                    <span class="hx-badge is-green" data-cart-badge <?= $cartCount > 0 ? '' : 'hidden' ?>><?= e(fa((string) min(99, $cartCount))) ?></span>
                </button>
                <div class="hx-panel" data-pop-panel data-src="/hub/cart" hidden>
                    <div class="hx-panel-body" data-pop-body><?php View::partial('partials.hub_loading'); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$isAdminArea): ?>
            <div class="hx-pop" data-pop>
                <button class="hx-ic" type="button" data-pop-trigger aria-haspopup="true" aria-expanded="false" aria-label="<?= e(t('فعال‌سازی و خرید')) ?>">
                    <?php $icon('key'); ?>
                </button>
                <div class="hx-panel" data-pop-panel data-src="/hub/activate" hidden>
                    <div class="hx-panel-body" data-pop-body><?php View::partial('partials.hub_loading'); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="hx-pop" data-pop>
            <button class="hx-ic" type="button" data-pop-trigger aria-haspopup="true" aria-expanded="false"
                    aria-label="<?= e(t('اعلان‌ها')) ?><?= $bell > 0 ? '، ' . e(fa((string) $bell)) . ' مورد تازه' : '' ?>">
                <?php $icon('bell'); ?>
                <?php if ($bell > 0): ?><span class="hx-badge"><?= e(fa((string) min(99, $bell))) ?></span><?php endif; ?>
            </button>
            <div class="hx-panel is-wide" data-pop-panel data-src="/hub/bell" hidden>
                <div class="hx-panel-body" data-pop-body><?php View::partial('partials.hub_loading'); ?></div>
            </div>
        </div>

        <div class="hx-pop" data-pop>
            <button class="hx-avatar" type="button" data-pop-trigger aria-haspopup="true" aria-expanded="false"
                    aria-label="<?= e(t('پروفایل')) ?>" title="<?= e($currentUser['full_name'] ?? '') ?>">
                <?php View::partial('partials.avatar', ['person' => $currentUser ?? []]); ?>
            </button>
            <div class="hx-panel" data-pop-panel hidden>
                <div class="hx-panel-body">
                    <?php View::partial('partials.profile_menu', ['currentUser' => $currentUser, 'isAdminArea' => $isAdminArea]); ?>
                </div>
            </div>
        </div>
    </div>
</header>
