<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\Balin\BalinCheckpointRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\Balin\BalinProgressRepository;
use HeleXa\Models\Balin\BalinQuestionRepository;
use HeleXa\Models\Balin\BalinStageRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Balin\AnswerService;
use HeleXa\Services\Balin\ProfileService;
use HeleXa\Services\Balin\StageGate;
use HeleXa\Services\Balin\StageService;

/**
 * The student's journey through the island: the map, a lesson, a stage, and
 * answering the questions inside one.
 *
 * BalinAccessMiddleware has already decided the student may be here, so
 * nothing in this class re-checks publication. What it does check, on every
 * request, is whether this particular stage is unlocked for this particular
 * student — because that is a per-row question the middleware cannot answer.
 */
final class BalinController extends Controller
{
    public function __construct(
        private readonly BalinLessonRepository $lessons = new BalinLessonRepository(),
        private readonly BalinStageRepository $stages = new BalinStageRepository(),
        private readonly BalinCheckpointRepository $checkpoints = new BalinCheckpointRepository(),
        private readonly BalinProgressRepository $progress = new BalinProgressRepository(),
        private readonly StageGate $gate = new StageGate(),
        private readonly StageService $stageService = new StageService(),
    ) {
    }

    /** The island: every published lesson, with how far the student has got. */
    public function index(Request $request, array $params = []): Response
    {
        $userId  = (int) Auth::id();
        $profile = (new ProfileService())->forStudent($userId);

        return $this->page('layouts.app', 'student.balin.island', [
            'title'   => 'جزیره بالین',
            'lessons' => $this->lessons->publishedForStudent($userId),
            'profile' => $profile,
        ]);
    }

    /** One lesson's map: its stages in order, each locked or open. */
    public function lesson(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $lesson = $this->publishedLesson((string) ($params['uuid'] ?? ''));

        $stages = $this->gate->annotate(
            $userId,
            (int) $lesson['id'],
            $this->stages->forLesson((int) $lesson['id'], true)
        );

        return $this->page('layouts.app', 'student.balin.lesson', [
            'title'   => $lesson['title'],
            'lesson'  => $lesson,
            'stages'  => $stages,
            'exams'   => $this->examsByAnchor((int) $lesson['id'], $userId),
            'summary' => $this->stageService->lessonSummary($userId, (int) $lesson['id']),
        ]);
    }

    /** Play a stage. Refuses outright if the student has not unlocked it. */
    public function stage(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $stage  = $this->openStage((string) ($params['uuid'] ?? ''), $userId);
        $lesson = $this->lessons->findById((int) $stage['lesson_id']);

        $this->progress->start($userId, (int) $stage['lesson_id'], (int) $stage['id']);

        $state = $this->gate->stateFor($userId, $stage);

        return $this->page('layouts.app', 'student.balin.stage', [
            'title'        => $stage['title'],
            'balinScript'  => true,
            'lesson'       => $lesson,
            'stage'        => $stage,
            'blocks'       => $this->stageService->playableBlocks($userId, $stage),
            'state'        => $state,
            // A finished stage is readable again, but nothing in it can be
            // answered a second time and nothing pays out again.
            'replay'       => $state === StageGate::COMPLETED,
            'requirements' => $this->stageService->requirementsMet($userId, $stage),
        ]);
    }

    /**
     * Answering a question.
     *
     * The response says what was right and why, which is the teaching part;
     * it is produced after the answer is recorded, never before, so the key
     * cannot be read out of a request that is then abandoned.
     */
    public function answer(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $stage  = $this->openStage((string) ($params['uuid'] ?? ''), $userId);

        $questionId = $request->int('question_id');
        $optionId   = $request->int('option_id');
        $usedHint   = $request->bool('used_hint');

        $questions = new BalinQuestionRepository();
        $question  = $questions->findById($questionId);

        // Membership comes from the block that presents the question, not from
        // the question's own stage_id: a question written into the bank and
        // then placed in a stage legitimately has no stage_id of its own.
        if ($question === null
            || !(new \HeleXa\Models\Balin\BalinBlockRepository())->hasQuestion((int) $stage['id'], $questionId)) {
            return $this->json([
                'ok'      => false,
                'error'   => 'QUESTION_NOT_IN_STAGE',
                'message' => 'این سؤال بخشی از این مرحله نیست.',
            ], 422);
        }
        if ($optionId <= 0) {
            return $this->json([
                'ok'      => false,
                'error'   => 'NO_OPTION',
                'message' => 'یک گزینه را انتخاب کن.',
            ], 422);
        }

        $result = (new AnswerService())->submit(
            $userId,
            $question,
            $optionId,
            $usedHint,
            (int) $stage['id'],
            null,
            $this->idempotencyKey($request)
        );

        $this->progress->touch($userId, (int) $stage['id']);

        return $this->json([
            'ok'                => true,
            'stored'            => $result['stored'],
            'already_answered'  => $result['reason'] === 'ALREADY_ANSWERED',
            'is_correct'        => $result['is_correct'],
            'correct_option_id' => $result['correct_option_id'],
            'explanation'       => $result['explanation'],
            'xp_awarded'        => $result['xp_awarded'],
            'levelled_up'       => $result['levelled_up'],
            'level'             => $result['level_after'],
            'rank'              => $result['levelled_up']
                                      ? (new ProfileService())->rankFor($result['level_after'])['title']
                                      : null,
            'requirements_met'  => $this->stageService->requirementsMet($userId, $stage),
        ]);
    }

