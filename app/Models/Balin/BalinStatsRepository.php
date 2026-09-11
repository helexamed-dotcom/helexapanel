<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Cached per-student figures: XP total, level, accuracy, mastery.
 *
 * Every value here is derivable from the ledger and the answers table. None
 * of it is authoritative. It exists so a leaderboard page or a profile can
 * be one query instead of one aggregate per student, and it is rewritten
 * from the source whenever the source moves.
 */
final class BalinStatsRepository extends BaseRepository
{
    public function find(int $userId): ?array
    {
        return $this->selectOne('SELECT * FROM balin_user_stats WHERE user_id = :user LIMIT 1', ['user' => $userId]);
    }

    /** Reads the cache, or an all-zero row for a student who has not started. */
    public function findOrEmpty(int $userId): array
    {
        return $this->find($userId) ?? [
            'user_id'          => $userId,
            'total_xp'         => 0,
            'cached_level'     => 0,
            'answered_count'   => 0,
            'correct_count'    => 0,
            'accuracy_percent' => 0.0,
            'stages_completed' => 0,
            'lessons_completed'=> 0,
            'cached_at'        => null,
        ];
    }

    /**
     * Takes a row lock on this student's stats for the rest of the
     * transaction, serialising the answer → XP → mastery → streak chain
     * per student. Two concurrent submissions then run one after the other
     * instead of interleaving and both reading a pre-award total.
     *
     * The row is created first because FOR UPDATE locks nothing when there
     * is no row to lock, which would leave the first answer a student ever
     * submits unprotected.
     */
    public function lockForUpdate(int $userId): void
    {
        $this->execute(
            'INSERT IGNORE INTO balin_user_stats (user_id, cached_at) VALUES (:user, :now)',
            ['user' => $userId, 'now' => $this->now()]
        );

        $this->selectOne(
            'SELECT user_id FROM balin_user_stats WHERE user_id = :user FOR UPDATE',
            ['user' => $userId]
        );
    }

    public function store(int $userId, array $values): void
    {
        $this->execute(
            'INSERT INTO balin_user_stats
                (user_id, total_xp, cached_level, answered_count, correct_count,
                 accuracy_percent, stages_completed, lessons_completed, cached_at)
             VALUES (:user, :xp, :level, :answered, :correct, :accuracy, :stages, :lessons, :now)
             ON DUPLICATE KEY UPDATE
                total_xp = VALUES(total_xp), cached_level = VALUES(cached_level),
                answered_count = VALUES(answered_count), correct_count = VALUES(correct_count),
                accuracy_percent = VALUES(accuracy_percent), stages_completed = VALUES(stages_completed),
                lessons_completed = VALUES(lessons_completed), cached_at = VALUES(cached_at)',
            [
                'user'     => $userId,
                'xp'       => $values['total_xp'],
                'level'    => $values['cached_level'],
                'answered' => $values['answered_count'],
                'correct'  => $values['correct_count'],
                'accuracy' => $values['accuracy_percent'],
                'stages'   => $values['stages_completed'],
                'lessons'  => $values['lessons_completed'],
                'now'      => $this->now(),
            ]
        );
    }

    // ------------------------------------------------ lesson-level mastery

    public function storeLessonMastery(int $userId, int $lessonId, float $percent, int $answered, int $correct): void
    {
        $this->execute(
            'INSERT INTO balin_student_lesson_mastery
                (user_id, lesson_id, mastery_percent, answered_count, correct_count, cached_at)
             VALUES (:user, :lesson, :percent, :answered, :correct, :now)
             ON DUPLICATE KEY UPDATE
                mastery_percent = VALUES(mastery_percent), answered_count = VALUES(answered_count),
                correct_count = VALUES(correct_count), cached_at = VALUES(cached_at)',
            [
                'user'     => $userId,
                'lesson'   => $lessonId,
                'percent'  => $percent,
                'answered' => $answered,
                'correct'  => $correct,
                'now'      => $this->now(),
            ]
        );
    }

    /** @return array<int, array<string,mixed>> keyed by lesson id */
    public function lessonMastery(int $userId): array
    {
        $rows = $this->select(
            'SELECT m.*, l.title, l.icon, l.color
             FROM balin_student_lesson_mastery m
             JOIN balin_lessons l ON l.id = m.lesson_id
             WHERE m.user_id = :user
             ORDER BY l.display_order',
            ['user' => $userId]
        );

        $byLesson = [];
        foreach ($rows as $row) {
            $byLesson[(int) $row['lesson_id']] = $row;
        }
        return $byLesson;
    }

