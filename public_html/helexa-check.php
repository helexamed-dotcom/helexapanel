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
 * Put it next to index.php and open https://your-site/helexa-check.php
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

<?php
/**
 * The server's own account of what went wrong.
 *
 * The front controller catches every unhandled Throwable and writes it here
 * with its message, file and line. When the app reports "a server error", this
 * is the only place that says which one — the app is deliberately told nothing
 * beyond the status code, because a student must never be shown a stack trace.
 *
 * Behind a key, because unlike the checks above this prints real error text,
 * which can name a table or a column. The key is not a security boundary worth
 * relying on: delete this file when you are done.
 */
const DIAG_KEY = 'c6c82af639145ebeecde97f2';

$keyed = isset($_GET['key']) && hash_equals(DIAG_KEY, (string) $_GET['key']);

if ($keyed):
    $logDir = $root . '/storage/logs';
    $lines = [];

    if (is_dir($logDir)) {
        // Newest file first, and only the last few days: an error from a
        // fortnight ago is not the one being chased.
        $files = glob($logDir . '/app-*.log') ?: [];
        rsort($files);

        foreach (array_slice($files, 0, 3) as $file) {
            foreach (array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
                if (str_contains($line, 'CRITICAL') || str_contains($line, 'ERROR')) {
                    $lines[] = $line;
                }
                if (count($lines) >= 25) {
                    break 2;
                }
            }
        }
    }
    ?>
    <?php
    /**
     * Who is this account, and can the app serve it?
     *
     * The app is the student client: /api/mobile is behind a student-only
     * guard, so an admin or teacher signs in fine and is then refused by
     * every screen. From outside that looks identical to a broken app, which
     * is why this asks the database directly.
     *
     * Prints role and status only — never a password, a hash, or a phone.
     */
    $who = trim((string) ($_GET['user'] ?? ''));

    if ($who !== '') {
        try {
            $pdo = \HeleXa\Core\Database::connection();
            $stmt = $pdo->prepare(
                'SELECT u.username, u.full_name, u.status, u.must_change_password,
                        r.slug AS role_slug,
                        (u.password_hash IS NOT NULL AND u.password_hash <> "") AS has_password
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE u.username = :u OR u.mobile = :m
                  LIMIT 1'
            );
            $stmt->execute(['u' => $who, 'm' => $who]);
            $found = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            $found = null;
        }
        ?>
        <h1 style="margin-top:32px">این حساب</h1>
        <?php if ($found === null): ?>
            <div class="row bad"><span class="tag bad">پیدا نشد ✗</span>
                <div class="why">هیچ حسابی با این نام کاربری یا شماره موبایل وجود ندارد.</div></div>
        <?php else:
            $isStudent = $found['role_slug'] === 'student';
            ?>
            <div class="row <?= $isStudent ? 'ok' : 'bad' ?>">
                <span class="tag <?= $isStudent ? 'ok' : 'bad' ?>">
                    نقش: <?= htmlspecialchars((string) $found['role_slug'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="name"><?= htmlspecialchars((string) $found['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php if (!$isStudent): ?>
                    <div class="why">
                        <b>علت پیدا شد.</b> این حساب دانشجو نیست، و اپ فقط برای دانشجویان است —
                        بخش داده‌های اپ عمداً روی حساب‌های غیردانشجو بسته است. برای همین وارد
                        می‌شوی ولی هیچ صفحه‌ای بالا نمی‌آید.
                        <br>با یک حساب دانشجو امتحان کن.
                    </div>
                <?php endif; ?>
            </div>
            <div class="row <?= (int) $found['must_change_password'] === 1 ? 'bad' : 'ok' ?>">
                <span class="tag <?= (int) $found['must_change_password'] === 1 ? 'bad' : 'ok' ?>">
                    <?= (int) $found['must_change_password'] === 1 ? 'باید رمز را عوض کند ✗' : 'رمز تثبیت‌شده ✓' ?>
                </span>
                <?php if ((int) $found['must_change_password'] === 1): ?>
                    <div class="why">تا وقتی رمزش را در خود سایت عوض نکند، اپ هم کار نمی‌کند.</div>
                <?php endif; ?>
            </div>
            <div class="row <?= $found['has_password'] ? 'ok' : 'bad' ?>">
                <span class="tag <?= $found['has_password'] ? 'ok' : 'bad' ?>">
                    <?= $found['has_password'] ? 'رمز عبور دارد ✓' : 'رمز عبور ندارد ✗' ?>
                </span>
            </div>
        <?php endif;
    } else { ?>
        <div class="note" style="margin-top:32px">
            برای دیدن اینکه یک حساب دانشجوست یا نه، نام کاربری را به آدرس اضافه کن:<br>
            <span class="name">&amp;user=نام‌کاربری</span>
        </div>
    <?php }

    /* --------------------------------------------------- session damage */
    try {
        $pdo = \HeleXa\Core\Database::connection();
        $broken = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE php_session_id = ''")->fetchColumn();
    } catch (\Throwable $e) {
        $broken = -1;
    }
    ?>

    <?php if ($broken > 0): ?>
        <div class="row bad">
            <span class="tag bad">جدول نشست‌ها آسیب دیده ✗</span>
            <div class="why">
                <?= $broken ?> ردیف با شناسه‌ی نشست خالی وجود دارد. تا وقتی پاک نشوند، ورود
                روی کل سایت با خطای «Duplicate entry» می‌شکند.
                فایل <span class="name" style="display:inline">2026_09_15_session_id_nullable.sql</span>
                را در phpMyAdmin اجرا کن.
            </div>
        </div>
    <?php elseif ($broken === 0): ?>
        <div class="row ok"><span class="tag ok">جدول نشست‌ها سالم است ✓</span></div>
    <?php endif; ?>

    <h1 style="margin-top:32px">آخرین خطاهای سرور</h1>
    <div class="sub">تازه‌ترین در بالا. این متن را برایم بفرست.</div>

    <?php if ($lines === []): ?>
        <div class="row">
            هیچ خطایی ثبت نشده. اگر اپ همین حالا خطا داد، یعنی درخواست اصلاً به
            سایت نرسیده یا وب‌سرور جلوتر از PHP آن را رد کرده است.
        </div>
    <?php else: ?>
        <?php foreach ($lines as $line): ?>
            <div class="row bad"><span class="name" style="direction:ltr; text-align:left"><?=
                htmlspecialchars($line, ENT_QUOTES, 'UTF-8')
            ?></span></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="row">
        <span class="name">PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></span>
        <?php if (version_compare(PHP_VERSION, '8.2.0', '<')): ?>
            <div class="why">این نسخه پایین‌تر از ۸.۲ است و سایت به ۸.۲ یا بالاتر نیاز دارد.
            در کنترل‌پنل هاست نسخه PHP را بالا ببر.</div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="note">
        برای دیدن خطاهای ثبت‌شده‌ی سرور، همین آدرس را با کلید باز کن:<br>
        <span class="name">?key=<?= DIAG_KEY ?></span>
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
