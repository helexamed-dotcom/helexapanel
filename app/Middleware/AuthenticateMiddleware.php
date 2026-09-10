<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\Auth;

/**
 * Server-side session revalidation. Nothing past this point runs for a guest.
 */
final class AuthenticateMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        // No live session? A remembered browser gets one restore attempt, which
        // repeats every check the login form performs before it succeeds.
        if (Auth::validate($request) === null && Auth::attemptRemember($request) === null) {
            // The JSON API always answers in JSON. A redirect to the login page
            // would be followed by fetch() and parsed as a successful response,
            // so the client could not tell "signed out" from "server down".
            if ($request->isAjax() || str_starts_with($request->path(), '/api/')) {
                return Response::json(['ok' => false, 'error' => 'UNAUTHENTICATED'], 401);
            }
            $_SESSION['_flash_error'] = 'نشست شما پایان یافته است. دوباره وارد شوید.';
            return Response::redirect('/login');
        }
        return $next($request);
    }
}
