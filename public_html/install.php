<?php
declare(strict_types=1);

/**
 * HeleXa Med — installer.
 *
 * Runs once. On success it writes config/config.php, creates the schema,
 * creates the super admin, drops storage/installed.lock and deletes itself.
 * Any later request is refused by the lock check below.
 */

use HeleXa\Core\Config;
use HeleXa\Core\Csrf;
use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Services\Auth;

/** @var \HeleXa\Core\Request $request */
$request = require dirname(__DIR__) . '/bootstrap/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

if (HELEXA_INSTALLED) {
    http_response_code(403);
    exit('<meta charset="utf-8"><p style="font-family:sans-serif;direction:rtl">سیستم قبلاً نصب شده است. برای نصب مجدد باید فایل قفل نصب حذف شود.</p>');
}

$state  = $_SESSION['_install'] ?? ['db' => null];
$step   = max(1, min(4, (int) ($_GET['step'] ?? 1)));
$errors = [];
$notice = null;

/* ------------------------------------------------------------- helpers */

function requirementList(): array
{
    return [
        'PHP نسخه ۸.۲ یا بالاتر'        => version_compare(PHP_VERSION, '8.2.0', '>='),
        'افزونه PDO MySQL'              => extension_loaded('pdo_mysql'),
        'افزونه mbstring'               => extension_loaded('mbstring'),
        'افزونه OpenSSL'                => extension_loaded('openssl'),
        'افزونه JSON'                   => extension_loaded('json'),
        'پشتیبانی از Argon2id'          => defined('PASSWORD_ARGON2ID'),
        'نوشتن در پوشه config'          => is_writable(CONFIG_PATH),
        'نوشتن در پوشه storage'         => is_writable(STORAGE_PATH),
        'نوشتن در storage/logs'         => is_writable(STORAGE_PATH . '/logs'),
        'نوشتن در storage/sessions'     => is_writable(STORAGE_PATH . '/sessions'),
        'نوشتن در storage/private'      => is_writable(PRIVATE_PATH),
    ];
}

function connectWith(array $db): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['name']),
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

/**
 * Migrations a fresh install needs on top of schema.sql.
 *
 * schema.sql is the base tables; anything added after it lives in its own
 * migration and is listed here rather than being copied into schema.sql,
 * so the two can never drift apart. Only migrations that are safe to run
 * against a brand-new database belong in this list.
 */
const FRESH_INSTALL_MIGRATIONS = [
    '2026_09_13_balin_island.sql',

    // phone_auth is still run, then undone, rather than dropped from this
    // list. It does two unrelated things: it builds the SMS/OTP tables and
    // settings, and it adds users.phone_verified_at and
    // users.registration_source — columns UserRepository, the mobile API and
    // two admin pages still read. Running the pair leaves a fresh install in
    // exactly the state an upgraded install reaches, which is the property
    // that keeps the two kinds of installation from drifting apart.
    '2026_09_14_phone_auth.sql',
    '2026_09_16_drop_sms_otp.sql',
    '2026_09_16_question_bank.sql',
    '2026_09_17_flashcards.sql',
    '2026_09_18_student_access.sql',
    '2026_09_18_ip_watch.sql',
    '2026_09_19_library_prefs.sql',
    '2026_09_20_ink_notes.sql',
    '2026_09_21_schedule_choice.sql',
    '2026_09_22_balin_clinical_blocks.sql',
    '2026_09_23_access_and_packages.sql',
    '2026_09_24_registration_free_packages.sql',
    '2026_09_25_study_suite.sql',
    '2026_09_26_student_types_and_home.sql',
];

