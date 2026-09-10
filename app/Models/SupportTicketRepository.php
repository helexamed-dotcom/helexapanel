<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Str;

final class SupportTicketRepository extends BaseRepository
{
    /** The one ticket a student is currently in, if any (open or answered, never closed). */
    public function openFor(int $userId): ?array
    {
        return $this->selectOne(
            "SELECT * FROM support_tickets WHERE user_id = :user AND status IN ('open','answered')
             ORDER BY id DESC LIMIT 1",
            ['user' => $userId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM support_tickets WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT t.*, u.full_name, u.username, u.uuid AS user_uuid
             FROM support_tickets t JOIN users u ON u.id = t.user_id
             WHERE t.uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    public function create(int $userId): int
    {
        $now = $this->now();
        return $this->insert(
            'INSERT INTO support_tickets (uuid, user_id, status, last_message_at, created_at)
             VALUES (:uuid, :user, \'open\', :now1, :now2)',
            ['uuid' => Str::uuid4(), 'user' => $userId, 'now1' => $now, 'now2' => $now]
        );
    }

    /** @return array<int,array<string,mixed>> messages oldest first */
    public function messagesFor(int $ticketId): array
    {
        return $this->select(
            'SELECT m.*, u.full_name AS sender_name
             FROM support_messages m JOIN users u ON u.id = m.sender_id
             WHERE m.ticket_id = :ticket ORDER BY m.id',
            ['ticket' => $ticketId]
        );
    }

    public function addMessage(int $ticketId, string $senderType, int $senderId, ?string $body, ?string $attachmentPath): int
    {
        $now = $this->now();
        $id = $this->insert(
            'INSERT INTO support_messages (uuid, ticket_id, sender_type, sender_id, body, attachment_path, created_at)
             VALUES (:uuid, :ticket, :type, :sender, :body, :attachment, :now)',
            [
                'uuid'       => Str::uuid4(),
                'ticket'     => $ticketId,
                'type'       => $senderType,
                'sender'     => $senderId,
                'body'       => $body,
                'attachment' => $attachmentPath,
                'now'        => $now,
            ]
        );

        // A student writing again re-opens an answered ticket; an admin
        // reply marks it answered. Either way the ticket surfaces at the top
        // of whichever queue needs to look at it next.
        $newStatus = $senderType === 'admin' ? 'answered' : 'open';
        $this->execute(
            'UPDATE support_tickets SET status = :status, last_message_at = :now WHERE id = :id',
            ['status' => $newStatus, 'now' => $now, 'id' => $ticketId]
        );

        return $id;
    }

    public function close(int $ticketId, int $closedBy): void
    {
        $this->execute(
            'UPDATE support_tickets SET status = \'closed\', closed_at = :now, closed_by = :by WHERE id = :id',
            ['now' => $this->now(), 'by' => $closedBy, 'id' => $ticketId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function forAdmin(string $status, int $limit = 100): array
    {
        $limit = max(1, min($limit, 300));
        $where = in_array($status, ['open', 'answered', 'closed'], true) ? 'WHERE t.status = :status' : '';
        $params = $where !== '' ? ['status' => $status] : [];

        return $this->select(
            "SELECT t.*, u.full_name, u.username, u.uuid AS user_uuid,
                    (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = t.id) AS message_count
             FROM support_tickets t JOIN users u ON u.id = t.user_id
             {$where}
             ORDER BY t.last_message_at DESC LIMIT {$limit}",
            $params
        );
    }

    public function countByStatus(): array
    {
        $rows = $this->select('SELECT status, COUNT(*) AS c FROM support_tickets GROUP BY status');
        $out  = ['open' => 0, 'answered' => 0, 'closed' => 0];
        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['c'];
        }
        return $out;
    }
}
