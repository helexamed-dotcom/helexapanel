<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\Auth;

final class GuestMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // Only GET is auto-restored. Restoring during the login POST would make
        // a failed sign-in look like a success.
        $restored = Auth::validate($request) !== null
            || ($request->method() === 'GET' && Auth::attemptRemember($request) !== null);

        if ($restored) {
            return Response::redirect(Auth::isAdmin() ? '/admin' : '/student');
        }
        return $next($request);
    }
}