/** Splits the schema on semicolons at end of line; the file contains no procedures. */
function runSchema(PDO $pdo, string $sqlFile): void
{
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException('فایل schema.sql خوانده نشد.');
    }
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    // Normalise Windows line endings, or the split below finds no statements.
    $sql = str_replace("\r\n", "\n", $sql);
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
        $statement = trim($statement, " \t\n\r\0\x0B;");
        if ($statement === '') {
            continue;
        }
        // Re-runnable: a step that failed half-way can simply be run again.
        // Tables that exist are kept, and a change that was already applied
        // is skipped rather than stopping the install.
        $statement = (string) preg_replace('/^CREATE TABLE (?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $statement);
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            if (!alreadyApplied($e, $statement)) {
                throw $e;
            }
        }
    }
}

/**
 * True for the errors that only mean "this was done on an earlier run":
 * table / column / index / constraint already there, a row already seeded,
 * or a column already gone.
 */
function alreadyApplied(PDOException $e, string $statement): bool
{
    $code = (int) ($e->errorInfo[1] ?? 0);
    if (in_array($code, [1050, 1060, 1061, 1062, 1068, 1091, 1826], true)) {
        return true;
    }
    // A foreign key that already exists surfaces as 1005 "errno: 121" on an
    // ALTER. On a CREATE TABLE it is a real clash of constraint names — with
    // IF NOT EXISTS an existing table never gets that far — so it is reported.
    return $code === 1005 && str_contains($e->getMessage(), 'errno: 121')
        && stripos(ltrim($statement), 'CREATE TABLE') !== 0;
}

function writeConfig(array $db, string $appKey, string $appUrl): void
{
    $config = [
        'app' => [
            'name'        => 'HeleXa Med',
            'url'         => $appUrl,
            'timezone'    => 'Asia/Tehran',
            'environment' => 'production',
            'debug'       => false,
        ],
        'database' => [
            'host'     => $db['host'],
            'port'     => (int) $db['port'],
            'name'     => $db['name'],
            'user'     => $db['user'],
            'password' => $db['password'],
            'charset'  => 'utf8mb4',
        ],
        'security' => [
            'app_key'         => $appKey,
            'force_https'     => true,
            'trust_proxy'     => false,
            'session_name'    => 'HLX_SID',
            'cookie_samesite' => 'Lax',
            'argon_memory'    => 65536,
            'argon_time'      => 4,
            'argon_threads'   => 1,
        ],
    ];

    $php = "<?php\n/** Generated by install.php. Keep this file outside public_html. */\nreturn "
         . var_export($config, true) . ";\n";

    if (file_put_contents(CONFIG_FILE, $php, LOCK_EX) === false) {
        throw new RuntimeException('نوشتن فایل config ناموفق بود.');
    }
    @chmod(CONFIG_FILE, 0600);
}

/* ---------------------------------------------------------- processing */

