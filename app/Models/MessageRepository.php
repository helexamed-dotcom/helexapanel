<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Database;

final class MessageRepository extends BaseRepository
{
    /** @param array<int,int> $recipientIds */
    public function send(int $senderId, string $subject, string $body, array $recipientIds, bool $broadcast): int
    {
        return Database::transaction(function () use ($senderId, $subject, $body, $recipientIds, $broadcast): int {
            $id = $this->insert(
                'INSERT INTO messages (sender_id, subject, body, is_broadcast, created_at)
                 VALUES (:sender, :subject, :body, :broadcast, :now)',
                [
                    'sender'    => $senderId,
                    'subject'   => $subject,
                    'body'      => $body,
                    'broadcast' => $broadcast ? 1 : 0,
                    'now'       => $this->now(),
                ]
            );

            foreach (array_unique($recipientIds) as $userId) {
                $this->insert(
                    'INSERT INTO message_recipients (message_id, user_id, is_read) VALUES (:message, :user, 0)',
                    ['message' => $id, 'user' => (int) $userId]
                );
            }

            return $id;
        });
    }

    public function inbox(int $userId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        return $this->select(
            'SELECT m.id, m.subject, m.body, m.created_at, mr.is_read, mr.read_at,
                    u.full_name AS sender_name
             FROM message_recipients mr
             JOIN messages m ON m.id = mr.message_id
             LEFT JOIN users u ON u.id = m.sender_id
             WHERE mr.user_id = :user AND mr.deleted_at IS NULL
             ORDER BY m.created_at DESC, m.id DESC LIMIT ' . $limit,
            ['user' => $userId]
        );
    }

    public function findForUser(int $userId, int $messageId): ?array
    {
        return $this->selectOne(
            'SELECT m.id, m.subject, m.body, m.created_at, mr.is_read, u.full_name AS sender_name
             FROM message_recipients mr
             JOIN messages m ON m.id = mr.message_id
             LEFT JOIN users u ON u.id = m.sender_id
             WHERE mr.user_id = :user AND m.id = :message AND mr.deleted_at IS NULL
             LIMIT 1',
            ['user' => $userId, 'message' => $messageId]
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM message_recipients
             WHERE user_id = :user AND is_read = 0 AND deleted_at IS NULL',
            ['user' => $userId]
        )['c'] ?? 0);
    }

    public function markRead(int $userId, int $messageId): void
    {
        $this->execute(
            'UPDATE message_recipients SET is_read = 1, read_at = :now
             WHERE user_id = :user AND message_id = :message AND is_read = 0',
            ['now' => $this->now(), 'user' => $userId, 'message' => $messageId]
        );
    }

    /** Admin view: what was sent, to whom, and how much of it was read. */
    public function sent(int $limit = 100): array
    {
        $limit = max(1, min($limit, 300));
        return $this->select(
            'SELECT m.*, u.full_name AS sender_name,
                    (SELECT COUNT(*) FROM message_recipients r WHERE r.message_id = m.id) AS recipients,
                    (SELECT COUNT(*) FROM message_recipients r WHERE r.message_id = m.id AND r.is_read = 1) AS read_count
             FROM messages m LEFT JOIN users u ON u.id = m.sender_id
             ORDER BY m.id DESC LIMIT ' . $limit
        );
    }

    public function recipientsOf(int $messageId): array
    {
        return $this->select(
            'SELECT u.full_name, u.username, r.is_read, r.read_at
             FROM message_recipients r JOIN users u ON u.id = r.user_id
             WHERE r.message_id = :message ORDER BY u.full_name',
            ['message' => $messageId]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM messages WHERE id = :id', ['id' => $id]);
    }
}
