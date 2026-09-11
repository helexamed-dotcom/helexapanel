<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Core\Database;
use HeleXa\Models\Balin\BalinAnswerRepository;
use HeleXa\Models\Balin\BalinQuestionRepository;
use HeleXa\Models\Balin\BalinStatsRepository;
use HeleXa\Models\Balin\BalinXpRepository;

/**
 * Submitting an answer — the one path a student can use to earn XP, and so
 * the one that has to be airtight.
 *
 * Three separate guards, because any one of them alone has a hole:
 *
 *  1. The client never says whether it was right. The correct option is read
 *     from the database here and compared server-side.
 *  2. A unique key on (user, question) means a question can only ever be
 *     answered once, whatever the client sends. Two racing requests both
 *     reach the insert and the database picks one.
 *  3. XP carries an idempotency key derived from the answer, so even a
 *     retried award cannot pay twice.
 *
 * The whole chain runs inside one transaction with a row lock on the
 * student, so concurrent submissions queue rather than interleave.
 */
final class AnswerService
{
    public function __construct(
        private readonly BalinAnswerRepository $answers = new BalinAnswerRepository(),
        private readonly BalinQuestionRepository $questions = new BalinQuestionRepository(),
        private readonly BalinXpRepository $xp = new BalinXpRepository(),
        private readonly BalinStatsRepository $stats = new BalinStatsRepository(),
        private readonly Recalculator $recalculator = new Recalculator(),
    ) {
    }

    /**
     * @param array<string,mixed> $question a row from balin_questions
     * @return array{
     *   stored:bool, reason:?string, is_correct:bool, correct_option_id:?int,
     *   explanation:?string, xp_awarded:int, level_before:int, level_after:int,
     *   levelled_up:bool, streak:?array
     * }
     */
    public function submit(
        int $userId,
        array $question,
        ?int $optionId,
        bool $usedHint,
        ?int $stageId = null,
        ?int $attemptId = null,
        ?string $idempotencyKey = null
    ): array {
        $questionId = (int) $question['id'];
        $lessonId   = (int) $question['lesson_id'];

        $correctOptionId = $this->questions->correctOptionId($questionId);
        $isCorrect       = $optionId !== null && $correctOptionId !== null && $optionId === $correctOptionId;

        $xpForAnswer = $isCorrect ? $this->xpFor($question, $usedHint) : 0;
        $weight      = Mastery::weightFor(
            (string) ($question['difficulty'] ?? 'medium'),
            (bool) ($question['is_final_case_step'] ?? false)
        );

        $trackIds = $this->questions->skillTrackIds($questionId);

        $outcome = Database::transaction(function () use (
            $userId, $questionId, $lessonId, $stageId, $attemptId, $optionId,
            $isCorrect, $usedHint, $xpForAnswer, $weight, $question, $idempotencyKey
        ): array {
            $this->stats->lockForUpdate($userId);

            $levelBefore = Level::forXp($this->xp->totalFor($userId));

            $stored = $this->answers->record([
                'user_id'         => $userId,
                'question_id'     => $questionId,
                'lesson_id'       => $lessonId,
                'stage_id'        => $stageId,
                'attempt_id'      => $attemptId,
                'option_id'       => $optionId,
                'is_correct'      => $isCorrect,
                'used_hint'       => $usedHint,
                'xp_awarded'      => $xpForAnswer,
                'difficulty'      => $question['difficulty'] ?? 'medium',
                'weight'          => $weight,
                'content_version' => $question['version'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            if (!$stored['stored']) {
                return ['stored' => false, 'level_before' => $levelBefore, 'level_after' => $levelBefore];
            }

            if ($xpForAnswer > 0) {
                // Keyed on the answer itself: the same answer can never fund
                // a second award, however the request arrives.
                $this->xp->award(
                    $userId,
                    $xpForAnswer,
                    'question_correct',
                    'question',
                    $questionId,
                    'answer:' . $userId . ':' . $questionId,
                    CompetitionService::currentId(),
                    ['used_hint' => $usedHint]
                );
            }

            $levelAfter = Level::forXp($this->xp->totalFor($userId));

            return ['stored' => true, 'level_before' => $levelBefore, 'level_after' => $levelAfter];
        });

        if (!$outcome['stored']) {
            $existing = $this->answers->find($userId, $questionId);

            return [
                'stored'            => false,
                'reason'            => 'ALREADY_ANSWERED',
                'is_correct'        => $existing !== null && (int) $existing['is_correct'] === 1,
                'correct_option_id' => $correctOptionId,
                'explanation'       => $question['explanation'] ?? null,
                'xp_awarded'        => (int) ($existing['xp_awarded'] ?? 0),
                'level_before'      => $outcome['level_before'],
                'level_after'       => $outcome['level_after'],
                'levelled_up'       => false,
                'streak'            => null,
            ];
        }

        // Cache rebuilds read the rows the transaction just committed, so
        // they run after it rather than inside it.
        $recalculated = $this->recalculator->afterActivity($userId, $lessonId, $trackIds);

        if ($xpForAnswer > 0) {
            CompetitionService::mirrorXp($userId, $xpForAnswer);
        }

        (new AchievementService())->evaluate($userId);
        (new MissionService())->recordAnswer($userId, $isCorrect);

        return [
            'stored'            => true,
            'reason'            => null,
            'is_correct'        => $isCorrect,
            'correct_option_id' => $correctOptionId,
            'explanation'       => $question['explanation'] ?? null,
            'xp_awarded'        => $xpForAnswer,
            'level_before'      => $outcome['level_before'],
            'level_after'       => $outcome['level_after'],
            'levelled_up'       => $outcome['level_after'] > $outcome['level_before'],
            'streak'            => $recalculated['streak'],
        ];
    }

    /**
     * What a correct answer is worth.
     *
     * A hint taken before answering reduces the award by the configured
     * penalty — fifty percent by default, so a ten-point question pays five.
     * A wrong answer pays nothing whether a hint was taken or not, so the
     * penalty never needs to apply there.
     */
    private function xpFor(array $question, bool $usedHint): int
    {
        $base = (int) ($question['xp_reward'] ?? BalinSettings::xpPerCorrect());
        if ($base <= 0) {
            return 0;
        }
        if (!$usedHint) {
            return $base;
        }

        $remaining = (100 - BalinSettings::hintPenaltyPercent()) / 100;

        return max(0, (int) round($base * $remaining));
    }
}