    public function averageMastery(int $userId): float
    {
        return (float) ($this->selectOne(
            'SELECT COALESCE(AVG(mastery_percent), 0) AS m
             FROM balin_student_lesson_mastery WHERE user_id = :user',
            ['user' => $userId]
        )['m'] ?? 0.0);
    }

    // ------------------------------------------------- skill track mastery

    public function storeSkillMastery(
        int $userId,
        int $trackId,
        float $percent,
        int $answered,
        float $weightedCorrect,
        float $weightTotal
    ): void {
        $this->execute(
            'INSERT INTO balin_student_skill_mastery
                (user_id, skill_track_id, mastery_percent, answered_count, weighted_correct, weight_total, cached_at)
             VALUES (:user, :track, :percent, :answered, :wc, :wt, :now)
             ON DUPLICATE KEY UPDATE
                mastery_percent = VALUES(mastery_percent), answered_count = VALUES(answered_count),
                weighted_correct = VALUES(weighted_correct), weight_total = VALUES(weight_total),
                cached_at = VALUES(cached_at)',
            [
                'user'     => $userId,
                'track'    => $trackId,
                'percent'  => $percent,
                'answered' => $answered,
                'wc'       => $weightedCorrect,
                'wt'       => $weightTotal,
                'now'      => $this->now(),
            ]
        );
    }

    /** @return array<int, array<string,mixed>> keyed by track id */
    public function skillMastery(int $userId): array
    {
        $rows = $this->select(
            'SELECT m.* FROM balin_student_skill_mastery m WHERE m.user_id = :user',
            ['user' => $userId]
        );

        $byTrack = [];
        foreach ($rows as $row) {
            $byTrack[(int) $row['skill_track_id']] = $row;
        }
        return $byTrack;
    }

    /** Average mastery per track across every student, for the admin dashboard. */
    public function skillMasteryAverages(): array
    {
        return $this->select(
            'SELECT t.id, t.name, t.icon, t.color,
                    COALESCE(AVG(m.mastery_percent), 0) AS average_percent,
                    COUNT(m.user_id) AS student_count
             FROM balin_skill_tracks t
             LEFT JOIN balin_student_skill_mastery m ON m.skill_track_id = t.id
             WHERE t.is_active = 1
             GROUP BY t.id, t.name, t.icon, t.color
             ORDER BY t.display_order'
        );
    }

    /** The student's weakest track with enough data behind it, for a nudge. */
    public function weakestSkill(int $userId): ?array
    {
        return $this->selectOne(
            'SELECT m.*, t.name, t.icon, t.slug
             FROM balin_student_skill_mastery m
             JOIN balin_skill_tracks t ON t.id = m.skill_track_id
             WHERE m.user_id = :user
               AND t.is_active = 1
               AND m.answered_count >= t.min_questions_for_reliable_mastery
             ORDER BY m.mastery_percent ASC
             LIMIT 1',
            ['user' => $userId]
        );
    }

    // ------------------------------------------------------- global counts

    public function platformTotals(): array
    {
        $row = $this->selectOne(
            "SELECT
                (SELECT COUNT(*) FROM balin_student_access WHERE is_enabled = 1) AS students_with_access,
                (SELECT COUNT(DISTINCT user_id) FROM balin_answers) AS active_students,
                (SELECT COUNT(*) FROM balin_lessons WHERE status = 'published') AS lessons,
                (SELECT COUNT(*) FROM balin_stages WHERE status = 'published') AS stages,
                (SELECT COUNT(*) FROM balin_questions WHERE status = 'published') AS questions,
                (SELECT COUNT(*) FROM balin_checkpoint_exams WHERE status = 'published') AS exams,
                (SELECT COUNT(*) FROM balin_student_progress WHERE status = 'completed') AS completed_stages,
                (SELECT COUNT(*) FROM balin_answers) AS answers,
                (SELECT COALESCE(SUM(is_correct), 0) FROM balin_answers) AS correct_answers,
                (SELECT COALESCE(SUM(amount), 0) FROM balin_xp_transactions) AS total_xp"
        );

        return $row ?? [];
    }
}
