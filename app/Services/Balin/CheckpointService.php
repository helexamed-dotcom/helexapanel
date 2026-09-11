<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Core\Database;
use HeleXa\Models\Balin\BalinAnswerRepository;
use HeleXa\Models\Balin\BalinCheckpointRepository;
use HeleXa\Models\Balin\BalinQuestionRepository;
use HeleXa\Models\Balin\BalinSkillTrackRepository;
use HeleXa\Models\Balin\BalinStatsRepository;
use HeleXa\Models\Balin\BalinXpRepository;

/**
 * Checkpoint exams: the assessments that sit between stages.
 *
 * Different from an ordinary question block in four ways that all live here:
 * an exam has a pass mark, it may gate the next stage, it allows a limited
 * number of attempts with a wait between them, and in random-pool mode each
 * attempt draws a fresh paper so a retry is not a memory test.
 *
 * Everything that decides an outcome — which questions, which are right,
 * what the score is, whether it passes, whether XP is owed — happens here on
 * the server. The client submits choices and nothing else.
 */
final class CheckpointService
{
    public const BLOCKED_MAX_ATTEMPTS = 'MAX_ATTEMPTS';
    public const BLOCKED_COOLDOWN     = 'COOLDOWN';
    public const BLOCKED_NO_QUESTIONS = 'NO_QUESTIONS';
    public const BLOCKED_UNPUBLISHED  = 'UNPUBLISHED';

    public function __construct(
        private readonly BalinCheckpointRepository $checkpoints = new BalinCheckpointRepository(),
        private readonly BalinQuestionRepository $questions = new BalinQuestionRepository(),
        private readonly BalinAnswerRepository $answers = new BalinAnswerRepository(),
        private readonly BalinXpRepository $xp = new BalinXpRepository(),
        private readonly BalinStatsRepository $stats = new BalinStatsRepository(),
        private readonly Recalculator $recalculator = new Recalculator(),
    ) {
    }

    /**
     * Whether a student may open this exam right now, and if not, why.
     *
     * Always returns a reason a person can act on. Hitting the attempt limit
     * is an ordinary outcome of the rules, not an error.
     *
     * @return array{allowed:bool, reason:?string, message:string, attempts_used:int,
     *               attempts_left:?int, next_attempt_at:?string, passed_already:bool}
     */
    public function canStart(int $userId, array $exam, bool $isPreview = false): array
    {
        $examId   = (int) $exam['id'];
        $used     = $this->checkpoints->countAttempts($userId, $examId);
        $passed   = $this->checkpoints->hasPassed($userId, $examId);
        $maxRaw   = $exam['max_attempts'];
        $max      = $maxRaw === null ? null : (int) $maxRaw;
        $left     = $max === null ? null : max(0, $max - $used);

        $base = [
            'attempts_used'  => $used,
            'attempts_left'  => $left,
            'passed_already' => $passed,
            'next_attempt_at'=> null,
        ];

        // Previewing admins bypass the allowance entirely — that is the point
        // of a preview, and none of it is recorded against the real limits.
        if ($isPreview) {
            return ['allowed' => true, 'reason' => null, 'message' => ''] + $base;
        }

        if (($exam['status'] ?? 'draft') !== 'published') {
            return ['allowed' => false, 'reason' => self::BLOCKED_UNPUBLISHED,
                    'message' => 'این آزمون هنوز منتشر نشده است.'] + $base;
        }

        // A half-finished attempt is resumed rather than blocked, so a
        // dropped connection does not cost an attempt.
        if ($this->checkpoints->openAttempt($userId, $examId) !== null) {
            return ['allowed' => true, 'reason' => null, 'message' => ''] + $base;
        }

        if ($max !== null && $used >= $max) {
            return ['allowed' => false, 'reason' => self::BLOCKED_MAX_ATTEMPTS,
                    'message' => 'تعداد تلاش‌های مجاز برای این آزمون تمام شده است. '
                               . 'برای بررسی مجدد با استاد یا پشتیبانی در تماس باش.'] + $base;
        }

        $wait = $this->cooldownRemaining($userId, $exam);
        if ($wait !== null) {
            return [
                'allowed'        => false,
                'reason'         => self::BLOCKED_COOLDOWN,
                'message'        => 'می‌تونی از ' . $wait['human'] . ' دوباره تلاش کنی.',
                'next_attempt_at'=> $wait['at'],
            ] + $base;
        }

        if ($this->availableQuestionCount($exam) === 0) {
            return ['allowed' => false, 'reason' => self::BLOCKED_NO_QUESTIONS,
                    'message' => 'هنوز سؤالی برای این آزمون تنظیم نشده است.'] + $base;
        }

        return ['allowed' => true, 'reason' => null, 'message' => ''] + $base;
    }

