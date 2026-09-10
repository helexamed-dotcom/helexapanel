<?php
declare(strict_types=1);

namespace HeleXa\Middleware;

use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Throttle;

/**
 * Declared per route as "ThrottleMiddleware:bucket,limit,windowSeconds".
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    private string $bucket;
    private int $limit;
    private int $window;

    public function __construct(string $bucket = 'default', string $limit = '60', string $window = '60')
    {
        $this->bucket = preg_replace('/[^a-z0-9_]/i', '', $bucket) ?: 'default';
        $this->limit  = max(1, (int) $limit);
        $this->window = max(1, (int) $window);
    }

    public function handle(Request $request, callable $next): Response
    {
        $result = Throttle::check($request, $this->bucket, $this->limit, $this->window);

        if (!$result['allowed']) {
            ActivityLogger::log('security.rate_limited', Auth::id(), null, null,
                ['bucket' => $this->bucket, 'hits' => $result['hits'], 'path' => $request->path()], 'warning', $request);

            if ($request->isAjax()) {
                return Response::json(
                    ['ok' => false, 'error' => 'RATE_LIMITED', 'retry_after' => $result['retryAfter']],
                    429
                )->withHeader('Retry-After', (string) $result['retryAfter']);
            }

            throw HttpException::tooManyRequests();
        }

        return $next($request);
    }
}
