<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\SecurityHeaders;
use HeleXa\Core\View;
use HeleXa\Services\Auth;
use HeleXa\Services\Balin\Access;

/**
 * Guards every route that serves real Balin content.
 *
 * The two conditions — the island is published, and this student has been
 * given access — are checked here rather than in each controller, so a route
 * added later cannot forget them. While the island is unpublished this
 * blocks everyone, which is the intended state today.
 *
 * A blocked student is not sent to an error page. They get the Coming Soon
 * page, which is the honest answer to "what is this menu item", and it
 * carries the same 200 a normal page does because nothing has gone wrong.
 */
final class BalinAccessMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $gate = Access::forStudent(Auth::id());

        if ($gate['allowed']) {
            return $next($request);
        }

        // An admin previewing unpublished content is not a student and does
        // not go through the publication gate.
        if (Access::canPreview()) {
            return $next($request);
        }

        $html = View::page('layouts.app', 'student.balin.gate', [
            'title'        => 'جزیره بالین',
            'gate'         => $gate,
            'appName'      => (string) \HeleXa\Core\Config::get('app.app.name', 'HeleXa Med'),
            'currentUser'  => Auth::user(),
            'permissions'  => Auth::check() ? Auth::permissions() : [],
            'flashSuccess' => null,
            'flashError'   => null,
            'tempPassword' => null,
            'unreadCounts' => ['notifications' => 0, 'messages' => 0, 'support_open' => 0],
            'cspNonce'     => SecurityHeaders::nonce(),
            'currentPath'  => $request->path(),
        ]);

        return Response::html($html);
    }
}
