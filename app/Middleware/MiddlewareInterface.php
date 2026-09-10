<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\Request;
use HeleXa\Core\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