if ($request->method() === 'POST') {
    if (!Csrf::verify($request->string('_token'))) {
        $errors[] = 'اعتبار فرم منقضی شده است. صفحه را تازه کنید.';
    } else {
        $action = $request->string('action');

        if ($action === 'test_db') {
            $db = [
                'host'     => $request->string('db_host', 'localhost'),
                'port'     => $request->int('db_port', 3306),
                'name'     => $request->string('db_name'),
                'user'     => $request->string('db_user'),
                'password' => (string) $request->input('db_password', ''),
            ];
            try {
                connectWith($db);
                $state['db'] = $db;
                $_SESSION['_install'] = $state;
                $notice = 'اتصال به دیتابیس موفق بود.';
                $step   = 3;
            } catch (Throwable $e) {
                $errors[] = 'اتصال ناموفق بود. مقادیر هاست، نام دیتابیس، کاربر و رمز را بررسی کنید.';
                $step     = 2;
            }
        }

        if ($action === 'create_tables') {
            if ($state['db'] === null) {
                $errors[] = 'ابتدا اتصال دیتابیس را تست کنید.';
                $step     = 2;
            } else {
                try {
                    $pdo = connectWith($state['db']);
                    runSchema($pdo, DATABASE_PATH . '/schema.sql');
                    foreach (FRESH_INSTALL_MIGRATIONS as $migration) {
                        runSchema($pdo, DATABASE_PATH . '/migrations/' . $migration);
                    }
                    $notice = 'جدول‌ها با موفقیت ساخته شدند.';
                    $step   = 4;
                } catch (Throwable $e) {
                    $errors[] = 'ساخت جدول‌ها ناموفق بود: ' . $e->getMessage();
                    $step     = 3;
                }
            }
        }

        if ($action === 'create_admin') {
            $fullName = $request->string('full_name');
            $username = $request->string('username');
            $password = (string) $request->input('password', '');
            $confirm  = (string) $request->input('password_confirmation', '');
            $appUrl   = rtrim($request->string('app_url'), '/');

            if ($state['db'] === null) {
                $errors[] = 'اطلاعات دیتابیس یافت نشد. از ابتدا شروع کنید.';
            }
            if (mb_strlen($fullName) < 3) {
                $errors[] = 'نام کامل معتبر نیست.';
            }
            if (preg_match('/^[a-zA-Z0-9._-]{3,64}$/', $username) !== 1) {
                $errors[] = 'نام کاربری فقط حروف انگلیسی، عدد، نقطه، خط تیره و زیرخط (۳ تا ۶۴ کاراکتر).';
            }
            // Same rule as the rest of the application, so an admin cannot end
            // up with a password the change-password form would later reject.
            if (preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z0-9]{8,}$/', $password) !== 1) {
                $errors[] = 'رمز عبور باید حداقل ۸ کاراکتر و فقط شامل حروف انگلیسی و اعداد باشد.';
            }
            if ($password !== $confirm) {
                $errors[] = 'تکرار رمز عبور مطابقت ندارد.';
            }
            if ($appUrl === '' || !filter_var($appUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'آدرس سایت معتبر نیست. مثال: https://example.com';
            }

            if ($errors === []) {
                try {
                    $appKey = Str::token(32);

                    // Config must exist before Auth::hashPassword reads its options.
                    writeConfig($state['db'], $appKey, $appUrl);
                    Config::loadFile('app', CONFIG_FILE);

                    $pdo = connectWith($state['db']);

                    $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE slug = 'super_admin' LIMIT 1");
                    $roleStmt->execute();
                    $roleId = (int) $roleStmt->fetchColumn();
                    if ($roleId === 0) {
                        throw new RuntimeException('نقش super_admin یافت نشد. مرحله ساخت جدول‌ها را دوباره اجرا کنید.');
                    }

                    $exists = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
                    $exists->execute([$username]);
                    if ((int) $exists->fetchColumn() > 0) {
                        throw new RuntimeException('این نام کاربری قبلاً ثبت شده است.');
                    }

                    $insert = $pdo->prepare(
                        'INSERT INTO users (uuid, role_id, username, password_hash, password_changed_at,
                                            full_name, status, created_at)
                         VALUES (?, ?, ?, ?, NOW(), ?, "active", NOW())'
                    );
                    $insert->execute([
                        Str::uuid4(),
                        $roleId,
                        $username,
                        Auth::hashPassword($password),
                        $fullName,
                    ]);

                    file_put_contents(INSTALL_LOCK, json_encode([
                        'installed_at' => date('c'),
                        'version'      => '1.0.0',
                    ], JSON_PRETTY_PRINT));
                    @chmod(INSTALL_LOCK, 0600);

                    unset($_SESSION['_install']);
                    $deleted = @unlink(__FILE__);

                    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
                       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                       . '<title>نصب کامل شد</title><link rel="stylesheet" href="/assets/css/app.css"></head>'
                       . '<body><div class="auth-wrap"><div class="auth-card">'
                       . '<div class="auth-logo">✓</div><h1 class="auth-title">نصب کامل شد</h1>'
                       . '<p class="auth-sub">حساب مدیر ارشد ساخته شد و نصب قفل گردید.</p>'
                       . ($deleted
                            ? '<div class="alert alert-success">فایل install.php به‌صورت خودکار حذف شد.</div>'
                            : '<div class="alert alert-error">فایل install.php حذف نشد. آن را دستی از هاست پاک کنید.</div>')
                       . '<a class="btn btn-primary btn-block" href="/login">ورود به سامانه</a>'
                       . '</div></div></body></html>';
                    exit;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                    $step     = 4;
                }
            } else {
                $step = 4;
            }
        }
    }
}

