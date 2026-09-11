<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Models\Balin\BalinCheckpointRepository;
use HeleXa\Models\Balin\BalinProgressRepository;

/**
 * Whether a stage is open to a student.
 *
 * Nothing about this is stored on the stage. A locked stage is locked
 * because of facts about the student — the previous stage is unfinished, or
 * a gating exam has not been passed — and those facts are read here, on the
 * server, every time. A request that asks for a locked stage directly gets
 * the same answer the map showed.
 *
 *     unlocked  ⇔  previous stage completed
 *                  AND every gating exam in front of this stage passed
 */
final class StageGate
{
    public const LOCKED      = 'locked';
    public const AVAILABLE   = 'available';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED   = 'completed';

    public function __construct(
        private readonly BalinProgressRepository $progress = new BalinProgressRepository(),
        private readonly BalinCheckpointRepository $checkpoints = new BalinCheckpointRepository(),
    ) {
    }

    /**
     * The state of every stage in a lesson, in order.
     *
     * Computed as one pass rather than per stage so the map costs a fixed
     * number of queries no matter how long the lesson is.
     *
     * @param array<int, array<string,mixed>> $stages ordered by display_order
     * @return array<int, array<string,mixed>> the same rows plus state, and why
     */
    public function annotate(int $userId, int $lessonId, array $stages): array
    {
        $progressByStage = $this->progress->forLesson($userId, $lessonId);

        $annotated       = [];
        $previousDone    = true;   // the first stage has nothing in front of it
        $previousTitle   = null;

        foreach ($stages as $stage) {
            $stageId  = (int) $stage['id'];
            $progress = $progressByStage[$stageId] ?? null;
            $done     = $progress !== null && $progress['status'] === 'completed';

            $blockingExam = $this->blockingExamFor($userId, $stageId);

            if ($done) {
                $state  = self::COMPLETED;
                $reason = null;
            } elseif (!$previousDone) {
                $state  = self::LOCKED;
                $reason = $previousTitle === null
                    ? 'ابتدا مرحله قبل را کامل کن.'
                    : 'ابتدا «' . $previousTitle . '» را کامل کن.';
            } elseif ($blockingExam !== null) {
                // The previous stage is finished, but an exam stands in front
                // of this one and has not been passed yet.
                $state  = self::LOCKED;
                $reason = 'برای باز شدن این مرحله باید آزمون «' . $blockingExam['title'] . '» را قبول شوی.';
            } elseif ($progress !== null) {
                $state  = self::IN_PROGRESS;
                $reason = null;
            } else {
                $state  = self::AVAILABLE;
                $reason = null;
            }

            $stage['state']         = $state;
            $stage['lock_reason']   = $reason;
            $stage['gating_exam']   = $blockingExam;
            $stage['progress']      = $progress;
            $annotated[]            = $stage;

            $previousDone  = $done;
            $previousTitle = (string) $stage['title'];
        }

        return $annotated;
    }

    /**
     * The single authoritative check, used by the routes that actually serve
     * stage content. The map is a view of this; this is the control.
     */
    public function isUnlocked(int $userId, array $stage): bool
    {
        $state = $this->stateFor($userId, $stage);
        return $state !== self::LOCKED;
    }

    public function stateFor(int $userId, array $stage): string
    {
        $stageId  = (int) $stage['id'];
        $lessonId = (int) $stage['lesson_id'];

        $progress = $this->progress->find($userId, $stageId);
        if ($progress !== null && $progress['status'] === 'completed') {
            return self::COMPLETED;
        }

        $previous = $this->previousStage($lessonId, (int) $stage['display_order'], $stageId);
        if ($previous !== null && !$this->progress->isCompleted($userId, (int) $previous['id'])) {
            return self::LOCKED;
        }

        if ($this->blockingExamFor($userId, $stageId) !== null) {
            return self::LOCKED;
        }

        return $progress !== null ? self::IN_PROGRESS : self::AVAILABLE;
    }

    /**
     * A published, gating exam positioned before this stage that the student
     * has not passed. Null when the way is clear.
     */
    private function blockingExamFor(int $userId, int $stageId): ?array
    {
        foreach ($this->checkpoints->gatingExamsForStage($stageId) as $exam) {
            if (!$this->checkpoints->hasPassed($userId, (int) $exam['id'])) {
                return $exam;
            }
        }

        return null;
    }

    /** The published stage immediately before this one in the same lesson. */
    private function previousStage(int $lessonId, int $displayOrder, int $stageId): ?array
    {
        $rows = (new \HeleXa\Models\Balin\BalinStageRepository())->forLesson($lessonId, true);

        $previous = null;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $stageId) {
                return $previous;
            }
            $previous = $row;
        }

        return null;
    }
}