    /**
     * Opens an attempt and draws its paper.
     *
     * The paper is stored on the attempt, so reloading the page resumes the
     * same questions rather than drawing new ones — which would otherwise let
     * a student reroll until the paper looked easy.
     *
     * @return array{attempt:?array, questions:array, resumed:bool, blocked:?array}
     */
    public function start(int $userId, array $exam, bool $isPreview = false, ?string $idempotencyKey = null): array
    {
        $gate = $this->canStart($userId, $exam, $isPreview);
        if (!$gate['allowed']) {
            return ['attempt' => null, 'questions' => [], 'resumed' => false, 'blocked' => $gate];
        }

        $examId = (int) $exam['id'];

        if (!$isPreview) {
            $open = $this->checkpoints->openAttempt($userId, $examId);
            if ($open !== null) {
                return [
                    'attempt'   => $open,
                    'questions' => $this->paperFor($open),
                    'resumed'   => true,
                    'blocked'   => null,
                ];
            }
        }

        $paper = $this->drawPaper($exam);
        if ($paper === []) {
            return ['attempt' => null, 'questions' => [], 'resumed' => false, 'blocked' => [
                'allowed' => false, 'reason' => self::BLOCKED_NO_QUESTIONS,
                'message' => 'هنوز سؤالی برای این آزمون تنظیم نشده است.',
            ]];
        }

        $limit     = $exam['time_limit_minutes'] === null ? null : (int) $exam['time_limit_minutes'];
        $expiresAt = $limit === null
            ? ($isPreview ? date('Y-m-d H:i:s', time() + BalinSettings::previewTtlMinutes() * 60) : null)
            : date('Y-m-d H:i:s', time() + $limit * 60);

        $result = $this->checkpoints->startAttempt(
            $userId,
            $examId,
            $isPreview ? 0 : $gate['attempts_used'] + 1,
            array_map(static fn (array $q): int => (int) $q['id'], $paper),
            $expiresAt,
            $isPreview,
            $idempotencyKey
        );

        $attempt = $result['attempt'];
        if ($attempt === null) {
            return ['attempt' => null, 'questions' => [], 'resumed' => false, 'blocked' => [
                'allowed' => false, 'reason' => 'START_FAILED',
                'message' => 'شروع آزمون ناموفق بود. دوباره تلاش کن.',
            ]];
        }

        return [
            'attempt'   => $attempt,
            'questions' => $this->paperFor($attempt),
            'resumed'   => !$result['started'],
            'blocked'   => null,
        ];
    }

