<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e($csrf_token) ?>">
    <title><?= e($content['title']) ?> | <?= e($appName) ?></title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <script nonce="<?= e($cspNonce ?? '') ?>">
        (function () {
            try {
                var saved = localStorage.getItem('helexa_theme');
                var dark  = saved === 'dark' ||
                    (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/pwa.css">
    <?php if ((int) $content['is_printable'] === 0): ?>
        <style>@media print { body { display: none !important; } }</style>
    <?php endif; ?>
</head>
<body class="viewer-body">
<?php
$backUrl     = '/student/courses/' . $content['course_uuid'];
$showTools   = $isStudent && $highlightEnabled;
$colors      = \HeleXa\Controllers\HighlightController::COLORS;
$colorNames  = [
    'yellow' => 'زرد', 'green' => 'سبز', 'blue' => 'آبی',
    'pink' => 'صورتی', 'purple' => 'بنفش',
];
?>
<div class="viewer">
    <header class="vbar">
        <a class="vbar-btn" href="<?= e($backUrl) ?>" aria-label="بازگشت به دوره" title="بازگشت به دوره">
            <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'back']); ?>
        </a>

        <div class="vbar-titles">
            <div class="vbar-title"><?= e($content['title']) ?></div>
            <div class="vbar-course"><?= e($content['course_title']) ?></div>
        </div>

        <?php if ($showTools): ?>
            <div class="vbar-tools" role="toolbar" aria-label="ابزار هایلایت">
                <button class="vbar-btn tool-btn" type="button" data-tool="pen"
                        aria-pressed="false" title="قلم هایلایت">
                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'pen']); ?>
                    <i class="tool-dot" data-tool-dot data-color="<?= e($colors[0]) ?>"></i>
                </button>
                <button class="vbar-btn tool-btn" type="button" data-tool="eraser"
                        aria-pressed="false" title="پاک‌کن هایلایت">
                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'eraser']); ?>
                </button>


                <?php
                /**
                 * Appears only while a passage is selected. It is the whole
                 * explanation of the select-then-press gesture, shown exactly
                 * when it is useful and never otherwise.
                 */
                ?>
                <span class="vbar-hint" data-selection-hint hidden>برای هایلایت، قلم را بزن</span>

                <span class="vbar-sep" aria-hidden="true"></span>

                <button class="vbar-btn" type="button" data-undo disabled title="واگرد (Ctrl+Z)" aria-label="واگرد">
                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'undo']); ?>
                </button>
                <button class="vbar-btn" type="button" data-redo disabled title="از نو (Ctrl+Shift+Z)" aria-label="از نو">
                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'redo']); ?>
                </button>
            </div>
        <?php endif; ?>

        <span class="timer" id="study-timer" title="زمان مطالعه‌ی ثبت‌شده روی سرور"><?= e($studiedClock) ?></span>
        <span data-conn-slot></span>

        <div class="vmenu-wrap" data-vmenu>
            <button class="vbar-btn" type="button" data-vmenu-trigger
                    aria-haspopup="true" aria-expanded="false" aria-label="گزینه‌های بیشتر" title="گزینه‌های بیشتر">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'more']); ?>
            </button>

            <div class="vmenu" data-vmenu-panel hidden>
                <?php if ($isStudent): ?>
                    <div class="vmenu-label">وضعیت مطالعه</div>
                    <button class="vmenu-row<?= $statusValue === 'completed' ? ' is-on' : '' ?>" type="button" data-status="completed">
                        <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'check']); ?></span>
                        <span>کامل شد</span>
                    </button>
                    <button class="vmenu-row<?= $statusValue === 'studying' ? ' is-on' : '' ?>" type="button" data-status="studying">
                        <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'book']); ?></span>
                        <span>در حال مطالعه</span>
                    </button>
                    <button class="vmenu-row<?= $statusValue === 'review_later' ? ' is-on' : '' ?>" type="button" data-status="review_later">
                        <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'clock']); ?></span>
                        <span>مرور بعدی</span>
                    </button>
                    <div class="vmenu-sep"></div>
                <?php endif; ?>

                <?php if ($showTools): ?>
                    <button class="vmenu-row" type="button" id="btn-highlights">
                        <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'list']); ?></span>
                        <span>هایلایت‌های من</span>
                        <span class="vmenu-count" id="hl-count">۰</span>
                    </button>
                <?php endif; ?>

                <?php if ($isStudent && $offlineEnabled): ?>
                    <button class="vmenu-row offline-btn"
                            type="button"
                            data-offline-save="<?= e($content['uuid']) ?>"
                            data-version="<?= e($content['checksum'] ?? '') ?>"
                            data-offline-allowed="<?= (int) ($content['offline_enabled'] ?? 1) === 1 ? '1' : '0' ?>"
                            data-needs-network>
                        <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'download']); ?></span>
                        <span data-offline-label>ذخیره برای مطالعه آفلاین</span>
                    </button>
                <?php endif; ?>

                <button class="vmenu-row" type="button" data-theme-toggle>
                    <span class="row-icon theme-icon-sun"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'sun']); ?></span>
                    <span class="row-icon theme-icon-moon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'moon']); ?></span>
                    <span>حالت روشن / شب</span>
                </button>

                <div class="vmenu-sep"></div>

                <a class="vmenu-row" href="<?= e($backUrl) ?>">
                    <span class="row-icon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'logout']); ?></span>
                    <span>خروج از جزوه</span>
                </a>
            </div>
        </div>
    </header>

    <?php if ($showTools): ?>
        <div class="vpalette" data-palette hidden>
            <span class="vpalette-hint">متن را انتخاب کنید تا هایلایت شود</span>
            <div class="vpalette-colors">
                <?php foreach ($colors as $color): ?>
                    <button class="swatch<?= $color === $colors[0] ? ' is-on' : '' ?>" type="button"
                            data-color="<?= e($color) ?>"
                            aria-label="<?= e($colorNames[$color] ?? $color) ?>"
                            title="<?= e($colorNames[$color] ?? $color) ?>"></button>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <aside class="hl-drawer" id="hl-drawer" hidden aria-label="فهرست هایلایت‌ها">
        <div class="hl-drawer-head">
            <strong>هایلایت‌های من</strong>
            <button class="vbar-btn" type="button" id="btn-hl-close" aria-label="بستن">
                <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'close']); ?>
            </button>
        </div>
        <div class="hl-drawer-body" id="hl-list"></div>
        <div class="hl-drawer-foot" id="hl-note"></div>
    </aside>

    <div class="viewer-stage">
        <!--
            The lesson runs with an opaque origin: no allow-same-origin, so its
            scripts cannot read this page's DOM, cookies or session. Communication
            is limited to postMessage, which we validate before acting on.
        -->
        <iframe id="lesson"
                class="viewer-frame"
                src="/content/<?= e($content['uuid']) ?>/stream"
                sandbox="allow-scripts allow-popups allow-modals allow-forms"
                referrerpolicy="no-referrer"
                title="<?= e($content['title']) ?>"></iframe>

        <div class="viewer-loading" id="viewer-loading">
            <div class="loader-ring" aria-hidden="true"></div>
            <div class="loader-text">در حال بارگذاری محتوا…</div>
            <?php if ((int) $content['byte_size'] > 1048576): ?>
                <div class="loader-hint">
                    حجم این جزوه <?= e(fa(number_format(((int) $content['byte_size']) / 1048576, 1))) ?> مگابایت است.
                    بار اول کمی طول می‌کشد؛ دفعات بعد از حافظه مرورگر باز می‌شود.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="vtoast" data-toast hidden role="status" aria-live="polite"></div>
</div>

<script src="/assets/js/app.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="/assets/js/helexa-db.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="/assets/js/helexa-core.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="/assets/js/pwa.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php if ($isStudent && $offlineEnabled): ?>
    <script src="/assets/js/offline-manager.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<?php endif; ?>
<script src="/assets/js/viewer.js"
        nonce="<?= e($cspNonce ?? '') ?>"
        data-content="<?= e($content['uuid']) ?>"
        data-heartbeat="<?= (int) $heartbeatInt ?>"
        data-studied="<?= (int) $studiedSecs ?>"
        data-highlight="<?= $showTools ? '1' : '0' ?>"
        data-tracking="<?= $isStudent ? '1' : '0' ?>"></script>
</body>
</html>
