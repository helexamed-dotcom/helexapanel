<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * The bot's stand-in for an HTTP session.
 *
 * Every webhook call is a fresh, stateless request — Telegram does not keep
 * a connection open the way a browser tab does. This table is "what step is
 * this chat in, and what has it collected so far" (e.g. mid-login, waiting
 * for the password after the username). Rows expire on their own so an
 * abandoned login cannot be resumed hours later.
 */
final class TelegramStateRepository extends BaseRepository
{
    private const TTL_SECONDS = 600;

    public function get(int $telegramUserId): ?array
    {
        $row = $this->selectOne(
            'SELECT * FROM telegram_states WHERE telegram_user_id = :tid AND expires_at > :now LIMIT 1',
            ['tid' => $telegramUserId, 'now' => $this->now()]
        );
        if ($row === null) {
            return null;
        }
        $row['payload'] = $row['payload'] !== null ? json_decode((string) $row['payload'], true) : [];
        return $row;
    }

    public function set(int $telegramUserId, string $step, array $payload = []): void
    {
        $this->execute(
            'INSERT INTO telegram_states (telegram_user_id, step, payload, updated_at, expires_at)
             VALUES (:tid, :step, :payload, :now, :expires)
             ON DUPLICATE KEY UPDATE step = :step2, payload = :payload2, updated_at = :now2, expires_at = :expires2',
            [
                'tid'      => $telegramUserId,
                'step'     => $step,
                'payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'now'      => $this->now(),
                'expires'  => date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
                'step2'    => $step,
                'payload2' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'now2'     => $this->now(),
                'expires2' => date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            ]
        );
    }

    public function clear(int $telegramUserId): void
    {
        $this->execute('DELETE FROM telegram_states WHERE telegram_user_id = :tid', ['tid' => $telegramUserId]);
    }

    public function purgeExpired(): int
    {
        return $this->execute('DELETE FROM telegram_states WHERE expires_at < :now', ['now' => $this->now()]);
    }
}
