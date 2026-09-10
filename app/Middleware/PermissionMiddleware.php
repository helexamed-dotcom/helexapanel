<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * Fine-grained admin authorization. Permissions are read from the database
 * on every request, so revoking one takes effect immediately.
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    /** @var array<int,string> */
    private array $required;

    public function __construct(string ...$permissions)
    {
        $this->required = $permissions;
    }

    public function handle(Request $request, callable $next): Response
    {
        foreach ($this->required as $permission) {
            if (!Auth::can($permission)) {
                ActivityLogger::log('authz.denied', Auth::id(), 'permission', null,
                    ['permission' => $permission, 'path' => $request->path()], 'warning', $request);
                throw HttpException::forbidden('شما دسترسی لازم برای این بخش را ندارید.');
            }
        }
        return $next($request);
    }
}
