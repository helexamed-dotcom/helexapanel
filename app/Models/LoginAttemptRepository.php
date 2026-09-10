<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class LoginAttemptRepository extends BaseRepository
{
    public function record(string $identifier, ?int $userId, string $ip, string $userAgent, bool $successful, ?string $reason): void
    {
        $this->insert(
            'INSERT INTO login_attempts (identifier, user_id, ip_address, user_agent, successful, failure_reason, attempted_at)
             VALUES (:identifier, :user_id, :ip, :ua, :ok, :reason, :now)',
            [
                'identifier' => mb_substr($identifier, 0, 191),
                'user_id'    => $userId,
                'ip'         => $ip,
                'ua'         => mb_substr($userAgent, 0, 500),
                'ok'         => $successful ? 1 : 0,
                'reason'     => $reason,
                'now'        => $this->now(),
            ]
        );
    }

    public function failuresForIp(string $ip, int $windowSeconds): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM login_attempts
             WHERE ip_address = :ip AND successful = 0 AND attempted_at >= :since',
            ['ip' => $ip, 'since' => date('Y-m-d H:i:s', time() - $windowSeconds)]
        )['c'] ?? 0);
    }

    public function failuresForIdentifier(string $identifier, int $windowSeconds): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM login_attempts
             WHERE identifier = :id AND successful = 0 AND attempted_at >= :since',
            ['id' => $identifier, 'since' => date('Y-m-d H:i:s', time() - $windowSeconds)]
        )['c'] ?? 0);
    }

    public function purgeOlderThan(int $days = 30): int
    {
        return $this->execute(
            'DELETE FROM login_attempts WHERE attempted_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - ($days * 86400))]
        );
    }
}
