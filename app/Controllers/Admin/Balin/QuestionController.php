<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Balin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\Balin\BalinAnswerRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\Balin\BalinQuestionRepository;
use HeleXa\Models\Balin\BalinSkillTrackRepository;
use HeleXa\Models\Balin\BalinStageRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * Writing questions, their options, and the skills they measure.
 *
 * The skill tags are what make a question count toward a clinical skill
 * track. A question with no tags still works perfectly well inside a stage —
 * it simply contributes to no track, which is the intended behaviour rather
 * than an oversight.
 */
final class QuestionController extends Controller
{
    private const STALE = 'این سؤال توسط مدیر دیگری تغییر کرده است. صفحه را دوباره بارگذاری کن.';

    public function __construct(
        private readonly BalinQuestionRepository $questions = new BalinQuestionRepository(),
        private readonly BalinLessonRepository $lessons = new BalinLessonRepository(),
        private readonly BalinStageRepository $stages = new BalinStageRepository(),
        private readonly BalinSkillTrackRepository $tracks = new BalinSkillTrackRepository(),
    ) {
    }

    public function create(Request $request, array $params = []): Response
    {
        $lesson = $this->lesson((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.balin.questions.form', [
            'title'    => 'سؤال جدید',
            'lesson'   => $lesson,
            'question' => null,
            'options'  => [],
            'tagged'   => [],
            'stages'   => $this->stages->forLesson((int) $lesson['id']),
            'tracks'   => $this->tracks->all(true),
            'old'      => [],
            'errors'   => [],
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $lesson  = $this->lesson((string) ($params['uuid'] ?? ''));
        $data    = $this->collect($request);
        $options = $this->collectOptions($request);
        $errors  = $this->validate($data, $options);

        if ($errors !== []) {
            return $this->page('layouts.app', 'admin.balin.questions.form', [
                'title'    => 'سؤال جدید',
                'lesson'   => $lesson,
                'question' => null,
                'options'  => $options,
                'tagged'   => $this->collectTracks($request),
                'stages'   => $this->stages->forLesson((int) $lesson['id']),
                'tracks'   => $this->tracks->all(true),
                'old'      => $data,
                'errors'   => $errors,
            ], 422);
        }

        $id = $this->questions->createWithOptions(
            $data + ['uuid' => Str::uuid4(), 'lesson_id' => (int) $lesson['id']],
            $options,
            $this->collectTracks($request)
        );

        ActivityLogger::log('balin.question.created', Auth::id(), 'balin_question', $id,
            ['lesson' => (int) $lesson['id']], 'notice', $request);
        $this->flash('success', 'سؤال ساخته شد.');

        return $this->redirect('/admin/balin/lessons/' . $lesson['uuid'] . '#questions');
    }

    public function edit(Request $request, array $params = []): Response
    {
        $question = $this->find((string) ($params['uuid'] ?? ''));
        $lesson   = $this->lessons->findById((int) $question['lesson_id']);

        return $this->page('layouts.app', 'admin.balin.questions.form', [
            'title'    => 'ویرایش سؤال',
            'lesson'   => $lesson,
            'question' => $question,
            'options'  => $this->questions->options((int) $question['id']),
            'tagged'   => $this->questions->skillTrackIds((int) $question['id']),
            'stages'   => $this->stages->forLesson((int) $question['lesson_id']),
            'tracks'   => $this->tracks->all(true),
            'stats'    => (new BalinAnswerRepository())->questionStats((int) $question['id']),
            'old'      => [],
            'errors'   => [],
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $question = $this->find((string) ($params['uuid'] ?? ''));
        $lesson   = $this->lessons->findById((int) $question['lesson_id']);
        $data     = $this->collect($request);
        $options  = $this->collectOptions($request);
        $errors   = $this->validate($data, $options);

        if ($errors !== []) {
            return $this->page('layouts.app', 'admin.balin.questions.form', [
                'title'    => 'ویرایش سؤال',
                'lesson'   => $lesson,
                'question' => $question,
                'options'  => $options,
                'tagged'   => $this->collectTracks($request),
                'stages'   => $this->stages->forLesson((int) $question['lesson_id']),
                'tracks'   => $this->tracks->all(true),
                'old'      => $data,
                'errors'   => $errors,
            ], 422);
        }

        $saved = $this->questions->updateWithOptions(
            (int) $question['id'],
            $data,
            $options,
            $this->collectTracks($request),
            $request->int('version')
        );

        $this->flash($saved ? 'success' : 'error', $saved ? 'سؤال ذخیره شد.' : self::STALE);

        return $this->redirect('/admin/balin/lessons/' . $lesson['uuid'] . '#questions');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $question = $this->find((string) ($params['uuid'] ?? ''));
        $lesson   = $this->lessons->findById((int) $question['lesson_id']);

        $this->questions->delete((int) $question['id']);

        ActivityLogger::log('balin.question.deleted', Auth::id(), 'balin_question', (int) $question['id'],
            [], 'warning', $request);
        $this->flash('success', 'سؤال حذف شد.');

        return $this->redirect('/admin/balin/lessons/' . $lesson['uuid'] . '#questions');
    }

    // ------------------------------------------------------------- helpers

    private function find(string $uuid): array
    {
        $question = $this->questions->findByUuid($uuid);

        if ($question === null) {
            throw HttpException::notFound();
        }

        return $question;
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
        $difficulty = $request->string('difficulty', 'medium');

        return [
            'stage_id'           => $request->int('stage_id') ?: null,
            'prompt'             => trim($request->string('prompt')),
            'explanation'        => trim($request->string('explanation')) ?: null,
            'hint'               => trim($request->string('hint')) ?: null,
            'difficulty'         => in_array($difficulty, ['easy', 'medium', 'hard', 'expert'], true)
                                        ? $difficulty
                                        : 'medium',
            'xp_reward'          => max(0, $request->int('xp_reward', 10)),
            'is_required'        => $request->bool('is_required'),
            'is_final_case_step' => $request->bool('is_final_case_step'),
            'status'             => in_array($request->string('status'), ['draft', 'published', 'archived'], true)
                                        ? $request->string('status')
                                        : 'published',
        ];
    }

    /** @return array<int, array{label:string, body:string, is_correct:bool}> */
    private function collectOptions(Request $request): array
    {
        $bodies  = (array) $request->input('option_body', []);
        $correct = (int) $request->input('correct_option', -1);

        $options = [];
        $index   = 0;

        foreach ($bodies as $key => $body) {
            $body = trim((string) $body);
            if ($body === '') {
                continue;
            }
            $options[] = [
                'label'      => chr(65 + $index),
                'body'       => $body,
                'is_correct' => (int) $key === $correct,
            ];
            $index++;
        }

        return $options;
    }

    /** @return array<int,int> */
    private function collectTracks(Request $request): array
    {
        return array_values(array_filter(
            array_map('intval', (array) $request->input('skill_track_ids', [])),
            static fn (int $id): bool => $id > 0
        ));
    }

    private function validate(array $data, array $options): array
    {
        $errors = [];

        if ($data['prompt'] === '') {
            $errors['prompt'] = 'متن سؤال الزامی است.';
        }
        if (count($options) < 2) {
            $errors['options'] = 'حداقل دو گزینه لازم است.';
        }

        // Without exactly one correct option the question is unscoreable, so
        // it is refused here rather than failing silently at answer time.
        $correct = array_filter($options, static fn (array $o): bool => $o['is_correct']);
        if (count($correct) !== 1) {
            $errors['correct'] = 'دقیقاً یک گزینه باید به‌عنوان پاسخ صحیح علامت بخورد.';
        }

        return $errors;
    }
}
