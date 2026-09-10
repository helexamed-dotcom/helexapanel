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
    <style>
        html, body { height: 100%; overflow: hidden; background: var(--canvas); }
        .viewer { display: flex; flex-direction: column; height: 100dvh; }
        .viewer-bar {
            display: flex; align-items: center; gap: 10px;
            padding: 0 14px; height: 56px; flex: 0 0 56px;
            background: #fff; border-bottom: 1px solid var(--line);
        }
        .viewer-title { font-size: 14.5px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .viewer-course { font-size: 11.5px; color: var(--ink-3); }
        .viewer-frame { flex: 1 1 auto; width: 100%; border: 0; background: #fff; opacity: 0; transition: opacity .35s ease; }
        .viewer-frame.is-ready { opacity: 1; }
        .viewer-stage { position: relative; flex: 1 1 auto; display: flex; }
        .viewer-loading {
            position: absolute; inset: 0; background: #fff; z-index: 5;
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px;
        }
        .viewer-loading.is-hidden { opacity: 0; pointer-events: none; transition: opacity .3s ease; }
        .loader-ring {
            width: 34px; height: 34px; border-radius: 50%;
            border: 3px solid var(--line); border-top-color: var(--blue);
            animation: viewer-spin .8s linear infinite;
        }
        @keyframes viewer-spin { to { transform: rotate(360deg); } }
        .loader-text { font-size: 13px; color: var(--ink-3); }
        .loader-hint { font-size: 11.5px; color: var(--ink-3); opacity: .8; }
        @media (prefers-reduced-motion: reduce) {
            .loader-ring { animation-duration: 2s; }
            .viewer-frame { transition: none; }
        }
        .status-bar { display: flex; gap: 6px; align-items: center; }
        .status-btn {
            border: 1.5px solid var(--line); background: #fff; color: var(--ink-2);
            border-radius: 10px; padding: 6px 11px; font-size: 12.5px; cursor: pointer;
        }
        .status-btn.is-on[data-status="completed"]    { background: #dcfce7; border-color: #86efac; color: #15803d; }
        .status-btn.is-on[data-status="studying"]     { background: #fef3c7; border-color: #fcd34d; color: #b45309; }
        .status-btn.is-on[data-status="review_later"] { background: var(--blue-soft); border-color: #93c5fd; color: var(--blue); }
        .timer { font-family: ui-monospace, Menlo, monospace; font-size: 13px; color: var(--ink-3); direction: ltr; }
        @media (max-width: 720px) {
            .viewer-bar { height: 52px; flex-basis: 52px; padding: 0 10px; gap: 6px; }
            .viewer-title { font-size: 13px; max-width: 40vw; }
            .status-btn { padding: 5px 8px; font-size: 11.5px; }
            .hide-sm { display: none; }
        }
        <?php if ((int) $content['is_printable'] === 0): ?>
        @media print { body { display: none !important; } }
        <?php endif; ?>
    </style>
</head>
<body>
<div class="viewer">
    <header class="viewer-bar">
        <a class="icon-btn" href="/student/courses/<?= e($content['course_uuid']) ?>" title="بازگشت">→</a>
        <div style="min-width:0;">
            <div class="viewer-title"><?= e($content['title']) ?></div>
            <div class="viewer-course hide-sm"><?= e($content['course_title']) ?></div>
        </div>
        <div class="spacer" style="flex:1"></div>
        <button class="icon-btn" type="button" data-theme-toggle aria-label="تغییر حالت روشن و شب">
            <span class="theme-icon-sun"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'sun']); ?></span>
            <span class="theme-icon-moon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'moon']); ?></span>
        </button>
        <span class="timer" id="study-timer" title="زمان مطالعه‌ی ثبت‌شده روی سرور"><?= e($studiedClock) ?></span>
        <?php if ($isStudent && $highlightEnabled): ?>
            <button class="btn btn-ghost btn-sm" type="button" id="btn-area-mode"
                    title="کشیدن کادر روی تصویر">هایلایت تصویر</button>
            <button class="btn btn-ghost btn-sm" type="button" id="btn-highlights"
                    title="فهرست هایلایت‌ها">هایلایت‌ها <span id="hl-count">۰</span></button>
        <?php endif; ?>
        <?php if ($isStudent && $offlineEnabled): ?>
            <button class="btn btn-sm offline-btn btn-ghost"
                    type="button"
                    data-offline-save="<?= e($content['uuid']) ?>"
                    data-version="<?= e($content['checksum'] ?? '') ?>"
                    data-offline-allowed="<?= (int) ($content['offline_enabled'] ?? 1) === 1 ? '1' : '0' ?>"
                    data-needs-network>ذخیره برای مطالعه آفلاین</button>
        <?php endif; ?>
        <?php if ($isStudent): ?>
            <div class="status-bar">
                <button class="status-btn<?= $statusValue === 'completed' ? ' is-on' : '' ?>" data-status="completed" type="button">کامل شد</button>
                <button class="status-btn<?= $statusValue === 'studying' ? ' is-on' : '' ?>" data-status="studying" type="button">در حال مطالعه</button>
                <button class="status-btn<?= $statusValue === 'review_later' ? ' is-on' : '' ?>" data-status="review_later" type="button">مرور بعدی</button>
            </div>
        <?php endif; ?>
    </header>

    <aside class="hl-drawer" id="hl-drawer" hidden aria-label="فهرست هایلایت‌ها">
        <div class="hl-drawer-head">
            <strong>هایلایت‌های من</strong>
            <button class="btn btn-ghost btn-sm" type="button" id="btn-hl-close">بستن</button>
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
</div>

<script src="/assets/js/app.js" nonce="<?= e($cspNonce ?? '') ?>"></script>
<script src="/assets/js/helexa-db.js"></script>
<script src="/assets/js/helexa-core.js"></script>
<script src="/assets/js/pwa.js"></script>
<script src="/assets/js/offline-manager.js"></script>
<script src="/assets/js/viewer.js"
        data-content="<?= e($content['uuid']) ?>"
        data-heartbeat="<?= (int) $heartbeatInt ?>"
        data-studied="<?= (int) $studiedSecs ?>"
        data-tracking="<?= $isStudent ? '1' : '0' ?>"></script>
</body>
</html>
