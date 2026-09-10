<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class RateLimitRepository extends BaseRepository
{
    /**
     * Increments the counter for one window and returns the new value.
     * The upsert is atomic, so two concurrent requests cannot both read
     * the pre-increment value and slip past the limit together.
     */
    public function hit(string $bucket, string $identifier, string $windowStart, int $windowSeconds): int
    {
        $this->execute(
            'INSERT INTO rate_limits (bucket, identifier, window_start, hits, expires_at)
             VALUES (:bucket, :identifier, :window, 1, :expires)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [
                'bucket'     => $bucket,
                'identifier' => $identifier,
                'window'     => $windowStart,
                'expires'    => date('Y-m-d H:i:s', strtotime($windowStart) + ($windowSeconds * 2)),
            ]
        );

        return (int) ($this->selectOne(
            'SELECT hits FROM rate_limits WHERE bucket = :bucket AND identifier = :identifier AND window_start = :window',
            ['bucket' => $bucket, 'identifier' => $identifier, 'window' => $windowStart]
        )['hits'] ?? 1);
    }

    public function purgeExpired(): int
    {
        return $this->execute('DELETE FROM rate_limits WHERE expires_at < :now', ['now' => $this->now()]);
    }

    public function countRows(): int
    {
        return (int) ($this->selectOne('SELECT COUNT(*) AS c FROM rate_limits')['c'] ?? 0);
    }
}
