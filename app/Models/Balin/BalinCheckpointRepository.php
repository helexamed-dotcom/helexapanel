<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;
use PDOException;

/**
 * Checkpoint exams and the attempts students make at them.
 *
 * An attempt row is created when the exam is opened, not when it is
 * submitted, so an abandoned attempt still counts against the allowance and
 * a student cannot shop for an easy paper by reloading. The unique key on
 * (user, exam, attempt_number) is what makes two parallel "start" requests
 * resolve to one attempt instead of two.
 */
final class BalinCheckpointRepository extends BaseRepository
{
    private const DUPLICATE = '23000';

    // ------------------------------------------------------------- exams

    public function forLesson(int $lessonId, bool $publishedOnly = false): array
    {
        $filter = $publishedOnly ? " AND e.status = 'published'" : '';

        return $this->select(
            "SELECT e.*, s.title AS anchor_stage_title, t.name AS skill_track_name, t.icon AS skill_track_icon,
                    (SELECT COUNT(*) FROM balin_checkpoint_exam_questions q WHERE q.exam_id = e.id) AS fixed_question_count
             FROM balin_checkpoint_exams e
             LEFT JOIN balin_stages s ON s.id = e.anchor_stage_id
             LEFT JOIN balin_skill_tracks t ON t.id = e.primary_skill_track_id
             WHERE e.lesson_id = :lesson {$filter}
             ORDER BY e.display_order, e.id",
            ['lesson' => $lessonId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_checkpoint_exams WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM balin_checkpoint_exams WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    /**
     * Published gating exams that stand in front of a stage.
     * Used by the unlock check, which is why it filters on is_gating here
     * rather than making the caller remember to.
     */
    public function gatingExamsForStage(int $stageId): array
    {
        return $this->select(
            "SELECT * FROM balin_checkpoint_exams
             WHERE anchor_stage_id = :stage
               AND position_type = 'before_stage'
               AND is_gating = 1
               AND status = 'published'",
            ['stage' => $stageId]
        );
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO balin_checkpoint_exams
                (uuid, lesson_id, position_type, anchor_stage_id, title, description,
                 primary_skill_track_id, secondary_skill_track_ids, question_source_mode, num_questions,
                 pass_threshold_percent, is_gating, max_attempts, cooldown_hours_between_attempts,
                 time_limit_minutes, xp_reward, difficulty_mix, display_order, status, created_at)
             VALUES (:uuid, :lesson, :position, :anchor, :title, :description,
                     :track, :secondary, :mode, :num,
                     :threshold, :gating, :attempts, :cooldown,
                     :limit, :xp, :mix, :order, :status, :now)',
            [
                'uuid'        => $data['uuid'],
                'lesson'      => $data['lesson_id'],
                'position'    => $data['position_type'] ?? 'after_stage',
                'anchor'      => $data['anchor_stage_id'] ?? null,
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'track'       => $data['primary_skill_track_id'] ?? null,
                'secondary'   => isset($data['secondary_skill_track_ids'])
                                    ? json_encode(array_values(array_map('intval', $data['secondary_skill_track_ids'])))
                                    : null,
                'mode'        => $data['question_source_mode'] ?? 'fixed_list',
                'num'         => $data['num_questions'] ?? 10,
                'threshold'   => $data['pass_threshold_percent'] ?? 70,
                'gating'      => !empty($data['is_gating']) ? 1 : 0,
                'attempts'    => $data['max_attempts'] ?? null,
                'cooldown'    => $data['cooldown_hours_between_attempts'] ?? 24,
                'limit'       => $data['time_limit_minutes'] ?? null,
                'xp'          => $data['xp_reward'] ?? 50,
                'mix'         => isset($data['difficulty_mix']) ? json_encode($data['difficulty_mix']) : null,
                'order'       => $data['display_order'] ?? 1000,
                'status'      => $data['status'] ?? 'draft',
                'now'         => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data, int $expectedVersion): bool
    {
        return $this->execute(
            'UPDATE balin_checkpoint_exams
             SET position_type = :position, anchor_stage_id = :anchor, title = :title,
                 description = :description, primary_skill_track_id = :track,
                 secondary_skill_track_ids = :secondary, question_source_mode = :mode,
                 num_questions = :num, pass_threshold_percent = :threshold, is_gating = :gating,
                 max_attempts = :attempts, cooldown_hours_between_attempts = :cooldown,
                 time_limit_minutes = :limit, xp_reward = :xp, difficulty_mix = :mix,
                 version = version + 1, content_version = content_version + 1, updated_at = :now
             WHERE id = :id AND version = :version',
            [
                'position'    => $data['position_type'] ?? 'after_stage',
                'anchor'      => $data['anchor_stage_id'] ?? null,
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'track'       => $data['primary_skill_track_id'] ?? null,
                'secondary'   => isset($data['secondary_skill_track_ids'])
                                    ? json_encode(array_values(array_map('intval', $data['secondary_skill_track_ids'])))
                                    : null,
                'mode'        => $data['question_source_mode'] ?? 'fixed_list',
                'num'         => $data['num_questions'] ?? 10,
                'threshold'   => $data['pass_threshold_percent'] ?? 70,
                'gating'      => !empty($data['is_gating']) ? 1 : 0,
                'attempts'    => $data['max_attempts'] ?? null,
                'cooldown'    => $data['cooldown_hours_between_attempts'] ?? 24,
                'limit'       => $data['time_limit_minutes'] ?? null,
                'xp'          => $data['xp_reward'] ?? 50,
                'mix'         => isset($data['difficulty_mix']) ? json_encode($data['difficulty_mix']) : null,
                'id'          => $id,
                'version'     => $expectedVersion,
                'now'         => $this->now(),
            ]
        ) > 0;
    }

    public function setStatus(int $id, string $status): int
    {
        return $this->execute(
            'UPDATE balin_checkpoint_exams SET status = :status, updated_at = :now WHERE id = :id',
            ['status' => $status, 'id' => $id, 'now' => $this->now()]
        );
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM balin_checkpoint_exams WHERE id = :id', ['id' => $id]);
    }

    // -------------------------------------------------- fixed question list

    public function fixedQuestions(int $examId): array
    {
        return $this->select(
            'SELECT q.*, eq.display_order AS exam_order
             FROM balin_checkpoint_exam_questions eq
             JOIN balin_questions q ON q.id = eq.question_id
             WHERE eq.exam_id = :exam
             ORDER BY eq.display_order, q.id',
            ['exam' => $examId]
        );
    }

    public function attachQuestion(int $examId, int $questionId, int $order): void
    {
        $this->execute(
            'INSERT INTO balin_checkpoint_exam_questions (exam_id, question_id, display_order)
             VALUES (:exam, :question, :order)
             ON DUPLICATE KEY UPDATE display_order = VALUES(display_order)',
            ['exam' => $examId, 'question' => $questionId, 'order' => $order]
        );
    }

    public function detachQuestion(int $examId, int $questionId): int
    {
        return $this->execute(
            'DELETE FROM balin_checkpoint_exam_questions WHERE exam_id = :exam AND question_id = :question',
            ['exam' => $examId, 'question' => $questionId]
        );
    }

    // ------------------------------------------------------------ attempts

    /**
     * Starts an attempt. The attempt number is computed from the rows that
     * exist, and the unique key rejects a second insert at the same number,
     * so two simultaneous starts cannot both open attempt 3.
     *
     * @return array{started:bool, attempt:?array, reason:?string}
     */
    public function startAttempt(
        int $userId,
        int $examId,
        int $attemptNumber,
        array $questionIds,
        ?string $expiresAt,
        bool $isPreview,
        ?string $idempotencyKey
    ): array {
        try {
            $uuid = \HeleXa\Core\Str::uuid4();
            $this->insert(
                'INSERT INTO balin_checkpoint_attempts
                    (uuid, user_id, exam_id, attempt_number, question_ids, total_count,
                     started_at, expires_at, is_preview, idempotency_key)
                 VALUES (:uuid, :user, :exam, :number, :questions, :total, :now, :expires, :preview, :idem)',
                [
                    'uuid'      => $uuid,
                    'user'      => $userId,
                    'exam'      => $examId,
                    'number'    => $attemptNumber,
                    'questions' => json_encode(array_values(array_map('intval', $questionIds))),
                    'total'     => count($questionIds),
                    'now'       => $this->now(),
                    'expires'   => $expiresAt,
                    'preview'   => $isPreview ? 1 : 0,
                    'idem'      => $idempotencyKey,
                ]
            );

            return ['started' => true, 'attempt' => $this->findAttemptByUuid($uuid), 'reason' => null];
        } catch (PDOException $e) {
            if ($e->getCode() !== self::DUPLICATE) {
                throw $e;
            }
            // Another request got there first. Hand back the attempt that won.
            $existing = $idempotencyKey !== null
                ? $this->findAttemptByIdempotencyKey($idempotencyKey)
                : $this->findAttempt($userId, $examId, $attemptNumber);

            return ['started' => false, 'attempt' => $existing, 'reason' => 'ALREADY_STARTED'];
        }
    }

    public function findAttemptByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM balin_checkpoint_attempts WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    public function findAttemptByIdempotencyKey(string $key): ?array
    {
        return $this->selectOne(
            'SELECT * FROM balin_checkpoint_attempts WHERE idempotency_key = :key LIMIT 1',
            ['key' => $key]
        );
    }

    public function findAttempt(int $userId, int $examId, int $attemptNumber): ?array
    {
        return $this->selectOne(
            'SELECT * FROM balin_checkpoint_attempts
             WHERE user_id = :user AND exam_id = :exam AND attempt_number = :number LIMIT 1',
            ['user' => $userId, 'exam' => $examId, 'number' => $attemptNumber]
        );
    }

    /** Real attempts only — preview runs never count against anything. */
    public function countAttempts(int $userId, int $examId): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM balin_checkpoint_attempts
             WHERE user_id = :user AND exam_id = :exam AND is_preview = 0',
            ['user' => $userId, 'exam' => $examId]
        )['c'] ?? 0);
    }

    public function latestAttempt(int $userId, int $examId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM balin_checkpoint_attempts
             WHERE user_id = :user AND exam_id = :exam AND is_preview = 0
             ORDER BY attempt_number DESC LIMIT 1',
            ['user' => $userId, 'exam' => $examId]
        );
    }