    /**
     * Grades a submitted attempt.
     *
     * The grade comes from what was submitted in this attempt. Recording the
     * answers in balin_answers is a separate step that may legitimately skip
     * a question the student has already answered elsewhere — mastery counts
     * first answers only, while the exam grade must reflect this sitting.
     *
     * @param array<int,int> $choices question id => chosen option id
     * @return array{
     *   graded:bool, reason:?string, score_percent:float, correct:int, total:int,
     *   passed:bool, xp_awarded:int, first_pass:bool, breakdown:array, expired:bool
     * }
     */
    public function submit(int $userId, array $exam, array $attempt, array $choices): array
    {
        $examId    = (int) $exam['id'];
        $attemptId = (int) $attempt['id'];
        $isPreview = (int) $attempt['is_preview'] === 1;

        if ($attempt['completed_at'] !== null) {
            return $this->alreadyGraded($attempt);
        }

        $paper = $this->paperFor($attempt);
        if ($paper === []) {
            return [
                'graded' => false, 'reason' => 'EMPTY_PAPER', 'score_percent' => 0.0,
                'correct' => 0, 'total' => 0, 'passed' => false, 'xp_awarded' => 0,
                'first_pass' => false, 'breakdown' => [], 'expired' => false,
            ];
        }

        $expired = $attempt['expires_at'] !== null && strtotime((string) $attempt['expires_at']) < time();

        // An expired paper is still graded. Discarding the student's work
        // because the clock ran out would lose real answers for no gain; the
        // expiry is recorded and shown instead.
        $breakdown = [];
        $correct   = 0;

        foreach ($paper as $question) {
            $questionId    = (int) $question['id'];
            $chosen        = isset($choices[$questionId]) ? (int) $choices[$questionId] : null;
            $correctOption = $this->questions->correctOptionId($questionId);
            $wasRight      = $chosen !== null && $correctOption !== null && $chosen === $correctOption;

            if ($wasRight) {
                $correct++;
            }

            $breakdown[] = [
                'question'          => $question,
                'chosen_option_id'  => $chosen,
                'correct_option_id' => $correctOption,
                'is_correct'        => $wasRight,
                'skill_track_ids'   => $this->questions->skillTrackIds($questionId),
            ];
        }

        $total     = count($paper);
        $percent   = $total > 0 ? round($correct / $total * 100, 2) : 0.0;
        $threshold = (float) $exam['pass_threshold_percent'];
        $passed    = $percent >= $threshold;

        // A preview never touches a real table.
        if ($isPreview) {
            return [
                'graded' => true, 'reason' => null, 'score_percent' => $percent,
                'correct' => $correct, 'total' => $total, 'passed' => $passed,
                'xp_awarded' => 0, 'first_pass' => false, 'breakdown' => $breakdown,
                'expired' => $expired,
            ];
        }

        $trackIds  = [];
        $firstPass = false;
        $xpAwarded = 0;

        $graded = Database::transaction(function () use (
            $userId, $exam, $examId, $attemptId, $attempt, $breakdown, $correct,
            $total, $percent, $passed, &$trackIds, &$firstPass, &$xpAwarded
        ): bool {
            $this->stats->lockForUpdate($userId);

            $closed = $this->checkpoints->completeAttempt(
                $attemptId,
                $correct,
                $total,
                $percent,
                $passed,
                (int) ($exam['content_version'] ?? 1)
            );

            if ($closed === 0) {
                return false;   // another request graded it first
            }

            foreach ($breakdown as $row) {
                $question = $row['question'];
                $trackIds = array_merge($trackIds, $row['skill_track_ids']);

                // Skipped silently when the question was answered before —
                // the unique key is the rule, and the exam grade above has
                // already counted this sitting.
                $this->answers->record([
                    'user_id'         => $userId,
                    'question_id'     => (int) $question['id'],
                    'lesson_id'       => (int) $question['lesson_id'],
                    'stage_id'        => $question['stage_id'] ?? null,
                    'attempt_id'      => $attemptId,
                    'option_id'       => $row['chosen_option_id'],
                    'is_correct'      => $row['is_correct'],
                    'used_hint'       => false,
                    'xp_awarded'      => 0,
                    'difficulty'      => $question['difficulty'] ?? 'medium',
                    'weight'          => Mastery::weightFor(
                        (string) ($question['difficulty'] ?? 'medium'),
                        (bool) ($question['is_final_case_step'] ?? false)
                    ),
                    'content_version' => $question['version'] ?? null,
                ]);
            }

            if ($passed) {
                $first = $this->checkpoints->firstPassingAttempt($userId, $examId);
                $firstPass = $first !== null && (int) $first['id'] === $attemptId;

                if ($firstPass) {
                    $reward = (int) $exam['xp_reward'];
                    $result = $this->xp->award(
                        $userId,
                        $reward,
                        'checkpoint_exam_passed',
                        'checkpoint_exam',
                        $examId,
                        // Keyed on the exam, not the attempt: a later passing
                        // attempt generates the same key and is refused.
                        'checkpoint:' . $userId . ':' . $examId,
                        CompetitionService::currentId(),
                        ['attempt' => (int) $attempt['attempt_number'], 'score' => $percent]
                    );
                    $xpAwarded = $result['awarded'] ? $reward : 0;
                }
            }

            return true;
        });

        if (!$graded) {
            return $this->alreadyGraded($this->checkpoints->findAttemptById($attemptId) ?? $attempt);
        }

        $this->recalculator->afterActivity($userId, (int) $exam['lesson_id'], array_unique($trackIds));
        if ($xpAwarded > 0) {
            CompetitionService::mirrorXp($userId, $xpAwarded);
        }
        (new AchievementService())->evaluate($userId);

        return [
            'graded' => true, 'reason' => null, 'score_percent' => $percent,
            'correct' => $correct, 'total' => $total, 'passed' => $passed,
            'xp_awarded' => $xpAwarded, 'first_pass' => $firstPass,
            'breakdown' => $breakdown, 'expired' => $expired,
        ];
    }