$token = Csrf::token();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>نصب HeleXa Med</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-card" style="max-width:560px;">
        <div class="auth-logo">H</div>
        <h1 class="auth-title">نصب HeleXa Med</h1>
        <p class="auth-sub">مرحله <?= (int) $step ?> از ۴</p>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php if ($notice !== null): ?>
            <div class="alert alert-success"><?= e($notice) ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <h3 class="card-title">بررسی پیش‌نیازها</h3>
            <table class="data" style="min-width:auto;">
                <?php $allOk = true; foreach (requirementList() as $label => $ok): $allOk = $allOk && $ok; ?>
                    <tr>
                        <td><?= e($label) ?></td>
                        <td style="text-align:left;">
                            <span class="stat-chip <?= $ok ? 'chip-green' : 'chip-red' ?>"><?= $ok ? 'OK' : 'ناموفق' ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php if ($allOk): ?>
                <a class="btn btn-primary btn-block" style="margin-top:18px;" href="?step=2">ادامه</a>
            <?php else: ?>
                <div class="alert alert-error" style="margin-top:18px;">
                    ابتدا موارد ناموفق را برطرف کنید. برای دسترسی نوشتن، سطح دسترسی پوشه‌ها را روی ۷۵۵ و مالک را کاربر هاست قرار دهید.
                </div>
            <?php endif; ?>

        <?php elseif ($step === 2): ?>
            <h3 class="card-title">اطلاعات دیتابیس</h3>
            <form method="post" action="?step=2">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="test_db">
                <div class="field"><label class="label">Database Host</label>
                    <input class="input" name="db_host" dir="ltr" value="localhost" required></div>
                <div class="field"><label class="label">Port</label>
                    <input class="input" name="db_port" dir="ltr" value="3306" required></div>
                <div class="field"><label class="label">Database Name</label>
                    <input class="input" name="db_name" dir="ltr" required></div>
                <div class="field"><label class="label">Database Username</label>
                    <input class="input" name="db_user" dir="ltr" required></div>
                <div class="field"><label class="label">Database Password</label>
                    <input class="input" type="password" name="db_password" dir="ltr"></div>
                <button class="btn btn-primary btn-block" type="submit">تست اتصال به دیتابیس</button>
            </form>

        <?php elseif ($step === 3): ?>
            <h3 class="card-title">ساخت جدول‌ها</h3>
            <p style="color:var(--ink-3); font-size:13.5px;">
                این مرحله ساختار کامل دیتابیس و داده‌های اولیه نقش‌ها و دسترسی‌ها را ایجاد می‌کند.
            </p>
            <form method="post" action="?step=3">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="create_tables">
                <button class="btn btn-primary btn-block" type="submit">Create Database Tables</button>
            </form>

        <?php else: ?>
            <h3 class="card-title">ساخت مدیر ارشد</h3>
            <form method="post" action="?step=4" autocomplete="off">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="action" value="create_admin">
                <div class="field"><label class="label">آدرس سایت</label>
                    <input class="input" name="app_url" dir="ltr" placeholder="https://example.com" required></div>
                <div class="field"><label class="label">نام کامل</label>
                    <input class="input" name="full_name" required></div>
                <div class="field"><label class="label">نام کاربری</label>
                    <input class="input" name="username" dir="ltr" required></div>
                <div class="field"><label class="label">رمز عبور (حداقل ۸ کاراکتر، حروف انگلیسی و عدد)</label>
                    <input class="input" type="password" name="password" required></div>
                <div class="field"><label class="label">تکرار رمز عبور</label>
                    <input class="input" type="password" name="password_confirmation" required></div>
                <button class="btn btn-primary btn-block" type="submit">Create Super Admin</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
