<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Balin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\Balin\BalinCheckpointRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\Balin\BalinQuestionRepository;
use HeleXa\Models\Balin\BalinSkillTrackRepository;
use HeleXa\Models\Balin\BalinStageRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * Building the exams that sit between stages.
 *
 * The two source modes behave differently enough to be worth stating: a
 * fixed list is the exact questions chosen here, in order; a random pool
 * draws fresh each attempt from everything tagged with the exam's primary
 * skill, so a retake is not the same paper again.
 */
final class CheckpointController extends Controller
{
    private const STALE = 'این آزمون توسط مدیر دیگری تغییر کرده است. صفحه را دوباره بارگذاری کن.';

    public function __construct(
        private readonly BalinCheckpointRepository $checkpoints = new BalinCheckpointRepository(),
        private readonly BalinLessonRepository $lessons = new BalinLessonRepository(),
        private readonly BalinStageRepository $stages = new BalinStageRepository(),
        private readonly BalinSkillTrackRepository $tracks = new BalinSkillTrackRepository(),
    ) {
    }

    public function create(Request $request, array $params = []): Response
    {
        $lesson = $this->lesson((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.balin.exams.form', [
            'title'  => 'آزمون بین‌مرحله‌ای جدید',
            'lesson' => $lesson,
            'exam'   => null,
            'stages' => $this->stages->forLesson((int) $lesson['id']),
            'tracks' => $this->tracks->all(true),
            'old'    => [],
            'errors' => [],
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $lesson = $this->lesson((string) ($params['uuid'] ?? ''));
        $data   = $this->collect($request);
        $errors = $this->validate($data);

        if ($errors !== []) {
            return $this->page('layouts.app', 'admin.balin.exams.form', [
                'title'  => 'آزمون بین‌مرحله‌ای جدید',
                'lesson' => $lesson,
                'exam'   => null,
                'stages' => $this->stages->forLesson((int) $lesson['id']),
                'tracks' => $this->tracks->all(true),
                'old'    => $data,
                'errors' => $errors,
            ], 422);
        }

        $id = $this->checkpoints->create($data + [
            'uuid'      => Str::uuid4(),
            'lesson_id' => (int) $lesson['id'],
        ]);

        ActivityLogger::log('balin.exam.created', Auth::id(), 'balin_exam', $id,
            ['title' => $data['title']], 'notice', $request);
        $this->flash('success', 'آزمون ساخته شد. حالا سؤال‌هایش را تعیین کن.');

        return $this->redirect('/admin/balin/exams/' . $this->checkpoints->findById($id)['uuid']);
    }

    /** The exam workbench: its settings, its questions, its results. */
    public function show(Request $request, array $params = []): Response
    {
        $exam   = $this->find((string) ($params['uuid'] ?? ''));
        $lesson = $this->lessons->findById((int) $exam['lesson_id']);

        $poolCount = 0;
        if ($exam['question_source_mode'] === 'random_pool' && $exam['primary_skill_track_id'] !== null) {
            $poolCount = (new BalinQuestionRepository())
                ->countPooledForTrack((int) $exam['primary_skill_track_id']);
        }

        return $this->page('layouts.app', 'admin.balin.exams.show', [
            'title'      => $exam['title'],
            'exam'       => $exam,
            'lesson'     => $lesson,
            'stages'     => $this->stages->forLesson((int) $exam['lesson_id']),
            'tracks'     => $this->tracks->all(true),
            'attached'   => $this->checkpoints->fixedQuestions((int) $exam['id']),
            'available'  => (new BalinQuestionRepository())->forLesson((int) $exam['lesson_id']),
            'pool_count' => $poolCount,
            'stats'      => $this->checkpoints->examStatistics((int) $exam['id']),
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $exam   = $this->find((string) ($params['uuid'] ?? ''));
        $data   = $this->collect($request);
        $errors = $this->validate($data);

        if ($errors !== []) {
            $this->flash('error', implode(' ', $errors));
            return $this->redirect('/admin/balin/exams/' . $exam['uuid']);
        }

        $saved = $this->checkpoints->update((int) $exam['id'], $data, $request->int('version'));

        ActivityLogger::log('balin.exam.updated', Auth::id(), 'balin_exam', (int) $exam['id'],
            ['title' => $data['title']], 'notice', $request);
        $this->flash($saved ? 'success' : 'error', $saved ? 'آزمون ذخیره شد.' : self::STALE);

        return $this->redirect('/admin/balin/exams/' . $exam['uuid']);
    }

    public function setStatus(Request $request, array $params = []): Response
    {
        $exam   = $this->find((string) ($params['uuid'] ?? ''));
        $status = $request->string('status');

        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw HttpException::notFound();
        }

        $this->checkpoints->setStatus((int) $exam['id'], $status);

        ActivityLogger::log('balin.exam.status', Auth::id(), 'balin_exam', (int) $exam['id'],
            ['status' => $status], 'notice', $request);
        $this->flash('success', 'وضعیت آزمون تغییر کرد.');

        return $this->redirect('/admin/balin/exams/' . $exam['uuid']);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $exam   = $this->find((string) ($params['uuid'] ?? ''));
        $lesson = $this->lessons->findById((int) $exam['lesson_id']);

        $this->checkpoints->delete((int) $exam['id']);

        ActivityLogger::log('balin.exam.deleted', Auth::id(), 'balin_exam', (int) $exam['id'],
            ['title' => $exam['title']], 'warning', $request);
        $this->flash('success', 'آزمون حذف شد.');

        return $this->redirect('/admin/balin/lessons/' . $lesson['uuid'] . '#exams');
    }

    // ----------------------------------------------------------- questions

    public function attachQuestion(Request $request, array $params = []): Response
    {
        $exam       = $this->find((string) ($params['uuid'] ?? ''));
        $questionId = $request->int('question_id');

        $question = (new BalinQuestionRepository())->findById($questionId);
        if ($question === null || (int) $question['lesson_id'] !== (int) $exam['lesson_id']) {
            $this->flash('error', 'این سؤال به همین درس تعلق ندارد.');
            return $this->redirect('/admin/balin/exams/' . $exam['uuid']);
        }

        $order = count($this->checkpoints->fixedQuestions((int) $exam['id'])) + 1;
        $this->checkpoints->attachQuestion((int) $exam['id'], $questionId, $order);

        $this->flash('success', 'سؤال به آزمون اضافه شد.');

        return $this->redirect('/admin/balin/exams/' . $exam['uuid']);
    }

    public function detachQuestion(Request $request, array $params = []): Response
    {
        $exam = $this->find((string) ($params['uuid'] ?? ''));

        $this->checkpoints->detachQuestion((int) $exam['id'], $request->int('question_id'));
        $this->flash('success', 'سؤال از آزمون حذف شد.');

        return $this->redirect('/admin/balin/exams/' . $exam['uuid']);
    }

    /**
     * Clears one student's attempt history so they may sit the exam again.
     * Logged, because it overrides a limit that exists for a reason.
     */
    public function resetAttempts(Request $request, array $params = []): Response
    {
        $exam   = $this->find((string) ($params['uuid'] ?? ''));
        $userId = $request->int('user_id');

        $student = (new UserRepository())->findById($userId);
        if ($student === null) {
            $this->flash('error', 'دانشجو پیدا نشد.');
            return $this->redirect('/admin/balin/exams/' . $exam['uuid']);
        }

        $removed = $this->checkpoints->resetAttempts($userId, (int) $exam['id']);

        ActivityLogger::log('balin.exam.attempts_reset', Auth::id(), 'balin_exam', (int) $exam['id'],
            ['student' => $userId, 'removed' => $removed], 'warning', $request);
        $this->flash('success', 'تلاش‌های این دانشجو برای این آزمون پاک شد.');

        return $this->redirect('/admin/balin/exams/' . $exam['uuid']);
    }

    // ------------------------------------------------------------- helpers

    private function find(string $uuid): array
    {
        $exam = $this->checkpoints->findByUuid($uuid);

        if ($exam === null) {
            throw HttpException::notFound();
        }

        return $exam;
    }

    private function lesson(string $uuid): array
    {
        $lesson = $this->lessons->findByUuid($uuid);

        if ($lesson === null) {
            throw HttpException::notFound();
        }

        return $lesson;
    }

    private function collect(Request $request): array
    {
        $position = $request->string('position_type', 'after_stage');
        $mode     = $request->string('question_source_mode', 'fixed_list');
        $attempts = $request->int('max_attempts');

        return [
            'title'                  => trim($request->string('title')),
            'description'            => trim($request->string('description')) ?: null,
            'position_type'          => in_array($position, ['before_stage', 'after_stage', 'after_lesson'], true)
                                            ? $position
                                            : 'after_stage',
            'anchor_stage_id'        => $position === 'after_lesson' ? null : ($request->int('anchor_stage_id') ?: null),
            'primary_skill_track_id' => $request->int('primary_skill_track_id') ?: null,
            'secondary_skill_track_ids' => array_values(array_filter(
                array_map('intval', (array) $request->input('secondary_skill_track_ids', [])),
                static fn (int $id): bool => $id > 0
            )),
            'question_source_mode'   => $mode === 'random_pool' ? 'random_pool' : 'fixed_list',
            'num_questions'          => max(1, min(100, $request->int('num_questions', 10))),
            'pass_threshold_percent' => max(1, min(100, $request->int('pass_threshold_percent', 70))),
            'is_gating'              => $request->bool('is_gating'),
            // Blank means unlimited, which is a real choice and not the same
            // as zero — zero would lock everyone out immediately.
            'max_attempts'           => $attempts > 0 ? $attempts : null,
            'cooldown_hours_between_attempts' => max(0, $request->int('cooldown_hours', 24)),
            'time_limit_minutes'     => $request->int('time_limit_minutes') ?: null,
            'xp_reward'              => max(0, $request->int('xp_reward', 50)),
            'display_order'          => max(1, $request->int('display_order', 1000)),
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];

        if ($data['title'] === '') {
            $errors['title'] = 'عنوان آزمون الزامی است.';
        }

        // A random pool has nothing to draw from without a skill to draw by.
        if ($data['question_source_mode'] === 'random_pool' && $data['primary_skill_track_id'] === null) {
            $errors['track'] = 'برای حالت استخر تصادفی باید یک مهارت اصلی انتخاب کنی.';
        }

        // A gating exam that is not attached to a stage gates nothing.
        if ($data['is_gating'] && $data['position_type'] === 'before_stage' && $data['anchor_stage_id'] === null) {
            $errors['anchor'] = 'آزمون دروازه‌ای باید به یک مرحله متصل باشد.';
        }
        if ($data['position_type'] !== 'after_lesson' && $data['anchor_stage_id'] === null) {
            $errors['anchor'] = 'مرحله مرجع را انتخاب کن.';
        }

        return $errors;
    }
}
