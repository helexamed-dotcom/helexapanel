<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Models\Balin\BalinAnswerRepository;
use HeleXa\Models\Balin\BalinBlockRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\Balin\BalinProgressRepository;
use HeleXa\Models\Balin\BalinStageRepository;
use HeleXa\Models\Balin\BalinXpRepository;

/**
 * Playing and finishing a stage.
 *
 * A stage is finished when every required block in it has been dealt with.
 * Optional blocks — a hint, an aside, a decorative image — never hold it
 * open. Completion pays once: the progress row's status is the guard, and
 * the XP award carries a key derived from the stage, so replaying a
 * finished stage is free to do and worth nothing.
 */
final class StageService
{
    public function __construct(
        private readonly BalinBlockRepository $blocks = new BalinBlockRepository(),
        private readonly BalinProgressRepository $progress = new BalinProgressRepository(),
        private readonly BalinAnswerRepository $answers = new BalinAnswerRepository(),
        private readonly BalinStageRepository $stages = new BalinStageRepository(),
        private readonly BalinLessonRepository $lessons = new BalinLessonRepository(),
        private readonly BalinXpRepository $xp = new BalinXpRepository(),
        private readonly Recalculator $recalculator = new Recalculator(),
    ) {
    }

    /**
     * The stage as the student should see it: published blocks, with any
     * question they have already answered marked up so the page can show the
     * outcome instead of offering the question again.
     *
     * @return array<int, array<string,mixed>>
     */
    public function playableBlocks(int $userId, array $stage): array
    {
        $blocks   = $this->blocks->forStage((int) $stage['id'], true);
        $answered = $this->answers->forStage($userId, (int) $stage['id']);

        foreach ($blocks as $index => $block) {
            if ($block['block_type'] !== 'question' || $block['question_id'] === null) {
                continue;
            }

            $questionId = (int) $block['question_id'];
            $answer     = $answered[$questionId] ?? null;

            $blocks[$index]['answered']    = $answer !== null;
            $blocks[$index]['answer']      = $answer;
            // Options never carry is_correct on this path; the answer key
            // stays on the server until the question has been answered.
            $blocks[$index]['options']     = (new \HeleXa\Models\Balin\BalinQuestionRepository())
                                                ->optionsForStudent($questionId);
        }

        return $blocks;
    }

    /**
     * Whether every required block has been satisfied.
     *
     * Only question blocks can be outstanding — the rest are read, and
     * reaching the end of the stage is what "reading" means. So the check is
     * whether any required question is still unanswered.
     */
    public function requirementsMet(int $userId, array $stage): bool
    {
        $answered = $this->answers->forStage($userId, (int) $stage['id']);

        foreach ($this->blocks->forStage((int) $stage['id'], true) as $block) {
            if ((int) $block['is_required'] !== 1) {
                continue;
            }
            if ($block['block_type'] !== 'question' || $block['question_id'] === null) {
                continue;
            }
            if (!isset($answered[(int) $block['question_id']])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Marks the stage complete and pays its XP, once.
     *
     * @return array{completed:bool, first_time:bool, xp_awarded:int, lesson_completed:bool,
     *               lesson_xp:int, blocked:?string}
     */
    public function complete(int $userId, array $stage): array
    {
        $stageId  = (int) $stage['id'];
        $lessonId = (int) $stage['lesson_id'];

        if (!$this->requirementsMet($userId, $stage)) {
            return [
                'completed' => false, 'first_time' => false, 'xp_awarded' => 0,
                'lesson_completed' => false, 'lesson_xp' => 0,
                'blocked' => 'هنوز همه‌ی بخش‌های الزامی این مرحله را کامل نکرده‌ای.',
            ];
        }

        $firstTime = $this->progress->complete(
            $userId,
            $lessonId,
            $stageId,
            (int) ($stage['content_version'] ?? 1)
        );

        $xpAwarded = 0;
        if ($firstTime) {
            $reward = (int) ($stage['xp_reward'] ?? 0);
            if ($reward > 0) {
                $result = $this->xp->award(
                    $userId,
                    $reward,
                    (int) ($stage['is_final_case'] ?? 0) === 1 ? 'final_case' : 'stage_complete',
                    'stage',
                    $stageId,
                    'stage:' . $userId . ':' . $stageId,
                    CompetitionService::currentId()
                );
                $xpAwarded = $result['awarded'] ? $reward : 0;
            }

            (new MissionService())->recordStageComplete($userId);
        }

        $lessonResult = $firstTime
            ? $this->completeLessonIfFinished($userId, $lessonId)
            : ['completed' => false, 'xp' => 0];

        $this->recalculator->afterActivity($userId, $lessonId);

        if ($xpAwarded + $lessonResult['xp'] > 0) {
            CompetitionService::mirrorXp($userId, $xpAwarded + $lessonResult['xp']);
        }
        (new AchievementService())->evaluate($userId);

        return [
            'completed'        => true,
            'first_time'       => $firstTime,
            'xp_awarded'       => $xpAwarded,
            'lesson_completed' => $lessonResult['completed'],
            'lesson_xp'        => $lessonResult['xp'],
            'blocked'          => null,
        ];
    }

    /** @return array{completed:bool, xp:int} */
    private function completeLessonIfFinished(int $userId, int $lessonId): array
    {
        if (!$this->progress->lessonIsComplete($userId, $lessonId)) {
            return ['completed' => false, 'xp' => 0];
        }

        $lesson = $this->lessons->findById($lessonId);
        $reward = (int) ($lesson['xp_reward'] ?? 0);

        if ($reward <= 0) {
            return ['completed' => true, 'xp' => 0];
        }

        $result = $this->xp->award(
            $userId,
            $reward,
            'lesson_complete',
            'lesson',
            $lessonId,
            'lesson:' . $userId . ':' . $lessonId,
            CompetitionService::currentId()
        );

        return ['completed' => true, 'xp' => $result['awarded'] ? $reward : 0];
    }

    /**
     * A summary shown when a lesson is finished: what was earned and how
     * well it went.
     */
    public function lessonSummary(int $userId, int $lessonId): array
    {
        $weights  = $this->answers->lessonWeights($userId, $lessonId);
        $stages   = $this->stages->forLesson($lessonId, true);
        $progress = $this->progress->forLesson($userId, $lessonId);

        $completed = 0;
        foreach ($stages as $stage) {
            if (($progress[(int) $stage['id']]['status'] ?? '') === 'completed') {
                $completed++;
            }
        }

        return [
            'stages_total'     => count($stages),
            'stages_completed' => $completed,
            'answered'         => $weights['answered'],
            'correct'          => $weights['correct'],
            'accuracy'         => Mastery::accuracy($weights['correct'], $weights['answered']),
            'mastery'          => Mastery::percent($weights['weighted_correct'], $weights['weight_total']),
        ];
    }
}
