<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;
use PDOException;

/**
 * Recorded answers — the source of truth for XP, mastery and accuracy.
 *
 * One answer per question per student, enforced by a unique key rather than
 * by a check-then-insert. Two requests racing to answer the same question
 * both reach the insert; the database picks a winner and the loser is told
 * "already answered" instead of quietly awarding a second lot of XP.
 */
final class BalinAnswerRepository extends BaseRepository
{
    /** MySQL's integrity-constraint class; a duplicate key lands here. */
    private const DUPLICATE = '23000';

    /**
     * Records an answer, or reports that one already existed.
     *
     * @return array{stored:bool, reason:?string, id:?int}
     */
    public function record(array $data): array
    {
        try {
            $id = $this->insert(
                'INSERT INTO balin_answers
                    (user_id, question_id, lesson_id, stage_id, attempt_id, option_id, is_correct,
                     used_hint, xp_awarded, difficulty, weight, time_spent_ms,
                     content_version_at_completion, idempotency_key, created_at)
                 VALUES (:user, :question, :lesson, :stage, :attempt, :option, :correct,
                         :hint, :xp, :difficulty, :weight, :spent, :version, :idem, :now)',
                [
                    'user'       => $data['user_id'],
                    'question'   => $data['question_id'],
                    'lesson'     => $data['lesson_id'],
                    'stage'      => $data['stage_id'] ?? null,
                    'attempt'    => $data['attempt_id'] ?? null,
                    'option'     => $data['option_id'] ?? null,
                    'correct'    => !empty($data['is_correct']) ? 1 : 0,
                    'hint'       => !empty($data['used_hint']) ? 1 : 0,
                    'xp'         => $data['xp_awarded'] ?? 0,
                    'difficulty' => $data['difficulty'] ?? 'medium',
                    'weight'     => $data['weight'] ?? 1.0,
                    'spent'      => $data['time_spent_ms'] ?? null,
                    'version'    => $data['content_version'] ?? null,
                    'idem'       => $data['idempotency_key'] ?? null,
                    'now'        => $this->now(),
                ]
            );

            return ['stored' => true, 'reason' => null, 'id' => $id];
        } catch (PDOException $e) {
            if ($e->getCode() !== self::DUPLICATE) {
                throw $e;
            }
            // Either the same question twice or the same idempotency key
            // replayed. Both mean the same thing to the caller: nothing new
            // happened, and nothing new should be paid out.
            return ['stored' => false, 'reason' => 'ALREADY_ANSWERED', 'id' => null];
        }
    }

    public function find(int $userId, int $questionId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM balin_answers WHERE user_id = :user AND question_id = :question LIMIT 1',
            ['user' => $userId, 'question' => $questionId]
        );
    }

    public function hasAnswered(int $userId, int $questionId): bool
    {
        return $this->find($userId, $questionId) !== null;
    }

    /**
     * Answers for the questions this stage presents, keyed by question id.
     *
     * Joined through the blocks rather than through balin_questions.stage_id:
     * a question can be placed in a stage by a block while its own stage_id
     * is null, and matching on the column would then show an answered
     * question as unanswered.
     *
     * @return array<int, array<string,mixed>> keyed by question id
     */
    public function forStage(int $userId, int $stageId): array
    {
        $rows = $this->select(
            "SELECT a.* FROM balin_answers a
             JOIN balin_blocks b ON b.question_id = a.question_id
                                AND b.stage_id = :stage
                                AND b.block_type = 'question'
             WHERE a.user_id = :user",
            ['user' => $userId, 'stage' => $stageId]
        );

        $byQuestion = [];
        foreach ($rows as $row) {
            $byQuestion[(int) $row['question_id']] = $row;
        }
        return $byQuestion;
    }

    public function forAttempt(int $attemptId): array
    {
        return $this->select(
            'SELECT a.*, q.prompt, q.explanation
             FROM balin_answers a
             JOIN balin_questions q ON q.id = a.question_id
             WHERE a.attempt_id = :attempt
             ORDER BY a.id',
            ['attempt' => $attemptId]
        );
    }

    /** @return array{answered:int, correct:int} */
    public function totals(int $userId): array
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS answered, COALESCE(SUM(is_correct), 0) AS correct
             FROM balin_answers WHERE user_id = :user',
            ['user' => $userId]
        );

        return [
            'answered' => (int) ($row['answered'] ?? 0),
            'correct'  => (int) ($row['correct'] ?? 0),
        ];
    }

    /** Weighted sums for one lesson, so mastery is a single query. */
    public function lessonWeights(int $userId, int $lessonId): array
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS answered,
                    COALESCE(SUM(is_correct), 0) AS correct,
                    COALESCE(SUM(weight), 0) AS weight_total,
                    COALESCE(SUM(CASE WHEN is_correct = 1 THEN weight ELSE 0 END), 0) AS weighted_correct
             FROM balin_answers
             WHERE user_id = :user AND lesson_id = :lesson',
            ['user' => $userId, 'lesson' => $lessonId]
        );

        return [
            'answered'         => (int) ($row['answered'] ?? 0),
            'correct'          => (int) ($row['correct'] ?? 0),
            'weight_total'     => (float) ($row['weight_total'] ?? 0),
            'weighted_correct' => (float) ($row['weighted_correct'] ?? 0),
        ];
    }

    /**
     * Weighted sums for one skill track across every lesson.
     *
     * A question with no tag joins nothing here, so it contributes to no
     * track — which is exactly the intended behaviour, not an oversight.
     */
    public function skillTrackWeights(int $userId, int $trackId): array
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS answered,
                    COALESCE(SUM(a.weight), 0) AS weight_total,
                    COALESCE(SUM(CASE WHEN a.is_correct = 1 THEN a.weight ELSE 0 END), 0) AS weighted_correct
             FROM balin_answers a
             JOIN balin_question_skill_tags t ON t.question_id = a.question_id
             WHERE a.user_id = :user AND t.skill_track_id = :track',
            ['user' => $userId, 'track' => $trackId]
        );

        return [
            'answered'         => (int) ($row['answered'] ?? 0),
            'weight_total'     => (float) ($row['weight_total'] ?? 0),
            'weighted_correct' => (float) ($row['weighted_correct'] ?? 0),
        ];
    }

    /** Difficulty-adjusted score over everything the student has answered. */
    public function globalWeights(int $userId): array
    {
        $row = $this->selectOne(
            'SELECT COALESCE(SUM(weight), 0) AS weight_total,
                    COALESCE(SUM(CASE WHEN is_correct = 1 THEN weight ELSE 0 END), 0) AS weighted_correct
             FROM balin_answers WHERE user_id = :user',
            ['user' => $userId]
        );

        return [
            'weight_total'     => (float) ($row['weight_total'] ?? 0),
            'weighted_correct' => (float) ($row['weighted_correct'] ?? 0),
        ];
    }

    /** Per-question analytics for the admin screen. */
    public function questionStats(int $questionId): array
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS attempts,
                    COALESCE(SUM(is_correct), 0) AS correct,
                    COALESCE(AVG(time_spent_ms), 0) AS avg_ms
             FROM balin_answers WHERE question_id = :q',
            ['q' => $questionId]
        );

        return [
            'attempts' => (int) ($row['attempts'] ?? 0),
            'correct'  => (int) ($row['correct'] ?? 0),
            'avg_ms'   => (int) ($row['avg_ms'] ?? 0),
        ];
    }
}
