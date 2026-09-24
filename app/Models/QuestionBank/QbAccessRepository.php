<?php
declare(strict_types=1);

namespace HeleXa\Models\QuestionBank;

use HeleXa\Models\BaseRepository;

/**
 * Which students may open which درس.
 *
 * A row means access; no row means none. There is no is_enabled flag, so
 * "revoked" and "never granted" are the same state and cannot disagree with
 * each other. Grants are per depth-1 subject — see the migration for why not
 * per question and not per student.
 */
final class QbAccessRepository extends BaseRepository
{
    /** @return array<int,int> subject ids this student may open */
    public function subjectIdsFor(int $userId): array
    {
        $rows = $this->select(
            'SELECT a.subject_id
             FROM qb_student_access a
             JOIN qb_subjects s ON s.id = a.subject_id
             WHERE a.user_id = :user AND s.is_active = 1',
            ['user' => $userId]
        );

        return array_map(static fn (array $r): int => (int) $r['subject_id'], $rows);
    }

    /**
     * The subjects themselves, for the student's own page.
     *
     * Deactivating a درس hides it from students without touching a single
     * access row, so turning it back on restores exactly who had it before.
     *
     * @return array<int,array<string,mixed>>
     */
    public function subjectsFor(int $userId): array
    {
        return $this->select(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM qb_questions q
                      WHERE q.deleted_at IS NULL AND q.status = \'published\'
                        AND (q.subject_id = s.id OR q.sub_subject_id = s.id OR q.topic_id = s.id)
                    ) AS question_count
             FROM qb_student_access a
             JOIN qb_subjects s ON s.id = a.subject_id
             WHERE a.user_id = :user AND s.is_active = 1
             ORDER BY s.sort_order, s.title',
            ['user' => $userId]
        );
    }

    public function has(int $userId, int $subjectId): bool
    {
        return $this->selectOne(
            'SELECT id FROM qb_student_access WHERE user_id = :user AND subject_id = :subject LIMIT 1',
            ['user' => $userId, 'subject' => $subjectId]
        ) !== null;
    }

    public function grant(int $userId, int $subjectId, ?int $adminId): void
    {
        // INSERT IGNORE against the unique key: pressing the toggle twice in
        // quick succession inserts once, without a check that a second request
        // could slip past between reading and writing.
        $this->execute(
            'INSERT IGNORE INTO qb_student_access (user_id, subject_id, granted_by, granted_at)
             VALUES (:user, :subject, :admin, :now)',
            ['user' => $userId, 'subject' => $subjectId, 'admin' => $adminId, 'now' => $this->now()]
        );
    }

    public function revoke(int $userId, int $subjectId): void
    {
        $this->execute(
            'DELETE FROM qb_student_access WHERE user_id = :user AND subject_id = :subject',
            ['user' => $userId, 'subject' => $subjectId]
        );
    }

    /**
     * Replaces a student's whole grant set with the one submitted.
     *
     * The access page posts every checkbox at once, so a per-row diff would be
     * more code for the same result. Existing grants that survive are left
     * alone rather than deleted and re-inserted, which keeps granted_at
     * meaning "when this student first got it".
     *
     * @param array<int,int> $subjectIds
     */
    public function sync(int $userId, array $subjectIds, ?int $adminId): void
    {
        $wanted  = array_values(array_unique(array_map('intval', $subjectIds)));
        $current = $this->subjectIdsForRaw($userId);

        foreach (array_diff($wanted, $current) as $subjectId) {
            if ($subjectId > 0) {
                $this->grant($userId, $subjectId, $adminId);
            }
        }

        foreach (array_diff($current, $wanted) as $subjectId) {
            $this->revoke($userId, $subjectId);
        }
    }

    /** How many students hold each subject, keyed by subject id. */
    public function countsBySubject(): array
    {
        $rows = $this->select(
            'SELECT subject_id, COUNT(*) AS c FROM qb_student_access GROUP BY subject_id'
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['subject_id']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * Every grant, ignoring whether the subject is currently active.
     *
     * sync() must see rows for deactivated subjects too, or saving the form
     * while a درس is switched off would silently revoke it for everyone.
     *
     * @return array<int,int>
     */
    private function subjectIdsForRaw(int $userId): array
    {
        $rows = $this->select(
            'SELECT subject_id FROM qb_student_access WHERE user_id = :user',
            ['user' => $userId]
        );

        return array_map(static fn (array $r): int => (int) $r['subject_id'], $rows);
    }
}
