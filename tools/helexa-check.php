<?php
declare(strict_types=1);

/**
 * A one-page answer to "is the update actually installed?".
 *
 * This exists because a half-finished upload looks exactly like a finished
 * one from the outside: the website keeps working, and only the app fails,
 * with an error that cannot say which file is missing.
 *
 * It reports presence and shape only. It prints no password, no API key, no
 * configuration value and no filesystem path, so a copy left behind by
 * accident gives an attacker nothing. Delete it when you are done anyway.
 *
 * It lives in tools/ rather than in public_html/ on purpose: it is meant to
 * be copied into the web root, read once, and deleted. Keeping it under the
 * web root here would deploy it to the live site on every upload, which is
 * the opposite of what the instructions ask the operator to do.
 *
 * To use: copy it next to index.php, open https://your-site/helexa-check.php,
 * act on what it says, then delete it from the server.
 */

header('Content-Type: text/html; charset=utf-8');
// Nothing here should ever be cached or indexed.
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$root = dirname(__DIR__);

/** Each check: a label, and a closure returning true, false, or a string note. */
$checks = [];

/** A file must exist and contain a marker only the new version has. */
function needs(string $path, string $marker, string $why): array
{
    return ['path' => $path, 'marker' => $marker, 'why' => $why];
}

$files = [
    needs('app/Views/layouts/auth.php', 'assets/js/auth.js',
        'اپ توکن امنیتی را از این صفحه می‌خواند. بدون آن، ورود با خطای «اعتبار صفحه تمام شده» رد می‌شود.'),
    needs('app/Controllers/AuthController.php', 'isAjax',
        'بدون این، سایت به اپ به‌جای پاسخ JSON یک صفحه HTML برمی‌گرداند و اپ آن را نمی‌فهمد.'),
    needs('app/Controllers/Api/MobileController.php', 'viewer_path',
        'کل داده‌های اپ (داشبورد، دوره‌ها، برنامه) از اینجا می‌آید.'),
    needs('routes/web.php', '/api/mobile',
        'بدون این مسیرها، درخواست‌های اپ به آدرس ناموجود می‌خورند.'),
    needs('app/Services/Phone.php', 'normalize',
        'شماره موبایل را یکسان می‌کند تا «۰۹۱۲…» و «+۹۸۹۱۲…» به یک حساب برسند.'),
    needs('app/Services/Auth.php', 'hasPassword',
        'هسته ورود.'),
    needs('app/Models/UserRepository.php', 'has_password',
        'اپ باید بداند حسابی رمز دارد یا نه.'),
    needs('app/Views/auth/login.php', 'auth-tabs',
        'صفحه ورود سایت.'),
];

?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>بررسی نصب HeleXa</title>
<style>
  body { font-family: Tahoma, sans-serif; background:#f6f7f9; color:#1b1f24;
         margin:0; padding:24px 16px; line-height:2; }
  .wrap { max-width: 760px; margin: 0 auto; }
  h1 { font-size:20px; margin:0 0 4px; }
  .sub { color:#5b6572; font-size:13px; margin-bottom:20px; }
  .row { background:#fff; border:1px solid #e3e6ea; border-radius:10px;
         padding:12px 14px; margin-bottom:8px; }
  .ok   { border-right:4px solid #1a9e5c; }
  .bad  { border-right:4px solid #d33; }
  .name { font-family: monospace; font-size:13px; direction:ltr; text-align:right;
          display:block; color:#3a4450; word-break:break-all; }
  .why  { font-size:13px; color:#5b6572; }
  .tag  { font-weight:bold; }
  .tag.ok  { color:#1a9e5c; border:0; }
  .tag.bad { color:#d33; border:0; }
  .verdict { border-radius:10px; padding:16px; margin:20px 0; font-weight:bold; }
  .verdict.ok  { background:#e7f6ee; border:1px solid #1a9e5c; color:#0e6b3d; }
  .verdict.bad { background:#fdecec; border:1px solid #d33; color:#a11; }
  .note { font-size:13px; color:#5b6572; margin-top:24px; }
</style>
</head>
<body>
<div class="wrap">
<h1>بررسی نصب به‌روزرسانی</h1>
<div class="sub">این صفحه فقط می‌گوید چه چیزی نصب شده و چه چیزی نه. هیچ رمز یا اطلاعات محرمانه‌ای نشان نمی‌دهد.</div>

<?php
$failed = 0;

foreach ($files as $f) {
    $full = $root . '/' . $f['path'];
    $exists = is_file($full);
    $fresh  = $exists && str_contains((string) file_get_contents($full), $f['marker']);

    if (!$fresh) {
        $failed++;
    }

    $cls = $fresh ? 'ok' : 'bad';
    $tag = $fresh ? 'نصب شده ✓' : ($exists ? 'نسخه قدیمی ✗' : 'وجود ندارد ✗');
    ?>
    <div class="row <?= $cls ?>">
        <span class="tag <?= $cls ?>"><?= $tag ?></span>
        <span class="name"><?= htmlspecialchars($f['path'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php if (!$fresh): ?>
            <div class="why"><?= htmlspecialchars($f['why'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * The database half.
 *
 * Bootstrapping the real application is what makes this honest: it uses the
 * site's own connection settings, so it cannot pass while the site fails.
 */
$dbNote = null;
$dbOk = false;

try {
    require_once $root . '/bootstrap/bootstrap.php';
    $pdo = \HeleXa\Core\Database::connection();

    $hasTable = (bool) $pdo->query("SHOW TABLES LIKE 'otp_codes'")->fetchColumn();
    $hasColumn = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'phone_verified_at'")->fetchColumn();

    $dbOk = $hasTable && $hasColumn;
    if (!$dbOk) {
        $dbNote = 'فایل database/migrations/2026_09_14_phone_auth.sql هنوز روی دیتابیس اجرا نشده.';
    }
} catch (\Throwable $e) {
    // The message itself may name a table or a column, never a credential —
    // but it is still not shown, because it is not needed to act on this.
    $dbNote = 'اتصال به دیتابیس یا خواندن ساختار آن ممکن نشد.';
}

if (!$dbOk) {
    $failed++;
}
?>
<div class="row <?= $dbOk ? 'ok' : 'bad' ?>">
    <span class="tag <?= $dbOk ? 'ok' : 'bad' ?>"><?= $dbOk ? 'انجام شده ✓' : 'انجام نشده ✗' ?></span>
    <span class="name">database migration (otp_codes + users.phone_verified_at)</span>
    <?php if ($dbNote): ?><div class="why"><?= htmlspecialchars($dbNote, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
</div>

<?php if ($failed === 0): ?>
    <div class="verdict ok">
        همه چیز نصب است. اپ اندروید باید وارد شود.<br>
        حالا این فایل را پاک کن.
    </div>
<?php else: ?>
    <div class="verdict bad">
        <?= $failed ?> مورد ناقص است. تا وقتی همه‌شان سبز نشوند، اپ وارد نمی‌شود.<br>
        موردهای قرمز بالا را از پوشه‌ای که برایت فرستادم، دقیقاً در همان مسیر کپی کن.
    </div>
<?php endif; ?>

<div class="note">
    نکته: مسیرها نسبت به پوشه‌ی اصلی سایت‌اند — یعنی همان پوشه‌ای که
    <span class="name" style="display:inline">app/</span> و
    <span class="name" style="display:inline">routes/</span> در آن هستند،
    نه پوشه‌ی <span class="name" style="display:inline">public_html/</span>.
</div>
</div>
</body>
</html>
