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
<body class="panel-body<?= $isAdminArea ? '' : ' is-student-shell' ?>">
<a class="skip-link" href="#main"><?= e(t('پرش به محتوای اصلی')) ?></a>
<div class="shell">
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
                    <span class="brand-sub"><?= e(t($isAdminArea ? 'پنل مدیریت' : 'پنل دانشجو')) ?></span>
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
                <button type="submit" class="nav-item nav-item-danger" data-tip="<?= e(t('خروج از حساب')) ?>">
                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'logout']); ?>
                    <span class="nav-text"><?= e(t('خروج از حساب')) ?></span>
                </button>
            </form>
        </div>
    </aside>
    <div class="scrim" data-scrim></div>

    <div class="main">
        <header class="topbar">
            <?php /* Students open the pop-up menu from here (and from the tab bar); admins keep the drawer. */ ?>
            <button class="icon-btn menu-toggle" <?= $isAdminArea ? 'data-menu-toggle' : 'data-student-menu-toggle aria-haspopup="dialog"' ?> type="button"
                    aria-label="<?= e(t('باز و بسته کردن منو')) ?>" aria-expanded="false">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'menu']); ?>
            </button>
            <h1><?= e(t((string) ($title ?? ''))) ?></h1>
            <div class="spacer"></div>

            <?php
            /**
             * Connection state, as a signal-strength glyph rather than a word.
             * The markup is rendered here and the script only swaps a class, so
             * the icon is present on first paint instead of appearing a moment
             * later once JavaScript has run.
             */
            ?>
            <span class="conn" data-conn-slot role="status" aria-live="polite" title="<?= e(t('وضعیت اتصال')) ?>">
                <?php
                /**
                 * width and height are on the element, not only in the
                 * stylesheet. An SVG with neither renders at its full
                 * intrinsic size, so a stale cached stylesheet — which the
                 * service worker will happily serve for a while after a
                 * deploy — would blow this up to a full-size graphic.
                 */
                ?>
                <svg class="conn-ic" width="18" height="18" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path class="conn-arc conn-arc-3" d="M2.5 8.5a15.9 15.9 0 0 1 19 0"/>
                    <path class="conn-arc conn-arc-2" d="M5.5 12a11 11 0 0 1 13 0"/>
                    <path class="conn-arc conn-arc-1" d="M8.5 15.5a6 6 0 0 1 7 0"/>
                    <circle class="conn-dot" cx="12" cy="19" r="1.5" fill="currentColor" stroke="none"/>
                    <path class="conn-slash" d="m4.8 4.8 14.4 14.4"/>
                </svg>
                <span class="conn-label"></span>
            </span>

            <?php
            /**
             * Notifications and messages. Students get their own two counters;
             * an admin gets the one number they can actually act on, which is
             * open support tickets.
             */
            $bellItems = $isAdminArea
                ? [
                    ['/admin/support', t('تیکت‌های باز پشتیبانی'), 'support',
                        (int) ($unreadCounts['support_open'] ?? 0)],
                ]
                : [
                    ['/student/notifications', t('اطلاعیه‌ها'), 'bell',
                        (int) ($unreadCounts['notifications'] ?? 0)],
                    ['/student/messages', t('پیام‌های من'), 'message',
                        (int) ($unreadCounts['messages'] ?? 0)],
                ];
            $bellTotal = array_sum(array_column($bellItems, 3));
            ?>
            <?php if ($bellItems !== []): ?>
                <div class="bell-menu" data-bell-menu>
                    <button class="icon-btn bell-trigger" type="button" data-bell-trigger
                            aria-haspopup="true" aria-expanded="false"
                            aria-label="<?= e(t('اعلان‌ها')) ?><?= $bellTotal > 0 ? '، ' . fa((string) $bellTotal) . ' مورد خوانده‌نشده' : '' ?>">
                        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'bell']); ?>
                        <?php if ($bellTotal > 0): ?>
                            <span class="bell-badge"><?= e(fa((string) min($bellTotal, 99))) ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="drop-panel bell-panel" data-bell-panel hidden>
                        <div class="drop-panel-label"><?= e(t('اعلان‌ها')) ?></div>
                        <?php foreach ($bellItems as [$href, $label, $icon, $count]): ?>
                            <a class="drop-panel-row" href="<?= e($href) ?>">
                                <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => $icon]); ?></span>
                                <span class="drop-panel-text"><?= e($label) ?></span>
                                <?php if ($count > 0): ?>
                                    <span class="drop-panel-count"><?= e(fa((string) min($count, 99))) ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($bellTotal === 0): ?>
                            <div class="drop-panel-empty"><?= e(t('چیز خوانده‌نشده‌ای نداری.')) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="user-menu" data-user-menu>
                <button class="avatar user-trigger" type="button" data-user-menu-trigger
                        aria-haspopup="true" aria-expanded="false"
                        title="<?= e($currentUser['full_name'] ?? '') ?>">
                    <?php \HeleXa\Core\View::partial('partials.avatar', ['person' => $currentUser ?? []]); ?>
                </button>

                <div class="drop-panel user-panel" data-user-panel hidden>
                    <div class="user-panel-head">
                        <div class="user-panel-avatar">
                            <?php \HeleXa\Core\View::partial('partials.avatar', ['person' => $currentUser ?? []]); ?>
                        </div>
                        <div class="user-panel-title">
                            <strong><?= e($currentUser['full_name'] ?? '') ?></strong>
                            <span><?= e($currentUser['username'] ?? '') ?></span>
                        </div>
                    </div>

                    <div class="drop-panel-label"><?= e(t('تنظیمات')) ?></div>

                    <button type="button" class="user-panel-row" data-theme-toggle>
                        <span class="row-icon theme-icon-sun"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'sun']); ?></span>
                        <span class="row-icon theme-icon-moon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'moon']); ?></span>
                        <span><?= e(t('حالت روشن / شب')) ?></span>
                    </button>

                    <a class="user-panel-row" href="/account/settings">
                        <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'palette']); ?></span>
                        <span><?= e(t('تنظیمات ظاهر و زبان')) ?></span>
                    </a>

                    <a class="user-panel-row" href="/account/profile">
                        <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'user']); ?></span>
                        <span><?= e(t('ویرایش اطلاعات کاربری')) ?></span>
                    </a>

                    <a class="user-panel-row" href="/account/password">
                        <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'key']); ?></span>
                        <span><?= e(t('تغییر رمز عبور')) ?></span>
                    </a>

                    <form method="post" action="/logout" class="user-panel-row-form">
                        <input type="hidden" name="_token" value="<?= e($csrf_token) ?>">
                        <button type="submit" class="user-panel-row is-danger">
                            <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'logout']); ?></span>
                            <span><?= e(t('خروج از حساب')) ?></span>
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
    <?php \HeleXa\Core\View::partial('partials.student_menu', [
        'currentPath'  => $currentPath ?? '/',
        'unreadCounts' => $unreadCounts ?? ['notifications' => 0, 'messages' => 0],
        'currentUser'  => $currentUser ?? [],
    ]); ?>
<?php endif; ?>

<script src="<?= asset('/assets/js/helexa-db.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="<?= asset('/assets/js/helexa-core.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="<?= asset('/assets/js/pwa.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="<?= asset('/assets/js/app.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php if (!empty($offlineEnabled)): ?>
    <script src="<?= asset('/assets/js/offline-manager.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php endif; ?>
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
</body>
</html>
