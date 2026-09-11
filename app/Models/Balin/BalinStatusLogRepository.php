<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Every change of the island's publication state, with who made it.
 *
 * Separate from the general activity log because this one question —
 * "when did Balin go live, and who published it" — is asked often enough
 * that it deserves a table it can be read from directly.
 */
final class BalinStatusLogRepository extends BaseRepository
{
    public function record(?int $adminId, string $from, string $to, ?string $note = null): void
    {
        $this->insert(
            'INSERT INTO balin_status_log (admin_id, from_status, to_status, note, created_at)
             VALUES (:admin, :from, :to, :note, :now)',
            ['admin' => $adminId, 'from' => $from, 'to' => $to, 'note' => $note, 'now' => $this->now()]
        );
    }

    public function recent(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));

        return $this->select(
            "SELECT l.*, u.full_name AS admin_name
             FROM balin_status_log l
             LEFT JOIN users u ON u.id = l.admin_id
             ORDER BY l.id DESC
             LIMIT {$limit}"
        );
    }
}
