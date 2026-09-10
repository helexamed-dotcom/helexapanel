<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * The Telegram <-> HeleXa account link.
 *
 * telegram_user_id is never treated as proof of identity. A row here only
 * exists after the bot's login flow verified a real password against
 * users.password_hash; every other query in the bot joins through user_id,
 * which is what that verification produced.
 */
final class TelegramAccountRepository extends BaseRepository
{
    public function findByTelegramId(int $telegramUserId): ?array
    {
        return $this->selectOne(
            'SELECT ta.*, u.uuid, u.username, u.full_name, u.role_id, u.status, r.slug AS role_slug
             FROM telegram_accounts ta
             JOIN users u ON u.id = ta.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE ta.telegram_user_id = :tid AND ta.unlinked_at IS NULL
             LIMIT 1',
            ['tid' => $telegramUserId]
        );
    }

    public function findByUserId(int $userId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM telegram_accounts WHERE user_id = :user AND unlinked_at IS NULL LIMIT 1',
            ['user' => $userId]
        );
    }

    public function link(array $data): int
    {
        // A Telegram id is only ever linked to one account: if this chat had
        // an old, unlinked row, it is reused instead of duplicated.
        $existing = $this->selectOne(
            'SELECT id FROM telegram_accounts WHERE telegram_user_id = :tid LIMIT 1',
            ['tid' => $data['telegram_user_id']]
        );

        if ($existing !== null) {
            $now = $this->now();
            $this->execute(
                'UPDATE telegram_accounts
                 SET user_id = :user, chat_id = :chat, telegram_username = :username, first_name = :first,
                     linked_at = :now1, last_seen_at = :now2, unlinked_at = NULL
                 WHERE id = :id',
                [
                    'user'     => $data['user_id'],
                    'chat'     => $data['chat_id'],
                    'username' => $data['telegram_username'] ?? null,
                    'first'    => $data['first_name'] ?? null,
                    'now1'     => $now,
                    'now2'     => $now,
                    'id'       => (int) $existing['id'],
                ]
            );
            return (int) $existing['id'];
        }

        $now = $this->now();
        return $this->insert(
            'INSERT INTO telegram_accounts
                (user_id, telegram_user_id, chat_id, telegram_username, first_name, linked_at, last_seen_at)
             VALUES (:user, :tid, :chat, :username, :first, :now1, :now2)',
            [
                'user'     => $data['user_id'],
                'tid'      => $data['telegram_user_id'],
                'chat'     => $data['chat_id'],
                'username' => $data['telegram_username'] ?? null,
                'first'    => $data['first_name'] ?? null,
                'now1'     => $now,
                'now2'     => $now,
            ]
        );
    }

    public function unlink(int $telegramUserId): void
    {
        $this->execute(
            'UPDATE telegram_accounts SET unlinked_at = :now WHERE telegram_user_id = :tid',
            ['now' => $this->now(), 'tid' => $telegramUserId]
        );
    }

    public function touchSeen(int $telegramUserId): void
    {
        $this->execute(
            'UPDATE telegram_accounts SET last_seen_at = :now WHERE telegram_user_id = :tid',
            ['now' => $this->now(), 'tid' => $telegramUserId]
        );
    }

    public function setPhone(int $telegramUserId, ?string $phone): void
    {
        $this->execute(
            'UPDATE telegram_accounts SET phone_shared = :phone WHERE telegram_user_id = :tid',
            ['phone' => $phone, 'tid' => $telegramUserId]
        );
    }

    public function setPreference(int $telegramUserId, string $column, bool $value): void
    {
        $allowed = ['notify_general', 'notify_schedule', 'notify_exams', 'notify_announcements', 'notify_support'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('Unknown preference column.');
        }
        $this->execute(
            "UPDATE telegram_accounts SET {$column} = :value WHERE telegram_user_id = :tid",
            ['value' => $value ? 1 : 0, 'tid' => $telegramUserId]
        );
    }

    /** @return array<int,int> chat ids of every account with this preference on */
    public function chatIdsForNotifiable(array $userIds, string $preferenceColumn): array
    {
        $allowed = ['notify_general', 'notify_schedule', 'notify_exams', 'notify_announcements', 'notify_support'];
        if (!in_array($preferenceColumn, $allowed, true) || $userIds === []) {
            return [];
        }

        $placeholders = [];
        $params       = [];
        foreach (array_values($userIds) as $index => $id) {
            $key = 'u' . $index;
            $placeholders[] = ':' . $key;
            $params[$key]   = $id;
        }

        $rows = $this->select(
            "SELECT user_id, chat_id FROM telegram_accounts
             WHERE unlinked_at IS NULL AND {$preferenceColumn} = 1
               AND user_id IN (" . implode(', ', $placeholders) . ')',
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['user_id']] = (int) $row['chat_id'];
        }
        return $out;
    }

    public function countLinked(): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM telegram_accounts WHERE unlinked_at IS NULL'
        )['c'] ?? 0);
    }

    public function countActiveToday(): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM telegram_accounts WHERE unlinked_at IS NULL AND DATE(last_seen_at) = :today',
            ['today' => date('Y-m-d')]
        )['c'] ?? 0);
    }

    /** How many linked accounts have each notification preference on — a
     * quick read on whether students are opting out of anything specific. */
    public function preferenceBreakdown(): array
    {
        $row = $this->selectOne(
            'SELECT
                SUM(notify_general)       AS general,
                SUM(notify_schedule)      AS schedule,
                SUM(notify_exams)         AS exams,
                SUM(notify_announcements) AS announcements,
                SUM(notify_support)       AS support
             FROM telegram_accounts WHERE unlinked_at IS NULL'
        );
        return [
            'general'       => (int) ($row['general'] ?? 0),
            'schedule'      => (int) ($row['schedule'] ?? 0),
            'exams'         => (int) ($row['exams'] ?? 0),
            'announcements' => (int) ($row['announcements'] ?? 0),
            'support'       => (int) ($row['support'] ?? 0),
        ];
    }
}