    /** An attempt that was opened and never submitted, so it can be resumed. */
    public function openAttempt(int $userId, int $examId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM balin_checkpoint_attempts
             WHERE user_id = :user AND exam_id = :exam AND completed_at IS NULL AND is_preview = 0
             ORDER BY attempt_number DESC LIMIT 1',
            ['user' => $userId, 'exam' => $examId]
        );
    }

    public function hasPassed(int $userId, int $examId): bool
    {
        return $this->selectOne(
            'SELECT 1 FROM balin_checkpoint_attempts
             WHERE user_id = :user AND exam_id = :exam AND passed = 1 AND is_preview = 0 LIMIT 1',
            ['user' => $userId, 'exam' => $examId]
        ) !== null;
    }

    /** The first passing attempt, which is the only one that ever pays XP. */
    public function firstPassingAttempt(int $userId, int $examId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM balin_checkpoint_attempts
             WHERE user_id = :user AND exam_id = :exam AND passed = 1 AND is_preview = 0
             ORDER BY attempt_number ASC LIMIT 1',
            ['user' => $userId, 'exam' => $examId]
        );
    }

    public function completeAttempt(int $attemptId, int $correct, int $total, float $percent, bool $passed, int $contentVersion): int
    {
        return $this->execute(
            'UPDATE balin_checkpoint_attempts
             SET correct_count = :correct, total_count = :total, score_percent = :percent,
                 passed = :passed, completed_at = :now, content_version_at_completion = :version
             WHERE id = :id AND completed_at IS NULL',
            [
                'correct' => $correct,
                'total'   => $total,
                'percent' => $percent,
                'passed'  => $passed ? 1 : 0,
                'now'     => $this->now(),
                'version' => $contentVersion,
                'id'      => $attemptId,
            ]
        );
    }

    public function attemptsFor(int $userId, int $examId): array
    {
        return $this->select(
            'SELECT * FROM balin_checkpoint_attempts
             WHERE user_id = :user AND exam_id = :exam AND is_preview = 0
             ORDER BY attempt_number',
            ['user' => $userId, 'exam' => $examId]
        );
    }

    public function findAttemptById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_checkpoint_attempts WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** Clears a student's attempt history for one exam, so they may retry. */
    public function resetAttempts(int $userId, int $examId): int
    {
        return $this->execute(
            'DELETE FROM balin_checkpoint_attempts WHERE user_id = :user AND exam_id = :exam AND is_preview = 0',
            ['user' => $userId, 'exam' => $examId]
        );
    }

    public function deleteExpiredPreviews(): int
    {
        return $this->execute(
            'DELETE FROM balin_checkpoint_attempts
             WHERE is_preview = 1 AND expires_at IS NOT NULL AND expires_at < :now',
            ['now' => $this->now()]
        );
    }

    /** Pass rate and average attempts-to-pass, for the admin dashboard. */
    public function examStatistics(int $examId): array
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS attempts,
                    COUNT(DISTINCT user_id) AS students,
                    COALESCE(SUM(passed), 0) AS passes,
                    COALESCE(AVG(score_percent), 0) AS average_score
             FROM balin_checkpoint_attempts
             WHERE exam_id = :exam AND is_preview = 0 AND completed_at IS NOT NULL',
            ['exam' => $examId]
        );

        $toPass = $this->selectOne(
            'SELECT COALESCE(AVG(first_pass), 0) AS average_attempts
             FROM (SELECT MIN(attempt_number) AS first_pass
                   FROM balin_checkpoint_attempts
                   WHERE exam_id = :exam AND passed = 1 AND is_preview = 0
                   GROUP BY user_id) AS firsts',
            ['exam' => $examId]
        );

        return [
            'attempts'          => (int) ($row['attempts'] ?? 0),
            'students'          => (int) ($row['students'] ?? 0),
            'passes'            => (int) ($row['passes'] ?? 0),
            'average_score'     => round((float) ($row['average_score'] ?? 0), 1),
            'average_attempts'  => round((float) ($toPass['average_attempts'] ?? 0), 2),
        ];
    }

    /** @return array<int,int> question ids stored with the attempt */
    public function attemptQuestionIds(array $attempt): array
    {
        $decoded = json_decode((string) ($attempt['question_ids'] ?? '[]'), true);
        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }
}
