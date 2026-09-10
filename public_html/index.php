<?php
declare(strict_types=1);

/**
 * Single front controller. Every request to HeleXa Med enters here,
 * which is what makes the security middleware impossible to bypass.
 */

use HeleXa\Core\Config;
use HeleXa\Core\HttpException;
use HeleXa\Core\Logger;
use HeleXa\Core\Response;
use HeleXa\Core\Router;
use HeleXa\Core\SecurityHeaders;
use HeleXa\Core\View;

/** @var \HeleXa\Core\Request $request */
$request = require dirname(__DIR__) . '/bootstrap/bootstrap.php';

SecurityHeaders::apply($request);

// Not installed yet: send the operator to the installer and stop.
if (!HELEXA_INSTALLED) {
    header('Location: /install.php');
    exit;
}

// Production runs on HTTPS only.
if ((bool) Config::get('app.security.force_https', true)
    && Config::get('app.app.environment') === 'production'
    && !$request->isSecure()) {
    header('Location: https://' . $request->host() . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

// Housekeeping runs at most once an hour, on whichever request happens to
// arrive first. Shared hosting rarely offers cron, so this is the fallback.
\HeleXa\Services\Maintenance::maybeRun();

$router = new Router();
require BASE_PATH . '/routes/web.php';

try {
    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $response = $request->isAjax()
        ? Response::json(['ok' => false, 'error' => $e->getMessage()], $e->statusCode())
        : Response::html(View::page('layouts.error', 'errors.error', [
            'title'   => 'خطای ' . $e->statusCode(),
            'code'    => $e->statusCode(),
            'message' => $e->getMessage(),
        ]), $e->statusCode());
} catch (Throwable $e) {
    Logger::critical('Unhandled exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'path'    => $request->path(),
    ]);

    $debug = (bool) Config::get('app.app.debug', false);
    $response = $request->isAjax()
        ? Response::json(['ok' => false, 'error' => 'SERVER_ERROR'], 500)
        : Response::html(View::page('layouts.error', 'errors.error', [
            'title'   => 'خطای سرور',
            'code'    => 500,
            // Stack traces, file paths and SQL never reach the browser in production.
            'message' => $debug ? $e->getMessage() : 'خطایی رخ داده است. لطفاً بعداً دوباره تلاش کنید.',
        ]), 500);
}

$response->send();
