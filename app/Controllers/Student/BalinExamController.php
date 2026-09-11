<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\Balin\BalinAnswerRepository;
use HeleXa\Models\Balin\BalinCheckpointRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\Balin\BalinQuestionRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Balin\CheckpointService;
use HeleXa\Services\Balin\ProfileService;

/**
 * Sitting a checkpoint exam.
 *
 * The intro page is always reachable — a student blocked by the attempt
 * limit or a cooldown needs to be told that, and when they may return. Only
 * starting and submitting are gated, and both decisions are made by
 * CheckpointService rather than here.
 */
final class BalinExamController extends Controller
{
    public function __construct(
        private readonly BalinCheckpointRepository $checkpoints = new BalinCheckpointRepository(),
        private readonly BalinLessonRepository $lessons = new BalinLessonRepository(),
        private readonly CheckpointService $exams = new CheckpointService(),
    ) {
    }

    /** What this exam is, and whether it can be taken right now. */
    public function show(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $exam   = $this->publishedExam((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'student.balin.exam_intro', [
            'title'    => $exam['title'],
            'exam'     => $exam,
            'lesson'   => $this->lessons->findById((int) $exam['lesson_id']),
            'gate'     => $this->exams->canStart($userId, $exam),
            'attempts' => $this->checkpoints->attemptsFor($userId, (int) $exam['id']),
            'open'     => $this->checkpoints->openAttempt($userId, (int) $exam['id']),
        ]);
    }

    /** Opens an attempt, or resumes one that was never finished. */
    public function start(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $exam   = $this->publishedExam((string) ($params['uuid'] ?? ''));

        $started = $this->exams->start($userId, $exam, false, $this->idempotencyKey($request));

        if ($started['blocked'] !== null) {
            $this->flash('error', $started['blocked']['message']);
            return $this->redirect('/student/balin/exam/' . $exam['uuid']);
        }

        if (!$started['resumed']) {
            ActivityLogger::log('balin.exam.started', $userId, 'balin_exam', (int) $exam['id'],
                ['attempt' => $started['attempt']['attempt_number']], 'info', $request);
        }

        return $this->redirect('/student/balin/exam/' . $exam['uuid'] . '/attempt');
    }

    /** The paper itself. */
    public function attempt(Request $request, array $params = []): Response
    {
        $userId  = (int) Auth::id();
        $exam    = $this->publishedExam((string) ($params['uuid'] ?? ''));
        $attempt = $this->checkpoints->openAttempt($userId, (int) $exam['id']);

        if ($attempt === null) {
            return $this->redirect('/student/balin/exam/' . $exam['uuid']);
        }

        $questions = (new BalinQuestionRepository())
            ->findMany($this->checkpoints->attemptQuestionIds($attempt));

        // Options are fetched without the answer key, so the correct choice
        // is never present in the page the student is looking at.
        $optionsRepository = new BalinQuestionRepository();
        $paper = [];
        foreach ($questions as $question) {
            $paper[] = [
                'question' => $question,
                'options'  => $optionsRepository->optionsForStudent((int) $question['id']),
            ];
        }

        return $this->page('layouts.app', 'student.balin.exam_attempt', [
            'title'   => $exam['title'],
            'exam'    => $exam,
            'lesson'  => $this->lessons->findById((int) $exam['lesson_id']),
            'attempt' => $attempt,
            'paper'   => $paper,
        ]);
    }

    /** Grades the paper and shows the breakdown. */
    public function submit(Request $request, array $params = []): Response
    {
        $userId  = (int) Auth::id();
        $exam    = $this->publishedExam((string) ($params['uuid'] ?? ''));
        $attempt = $this->checkpoints->openAttempt($userId, (int) $exam['id']);

        if ($attempt === null) {
            $this->flash('error', 'آزمون بازی برای ثبت پیدا نشد.');
            return $this->redirect('/student/balin/exam/' . $exam['uuid']);
        }

        $choices = [];
        foreach ((array) $request->input('answers', []) as $questionId => $optionId) {
            $questionId = (int) $questionId;
            $optionId   = (int) $optionId;
            if ($questionId > 0 && $optionId > 0) {
                $choices[$questionId] = $optionId;
            }
        }

        $result = $this->exams->submit($userId, $exam, $attempt, $choices);

        if (!$result['graded'] && $result['reason'] === 'ALREADY_SUBMITTED') {
            return $this->redirect('/student/balin/exam/' . $exam['uuid'] . '/result');
        }

        ActivityLogger::log('balin.exam.submitted', $userId, 'balin_exam', (int) $exam['id'],
            ['score' => $result['score_percent'], 'passed' => $result['passed']], 'info', $request);

        return $this->page('layouts.app', 'student.balin.exam_result', [
            'title'   => 'نتیجه آزمون',
            'exam'    => $exam,
            'lesson'  => $this->lessons->findById((int) $exam['lesson_id']),
            'result'  => $result,
            'skills'  => $this->exams->skillBreakdown($result['breakdown']),
            'gate'    => $this->exams->canStart($userId, $exam),
            'profile' => (new ProfileService())->forStudent($userId),
        ]);
    }

    /** The last graded attempt, read-only. */
    public function result(Request $request, array $params = []): Response
    {
        $userId  = (int) Auth::id();
        $exam    = $this->publishedExam((string) ($params['uuid'] ?? ''));
        $attempt = $this->checkpoints->latestAttempt($userId, (int) $exam['id']);

        if ($attempt === null || $attempt['completed_at'] === null) {
            return $this->redirect('/student/balin/exam/' . $exam['uuid']);
        }

        $answers   = (new BalinAnswerRepository())->forAttempt((int) $attempt['id']);
        $breakdown = [];

        foreach ($answers as $answer) {
            $questionId = (int) $answer['question_id'];
            $breakdown[] = [
                'question'          => ['id' => $questionId, 'prompt' => $answer['prompt'],
                                        'explanation' => $answer['explanation']],
                'chosen_option_id'  => $answer['option_id'] === null ? null : (int) $answer['option_id'],
                'correct_option_id' => (new BalinQuestionRepository())->correctOptionId($questionId),
                'is_correct'        => (int) $answer['is_correct'] === 1,
                'skill_track_ids'   => (new BalinQuestionRepository())->skillTrackIds($questionId),
            ];
        }

        return $this->page('layouts.app', 'student.balin.exam_result', [
            'title'  => 'نتیجه آزمون',
            'exam'   => $exam,
            'lesson' => $this->lessons->findById((int) $exam['lesson_id']),
            'result' => [
                'graded'        => true,
                'score_percent' => (float) $attempt['score_percent'],
                'correct'       => (int) $attempt['correct_count'],
                'total'         => (int) $attempt['total_count'],
                'passed'        => (int) $attempt['passed'] === 1,
                'xp_awarded'    => 0,
                'first_pass'    => false,
                'breakdown'     => $breakdown,
                'expired'       => false,
                'reason'        => null,
            ],
            'skills'  => $this->exams->skillBreakdown($breakdown),
            'gate'    => $this->exams->canStart($userId, $exam),
            'profile' => (new ProfileService())->forStudent($userId),
            'archived'=> true,
        ]);
    }

    private function publishedExam(string $uuid): array
    {
        $exam = $this->checkpoints->findByUuid($uuid);

        if ($exam === null || $exam['status'] !== 'published') {
            throw HttpException::notFound();
        }

        $lesson = $this->lessons->findById((int) $exam['lesson_id']);
        if ($lesson === null || $lesson['status'] !== 'published') {
            throw HttpException::notFound();
        }

        return $exam;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $header = $request->header('Idempotency-Key');

        return $header === null || $header === '' ? null : hash('sha256', $header);
    }
}
