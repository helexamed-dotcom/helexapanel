<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\QuestionBank\QbAccessRepository;
use HeleXa\Models\QuestionBank\QbMyExamRepository;
use HeleXa\Models\QuestionBank\QbPracticeRepository;
use HeleXa\Models\QuestionBank\QbQuestionRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\QuestionBank\QbAccess;
use HeleXa\Services\QuestionBank\QbXp;

/**
 * «آزمون‌های من»: exams a student builds from the questions they collected.
 *
 * The answer sheet is saved one tick at a time, so a dropped connection or
 * a closed tab loses nothing; the key is loaded only once the exam is
 * finished. Finishing also records each answer as an ordinary practice
 * attempt, so the bank's own history ("غلط جواب داده", accuracy, XP) knows
 * about it.
 */
final class MyExamController extends Controller
{
    /** Seconds past the deadline still accepted — the submit takes a moment. */
    private const GRACE_SECONDS = 45;
    private const MAX_QUESTIONS = 200;

    private QbMyExamRepository $exams;

    public function __construct()
    {
        $this->exams = new QbMyExamRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $userId = $this->gate();
        if ($userId instanceof Response) {
            return $userId;
        }
        $rows   = $this->exams->collection($userId, $this->subjectIds($userId));

        return $this->page('layouts.app', 'student.myexams.index', [
            'title'       => 'آزمون‌های من',
            'tree'        => QbMyExamRepository::tree($rows),
            'total'       => count($rows),
            'history'     => $this->exams->history($userId),
            'performance' => $this->exams->performance($userId),
        ]);
    }

    public function start(Request $request, array $params = []): Response
    {
        $userId = $this->gate();
        if ($userId instanceof Response) {
            return $userId;
        }
        $rows   = $this->exams->collection($userId, $this->subjectIds($userId));

        $scope     = $request->string('scope', 'all');
        $subjectId = ctype_digit($scope) ? (int) $scope : null;
        $subs      = array_map('intval', (array) ($request->input('subs') ?? []));

        $picked = array_values(array_filter($rows, static function (array $row) use ($subjectId, $subs): bool {
            if ($subjectId !== null && (int) $row['subject_id'] !== $subjectId) {
                return false;
            }
            if ($subs !== [] && !in_array((int) ($row['sub_subject_id'] ?? 0), $subs, true)) {
                return false;
            }
            return true;
        }));

        if ($picked === []) {
            $this->flash('error', 'در این بخش هنوز سوالی به «آزمون‌های من» اضافه نکرده‌ای.');
            return $this->redirect('/student/my-exams');
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $picked);
        shuffle($ids);

        $count = $request->int('count');
        if ($count > 0) {
            $ids = array_slice($ids, 0, $count);
        }
        $ids = array_slice($ids, 0, self::MAX_QUESTIONS);

        $minutes = $request->bool('timed') ? max(1, min(600, $request->int('minutes', count($ids)))) : 0;

        $title = $subjectId !== null ? (string) $picked[0]['subject_title'] : 'آزمون ترکیبی';
        if ($subjectId !== null && count($subs) === 1) {
            foreach ($picked as $row) {
                if ((int) ($row['sub_subject_id'] ?? 0) === $subs[0] && !empty($row['sub_title'])) {
                    $title .= ' — ' . $row['sub_title'];
                    break;
                }
            }
        }

        $exam = $this->exams->create($userId, $title, $subjectId, $ids, $request->bool('negative'), $minutes);

        return $this->redirect('/student/my-exams/' . $exam['uuid']);
    }

    /** The answer sheet while it is open, the report card once it is finished. */
    public function show(Request $request, array $params = []): Response
    {
        $userId = $this->gate();
        if ($userId instanceof Response) {
            return $userId;
        }
        $exam   = $this->examOr404((string) ($params['uuid'] ?? ''), $userId);

        if ($exam['status'] === 'in_progress' && $this->expired($exam)) {
            $this->close($exam, $userId);
            $exam = $this->examOr404((string) $exam['uuid'], $userId);
        }

        $finished  = $exam['status'] === 'finished';
        $questions = $this->exams->questions($exam['question_ids'], $finished);
        $answers   = $this->exams->answers((int) $exam['id']);

        if (!$finished) {
            return $this->page('layouts.app', 'student.myexams.take', [
                'title'     => $exam['title'],
                'exam'      => $exam,
                'questions' => $questions,
                'answers'   => $answers,
                'deadline'  => (int) $exam['duration_minutes'] > 0
                    ? strtotime((string) $exam['started_at']) + (int) $exam['duration_minutes'] * 60 : 0,
            ]);
        }

        $performance = $this->exams->performance($userId, (int) $exam['id']);

        return $this->page('layouts.app', 'student.myexams.result', [
            'title'        => 'کارنامه — ' . $exam['title'],
            'exam'         => $exam,
            'questions'    => $questions,
            'answers'      => $answers,
            'performance'  => $performance,
            'advice'       => \HeleXa\Services\ExamAdvisor::advise($questions, $answers, $performance),
            'difficulties' => QbQuestionRepository::DIFFICULTY_LABELS,
        ]);
    }

