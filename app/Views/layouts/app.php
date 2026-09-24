<?php $prefs = \HeleXa\Services\Preferences::current(); ?>
<!DOCTYPE html>
<html lang="<?= e($prefs['lang']) ?>" dir="<?= $prefs['lang'] === 'en' ? 'ltr' : 'rtl' ?>" data-theme="light"
      data-accent="<?= e($prefs['accent']) ?>" data-mode="<?= e($prefs['mode']) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e($csrf_token) ?>">
    <title><?= e(t((string) ($title ?? ''))) ?> | <?= e($appName ?? 'HeleXa Med') ?></title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
    <link rel="icon" href="/assets/icons/favicon-32.png" sizes="32x32">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="HeleXa Med">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/pwa.css') ?>">
    <?php /* The glass layer: overrides surfaces only, so it can be removed
             without breaking a page. */ ?>
    <link rel="stylesheet" href="<?= asset('/assets/css/ios.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/themes.css') ?>">
    <?php /* The study suite, the pop-ups, the student's menu and the site font. */ ?>
    <link rel="stylesheet" href="<?= asset('/assets/css/suite.css') ?>">
    <?php /* The shell: header, menu, tab bar, pop-ups and the motion. Last,
             so it has the final word over the older layers. */ ?>
    <link rel="stylesheet" href="<?= asset('/assets/css/shell.css') ?>">
    <?php if (isset($currentUser['role_slug']) && $currentUser['role_slug'] !== 'student'): ?>
        <link rel="stylesheet" href="<?= asset('/assets/css/admin.css') ?>">
    <?php endif; ?>
    <?php foreach ((array) ($extraCss ?? []) as $css): ?>
        <link rel="stylesheet" href="<?= asset('/assets/css/' . $css . '.css') ?>">
    <?php endforeach; ?>
    <?php if (!empty($qbank)): ?>
        <link rel="stylesheet" href="<?= asset('/assets/css/qbank.css') ?>">
    <?php endif; ?>
    <?php if (!empty($flashcards)): ?>
        <link rel="stylesheet" href="<?= asset('/assets/css/flashcards.css') ?>">
    <?php endif; ?>
    <?php if (!empty($notesUi)): ?>
        <link rel="stylesheet" href="<?= asset('/assets/css/notes.css') ?>">
    <?php endif; ?>
    <?php if (!empty($balinGame)): ?>
        <link rel="stylesheet" href="<?= asset('/assets/css/balin-game.css') ?>">
    <?php endif; ?>
    <meta name="theme-color" content="#ffffff" id="theme-color-meta">
    <script nonce="<?= e($cspNonce ?? '') ?>">
        // Runs before the stylesheet paints, so a dark-mode user never sees a
        // white flash. Kept inline for that reason; it is nonce-allowed.
        (function () {
            try {
                // An explicit choice saved on the account wins on every device;
                // "system" falls back to this device's own last toggle.
                var mode  = document.documentElement.getAttribute('data-mode');
                var saved = mode === 'light' || mode === 'dark' ? mode : localStorage.getItem('helexa_theme');
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
<?php $isAdminArea = isset($currentUser['role_slug']) && $currentUser['role_slug'] !== 'student'; ?>
<body class="panel-body<?= $isAdminArea ? ' is-admin-shell' : ' is-student-shell' ?>">
<a class="skip-link" href="#main"><?= e(t('پرش به محتوای اصلی')) ?></a>
<div class="shell">
    <?php if ($isAdminArea): ?>
        <aside class="sidebar" data-sidebar aria-label="<?= e(t('منوی کناری')) ?>">
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
                        <span class="brand-sub"><?= e(t('پنل مدیریت')) ?></span>
                    </div>
                </div>
                <button class="rail-toggle" type="button" data-sidebar-toggle
                        aria-label="<?= e(t('جمع کردن یا باز کردن منو')) ?>">
                    <span class="rail-icon-collapse"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'collapse']); ?></span>
                    <span class="rail-icon-expand"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'expand']); ?></span>
                </button>
                <button class="drawer-close" type="button" data-menu-close aria-label="<?= e(t('بستن منو')) ?>">
                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'close']); ?>
                </button>
            </div>

            <nav class="nav-scroll">
                <?php \HeleXa\Core\View::partial('partials.nav_admin', [
                    'currentPath'  => $currentPath ?? '/',
                    'permissions'  => $permissions ?? [],
                    'unreadCounts' => $unreadCounts ?? ['notifications' => 0, 'messages' => 0, 'support_open' => 0],
                ]); ?>
            </nav>

            <div class="sidebar-foot">
                <form method="post" action="/logout">
                    <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                    <button type="submit" class="nav-item nav-item-danger" data-tip="<?= e(t('خروج از حساب')) ?>">
                        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'logout']); ?>
                        <span class="nav-text"><?= e(t('خروج از حساب')) ?></span>
                    </button>
                </form>
            </div>
        </aside>
        <div class="scrim" data-scrim></div>
    <?php endif; ?>

    <div class="main">
        <?php \HeleXa\Core\View::partial('partials.header', [
            'isAdminArea'  => $isAdminArea,
            'currentUser'  => $currentUser ?? [],
            'unreadCounts' => $unreadCounts ?? [],
            'title'        => $title ?? '',
            'appName'      => $appName ?? 'HeleXa Med',
            'currentPath'  => $currentPath ?? '/',
        ]); ?>

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
    <?php \HeleXa\Core\View::partial('partials.tabbar', ['currentPath' => $currentPath ?? '/']); ?>
    <?php \HeleXa\Core\View::partial('partials.launcher', [
        'currentPath' => $currentPath ?? '/',
        'currentUser' => $currentUser ?? [],
    ]); ?>
<?php endif; ?>

<script src="<?= asset('/assets/js/helexa-db.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="<?= asset('/assets/js/helexa-core.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="<?= asset('/assets/js/pwa.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="<?= asset('/assets/js/app.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="<?= asset('/assets/js/shell.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php /* Only the pages that actually play a clinical case carry this. */ ?>
<?php if (!empty($balinScript)): ?>
    <script src="<?= asset('/assets/js/balin.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php endif; ?>
<?php if (!empty($balinMap)): ?>
    <script src="<?= asset('/assets/js/balin-map.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php endif; ?>
<?php /* Question-bank pages: the editor's paste/upload handling and the
         student's answer flow. */ ?>
<?php if (!empty($qbank)): ?>
    <script src="<?= asset('/assets/js/qbank.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php endif; ?>
<?php if (!empty($flashcards)): ?>
    <script src="<?= asset('/assets/js/flashcards.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php endif; ?>
<?php if (!empty($notesUi)): ?>
    <script src="<?= asset('/assets/js/ink.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
    <script src="<?= asset('/assets/js/notes.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php endif; ?>
<?php foreach ((array) ($extraJs ?? []) as $js): ?>
    <script src="<?= asset('/assets/js/' . $js . '.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php endforeach; ?>
</body>
</html>
