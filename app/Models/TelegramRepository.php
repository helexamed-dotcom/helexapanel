<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Database;
use HeleXa\Core\Str;

/**
 * Telegram accounts that talked to the sign-in bot, and the one-time links
 * it hands out. Only a SHA-256 of each link's secret is stored.
 */
final class TelegramRepository extends BaseRepository
{
    public const LINK_MINUTES = 30;

    public static function ready(): bool
    {
        try {
            Database::selectOne('SELECT 1 FROM telegram_accounts LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public function touch(int $tgUserId, int $chatId, ?string $username, ?string $firstName): void
    {
        $this->execute(
            'INSERT INTO telegram_accounts (tg_user_id, chat_id, tg_username, first_name, created_at, updated_at)
             VALUES (:id, :chat, :un, :fn, :now, :now2)
             ON DUPLICATE KEY UPDATE chat_id = VALUES(chat_id), tg_username = VALUES(tg_username), first_name = VALUES(first_name), updated_at = VALUES(updated_at)',
            ['id' => $tgUserId, 'chat' => $chatId, 'un' => $username !== null ? mb_substr($username, 0, 64) : null,
             'fn' => $firstName !== null ? mb_substr($firstName, 0, 128) : null, 'now' => $this->now(), 'now2' => $this->now()]
        );
    }

    public function account(int $tgUserId): ?array
    {
        return $this->selectOne('SELECT * FROM telegram_accounts WHERE tg_user_id = :id', ['id' => $tgUserId]);
    }

    public function accountForUser(int $userId): ?array
    {
        return $this->selectOne('SELECT * FROM telegram_accounts WHERE user_id = :u', ['u' => $userId]);
    }

    public function setPhone(int $tgUserId, string $phone): void
    {
        $this->execute('UPDATE telegram_accounts SET phone = :p, verified_at = :now, updated_at = :now2 WHERE tg_user_id = :id',
            ['p' => $phone, 'id' => $tgUserId, 'now' => $this->now(), 'now2' => $this->now()]);
    }

    /** One site account, one Telegram account: linking takes it from any other. */
    public function link(int $tgUserId, int $userId): void
    {
        Database::transaction(function () use ($tgUserId, $userId): void {
            $this->execute('UPDATE telegram_accounts SET user_id = NULL WHERE user_id = :u AND tg_user_id <> :id', ['u' => $userId, 'id' => $tgUserId]);
            $this->execute('UPDATE telegram_accounts SET user_id = :u, updated_at = :now WHERE tg_user_id = :id',
                ['u' => $userId, 'id' => $tgUserId, 'now' => $this->now()]);
        });
    }

    public function recentLinks(int $tgUserId, int $minutes): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM telegram_tokens WHERE tg_user_id = :id AND created_at >= :since',
            ['id' => $tgUserId, 'since' => date('Y-m-d H:i:s', time() - $minutes * 60)]);
    }

    /** A new one-time link; any older unused link of the same person stops working. */
    public function issue(int $tgUserId, string $phone, string $purpose, ?int $userId): string
    {
        $secret = Str::token(24);
        Database::transaction(function () use ($tgUserId, $phone, $purpose, $userId, $secret): void {
            $this->execute('UPDATE telegram_tokens SET used_at = :now WHERE tg_user_id = :id AND used_at IS NULL',
                ['id' => $tgUserId, 'now' => $this->now()]);
            $this->insert(
                'INSERT INTO telegram_tokens (token_hash, tg_user_id, phone, purpose, user_id, expires_at, created_at)
                 VALUES (:h, :id, :p, :pu, :u, :exp, :now)',
                ['h' => hash('sha256', $secret), 'id' => $tgUserId, 'p' => $phone, 'pu' => $purpose, 'u' => $userId,
                 'exp' => date('Y-m-d H:i:s', time() + self::LINK_MINUTES * 60), 'now' => $this->now()]
            );
            $this->execute('UPDATE telegram_accounts SET last_link_at = :now WHERE tg_user_id = :id', ['id' => $tgUserId, 'now' => $this->now()]);
        });
        return $secret;
    }

    /** A link that is still good: unused and not expired. */
    public function findValid(string $secret): ?array
    {
        if (preg_match('/^[0-9a-f]{48}$/', $secret) !== 1) {
            return null;
        }
        return $this->selectOne(
            'SELECT t.*, a.chat_id, a.first_name, a.tg_username FROM telegram_tokens t
             JOIN telegram_accounts a ON a.tg_user_id = t.tg_user_id
             WHERE t.token_hash = :h AND t.used_at IS NULL AND t.expires_at > :now',
            ['h' => hash('sha256', $secret), 'now' => $this->now()]
        );
    }

    /** Spends a link. False when someone else spent it first. */
    public function spend(int $tokenId): bool
    {
        return $this->execute('UPDATE telegram_tokens SET used_at = :now WHERE id = :id AND used_at IS NULL',
            ['id' => $tokenId, 'now' => $this->now()]) === 1;
    }

    public function stats(): array
    {
        $r = $this->selectOne(
            "SELECT (SELECT COUNT(*) FROM telegram_accounts) AS started,
                    (SELECT COUNT(*) FROM telegram_accounts WHERE verified_at IS NOT NULL) AS verified,
                    (SELECT COUNT(*) FROM telegram_accounts WHERE user_id IS NOT NULL) AS linked,
                    (SELECT COUNT(*) FROM users WHERE registration_source = 'telegram' AND deleted_at IS NULL) AS registered"
        ) ?? [];
        return array_map('intval', $r);
    }
}