    /** Finish a stage and collect what it is worth. */
    public function complete(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $stage  = $this->openStage((string) ($params['uuid'] ?? ''), $userId);

        $result = $this->stageService->complete($userId, $stage);

        if (!$result['completed']) {
            $this->flash('error', (string) $result['blocked']);
            return $this->redirect('/student/balin/stage/' . $stage['uuid']);
        }

        if ($result['first_time']) {
            ActivityLogger::log('balin.stage.completed', $userId, 'balin_stage', (int) $stage['id'],
                ['xp' => $result['xp_awarded']], 'info', $request);
        }

        $lesson = $this->lessons->findById((int) $stage['lesson_id']);

        return $this->page('layouts.app', 'student.balin.complete', [
            'title'   => 'مرحله کامل شد',
            'stage'   => $stage,
            'lesson'  => $lesson,
            'result'  => $result,
            'summary' => $this->stageService->lessonSummary($userId, (int) $stage['lesson_id']),
            'profile' => (new ProfileService())->forStudent($userId),
        ]);
    }

    // ------------------------------------------------------------- helpers

    private function publishedLesson(string $uuid): array
    {
        $lesson = $this->lessons->findByUuid($uuid);

        if ($lesson === null || $lesson['status'] !== 'published') {
            throw HttpException::notFound();
        }

        return $lesson;
    }

    /**
     * Loads a stage and proves this student may open it.
     *
     * This is the check that matters: the map shows what is open, but a
     * request that names a locked stage directly is stopped here, on the
     * server, before any of its content is read.
     */
    private function openStage(string $uuid, int $userId): array
    {
        $stage = $this->stages->findByUuid($uuid);

        if ($stage === null || $stage['status'] !== 'published') {
            throw HttpException::notFound();
        }

        $lesson = $this->lessons->findById((int) $stage['lesson_id']);
        if ($lesson === null || $lesson['status'] !== 'published') {
            throw HttpException::notFound();
        }

        if (!$this->gate->isUnlocked($userId, $stage)) {
            throw HttpException::forbidden('این مرحله هنوز برای تو باز نشده است.');
        }

        return $stage;
    }

    /**
     * Exams grouped by the stage they sit against, so the map can draw them
     * between the stages rather than in a list of their own.
     *
     * @return array<string, array<int, array<string,mixed>>>
     */
    private function examsByAnchor(int $lessonId, int $userId): array
    {
        $grouped = ['before' => [], 'after' => [], 'end' => []];

        foreach ($this->checkpoints->forLesson($lessonId, true) as $exam) {
            $exam['passed']        = $this->checkpoints->hasPassed($userId, (int) $exam['id']);
            $exam['attempts_used'] = $this->checkpoints->countAttempts($userId, (int) $exam['id']);

            $key = match ($exam['position_type']) {
                'before_stage' => 'before',
                'after_stage'  => 'after',
                default        => 'end',
            };

            $anchor = $exam['anchor_stage_id'] === null ? 0 : (int) $exam['anchor_stage_id'];
            $grouped[$key][$anchor][] = $exam;
        }

        return $grouped;
    }

    /**
     * An optional client-supplied key that makes a resubmitted request a
     * no-op instead of a second answer. Hashed so a hostile value cannot be
     * anything but sixty-four hex characters by the time it reaches SQL.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $header = $request->header('Idempotency-Key');

        return $header === null || $header === '' ? null : hash('sha256', $header);
    }
}