    /** Saves one tick while the exam is open (JSON). */
    public function answer(Request $request, array $params = []): Response
    {
        $userId = $this->gate();
        if ($userId instanceof Response) {
            return $userId;
        }
        $exam   = $this->examOr404((string) ($params['uuid'] ?? ''), $userId);

        if ($exam['status'] !== 'in_progress' || $this->expired($exam, self::GRACE_SECONDS)) {
            return $this->json(['ok' => false, 'message' => 'زمان این آزمون تمام شده است.'], 409);
        }

        $questionId = $request->int('question');
        if (!in_array($questionId, $exam['question_ids'], true)) {
            return $this->json(['ok' => false, 'message' => 'این سوال در این آزمون نیست.'], 422);
        }

        $optionUuid = $request->string('option');
        $optionId   = $optionUuid === '' ? null : $this->exams->optionIdFor($questionId, $optionUuid);
        if ($optionUuid !== '' && $optionId === null) {
            return $this->json(['ok' => false, 'message' => 'گزینه نامعتبر است.'], 422);
        }

        $this->exams->saveAnswer((int) $exam['id'], $questionId, $optionId);

        return $this->json(['ok' => true]);
    }

    public function finish(Request $request, array $params = []): Response
    {
        $userId = $this->gate();
        if ($userId instanceof Response) {
            return $userId;
        }
        $exam   = $this->examOr404((string) ($params['uuid'] ?? ''), $userId);

        if ($exam['status'] === 'in_progress') {
            // Whatever the form carries is saved first — the whole sheet
            // arrives here when JavaScript is off, and the last tick before a
            // timeout arrives here in any case.
            if (!$this->expired($exam, self::GRACE_SECONDS)) {
                $posted = $request->input('a');
                if (is_array($posted)) {
                    foreach ($posted as $questionId => $optionUuid) {
                        $questionId = (int) $questionId;
                        if (!in_array($questionId, $exam['question_ids'], true) || !is_string($optionUuid) || $optionUuid === '') {
                            continue;
                        }
                        $optionId = $this->exams->optionIdFor($questionId, $optionUuid);
                        if ($optionId !== null) {
                            $this->exams->saveAnswer((int) $exam['id'], $questionId, $optionId);
                        }
                    }
                }
            }
            $this->close($exam, $userId);
        }

        return $this->redirect('/student/my-exams/' . $exam['uuid']);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $userId = $this->gate();
        if ($userId instanceof Response) {
            return $userId;
        }
        $exam   = $this->examOr404((string) ($params['uuid'] ?? ''), $userId);
        $this->exams->delete((int) $exam['id'], $userId);
        $this->flash('success', 'آزمون حذف شد.');

        return $this->redirect('/student/my-exams');
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Grades, then records every answered question as a practice attempt
     * (with XP on a first correct answer, exactly as in practice mode).
     */
    private function close(array $exam, int $userId): void
    {
        $this->exams->finish($exam);

        $practice = new QbPracticeRepository();
        $answers  = $this->exams->answers((int) $exam['id']);
        foreach ($this->exams->questions($exam['question_ids'], false) as $question) {
            $answer = $answers[(int) $question['id']] ?? null;
            if ($answer === null || $answer['option_id'] === null) {
                continue;
            }
            $first   = !$practice->hasAttempted($userId, (int) $question['id']);
            $correct = (int) $answer['is_correct'] === 1;
            $practice->recordAttempt($userId, (int) $question['id'], $answer['option_id'], (int) $question['subject_id'], $correct);
            if ($correct && $first) {
                QbXp::award($userId, (int) $question['id'], (string) $question['difficulty']);
            }
        }

        // Finishing an exam is worth something in itself, once per exam.
        \HeleXa\Services\Points::award($userId, \HeleXa\Services\Points::amount('exam_finished', 20), 'exam_finished',
            'qb_my_exam', (int) $exam['id'], 'exam_finished:' . $userId . ':' . $exam['id']);
    }

    private function expired(array $exam, int $grace = 0): bool
    {
        $minutes = (int) $exam['duration_minutes'];
        return $minutes > 0 && time() > strtotime((string) $exam['started_at']) + $minutes * 60 + $grace;
    }

    /**
     * The bank's own gate: the student id, or — when the bank is closed to
     * this student — a redirect to the bank's page, which explains why,
     * rather than a bare 404.
     */
    private function gate(): int|Response
    {
        $userId = (int) Auth::id();
        if (!QbAccess::forStudent($userId)['allowed']) {
            return $this->redirect('/student/qbank');
        }
        return $userId;
    }

    /** @return array<int,int> */
    private function subjectIds(int $userId): array
    {
        return (new QbAccessRepository())->subjectIdsFor($userId);
    }

    private function examOr404(string $uuid, int $userId): array
    {
        $exam = $this->exams->find($uuid, $userId);
        if ($exam === null) {
            throw HttpException::notFound();
        }
        return $exam;
    }

    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['qbank' => true], $status);
    }
}
