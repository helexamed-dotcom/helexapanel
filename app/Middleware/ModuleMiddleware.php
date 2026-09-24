<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\Auth;
use HeleXa\Services\Modules;

/**
 * A section switched off for this student — site-wide, or by their student
 * type — is closed at the door, not only hidden from the menu. The student
 * lands on their home page with a word about why.
 *
 * Admins are never stopped: they preview everything.
 */
final class ModuleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        if (!Auth::check() || !Auth::isStudent()) {
            return $next($request);
        }
        $module = Modules::forPath($request->path());
        if ($module !== null && !Modules::enabled($module)) {
            if ($request->isAjax()) {
                return Response::json(['ok' => false, 'message' => 'این بخش برای شما فعال نیست.'], 403);
            }
            $_SESSION['_flash_error'] = 'این بخش برای حساب شما فعال نیست.';
            return Response::redirect('/student');
        }
        return $next($request);
    }
}
