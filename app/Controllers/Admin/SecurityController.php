<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Config;
use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Router;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Maintenance;
use HeleXa\Services\Settings;

/**
 * Security review.
 *
 * Two things live here. First, a self-check of the deployment: the mistakes
 * that actually happen on shared hosting are leftover installers, debug left
 * on, private folders uploaded into public_html and world-readable config.
 * Second, an audit of the route table, so "every page is protected" is a
 * verifiable statement rather than a claim.
 */
final class SecurityController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.security', [
            'title'   => 'بازبینی امنیت',
            'limits'  => $this->uploadLimits(),
            'checks'  => $this->environmentChecks($request),
            'routes'  => $this->routeAudit(),
            'hygiene' => $this->dataHygiene(),
            'cleanup' => $this->pullFlashArray(),
        ]);
    }

    public function cleanup(Request $request, array $params = []): Response
    {
        $result = Maintenance::run();

        ActivityLogger::log('security.cleanup_run', Auth::id(), null, null, $result, 'notice', $request);
        $_SESSION['_cleanup_result'] = $result;
        $this->flash('success', 'پاک‌سازی انجام شد.');

        return $this->redirect('/admin/security');
    }

    /* ------------------------------------------------------------ checks */

    /** @return array<int, array{label:string, ok:bool, detail:string, severity:string}> */
    private function environmentChecks(Request $request): array
    {
        $checks = [];

        $checks[] = $this->check(
            'اتصال امن (HTTPS)',
            $request->isSecure(),
            $request->isSecure() ? 'درخواست‌ها روی HTTPS هستند.' : 'سایت روی HTTP باز شده است. کوکی نشست بدون Secure ارسال می‌شود.',
            'critical'
        );

        $debug = (bool) Config::get('app.app.debug', false);
        $checks[] = $this->check(
            'نمایش خطا خاموش است',
            !$debug && Config::get('app.app.environment') === 'production',
            $debug ? 'debug روشن است و متن خطاها به کاربر نمایش داده می‌شود.' : 'خطاها فقط در لاگ ثبت می‌شوند.',
            'critical'
        );

        $installerPresent = is_file(PUBLIC_PATH . '/install.php');
        $checks[] = $this->check(
            'نصب‌کننده حذف شده است',
            !$installerPresent,
            $installerPresent ? 'فایل install.php هنوز روی هاست است. آن را حذف کنید.' : 'install.php روی سرور نیست.',
            'critical'
        );

        $checks[] = $this->check(
            'قفل نصب وجود دارد',
            is_file(INSTALL_LOCK),
            is_file(INSTALL_LOCK) ? 'storage/installed.lock موجود است.' : 'قفل نصب پیدا نشد؛ نصب مجدد ممکن می‌شود.',
            'critical'
        );

        $perms = is_file(CONFIG_FILE) ? substr(sprintf('%o', fileperms(CONFIG_FILE)), -3) : '---';
        $checks[] = $this->check(
            'دسترسی فایل config محدود است',
            in_array($perms, ['600', '640', '644'], true),
            'سطح دسترسی فعلی: ' . $perms . ' (مقدار پیشنهادی ۶۰۰)',
            'warning'
        );

        // The single most damaging shared-hosting mistake: uploading the whole
        // project inside public_html, which puts private storage on the web.
        $leaked = [];
        foreach (['app', 'config', 'storage', 'database', 'bootstrap', 'routes'] as $folder) {
            if (is_dir(PUBLIC_PATH . '/' . $folder)) {
                $leaked[] = $folder;
            }
        }
        $checks[] = $this->check(
            'پوشه‌های خصوصی بیرون از public_html هستند',
            $leaked === [],
            $leaked === []
                ? 'هیچ پوشه خصوصی داخل public_html پیدا نشد.'
                : 'این پوشه‌ها داخل public_html قرار دارند: ' . implode('، ', $leaked),
            'critical'
        );

        $checks[] = $this->check(
            'نسخه PHP پشتیبانی‌شده',
            version_compare(PHP_VERSION, '8.2.0', '>='),
            'نسخه فعلی: ' . PHP_VERSION,
            'warning'
        );

        $checks[] = $this->check(
            'هش رمز عبور Argon2id',
            defined('PASSWORD_ARGON2ID'),
            defined('PASSWORD_ARGON2ID') ? 'در دسترس است.' : 'Argon2id در دسترس نیست؛ سیستم به bcrypt برمی‌گردد.',
            'warning'
        );

        $trustProxy = (bool) Config::get('app.security.trust_proxy', false);
        $checks[] = $this->check(
            'اعتماد به هدر پروکسی',
            !$trustProxy,
            $trustProxy
                ? 'trust_proxy روشن است. اگر پشت CDN نیستید خاموشش کنید، وگرنه IP قابل جعل است.'
                : 'IP فقط از REMOTE_ADDR خوانده می‌شود.',
            'warning'
        );

        $checks[] = $this->check(
            'سیاست تک‌دستگاه فعال است',
            Settings::bool('single_device_enabled', true),
            Settings::bool('single_device_enabled', true) ? 'هر دانشجو یک نشست فعال دارد.' : 'اشتراک‌گذاری حساب محدود نشده است.',
            'warning'
        );

        $idle = Settings::int('session_idle_timeout', 1800);
        $checks[] = $this->check(
            'زمان بی‌کاری نشست منطقی است',
            $idle >= 300 && $idle <= 7200,
            'مقدار فعلی: ' . $idle . ' ثانیه',
            'info'
        );

        $checks[] = $this->check(
            'کلید برنامه تنظیم شده است',
            strlen((string) Config::get('app.security.app_key', '')) >= 32,
            'اثر انگشت دستگاه و هش توکن‌ها به این کلید وابسته‌اند.',
            'critical'
        );

        return $checks;
    }

    /**
     * The limits that decide whether a large lesson can be uploaded at all.
     *
     * These are the *effective* values PHP is running with, not what a config
     * file says, which is the whole point: on shared hosting a .user.ini is
     * often overridden and the only way to know is to ask PHP directly.
     *
     * @return array<int, array{label:string, value:string, ok:bool, note:string}>
     */
    private function uploadLimits(): array
    {
        $needMb   = Settings::int('content_max_upload_mb', 25);
        $needBytes = $needMb * 1024 * 1024;

        $post   = \HeleXa\Core\Request::bytesFromIni('post_max_size');
        $upload = \HeleXa\Core\Request::bytesFromIni('upload_max_filesize');
        $memory = \HeleXa\Core\Request::bytesFromIni('memory_limit');
        $vars   = (int) ini_get('max_input_vars');
        $exec   = (int) ini_get('max_execution_time');
        $input  = (int) ini_get('max_input_time');

        $mb = static fn (int $bytes): string => $bytes === 0 ? 'نامحدود' : (string) round($bytes / 1048576, 1) . ' MB';

        return [
            [
                'label' => 'upload_max_filesize',
                'value' => $mb($upload),
                'ok'    => $upload === 0 || $upload >= $needBytes,
                'note'  => 'باید دست‌کم به اندازه سقف محتوای تنظیم‌شده (' . $needMb . ' مگابایت) باشد.',
            ],
            [
                'label' => 'post_max_size',
                'value' => $mb($post),
                // Multipart overhead plus the other form fields: the body is
                // always a little larger than the file itself.
                'ok'    => $post === 0 || ($post > $upload && $post >= $needBytes + 2097152),
                'note'  => 'باید از upload_max_filesize بزرگ‌تر باشد، وگرنه آپلود قبل از رسیدن به PHP دور ریخته می‌شود.',
            ],
            [
                'label' => 'memory_limit',
                'value' => $mb($memory),
                'ok'    => $memory === 0 || $memory >= $needBytes * 2,
                'note'  => 'برای خواندن، اسکن و هش کردن فایل آپلودشده لازم است.',
            ],
            [
                'label' => 'max_input_vars',
                'value' => (string) $vars,
                'ok'    => $vars === 0 || $vars >= 3000,
                'note'  => 'فرم‌های مدیریت فیلد زیاد دارند؛ مقدار پیش‌فرض ۱۰۰۰ کم است.',
            ],
            [
                'label' => 'max_execution_time',
                'value' => $exec === 0 ? 'نامحدود' : $exec . ' ثانیه',
                'ok'    => $exec === 0 || $exec >= 120,
                'note'  => 'آپلود چندمگابایتی روی اینترنت کند زمان می‌برد.',
            ],
            [
                'label' => 'max_input_time',
                'value' => $input < 0 ? 'برابر max_execution_time' : $input . ' ثانیه',
                'ok'    => $input < 0 || $input >= 120,
                'note'  => 'زمان دریافت بدنه درخواست، جدا از زمان اجرای اسکریپت.',
            ],
        ];
    }

    /** @return array{total:int, unguarded:array<int,array<string,mixed>>, rows:array<int,array<string,mixed>>} */
    private function routeAudit(): array
    {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';

        // Routes with their own independent verification instead of the
        // session/CSRF pair — a third-party webhook cannot present either,
        // so it is not a gap, it is a different (and here, equally strict)
        // mechanism. Checked by prefix since the path itself carries a
        // per-installation secret segment.
        $selfSecuredPrefixes = ['/telegram/webhook/'];

        $public    = ['/login', '/logout', '/'];
        $rows      = [];
        $unguarded = [];

        foreach ($router->table() as $route) {
            $names = array_map(
                static fn (string $m): string => substr(strrchr(explode(':', $m)[0], '\\') ?: $m, 1),
                $route['middleware']
            );

            $isPublic = in_array($route['path'], $public, true);
            $isSelfSecured = false;
            foreach ($selfSecuredPrefixes as $prefix) {
                if (str_starts_with($route['path'], $prefix)) {
                    $isSelfSecured = true;
                    break;
                }
            }

            $authed      = in_array('AuthenticateMiddleware', $names, true) || in_array('GuestMiddleware', $names, true);
            $csrfCovered = in_array('CsrfMiddleware', $names, true);

            $row = [
                'method'     => $route['method'],
                'path'       => $route['path'],
                'middleware' => $names,
                'ok'         => $isPublic || $isSelfSecured || ($authed && $csrfCovered),
                'public'     => $isPublic,
                'selfSecured'=> $isSelfSecured,
            ];

            $rows[] = $row;
            if (!$row['ok']) {
                $unguarded[] = $row;
            }
        }

        return ['total' => count($rows), 'unguarded' => $unguarded, 'rows' => $rows];
    }

    private function dataHygiene(): array
    {
        $count = static function (string $sql, array $params = []): int {
            try {
                return (int) (Database::selectOne($sql, $params)['c'] ?? 0);
            } catch (\Throwable) {
                return 0;
            }
        };

        return [
            'نشست‌های فعال'            => $count('SELECT COUNT(*) AS c FROM sessions WHERE is_active = 1'),
            'توکن‌های منقضی نمایشگر'    => $count('SELECT COUNT(*) AS c FROM viewer_tokens WHERE expires_at < :now', ['now' => date('Y-m-d H:i:s')]),
            'تلاش‌های ورود ثبت‌شده'     => $count('SELECT COUNT(*) AS c FROM login_attempts'),
            'رکوردهای گزارش فعالیت'    => $count('SELECT COUNT(*) AS c FROM activity_logs'),
            'رویدادهای بحرانی'         => $count("SELECT COUNT(*) AS c FROM activity_logs WHERE severity = 'critical'"),
            'ردیف‌های محدودیت نرخ'      => $count('SELECT COUNT(*) AS c FROM rate_limits'),
            'نشست‌های مطالعه باز'       => $count('SELECT COUNT(*) AS c FROM study_sessions WHERE is_active = 1'),
        ];
    }

    private function check(string $label, bool $ok, string $detail, string $severity): array
    {
        return ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'severity' => $severity];
    }

    private function pullFlashArray(): ?array
    {
        if (!isset($_SESSION['_cleanup_result'])) {
            return null;
        }
        $value = $_SESSION['_cleanup_result'];
        unset($_SESSION['_cleanup_result']);
        return is_array($value) ? $value : null;
    }
}
