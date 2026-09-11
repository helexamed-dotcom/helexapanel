<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Who may enter the island.
 *
 * Absence of a row means no access. That is why this is a table rather than
 * a column on users with a default: a student who has never been considered
 * is denied by default, and granting access is always a deliberate act that
 * records who did it.
 *
 * Revoking never deletes progress. The row is flipped to disabled, so the
 * student's XP, answers and mastery survive and come back intact if access
 * is restored.
 */
final class BalinAccessRepository extends BaseRepository
{
    public function isEnabled(int $userId): bool
    {
        $row = $this->selectOne(
            'SELECT is_enabled FROM balin_student_access WHERE user_id = :user LIMIT 1',
            ['user' => $userId]
        );

        return $row !== null && (int) $row['is_enabled'] === 1;
    }

    public function find(int $userId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM balin_student_access WHERE user_id = :user LIMIT 1',
            ['user' => $userId]
        );
    }

    public function grant(int $userId, ?int $grantedBy, ?string $note = null): void
    {
        $this->execute(
            'INSERT INTO balin_student_access (user_id, is_enabled, granted_by, granted_at, revoked_at, note)
             VALUES (:user, 1, :by, :now, NULL, :note)
             ON DUPLICATE KEY UPDATE
                is_enabled = 1, granted_by = VALUES(granted_by),
                granted_at = VALUES(granted_at), revoked_at = NULL, note = VALUES(note)',
            ['user' => $userId, 'by' => $grantedBy, 'now' => $this->now(), 'note' => $note]
        );
    }

    public function revoke(int $userId, ?int $revokedBy, ?string $note = null): void
    {
        $this->execute(
            'INSERT INTO balin_student_access (user_id, is_enabled, granted_by, granted_at, revoked_at, note)
             VALUES (:user, 0, :by, :granted, :revoked, :note)
             ON DUPLICATE KEY UPDATE
                is_enabled = 0, revoked_at = VALUES(revoked_at), note = VALUES(note)',
            [
                'user'    => $userId,
                'by'      => $revokedBy,
                'granted' => $this->now(),
                'revoked' => $this->now(),
                'note'    => $note,
            ]
        );
    }

    /** Every student, with their access state, for the admin screen. */
    public function paginateStudents(string $search, int $limit, int $offset): array
    {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $where  = "u.role_id = (SELECT id FROM roles WHERE slug = 'student')";
        $params = [];

        if ($search !== '') {
            $where .= ' AND (u.full_name LIKE :q OR u.username LIKE :q OR u.mobile LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }

        return $this->select(
            "SELECT u.id, u.uuid, u.full_name, u.username, u.status,
                    COALESCE(a.is_enabled, 0) AS has_access,
                    a.granted_at, a.revoked_at,
                    COALESCE(s.total_xp, 0) AS total_xp
             FROM users u
             LEFT JOIN balin_student_access a ON a.user_id = u.id
             LEFT JOIN balin_user_stats s ON s.user_id = u.id
             WHERE {$where}
             ORDER BY u.full_name
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public function countStudents(string $search): int
    {
        $where  = "u.role_id = (SELECT id FROM roles WHERE slug = 'student')";
        $params = [];
        if ($search !== '') {
            $where .= ' AND (u.full_name LIKE :q OR u.username LIKE :q OR u.mobile LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }

        return (int) ($this->selectOne("SELECT COUNT(*) AS c FROM users u WHERE {$where}", $params)['c'] ?? 0);
    }

    public function countEnabled(): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM balin_student_access WHERE is_enabled = 1'
        )['c'] ?? 0);
    }

    /** Bulk grant, used by the "give everyone access" action on the admin screen. */
    public function grantAllStudents(?int $grantedBy): int
    {
        return $this->execute(
            "INSERT INTO balin_student_access (user_id, is_enabled, granted_by, granted_at, note)
             SELECT u.id, 1, :by, :now, 'bulk'
             FROM users u
             WHERE u.role_id = (SELECT id FROM roles WHERE slug = 'student')
             ON DUPLICATE KEY UPDATE is_enabled = 1, revoked_at = NULL",
            ['by' => $grantedBy, 'now' => $this->now()]
        );
    }
}
