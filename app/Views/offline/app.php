<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#ffffff">
    <title>مطالعه آفلاین | <?= e($appName) ?></title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
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
</head>
<body class="offline-body">
<a class="skip-link" href="#offline-main">پرش به محتوای اصلی</a>

<div class="offline-shell" data-heartbeat="<?= (int) $heartbeatSecs ?>">
    <header class="offline-bar">
        <a class="icon-btn" href="/student" id="offline-back" aria-label="بازگشت">→</a>
        <div style="min-width:0; flex:1;">
            <div class="offline-title" id="offline-heading">مطالعه آفلاین</div>
            <div class="offline-sub" id="offline-subtitle">محتوای ذخیره‌شده روی این دستگاه</div>
        </div>
        <button class="icon-btn" type="button" data-theme-toggle aria-label="تغییر حالت روشن و شب">
            <span class="theme-icon-sun"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'sun']); ?></span>
            <span class="theme-icon-moon"><?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'moon']); ?></span>
        </button>
        <span class="conn-pill" id="conn-pill" role="status" aria-live="polite">
            <i class="conn-dot"></i><span class="conn-text">در حال بررسی…</span>
        </span>
    </header>

    <main class="offline-main" id="offline-main">
        <section id="view-library">
            <div class="card offline-summary" id="storage-summary">
                <div>
                    <div class="stat-label">حجم محتوای ذخیره‌شده</div>
                    <div class="stat-value" id="storage-used">—</div>
                    <div class="leaf-meta" id="storage-quota"></div>
                </div>
                <div class="row-actions">
                    <button class="btn btn-ghost btn-sm" id="btn-refresh-all" type="button">بررسی به‌روزرسانی</button>
                    <button class="btn btn-danger btn-sm" id="btn-purge-all" type="button">حذف همه محتوای آفلاین</button>
                </div>
            </div>

            <div class="card" id="sync-card" hidden>
                <h3 class="card-title">همگام‌سازی</h3>
                <div id="sync-status" class="leaf-meta"></div>
                <button class="btn btn-primary btn-sm" id="btn-sync-now" type="button" style="margin-top:10px;">
                    همگام‌سازی همین حالا
                </button>
            </div>

            <div id="library"></div>

            <div class="card" id="empty-library">
                <div class="empty">
                    هنوز محتوایی برای مطالعه آفلاین ذخیره نکرده‌اید.<br>
                    وقتی آنلاین هستید، داخل هر جزوه دکمه «ذخیره برای مطالعه آفلاین» را بزنید.
                </div>
            </div>
        </section>

        <section id="view-reader" hidden>
            <?php
            $colors     = \HeleXa\Controllers\HighlightController::COLORS;
            $colorNames = ['yellow' => 'زرد', 'green' => 'سبز', 'blue' => 'آبی', 'pink' => 'صورتی', 'purple' => 'بنفش'];
            ?>
            <div class="reader-head">
                <button class="vbar-btn" id="btn-reader-back" type="button" aria-label="بازگشت به کتابخانه" title="بازگشت به کتابخانه">
                    <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'back']); ?>
                </button>
                <div class="reader-title" id="reader-title"></div>

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
                    <span class="vbar-sep" aria-hidden="true"></span>
                    <button class="vbar-btn" type="button" data-undo disabled title="واگرد" aria-label="واگرد">
                        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'undo']); ?>
                    </button>
                    <button class="vbar-btn" type="button" data-redo disabled title="از نو" aria-label="از نو">
                        <?php \HeleXa\Core\View::partial('partials.icon', ['name' => 'redo']); ?>
                    </button>
                </div>

                <span class="timer" id="reader-timer">۰۰:۰۰:۰۰</span>
                <span class="hl-mini" id="reader-hl-count" title="هایلایت‌های این جزوه"></span>
            </div>

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

            <div class="reader-status">
                <button class="status-btn" data-status="completed" type="button">کامل شد</button>
                <button class="status-btn" data-status="studying" type="button">در حال مطالعه</button>
                <button class="status-btn" data-status="review_later" type="button">مرور بعدی</button>
            </div>
            <div class="reader-stage">
                <iframe id="reader-frame"
                        class="reader-frame"
                        sandbox="allow-scripts allow-popups allow-modals allow-forms"
                        referrerpolicy="no-referrer"
                        title="محتوای آفلاین"></iframe>
                <div class="reader-loading" id="reader-loading">
                    <div class="loader-ring" aria-hidden="true"></div>
                    <div class="loader-text">در حال باز کردن محتوا…</div>
                </div>
            </div>
        </section>
    </main>
</div>

<script src="/assets/js/helexa-db.js"></script>
<script src="/assets/js/helexa-core.js"></script>
<script src="/assets/js/pwa.js"></script>
<script src="/assets/js/offline-app.js"></script>
</body>
</html>
