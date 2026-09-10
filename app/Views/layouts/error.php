<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title ?? 'خطا') ?> | <?= e($appName ?? 'HeleXa Med') ?></title>
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
</head>
<body>
<div class="error-wrap"><?= $content ?? '' ?></div>
</body>
</html>
