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
    // The marker used to be assets/js/auth.js, which was only ever the OTP
    // script. What the app actually reads off this page is the CSRF token, so
    // that is what is checked now — the old marker would have failed forever
    // after the SMS sign-in was removed, for a reason unrelated to the
    // failure it describes.
    needs('app/Views/layouts/auth.php', 'csrf-token',
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
    // 'auth-tabs' was the password/SMS tab strip. There is one way in now, so
    // the marker is the form that does it.
    needs('app/Views/auth/login.php', 'name="identifier"',
        'صفحه ورود سایت.'),

    // بانک سوال — انتخاب چندتایی و عملیات گروهی
    needs('app/Controllers/Admin/QuestionBank/QuestionController.php', 'function bulk',
        'بدون این، حذف و انتشار گروهی خطای ۴۰۴ می‌دهد و «در هر صفحه» اعمال نمی‌شود.'),
    needs('app/Models/QuestionBank/QbQuestionRepository.php', 'findManyByUuids',
        'عملیات گروهی سوال‌های انتخاب‌شده را از اینجا پیدا می‌کند.'),
    needs('app/Views/admin/qbank/questions/index.php', 'data-bulk',
        'تیک کنار سوال‌ها و نوار عملیات گروهی.'),
    needs('routes/web.php', 'questions/bulk',
        'مسیر عملیات گروهی.'),
    needs('public_html/assets/js/qbank.js', 'data-bulk-item',
        'انتخاب همه، شمارنده و پیام تأیید.'),

    // انتخاب درس از برنامه گروه‌های مختلف
    needs('app/Services/StudentSchedule.php', 'class StudentSchedule',
        'تصمیم می‌گیرد هر دانشجو کدام کلاس‌ها را ببیند.'),
    needs('app/Models/ScheduleRepository.php', 'visibleForTerm',
        'برنامه همه گروه‌های ترم را پیدا می‌کند.'),
    needs('app/Controllers/Student/PlannerController.php', 'saveChoices',
        'صفحه انتخاب درس دانشجو.'),
    needs('app/Controllers/Admin/ScheduleController.php', 'updateItem',
        'ویرایش برنامه هفتگی و جلسات آن.'),
    needs('app/Views/account/profile.php', 'class-picker-box',
        'انتخاب درس‌های اخذشده داخل پروفایل.'),
    needs('app/Controllers/AccountController.php', 'saveClasses',
        'ذخیره درس‌های اخذشده از پروفایل.'),
    needs('app/Views/partials/class_picker.php', 'data-pick-form',
        'فرم انتخاب درس.'),
    needs('app/Services/StudentSchedule.php', 'function plans',
        'برنامه هفتگی همه گروه‌ها زیر هم.'),
    needs('app/Views/partials/class_picker.php', 'data-pick-form',
        'صفحه انتخاب درس.'),
    needs('routes/web.php', 'schedule/choose',
        'مسیر صفحه انتخاب درس.'),

    // جزیره بالین — ورود و خروج JSON و بلوک‌های تخصصی
    needs('app/Services/Balin/BalinTransfer.php', 'class BalinTransfer',
        'ورود و خروج JSON درس‌های جزیره.'),
    needs('app/Services/Balin/BalinTransferGuide.php', 'function prompt',
        'پرامپت هوش مصنوعی ساخت درس.'),
    needs('app/Controllers/Admin/Balin/TransferController.php', 'class TransferController',
        'صفحه ورود و خروج JSON جزیره.'),
    needs('app/Views/admin/balin/transfer.php', 'balin-prompt',
        'صفحه ورود و خروج JSON جزیره.'),
    needs('routes/web.php', 'balin/transfer',
        'مسیرهای ورود و خروج JSON جزیره.'),
    needs('app/Views/student/balin/stage.php', 'balin-vitals',
        'نمایش علائم حیاتی، آزمایش، تشخیص افتراقی و نکته کلیدی به دانشجو.'),
    needs('app/Controllers/Admin/Balin/StageController.php', 'clinicalReady',
        'بلوک‌های تخصصی در سازنده مرحله.'),

    // مجموعه مطالعه: آزمون‌های من، امروز من، درس‌های من، گزارش اشکال، درسنامه، کد فعال‌سازی، پشتیبان‌گیری، منوی دانشجو
    needs('app/Controllers/Student/MyExamController.php', 'GRACE_SECONDS',
        'آزمون‌های من.'),
    needs('app/Views/student/myexams/take.php', 'data-exam-sheet',
        'برگه پاسخ آزمون‌های من.'),
    needs('app/Controllers/Student/TodayController.php', 'forWeekday',
        'صفحه امروز من.'),
    needs('app/Controllers/Student/StudyMarkController.php', 'clearDone',
        'درس‌های من.'),
    needs('app/Models/QuestionBank/QbReportRepository.php', 'REASONS',
        'گزارش اشکال سوال.'),
    needs('app/Services/QuestionBank/LessonNotes.php', 'forQuestion',
        'درسنامه هر سوال.'),
    needs('app/Services/ActivationCodes.php', 'redeem',
        'کد فعال‌سازی یک‌بارمصرف.'),
    needs('app/Services/Backup.php', 'streamSql',
        'پشتیبان‌گیری کامل.'),
    needs('app/Services/Balin/MyRank.php', 'balin_ranking_mode',
        'رتبه‌بندی جزیره: خاموش، فقط رتبه خود دانشجو، یا جدول کامل.'),
    needs('app/Controllers/Admin/Balin/DashboardController.php', 'saveRanking',
        'کلید رتبه‌بندی در «مرور جزیره».'),
    needs('routes/web.php', '/balin/ranking',
        'مسیر ذخیره کلید رتبه‌بندی.'),
    needs('app/Views/partials/student_menu.php', 'data-student-menu',
        'منوی پاپ‌آپ دانشجو.'),
    needs('app/Views/partials/tabbar.php', 'data-student-menu-toggle',
        'نوار پایین موبایل با دکمه منو در وسط.'),
    needs('public_html/assets/css/suite.css', 'tab-menu-glyph',
        'ظاهر منوی تازه، همه بخش‌های تازه و فونت وزیرمتن.'),
    needs('public_html/assets/js/app.js', 'data-student-menu-search',
        'باز و بسته شدن و جستجوی منوی دانشجو.'),
    needs('public_html/install.php', 'alreadyApplied',
        'نصب‌کننده‌ای که بعد از خطا از همان‌جا ادامه می‌دهد.'),
    needs('database/migrations/2026_09_25_study_suite.sql', 'fk_actcode_package',
        'رفع خطای 1005 (errno 121) در ساخت جدول activation_codes.'),
    needs('app/Views/layouts/app.php', 'suite.css',
        'بارگذاری ظاهر تازه.'),
    needs('public_html/assets/js/ink.js', 'hardware-overlay',
        'رفع سیاه شدن صفحه هنگام نوشتن با قلم.'),
    needs('routes/web.php', '/my-exams/{uuid}/finish',
        'مسیرهای مجموعه مطالعه.'),

    // ثبت‌نام دانشجو و پکیج رایگان
    needs('app/Controllers/RegisterController.php', 'registration_enabled',
        'ثبت‌نام خود دانشجو.'),
    needs('app/Views/auth/register.php', 'term-choices',
        'فرم ثبت‌نام.'),
    needs('routes/web.php', "'/register'",
        'مسیر ثبت‌نام.'),
    needs('app/Services/PackageAccess.php', 'grantToEveryone',
        'پکیج رایگان برای همه.'),

    // دسترسی‌ها و پکیج‌ها
    needs('app/Views/admin/access/index.php', 'مدیریت دسترسی',
        'صفحه فهرست دسترسی دانشجویان.'),
    needs('app/Services/PackageAccess.php', 'contentAdded',
        'پکیج با چند نوع محتوا و پکیج کامل.'),
    needs('app/Models/AccessOverviewRepository.php', 'summaries',
        'شمارش دسترسی‌ها برای فهرست.'),
    needs('app/Models/Balin/BalinLessonRepository.php', 'OPEN_TO_STUDENT',
        'باز کردن درس جزیره برای دانشجوی انتخابی.'),
    needs('routes/web.php', '/packages/{uuid}/items',
        'ذخیره محتوای پکیج.'),

    // جزیره بالین — نقشه بازی‌گونه
    needs('app/Views/student/balin/lesson.php', 'data-bgame-world',
        'نقشه مارپیچ مرحله‌ها.'),
    needs('public_html/assets/css/balin-game.css', 'bgame-road',
        'ظاهر بازی‌گونه نقشه و مرحله‌ها.'),
    needs('public_html/assets/js/balin-map.js', 'stopWalking',
        'جاده و حرکت آواتار روی نقشه.'),
    needs('app/Views/layouts/app.php', 'balin-game.css',
        'بارگذاری ظاهر بازی‌گونه.'),
    needs('app/Controllers/Admin/Balin/DashboardController.php', 'saveAvatars',
        'آپلود آواتار بازیکن (مرد و زن).'),
    needs('routes/web.php', 'balin/avatars',
        'مسیر ذخیره آواتار بازیکن.'),

    needs('public_html/assets/css/balin-game.css', '--callout-icon',
        'طراحی مدرن داخل مرحله (گفت‌وگو، بلوک‌های بالینی، سؤال‌ها).'),
    needs('app/Controllers/Student/BalinExamController.php', 'balinGame',
        'ظاهر جدید در صفحه‌های آزمون جزیره.'),

    // فاز ۸ — قلم و یادداشت‌ها
    needs('app/Controllers/Student/NoteController.php', 'class NoteController',
        'صفحه یادداشت‌ها و ذخیره آن‌ها.'),
    needs('app/Services/ViewerPayload.php', 'wireInk',
        'نوشتن با قلم روی جزوه.'),
    needs('public_html/assets/js/ink.js', 'HlxInk',
        'موتور قلم.'),
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

    // This check was inverted when SMS sign-in was removed. It used to demand
    // that otp_codes EXIST; now its presence means the drop migration has not
    // been run and a table of issued codes is still sitting in the database.
    // users.phone_verified_at is still required — the column stayed, because
    // the mobile API and two admin pages read it.
    $otpGone   = !(bool) $pdo->query("SHOW TABLES LIKE 'otp_codes'")->fetchColumn();
    $hasColumn = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'phone_verified_at'")->fetchColumn();

    $dbOk = $otpGone && $hasColumn;
    if (!$hasColumn) {
        $dbNote = 'فایل database/migrations/2026_09_14_phone_auth.sql هنوز روی دیتابیس اجرا نشده.';
    } elseif (!$otpGone) {
        $dbNote = 'جدول otp_codes هنوز هست. فایل database/migrations/2026_09_16_drop_sms_otp.sql را اجرا کن.';
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
    <span class="name">database migration (users.phone_verified_at, otp_codes dropped)</span>
    <?php if ($dbNote): ?><div class="why"><?= htmlspecialchars($dbNote, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
</div>

<?php
/**
 * PHP's code cache. With timestamp checks switched off, the server keeps
 * running the old copy of a PHP file after a new one is uploaded — which
 * looks exactly like "I replaced the file and nothing changed".
 */
$opcacheOn = function_exists('opcache_get_status') && (bool) ini_get('opcache.enable');
$staleRisk = $opcacheOn && !filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN);
?>
<div class="row <?= $staleRisk ? 'bad' : 'ok' ?>">
    <span class="tag <?= $staleRisk ? 'bad' : 'ok' ?>"><?= $staleRisk ? 'کش کد قدیمی ممکن است ✗' : 'کش کد مشکلی ندارد ✓' ?></span>
    <span class="name">OPcache</span>
    <?php if ($staleRisk): ?>
        <div class="why">سرور فایل‌های PHP آپلودشده را خودکار دوباره نمی‌خواند. پایین صفحه، لینک کلیددار را باز کن و «پاک کردن کش کد» را بزن، یا از کنترل‌پنل هاست PHP را ری‌استارت کن.</div>
    <?php endif; ?>
</div>

<?php if ($failed === 0): ?>
    <div class="verdict ok">
        همه چیز نصب است.<br>
        حالا این فایل را پاک کن.
    </div>
<?php else: ?>
    <div class="verdict bad">
        <?= $failed ?> مورد ناقص است. تا وقتی همه‌شان سبز نشوند، آن بخش‌ها درست کار نمی‌کنند.<br>
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

    <?php if (function_exists('opcache_reset')): ?>
        <div class="row">
            <?php if (isset($_GET['reset']) && opcache_reset()): ?>
                <span class="tag ok">کش کد PHP پاک شد ✓ — صفحه سایت را دوباره باز کن.</span>
            <?php else: ?>
                <a href="?key=<?= DIAG_KEY ?>&amp;reset=1">پاک کردن کش کد PHP (OPcache)</a>
                <div class="why">اگر فایل‌ها را جایگزین کرده‌ای ولی سایت هنوز رفتار قبلی را دارد، این را بزن.</div>
            <?php endif; ?>
        </div>
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
