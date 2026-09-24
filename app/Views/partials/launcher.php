<?php
/**
 * The student's menu: the site's sections as app icons.
 *
 *   laptop  — drops from the round button at the top-right of the header,
 *             growing out of the button itself;
 *   phone   — rises out of the round button in the middle of the tab bar
 *             and fills the screen, the icons flying out from the button to
 *             their places, the way the iPhone opens a folder.
 *
 * Only sections that are on for this student are listed (Modules::menu()):
 * notifications, support, activation and profile live in the header now,
 * so they are not repeated here.
 *
 * @var string $currentPath
 * @var array  $currentUser
 */
use HeleXa\Services\Modules;

$groups = Modules::menu();
$labels = Modules::GROUPS;
$icon   = static fn (string $name) => \HeleXa\Core\View::partial('partials.icon', ['name' => $name]);
$first  = trim(explode(' ', trim((string) ($currentUser['full_name'] ?? '')))[0] ?? '');
$isOn   = static fn (string $href): bool => $currentPath === $href || str_starts_with($currentPath, $href . '/');
$i = 0;
?>
<div class="lx" data-launcher hidden>
    <div class="lx-backdrop" data-launcher-close></div>
    <section class="lx-panel" role="dialog" aria-modal="true" aria-labelledby="lx-title" tabindex="-1">
        <header class="lx-head">
            <div class="lx-hello">
                <small><?= e(t('منوی اصلی')) ?></small>
                <strong id="lx-title"><?= $first !== '' ? e('سلام ' . $first) : e(t('منوی اصلی')) ?></strong>
            </div>
            <label class="lx-search">
                <?php $icon('search'); ?>
                <input type="search" placeholder="<?= e(t('جستجو…')) ?>" data-launcher-search autocomplete="off" aria-label="<?= e(t('جستجو در بخش‌ها')) ?>">
                <kbd>Ctrl K</kbd>
            </label>
            <button type="button" class="lx-x" data-launcher-close aria-label="<?= e(t('بستن')) ?>"><?php $icon('close'); ?></button>
        </header>

        <div class="lx-body">
            <a class="lx-home<?= $currentPath === '/student' ? ' is-active' : '' ?>" href="/student" data-lx-item data-search="داشبورد خانه" style="--i: <?= $i++ ?>">
                <span class="lx-app tone-blue"><?php $icon('home'); ?></span>
                <span class="lx-home-text"><b><?= e(t('داشبورد')) ?></b><small><?= e(t('امروز، برنامه، درس‌های من و شمارش معکوس')) ?></small></span>
            </a>
            <?php foreach ($groups as $group => $items): ?>
                <div class="lx-group" data-lx-group>
                    <div class="lx-group-title"><?= e(t($labels[$group] ?? $group)) ?></div>
                    <div class="lx-grid">
                        <?php foreach ($items as $item): $active = $isOn($item['href']); ?>
                            <a class="lx-item hx-zoom<?= $active ? ' is-active' : '' ?>" href="<?= e($item['href']) ?>"
                               data-lx-item data-search="<?= e($item['label'] . ' ' . $item['desc']) ?>"
                               style="--i: <?= $i++ ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                                <span class="lx-app tone-<?= e($item['tone']) ?>"><?php $icon($item['icon']); ?></span>
                                <b><?= e(t($item['label'])) ?></b>
                                <small><?= e(t($item['desc'])) ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="lx-empty" data-lx-empty hidden><?= e(t('بخشی با این نام پیدا نشد.')) ?></p>
        </div>
    </section>
</div>
