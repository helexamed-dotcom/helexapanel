<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\Auth;

/**
 * Users created by an admin receive a temporary password and must replace it
 * before reaching any other page.
 */
final class ForcePasswordChangeMiddleware implements MiddlewareInterface
{
    private const ALLOWED = ['/account/password', '/logout'];

    public function handle(Request $request, callable $next): Response
    {
        $user = Auth::user();
        if ($user !== null && (int) $user['must_change_password'] === 1
            && !in_array($request->path(), self::ALLOWED, true)) {
            return Response::redirect('/account/password');
        }
        return $next($request);
    }
}
