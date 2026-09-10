<?php
declare(strict_types=1);

namespace HeleXa\Models;

use PDOException;

/**
 * Ledger of synced offline events.
 *
 * Idempotency is enforced by the unique key on (user_id, event_id), not by an
 * application-level "have I seen this?" check. A replayed batch therefore
 * cannot slip through a race between two concurrent requests.
 */
final class SyncEventRepository extends BaseRepository
{
    /** @return bool true when this event was recorded for the first time */
    public function claim(array $data): bool
    {
        try {
            $this->insert(
                'INSERT INTO offline_sync_events
                    (user_id, event_id, event_type, content_id, accepted_seconds,
                     client_started_at, client_ended_at, outcome, reason, device_hash, received_at)
                 VALUES (:user, :event, :type, :content, :seconds, :started, :ended, :outcome, :reason, :device, :now)',
                [
                    'user'    => $data['user_id'],
                    'event'   => $data['event_id'],
                    'type'    => $data['event_type'],
                    'content' => $data['content_id'],
                    'seconds' => (int) ($data['accepted_seconds'] ?? 0),
                    'started' => $data['client_started_at'],
                    'ended'   => $data['client_ended_at'],
                    'outcome' => $data['outcome'],
                    'reason'  => $data['reason'],
                    'device'  => $data['device_hash'],
                    'now'     => $this->now(),
                ]
            );
            return true;
        } catch (PDOException $e) {
            // 23000 is the integrity-constraint family; here it means the
            // unique (user_id, event_id) already exists, i.e. a replay.
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /** Offline seconds already credited to this user for a given day. */
    public function acceptedSecondsOn(int $userId, string $date): int
    {
        return (int) ($this->selectOne(
            "SELECT COALESCE(SUM(accepted_seconds), 0) AS total
             FROM offline_sync_events
             WHERE user_id = :user AND outcome = 'accepted' AND DATE(client_started_at) = :day",
            ['user' => $userId, 'day' => $date]
        )['total'] ?? 0);
    }

    /**
     * Finalises a claimed event.
     *
     * The client timestamps are written here, not at claim time, because the
     * daily ceiling is computed from them: without them the ledger has no day
     * to group by and the ceiling silently never applies.
     */
    public function markOutcome(
        int $userId,
        string $eventId,
        string $outcome,
        ?string $reason,
        int $seconds,
        ?string $startedAt = null,
        ?string $endedAt = null
    ): void {
        $this->execute(
            'UPDATE offline_sync_events
             SET outcome = :outcome, reason = :reason, accepted_seconds = :seconds,
                 client_started_at = COALESCE(:started, client_started_at),
                 client_ended_at   = COALESCE(:ended, client_ended_at)
             WHERE user_id = :user AND event_id = :event',
            [
                'outcome' => $outcome,
                'reason'  => $reason,
                'seconds' => $seconds,
                'started' => $startedAt,
                'ended'   => $endedAt,
                'user'    => $userId,
                'event'   => $eventId,
            ]
        );
    }

    public function recent(int $userId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        return $this->select(
            'SELECT event_id, event_type, outcome, reason, accepted_seconds, received_at
             FROM offline_sync_events WHERE user_id = :user
             ORDER BY id DESC LIMIT ' . $limit,
            ['user' => $userId]
        );
    }

    public function purgeOlderThan(int $days = 90): int
    {
        return $this->execute(
            'DELETE FROM offline_sync_events WHERE received_at < :cutoff',
            ['cutoff' => date('Y-m-d H:i:s', time() - ($days * 86400))]
        );
    }
}
