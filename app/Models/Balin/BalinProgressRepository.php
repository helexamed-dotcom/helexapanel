<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Where each student is on each stage.
 *
 * One row per (student, stage), so opening a stage twice cannot create two
 * histories. The row also carries the stage's content_version at the moment
 * it was completed: editing a stage afterwards does not retroactively
 * un-complete it for the students who already passed the old version.
 */
final class BalinProgressRepository extends BaseRepository
{
    public function find(int $userId, int $stageId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM balin_student_progress WHERE user_id = :user AND stage_id = :stage LIMIT 1',
            ['user' => $userId, 'stage' => $stageId]
        );
    }

    /** @return array<int, array<string,mixed>> keyed by stage id */
    public function forLesson(int $userId, int $lessonId): array
    {
        $rows = $this->select(
            'SELECT * FROM balin_student_progress WHERE user_id = :user AND lesson_id = :lesson',
            ['user' => $userId, 'lesson' => $lessonId]
        );

        $byStage = [];
        foreach ($rows as $row) {
            $byStage[(int) $row['stage_id']] = $row;
        }
        return $byStage;
    }

    /** Opens a stage, or refreshes its activity stamp if it is already open. */
    public function start(int $userId, int $lessonId, int $stageId): void
    {
        $this->execute(
            // Each placeholder appears once: PDO runs with emulation off, and
            // a real prepared statement binds by position, so reusing a name
            // would fail with "invalid parameter number".
            'INSERT INTO balin_student_progress
                (user_id, lesson_id, stage_id, status, started_at, last_activity_at)
             VALUES (:user, :lesson, :stage, \'in_progress\', :started, :active)
             ON DUPLICATE KEY UPDATE last_activity_at = VALUES(last_activity_at)',
            [
                'user'    => $userId,
                'lesson'  => $lessonId,
                'stage'   => $stageId,
                'started' => $this->now(),
                'active'  => $this->now(),
            ]
        );
    }

    public function touch(int $userId, int $stageId, ?int $lastBlockId = null): void
    {
        $this->execute(
            'UPDATE balin_student_progress
             SET last_activity_at = :now,
                 last_block_id = COALESCE(:block, last_block_id)
             WHERE user_id = :user AND stage_id = :stage',
            ['now' => $this->now(), 'block' => $lastBlockId, 'user' => $userId, 'stage' => $stageId]
        );
    }

    /**
     * Marks a stage complete once, and reports whether this call is the one
     * that did it. The caller uses that answer to decide whether to award
     * completion XP, so a replay cannot pay out twice.
     */
    public function complete(int $userId, int $lessonId, int $stageId, int $contentVersion): bool
    {
        $this->start($userId, $lessonId, $stageId);

        $changed = $this->execute(
            'UPDATE balin_student_progress
             SET status = \'completed\', completed_at = :finished, last_activity_at = :active,
                 content_version_at_completion = :version
             WHERE user_id = :user AND stage_id = :stage AND status <> \'completed\'',
            [
                'finished' => $this->now(),
                'active'   => $this->now(),
                'version'  => $contentVersion,
                'user'     => $userId,
                'stage'    => $stageId,
            ]
        );

        return $changed > 0;
    }

    public function isCompleted(int $userId, int $stageId): bool
    {
        $row = $this->selectOne(
            'SELECT status FROM balin_student_progress
             WHERE user_id = :user AND stage_id = :stage LIMIT 1',
            ['user' => $userId, 'stage' => $stageId]
        );

        return $row !== null && $row['status'] === 'completed';
    }

    public function countCompletedStages(int $userId): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM balin_student_progress WHERE user_id = :user AND status = \'completed\'',
            ['user' => $userId]
        )['c'] ?? 0);
    }

    /** A lesson counts as finished when every published stage in it is complete. */
    public function countCompletedLessons(int $userId): int
    {
        return (int) ($this->selectOne(
            "SELECT COUNT(*) AS c FROM (
                SELECT l.id
                FROM balin_lessons l
                JOIN balin_stages s ON s.lesson_id = l.id AND s.status = 'published'
                LEFT JOIN balin_student_progress p
                       ON p.stage_id = s.id AND p.user_id = :user AND p.status = 'completed'
                WHERE l.status = 'published'
                GROUP BY l.id
                HAVING COUNT(s.id) > 0 AND COUNT(s.id) = COUNT(p.id)
             ) AS finished",
            ['user' => $userId]
        )['c'] ?? 0);
    }

    public function lessonIsComplete(int $userId, int $lessonId): bool
    {
        $row = $this->selectOne(
            "SELECT COUNT(s.id) AS total, COUNT(p.id) AS done
             FROM balin_stages s
             LEFT JOIN balin_student_progress p
                    ON p.stage_id = s.id AND p.user_id = :user AND p.status = 'completed'
             WHERE s.lesson_id = :lesson AND s.status = 'published'",
            ['user' => $userId, 'lesson' => $lessonId]
        );

        return $row !== null && (int) $row['total'] > 0 && (int) $row['total'] === (int) $row['done'];
    }

    /** Distinct days with activity, for the consistency term of the CPS. */
    public function activeDayCount(int $userId): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(DISTINCT DATE(last_activity_at)) AS c
             FROM balin_student_progress WHERE user_id = :user',
            ['user' => $userId]
        )['c'] ?? 0);
    }

    public function firstActivityAt(int $userId): ?string
    {
        $row = $this->selectOne(
            'SELECT MIN(started_at) AS t FROM balin_student_progress WHERE user_id = :user',
            ['user' => $userId]
        );
        return $row === null || $row['t'] === null ? null : (string) $row['t'];
    }
}
