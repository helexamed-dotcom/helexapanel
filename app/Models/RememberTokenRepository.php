<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class RememberTokenRepository extends BaseRepository
{
    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO remember_tokens
                (user_id, selector, validator_hash, device_hash, ip_address, user_agent, created_at, expires_at)
             VALUES (:user, :selector, :validator, :device, :ip, :agent, :now, :expires)',
            [
                'user'      => $data['user_id'],
                'selector'  => $data['selector'],
                'validator' => $data['validator_hash'],
                'device'    => $data['device_hash'],
                'ip'        => $data['ip_address'],
                'agent'     => $data['user_agent'],
                'now'       => $this->now(),
                'expires'   => $data['expires_at'],
            ]
        );
    }

    /** Live tokens only: revoked or expired rows are treated as absent. */
    public function findUsable(string $selector): ?array
    {
        return $this->selectOne(
            'SELECT * FROM remember_tokens
             WHERE selector = :selector AND revoked_at IS NULL AND expires_at > :now
             LIMIT 1',
            ['selector' => $selector, 'now' => $this->now()]
        );
    }

    /** Any row with this selector, including revoked ones — used for theft detection. */
    public function findAny(string $selector): ?array
    {
        return $this->selectOne(
            'SELECT * FROM remember_tokens WHERE selector = :selector LIMIT 1',
            ['selector' => $selector]
        );
    }

    public function rotate(int $id, string $validatorHash, string $expiresAt): void
    {
        $this->execute(
            'UPDATE remember_tokens
             SET validator_hash = :validator, last_used_at = :now, expires_at = :expires
             WHERE id = :id AND revoked_at IS NULL',
            ['validator' => $validatorHash, 'now' => $this->now(), 'expires' => $expiresAt, 'id' => $id]
        );
    }

    public function revoke(int $id, string $reason): void
    {
        $this->execute(
            'UPDATE remember_tokens SET revoked_at = :now, revoke_reason = :reason
             WHERE id = :id AND revoked_at IS NULL',
            ['now' => $this->now(), 'reason' => $reason, 'id' => $id]
        );
    }

    public function revokeAllForUser(int $userId, string $reason): int
    {
        return $this->execute(
            'UPDATE remember_tokens SET revoked_at = :now, revoke_reason = :reason
             WHERE user_id = :user AND revoked_at IS NULL',
            ['now' => $this->now(), 'reason' => $reason, 'user' => $userId]
        );
    }

    public function activeForUser(int $userId): array
    {
        return $this->select(
            'SELECT * FROM remember_tokens
             WHERE user_id = :user AND revoked_at IS NULL AND expires_at > :now
             ORDER BY last_used_at DESC, id DESC',
            ['user' => $userId, 'now' => $this->now()]
        );
    }

    public function purgeExpired(int $graceDays = 7): int
    {
        return $this->execute(
            'DELETE FROM remember_tokens WHERE expires_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - ($graceDays * 86400))]
        );
    }
}
