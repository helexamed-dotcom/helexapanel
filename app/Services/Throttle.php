<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Request;
use HeleXa\Core\Str;
use HeleXa\Models\RateLimitRepository;

/**
 * Fixed-window request throttle for endpoints other than login.
 *
 * Login already has its own two-dimensional limiter built on login_attempts.
 * This one covers everything else that is cheap to call and expensive to serve:
 * the content stream, study heartbeats, uploads and outbound messages.
 *
 * The identifier is stored as an HMAC, so a database dump does not reveal which
 * IP addresses used the system.
 */
final class Throttle
{
    /** @return array{allowed:bool, hits:int, limit:int, retryAfter:int} */
    public static function check(Request $request, string $bucket, int $limit, int $windowSeconds): array
    {
        $windowSeconds = max(1, $windowSeconds);
        $limit         = max(1, $limit);

        // Authenticated callers are limited per account, guests per address.
        $subject = Auth::check() ? 'u:' . Auth::id() : 'ip:' . $request->ip();

        $now         = time();
        $windowStart = date('Y-m-d H:i:s', $now - ($now % $windowSeconds));

        try {
            $hits = (new RateLimitRepository())->hit(
                $bucket,
                Str::hmac($subject),
                $windowStart,
                $windowSeconds
            );
        } catch (\Throwable $e) {
            // A throttle that cannot record must not become a denial of service
            // against legitimate users; log and let the request through.
            \HeleXa\Core\Logger::error('Throttle unavailable', ['bucket' => $bucket, 'error' => $e->getMessage()]);
            return ['allowed' => true, 'hits' => 0, 'limit' => $limit, 'retryAfter' => 0];
        }

        $retryAfter = $windowSeconds - ($now % $windowSeconds);

        return [
            'allowed'    => $hits <= $limit,
            'hits'       => $hits,
            'limit'      => $limit,
            'retryAfter' => $retryAfter,
        ];
    }
}
