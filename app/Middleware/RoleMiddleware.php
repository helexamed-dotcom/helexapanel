<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\Auth;

/**
 * Area guard: keeps students out of the admin tree and vice versa.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    /** @var array<int,string> */
    private array $allowed;

    public function __construct(string ...$roles)
    {
        $this->allowed = $roles;
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = Auth::user();
        if ($user === null || !in_array((string) $user['role_slug'], $this->allowed, true)) {
            throw HttpException::forbidden();
        }
        return $next($request);
    }
}
