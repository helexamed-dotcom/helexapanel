<?php
/**
 * HeleXa Med — one-time diagnostic tool.
 *
 * Deliberately standalone: it does not touch any of the application's own
 * classes, so if the real app is throwing a 500, this file still runs and
 * explains why.
 *
 * Delete this file from the server after you are done with it.
 */
declare(strict_types=1);

$secret = '69c1352d8c0f6ae9db18e971';
if (!isset($_GET['key']) || !hash_equals($secret, (string) $_GET['key'])) {
    http_response_code(404);
    exit('Not found.');
}

header('Content-Type: text/html; charset=UTF-8');

$configPath = __DIR__ . '/../config/config.php';
$ok  = static fn (string $s): string => "<span style='color:#15803d;font-weight:700'>✅ {$s}</span>";
$bad = static fn (string $s): string => "<span style='color:#b91c1c;font-weight:700'>❌ {$s}</span>";
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>تشخیص HeleXa Med</title>
<style>
  body{font-family:Tahoma,sans-serif;background:#f5f7fb;color:#101a2c;padding:24px;line-height:2}
  .box{background:#fff;border:1px solid #e6ebf3;border-radius:14px;padding:20px;max-width:820px;margin:0 auto 16px}
  h2{margin:0 0 10px;font-size:16px}
  table{width:100%;border-collapse:collapse;font-size:13.5px}
  td{padding:6px 4px;border-bottom:1px solid #eee}
  .todo{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px;margin-top:10px}
  code{background:#eef2f7;padding:2px 6px;border-radius:6px;direction:ltr;display:inline-block}
</style>
</head>
<body>
<div class="box">
<h2>۱. فایل تنظیمات و اتصال دیتابیس</h2>
<table>
<?php
if (!is_file($configPath)) {
    echo '<tr><td>' . $bad('فایل config/config.php پیدا نشد: ' . htmlspecialchars($configPath)) . '</td></tr>';
    echo '</table></div></body></html>';
    exit;
}
echo '<tr><td>' . $ok('فایل config/config.php پیدا شد') . '</td></tr>';

$config = require $configPath;
$db     = $config['database'] ?? [];

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'] ?? 'localhost',
        (int) ($db['port'] ?? 3306),
        $db['name'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $db['user'] ?? '', $db['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo '<tr><td>' . $ok('اتصال به دیتابیس «' . htmlspecialchars((string) ($db['name'] ?? '')) . '» برقرار شد') . '</td></tr>';
} catch (Throwable $e) {
    echo '<tr><td>' . $bad('اتصال به دیتابیس ناموفق بود: ' . htmlspecialchars($e->getMessage())) . '</td></tr>';
    echo '</table></div></body></html>';
    exit;
}
?>
</table>
</div>

<div class="box">
<h2>۲. وضعیت جدول‌ها و ستون‌های هر migration</h2>
<table>
<?php
function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return $stmt->fetch() !== false;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    if (!tableExists($pdo, $table)) {
        return false;
    }
    // Table names cannot be bound as parameters; it is validated against a
    // fixed whitelist by the caller below, never taken from user input.
    $stmt = $pdo->prepare(sprintf('SHOW COLUMNS FROM `%s` LIKE ?', $table));
    $stmt->execute([$column]);
    return $stmt->fetch() !== false;
}

$checks = [
    // label                                        file                                   check
    ['2026_09_06_phase3_content.sql',                '2026_09_06_phase3_content.sql',       ['col',   'course_contents',  'scan_report']],
    ['2026_09_06_phase7_hardening.sql',               '2026_09_06_phase7_hardening.sql',     ['table', 'rate_limits']],
    ['2026_09_07_pwa_offline.sql',                    '2026_09_07_pwa_offline.sql',          ['table', 'offline_sync_events']],
    ['2026_09_09_remember_me.sql',                    '2026_09_09_remember_me.sql',          ['table', 'remember_tokens']],
    ['2026_09_09_academic.sql',                       '2026_09_09_academic.sql',             ['table', 'universities']],
    ['2026_09_09_academic.sql (majors)',               '2026_09_09_academic.sql',             ['table', 'majors']],
    ['2026_09_09_academic.sql (user_semesters)',       '2026_09_09_academic.sql',             ['table', 'user_semesters']],
    ['2026_09_09_packages.sql',                       '2026_09_09_packages.sql',             ['table', 'packages']],
    ['2026_09_09_highlights.sql',                     '2026_09_09_highlights.sql',           ['table', 'content_highlights']],
    ['2026_09_09_guest_mode.sql',                     '2026_09_09_guest_mode.sql',           ['col',   'course_contents',  'guest_visible']],
    ['2026_09_10_subjects_and_media.sql',             '2026_09_10_subjects_and_media.sql',   ['table', 'subjects']],
    ['2026_09_10_subjects_and_media.sql (schedule_items.subject_id)', '2026_09_10_subjects_and_media.sql', ['col', 'schedule_items', 'subject_id']],
    ['2026_09_10_subjects_and_media.sql (exams.subject_id)',          '2026_09_10_subjects_and_media.sql', ['col', 'exams',          'subject_id']],
];

$missingFiles = [];
foreach ($checks as [$label, $file, $check]) {
    $found = $check[0] === 'table'
        ? tableExists($pdo, $check[1])
        : columnExists($pdo, $check[1], $check[2]);

    echo '<tr><td>' . ($found ? $ok($label) : $bad($label)) . '</td></tr>';
    if (!$found) {
        $missingFiles[$file] = true;
    }
}
$missing = array_keys($missingFiles);
?>
</table>
</div>

<div class="box">
<h2>۳. افزونه‌های PHP لازم برای تصاویر</h2>
<table>
<tr><td><?= class_exists('DOMDocument') ? $ok('DOMDocument (ext-dom) نصب است') : $bad('DOMDocument (ext-dom) نصب نیست — آپلود SVG برای لوگو کار نمی‌کند') ?></td></tr>
<tr><td><?= function_exists('getimagesize') ? $ok('پردازش تصویر (GD) در دسترس است') : $bad('GD در دسترس نیست') ?></td></tr>
<tr><td>نسخه PHP: <b><?= htmlspecialchars(PHP_VERSION) ?></b></td></tr>
</table>
</div>

<?php if ($missing !== []): ?>
<div class="box todo">
<h2>نتیجه</h2>
<p>این‌ها روی هاست اجرا نشده‌اند و علت خطای ۵۰۰ همین‌هاست. از پنل دایرکت‌ادمین وارد
   <b>phpMyAdmin</b> شوید، پایگاه‌داده سایت را انتخاب کنید، به تب <b>SQL</b> بروید و
   محتوای این فایل‌ها را به همین ترتیب اجرا کنید (هرکدام را جدا کپی و Go را بزنید):</p>
<ol>
<?php foreach ($missing as $file): ?>
    <li><code>database/migrations/<?= htmlspecialchars($file) ?></code></li>
<?php endforeach; ?>
</ol>
</div>
<?php else: ?>
<div class="box" style="background:#f0fdf4;border-color:#bbf7d0;">
<h2>نتیجه</h2>
<p>همه‌ی migration ها روی دیتابیس اجرا شده‌اند. علت خطای ۵۰۰ چیز دیگری است؛
   لطفاً همین صفحه را برای من بفرستید تا ادامه بدهیم.</p>
</div>
<?php endif; ?>

<div class="box" style="background:#fef2f2;border-color:#fecaca;">
<b>⚠️ این فایل را بعد از استفاده حتماً از روی سرور حذف کنید</b> (همان public_html/diag.php).
</div>
</body>
</html>
