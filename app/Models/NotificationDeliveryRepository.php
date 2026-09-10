<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * Outbound deliveries for a notification, one row per (notification, user,
 * channel). The unique key is what makes queuing idempotent: attempting to
 * queue the same delivery twice is a silent no-op, not a duplicate message.
 */
final class NotificationDeliveryRepository extends BaseRepository
{
    public function enqueue(int $notificationId, int $userId, string $channel): void
    {
        $now = $this->now();
        $this->execute(
            'INSERT IGNORE INTO notification_deliveries
                (notification_id, user_id, channel, status, next_attempt_at, created_at)
             VALUES (:notification, :user, :channel, \'pending\', :now1, :now2)',
            ['notification' => $notificationId, 'user' => $userId, 'channel' => $channel, 'now1' => $now, 'now2' => $now]
        );
    }

    /** @return array<int, array<string,mixed>> due deliveries, oldest first */
    public function due(string $channel, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        return $this->select(
            "SELECT d.*, n.title, n.body, n.link_url
             FROM notification_deliveries d
             JOIN notifications n ON n.id = d.notification_id
             WHERE d.channel = :channel AND d.status = 'pending' AND d.next_attempt_at <= :now
             ORDER BY d.id LIMIT {$limit}",
            ['channel' => $channel, 'now' => $this->now()]
        );
    }

    public function markSent(int $id): void
    {
        $this->execute(
            "UPDATE notification_deliveries SET status = 'sent', sent_at = :now WHERE id = :id",
            ['now' => $this->now(), 'id' => $id]
        );
    }

    /** Exponential backoff, capped, so a prolonged outage does not hammer the API. */
    public function markFailed(int $id, int $attempts, string $error): void
    {
        $permanent = $attempts >= 6;
        $delay     = min(3600, (int) (30 * 2 ** $attempts));

        $this->execute(
            'UPDATE notification_deliveries
             SET status = :status, attempts = :attempts, last_error = :error, next_attempt_at = :next
             WHERE id = :id',
            [
                'status'   => $permanent ? 'failed' : 'pending',
                'attempts' => $attempts,
                'error'    => mb_substr($error, 0, 255),
                'next'     => date('Y-m-d H:i:s', time() + $delay),
                'id'       => $id,
            ]
        );
    }

    public function purgeOld(int $days = 30): int
    {
        return $this->execute(
            "DELETE FROM notification_deliveries WHERE status IN ('sent','failed','cancelled') AND created_at < :cutoff",
            ['cutoff' => date('Y-m-d H:i:s', time() - $days * 86400)]
        );
    }

    public function countByStatus(string $channel): array
    {
        $rows = $this->select(
            'SELECT status, COUNT(*) AS c FROM notification_deliveries WHERE channel = :channel GROUP BY status',
            ['channel' => $channel]
        );
        $out = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $out[$row['status']] = (int) $row['c'];
        }
        return $out;
    }

    public function countSentToday(string $channel): int
    {
        return (int) ($this->selectOne(
            "SELECT COUNT(*) AS c FROM notification_deliveries
             WHERE channel = :channel AND status = 'sent' AND DATE(sent_at) = :today",
            ['channel' => $channel, 'today' => date('Y-m-d')]
        )['c'] ?? 0);
    }
}
