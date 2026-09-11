<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e($csrf_token) ?>">
    <title><?= e($title ?? '') ?> | <?= e($appName ?? 'HeleXa Med') ?></title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
    <link rel="icon" href="/assets/icons/favicon-32.png" sizes="32x32">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="HeleXa Med">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pwa.css">
    <meta name="theme-color" content="#ffffff" id="theme-color-meta">
    <script nonce="<?= e($cspNonce ?? '') ?>">
        // Runs before the stylesheet paints, so a dark-mode user never sees a
        // white flash. Kept inline for that reason; it is nonce-allowed.
        (function () {
            try {
                var saved = localStorage.getItem('helexa_theme');
                var dark  = saved === 'dark' ||
                    (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
                if (localStorage.getItem('helexa_sidebar') === 'collapsed') {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch (e) { /* private mode: defaults apply */ }
        })();
    </script>
</head>
<body>
<a class="skip-link" href="#main">پرش به محتوای اصلی</a>
<?php $isAdminArea = isset($currentUser['role_slug']) && $currentUser['role_slug'] !== 'student'; ?>
<div class="shell">
    <aside class="sidebar" data-sidebar aria-label="منوی کناری">
        <?php $siteLogo = \HeleXa\Services\Settings::get('site_logo_path', ''); ?>
        <div class="sidebar-head">
            <div class="brand">
                <?php if ($siteLogo !== ''): ?>
                    <div class="brand-mark brand-mark-image"><img src="/assets/<?= e($siteLogo) ?>" alt="<?= e($appName) ?>"></div>
                <?php else: ?>
                    <div class="brand-mark">H</div>
                <?php endif; ?>
                <div class="brand-text nav-text">
                    <span class="brand-name"><?= e($appName) ?></span>
                    <span class="brand-sub"><?= $isAdminArea ? 'پنل مدیریت' : 'پنل دانشجو' ?></span>
                </div>
            </div>
            <button class="rail-toggle" type="button" data-sidebar-toggle
                    aria-label="جمع کردن یا باز کردن منو">
                <span class="rail-icon-collapse"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'collapse']); ?></span>
                <span class="rail-icon-expand"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'expand']); ?></span>
            </button>
            <button class="drawer-close" type="button" data-menu-close aria-label="بستن منو">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'close']); ?>
            </button>
        </div>

        <nav class="nav-scroll">
            <?php
            \HeleXa\Core\View::partial($isAdminArea ? 'partials.nav_admin' : 'partials.nav_student', [
                'currentPath'  => $currentPath ?? '/',
                'permissions'  => $permissions ?? [],
                'unreadCounts' => $unreadCounts ?? ['notifications' => 0, 'messages' => 0, 'support_open' => 0],
            ]);
            ?>
        </nav>

        <div class="sidebar-foot">
            <form method="post" action="/logout">
                <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                <button type="submit" class="nav-item nav-item-danger" data-tip="خروج از حساب">
                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'logout']); ?>
                    <span class="nav-text">خروج از حساب</span>
                </button>
            </form>
        </div>
    </aside>
    <div class="scrim" data-scrim></div>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn menu-toggle" data-menu-toggle type="button"
                    aria-label="باز و بسته کردن منو" aria-expanded="false">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'menu']); ?>
            </button>
            <h1><?= e($title ?? '') ?></h1>
            <div class="spacer"></div>
            <span data-conn-slot></span>

            <div class="user-menu" data-user-menu>
                <button class="avatar user-trigger" type="button" data-user-menu-trigger
                        aria-haspopup="true" aria-expanded="false"
                        title="<?= e($currentUser['full_name'] ?? '') ?>">
                    <?php \HeleXa\Core\View::partial('partials.avatar', ['person' => $currentUser ?? []]); ?>
                </button>

                <div class="user-panel" data-user-panel hidden>
                    <div class="user-panel-head">
                        <div class="user-panel-avatar">
                            <?php \HeleXa\Core\View::partial('partials.avatar', ['person' => $currentUser ?? []]); ?>
                        </div>
                        <div class="user-panel-title">
                            <strong><?= e($currentUser['full_name'] ?? '') ?></strong>
                            <span><?= e($currentUser['username'] ?? '') ?></span>
                        </div>
                    </div>

                    <div class="user-panel-label">تنظیمات</div>

                    <button type="button" class="user-panel-row" data-theme-toggle>
                        <span class="row-icon theme-icon-sun"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'sun']); ?></span>
                        <span class="row-icon theme-icon-moon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'moon']); ?></span>
                        <span>حالت روشن / شب</span>
                    </button>

                    <a class="user-panel-row" href="/account/profile">
                        <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'user']); ?></span>
                        <span>ویرایش اطلاعات کاربری</span>
                    </a>

                    <a class="user-panel-row" href="/account/password">
                        <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'key']); ?></span>
                        <span>تغییر رمز عبور</span>
                    </a>

                    <form method="post" action="/logout" class="user-panel-row-form">
                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                        <button type="submit" class="user-panel-row is-danger">
                            <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'logout']); ?></span>
                            <span>خروج از حساب</span>
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="content" id="main">
            <?php if (!empty($flashSuccess)): ?>
                <div class="alert alert-success"><?= e($flashSuccess) ?></div>
            <?php endif; ?>
            <?php if (!empty($flashError)): ?>
                <div class="alert alert-error"><?= e($flashError) ?></div>
            <?php endif; ?>
            <?php if (!empty($tempPassword)): ?>
                <div class="alert alert-success">
                    <strong>رمز موقت برای <?= e($tempPassword['username']) ?>:</strong>
                    <span class="mono" style="font-size:15px; user-select:all;"><?= e($tempPassword['password']) ?></span>
                    <div style="font-size:12.5px; margin-top:6px;">
                        این رمز فقط همین یک بار نمایش داده می‌شود و در دیتابیس به‌صورت هش ذخیره شده است.
                        کاربر در اولین ورود مجبور به تغییر آن خواهد بود.
                    </div>
                </div>
            <?php endif; ?>
            <?= $content ?? '' ?>
        </main>
    </div>
</div>

<?php if (!$isAdminArea): ?>
    <?php \HeleXa\Core\View::partial('partials.tabbar', [
        'currentPath'  => $currentPath ?? '/',
        'unreadCounts' => $unreadCounts ?? ['notifications' => 0, 'messages' => 0],
    ]); ?>
<?php endif; ?>

<script src="/assets/js/helexa-db.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="/assets/js/helexa-core.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="/assets/js/pwa.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="/assets/js/app.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php if (!empty($offlineEnabled)): ?>
    <script src="/assets/js/offline-manager.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php endif; ?>
<?php /* Only the pages that actually play a clinical case carry this. */ ?>
<?php if (!empty($balinScript)): ?>
    <script src="/assets/js/balin.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php endif; ?>
</body>
</html>
