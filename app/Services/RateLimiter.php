<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Models\LoginAttemptRepository;

/**
 * Two-dimensional login throttle: per source IP and per typed identifier.
 * The identifier dimension stops credential stuffing against one account from
 * a botnet; the IP dimension stops one host spraying many accounts.
 */
final class RateLimiter
{
    private LoginAttemptRepository $attempts;

    public function __construct()
    {
        $this->attempts = new LoginAttemptRepository();
    }

    public function isBlocked(string $ip, string $identifier): bool
    {
        $window   = Settings::int('login_lockout_seconds', 900);
        $maxUser  = Settings::int('login_max_attempts', 5);
        $maxIp    = $maxUser * 4; // one household / lab can share an IP

        if ($this->attempts->failuresForIdentifier($identifier, $window) >= $maxUser) {
            return true;
        }
        return $this->attempts->failuresForIp($ip, $window) >= $maxIp;
    }

    public function record(string $identifier, ?int $userId, string $ip, string $userAgent, bool $successful, ?string $reason = null): void
    {
        $this->attempts->record($identifier, $userId, $ip, $userAgent, $successful, $reason);
    }

    public function retryAfterSeconds(): int
    {
        return Settings::int('login_lockout_seconds', 900);
    }
}
