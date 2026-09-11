<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class SessionRepository extends BaseRepository
{
    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO sessions
                (user_id, php_session_id, token_hash, device_hash, ip_address, user_agent,
                 browser, operating_system, device_type, login_at, last_activity, is_active)
             VALUES
                (:user_id, :php_session_id, :token_hash, :device_hash, :ip_address, :user_agent,
                 :browser, :operating_system, :device_type, :login_at, :last_activity, 1)',
            [
                'user_id'          => $data['user_id'],
                'php_session_id'   => $data['php_session_id'],
                'token_hash'       => $data['token_hash'],
                'device_hash'      => $data['device_hash'],
                'ip_address'       => $data['ip_address'],
                'user_agent'       => $data['user_agent'],
                'browser'          => $data['browser'],
                'operating_system' => $data['operating_system'],
                'device_type'      => $data['device_type'],
                'login_at'         => $this->now(),
                'last_activity'    => $this->now(),
            ]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM sessions WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function findActiveByPhpSessionId(string $phpSessionId): ?array
    {
        // No id means no session. Without this, '' would match any row that
        // still carries the empty string and hand one person another's
        // session.
        if ($phpSessionId === '') {
            return null;
        }

        return $this->selectOne(
            'SELECT * FROM sessions WHERE php_session_id = :sid AND is_active = 1 LIMIT 1',
            ['sid' => $phpSessionId]
        );
    }

    public function activeForUser(int $userId): array
    {
        return $this->select(
            'SELECT * FROM sessions WHERE user_id = :user AND is_active = 1 ORDER BY last_activity DESC',
            ['user' => $userId]
        );
    }

    public function historyForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        return $this->select(
            'SELECT * FROM sessions WHERE user_id = :user ORDER BY login_at DESC LIMIT ' . $limit,
            ['user' => $userId]
        );
    }

    public function touch(int $sessionId): void
    {
        $this->execute(
            'UPDATE sessions SET last_activity = :now WHERE id = :id AND is_active = 1',
            ['now' => $this->now(), 'id' => $sessionId]
        );
    }

    public function rotateToken(int $sessionId, string $tokenHash): void
    {
        $this->execute(
            'UPDATE sessions SET token_hash = :hash, last_activity = :now WHERE id = :id AND is_active = 1',
            ['hash' => $tokenHash, 'now' => $this->now(), 'id' => $sessionId]
        );
    }

    public function terminate(int $sessionId, string $reason, ?int $terminatedBy = null): void
    {
        $this->execute(
            'UPDATE sessions
             SET is_active = 0, logout_at = :now, termination_reason = :reason, terminated_by = :by
             WHERE id = :id AND is_active = 1',
            ['now' => $this->now(), 'reason' => $reason, 'by' => $terminatedBy, 'id' => $sessionId]
        );
    }

    public function terminateAllForUser(int $userId, string $reason, ?int $terminatedBy = null, ?int $exceptSessionId = null): int
    {
        $sql = 'UPDATE sessions
                SET is_active = 0, logout_at = :now, termination_reason = :reason, terminated_by = :by
                WHERE user_id = :user AND is_active = 1';
        $params = [
            'now'    => $this->now(),
            'reason' => $reason,
            'by'     => $terminatedBy,
            'user'   => $userId,
        ];
        if ($exceptSessionId !== null) {
            $sql .= ' AND id <> :except';
            $params['except'] = $exceptSessionId;
        }
        return $this->execute($sql, $params);
    }

    /** Closes sessions that stopped sending activity; keeps single-device from locking users out. */
    public function sweepStale(int $idleSeconds, int $absoluteSeconds): int
    {
        return $this->execute(
            "UPDATE sessions
             SET is_active = 0, logout_at = :now,
                 termination_reason = CASE WHEN last_activity < :idle THEN 'idle_timeout' ELSE 'absolute_timeout' END
             WHERE is_active = 1 AND (last_activity < :idle2 OR login_at < :absolute)",
            [
                'now'      => $this->now(),
                'idle'     => date('Y-m-d H:i:s', time() - $idleSeconds),
                'idle2'    => date('Y-m-d H:i:s', time() - $idleSeconds),
                'absolute' => date('Y-m-d H:i:s', time() - $absoluteSeconds),
            ]
        );
    }

    public function countOnline(int $windowSeconds = 300): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(DISTINCT user_id) AS c FROM sessions WHERE is_active = 1 AND last_activity >= :since',
            ['since' => date('Y-m-d H:i:s', time() - $windowSeconds)]
        )['c'] ?? 0);
    }

    public function recentLogins(int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        return $this->select(
            'SELECT s.id, s.login_at, s.ip_address, s.browser, s.operating_system, s.is_active,
                    u.full_name, u.username, r.slug AS role_slug
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             JOIN roles r ON r.id = u.role_id
             ORDER BY s.login_at DESC LIMIT ' . $limit
        );
    }
}
