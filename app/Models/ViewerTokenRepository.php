<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class ViewerTokenRepository extends BaseRepository
{
    public function store(array $data): int
    {
        return $this->insert(
            'INSERT INTO viewer_tokens
                (token_hash, user_id, session_id, content_id, purpose, ua_hash, ip_prefix_hash,
                 issued_at, expires_at, max_uses, used_count)
             VALUES (:hash, :user, :session, :content, :purpose, :ua, :ip, :issued, :expires, :max_uses, 0)',
            [
                'hash'     => $data['token_hash'],
                'user'     => $data['user_id'],
                'session'  => $data['session_id'],
                'content'  => $data['content_id'],
                'purpose'  => $data['purpose'],
                'ua'       => $data['ua_hash'],
                'ip'       => $data['ip_prefix_hash'],
                'issued'   => $this->now(),
                'expires'  => $data['expires_at'],
                'max_uses' => (int) $data['max_uses'],
            ]
        );
    }

    public function findUsable(string $tokenHash, string $purpose): ?array
    {
        return $this->selectOne(
            'SELECT * FROM viewer_tokens
             WHERE token_hash = :hash AND purpose = :purpose
               AND revoked_at IS NULL AND expires_at >= :now AND used_count < max_uses
             LIMIT 1',
            ['hash' => $tokenHash, 'purpose' => $purpose, 'now' => $this->now()]
        );
    }

    public function consume(int $id): void
    {
        $this->execute('UPDATE viewer_tokens SET used_count = used_count + 1 WHERE id = :id', ['id' => $id]);
    }

    public function revokeForSession(int $sessionId): void
    {
        $this->execute(
            'UPDATE viewer_tokens SET revoked_at = :now WHERE session_id = :session AND revoked_at IS NULL',
            ['now' => $this->now(), 'session' => $sessionId]
        );
    }

    /** Housekeeping: expired tokens carry no value and should not accumulate. */
    public function purgeExpired(): int
    {
        return $this->execute(
            'DELETE FROM viewer_tokens WHERE expires_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - 3600)]
        );
    }
}
