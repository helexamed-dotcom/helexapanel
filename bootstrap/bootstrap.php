<?php
declare(strict_types=1);

/**
 * Application bootstrap. Required by public_html/index.php and install.php.
 * Everything security-relevant is configured here, once, for every request.
 */

use HeleXa\Core\Autoloader;
use HeleXa\Core\Config;
use HeleXa\Core\Logger;
use HeleXa\Core\View;

define('HELEXA_START', microtime(true));
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PRIVATE_PATH', STORAGE_PATH . '/private');
define('DATABASE_PATH', BASE_PATH . '/database');
define('PUBLIC_PATH', BASE_PATH . '/public_html');
define('INSTALL_LOCK', STORAGE_PATH . '/installed.lock');
define('CONFIG_FILE', CONFIG_PATH . '/config.php');

require APP_PATH . '/Core/Autoloader.php';
(new Autoloader(APP_PATH))->register();

require APP_PATH . '/Helpers/functions.php';

Logger::setPath(STORAGE_PATH . '/logs');
View::setBasePath(APP_PATH . '/Views');

define('HELEXA_INSTALLED', is_file(CONFIG_FILE) && is_file(INSTALL_LOCK));

if (HELEXA_INSTALLED) {
    Config::loadFile('app', CONFIG_FILE);
    Config::loadFile('security', CONFIG_PATH . '/security.php');
} else {
    // Minimal defaults so the installer can boot without a config file.
    Config::hydrate('app', [
        'app'      => ['name' => 'HeleXa Med', 'timezone' => 'Asia/Tehran', 'environment' => 'production', 'debug' => false],
        'database' => [],
        'security' => ['app_key' => '', 'session_name' => 'HLX_SID', 'cookie_samesite' => 'Lax'],
    ]);
    Config::loadFile('security', CONFIG_PATH . '/security.php');
}

date_default_timezone_set((string) Config::get('app.app.timezone', 'Asia/Tehran'));
// Guarded: if mbstring is absent the installer must still render its
// requirements page instead of dying with a fatal error.
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

$isProduction = Config::get('app.app.environment', 'production') === 'production';
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('display_startup_errors', $isProduction ? '0' : '1');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
error_reporting(E_ALL);

/* ------------------------------------------------------------------ session */

$request  = \HeleXa\Core\Request::capture();
$isSecure = $request->isSecure();

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionPath = STORAGE_PATH . '/sessions';
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        // Keeping session files out of the shared /tmp stops other accounts
        // on the same host from reading them.
        session_save_path($sessionPath);
    }

    ini_set('session.use_strict_mode', '1');   // refuse client-supplied session ids
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '7200');

    // Session id length and alphabet were tunable up to PHP 8.3. From 8.4 the
    // engine fixes both at a strong default and deprecates the settings, so
    // touching them there only writes a notice to the log on every request.
    if (PHP_VERSION_ID < 80400) {
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '5');
    }

    session_name((string) Config::get('app.security.session_name', 'HLX_SID'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => (string) Config::get('app.security.cookie_samesite', 'Lax'),
    ]);
    session_start();
}

return $request;