    /**
     * Per-skill summary of one attempt, so feedback names what was weak
     * rather than only printing a percentage.
     *
     * @param array<int, array<string,mixed>> $breakdown from submit()
     * @return array<int, array{track:array, correct:int, total:int, percent:float}>
     */
    public function skillBreakdown(array $breakdown): array
    {
        $tracks  = new BalinSkillTrackRepository();
        $byTrack = [];

        foreach ($breakdown as $row) {
            foreach ($row['skill_track_ids'] as $trackId) {
                $byTrack[$trackId] ??= ['correct' => 0, 'total' => 0];
                $byTrack[$trackId]['total']++;
                if ($row['is_correct']) {
                    $byTrack[$trackId]['correct']++;
                }
            }
        }

        $out = [];
        foreach ($byTrack as $trackId => $counts) {
            $track = $tracks->findById((int) $trackId);
            if ($track === null) {
                continue;
            }
            $out[] = [
                'track'   => $track,
                'correct' => $counts['correct'],
                'total'   => $counts['total'],
                'percent' => $counts['total'] > 0 ? round($counts['correct'] / $counts['total'] * 100, 1) : 0.0,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['percent'] <=> $b['percent']);

        return $out;
    }

    // --------------------------------------------------------------- paper

    /** The questions stored on an attempt, in the order they were drawn. */
    private function paperFor(array $attempt): array
    {
        return $this->questions->findMany($this->checkpoints->attemptQuestionIds($attempt));
    }

    /**
     * Draws a paper for a new attempt.
     *
     * Fixed lists are the admin's exact selection. Random pools are drawn
     * fresh from everything tagged with the exam's primary skill, which is
     * what keeps a second attempt from being the first one again.
     */
    private function drawPaper(array $exam): array
    {
        if (($exam['question_source_mode'] ?? 'fixed_list') === 'random_pool') {
            $trackId = $exam['primary_skill_track_id'] === null ? 0 : (int) $exam['primary_skill_track_id'];
            if ($trackId <= 0) {
                return [];
            }

            return $this->questions->pooledForTrack($trackId, (int) $exam['num_questions']);
        }

        $fixed = $this->checkpoints->fixedQuestions((int) $exam['id']);

        // num_questions is a cap on a fixed list, not a promise to invent more.
        $limit = (int) $exam['num_questions'];

        return $limit > 0 ? array_slice($fixed, 0, $limit) : $fixed;
    }

    private function availableQuestionCount(array $exam): int
    {
        if (($exam['question_source_mode'] ?? 'fixed_list') === 'random_pool') {
            $trackId = $exam['primary_skill_track_id'] === null ? 0 : (int) $exam['primary_skill_track_id'];

            return $trackId <= 0 ? 0 : $this->questions->countPooledForTrack($trackId);
        }

        return count($this->checkpoints->fixedQuestions((int) $exam['id']));
    }

    // ------------------------------------------------------------ cooldown

    /**
     * How long is left before another attempt is allowed, or null when the
     * student may start now.
     *
     * The wait runs from when the last attempt was submitted. Times are
     * formatted in the institution's timezone, so "tomorrow at 9" means the
     * student's tomorrow rather than the server's.
     *
     * @return array{at:string, human:string}|null
     */
    private function cooldownRemaining(int $userId, array $exam): ?array
    {
        $hours = (int) ($exam['cooldown_hours_between_attempts'] ?? 0);
        if ($hours <= 0) {
            return null;
        }

        $last = $this->checkpoints->latestAttempt($userId, (int) $exam['id']);
        if ($last === null || $last['completed_at'] === null) {
            return null;
        }

        $readyAt = strtotime((string) $last['completed_at']) + $hours * 3600;
        if ($readyAt <= time()) {
            return null;
        }

        $moment = (new \DateTimeImmutable('@' . $readyAt))->setTimezone(BalinSettings::timezone());

        return [
            'at'    => $moment->format('Y-m-d H:i:s'),
            'human' => \HeleXa\Services\Jalali::dateTime($readyAt),
        ];
    }

    private function alreadyGraded(array $attempt): array
    {
        return [
            'graded'        => false,
            'reason'        => 'ALREADY_SUBMITTED',
            'score_percent' => (float) ($attempt['score_percent'] ?? 0),
            'correct'       => (int) ($attempt['correct_count'] ?? 0),
            'total'         => (int) ($attempt['total_count'] ?? 0),
            'passed'        => (int) ($attempt['passed'] ?? 0) === 1,
            'xp_awarded'    => 0,
            'first_pass'    => false,
            'breakdown'     => [],
            'expired'       => false,
        ];
    }
}
