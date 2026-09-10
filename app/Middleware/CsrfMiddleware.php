<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\Csrf;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * Applied globally. Every state-changing verb needs a valid token,
 * supplied either as a form field or the X-CSRF-Token header for fetch().
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method(), self::PROTECTED_METHODS, true)) {
            return $next($request);
        }

        $token = $request->header('X-CSRF-Token') ?? $request->string('_token');

        if (!Csrf::verify($token)) {
            ActivityLogger::log('security.csrf_failed', Auth::id(), null, null,
                ['path' => $request->path()], 'warning', $request);

            if ($request->isAjax()) {
                return Response::json(['ok' => false, 'error' => 'CSRF_TOKEN_INVALID'], 419);
            }
            throw new HttpException(419, 'اعتبار فرم منقضی شده است. صفحه را تازه کنید و دوباره تلاش کنید.');
        }

        return $next($request);
    }
}
