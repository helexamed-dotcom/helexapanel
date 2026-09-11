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
    <link rel="stylesheet" href="<?= asset('/assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset('/assets/css/pwa.css') ?>">
    <meta name="theme-color" content="#00694F">
</head>
<body>
<div class="auth-wrap">
    <?= $content ?? '' ?>
</div>
<script src="<?= asset('/assets/js/app.js') ?>" nonce="<?= e($cspNonce ?? '') ?>"></script>
</body>
</html>
