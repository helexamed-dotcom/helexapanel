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
use HeleXa\Models\Balin\BalinStageRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\ImageAssetStorage;

/**
 * Clinical lessons and the stages inside them.
 *
 * Saves go through optimistic locking: the form carries the version it was
 * rendered from, and an update whose version no longer matches is refused
 * with an explanation rather than overwriting whatever the other admin
 * wrote in the meantime.
 */
final class LessonController extends Controller
{
    private const STALE = 'این محتوا توسط مدیر دیگری تغییر کرده است. صفحه را دوباره بارگذاری کن و تغییراتت را اعمال کن.';

    public function __construct(
        private readonly BalinLessonRepository $lessons = new BalinLessonRepository(),
        private readonly BalinStageRepository $stages = new BalinStageRepository(),
        private readonly BalinCheckpointRepository $checkpoints = new BalinCheckpointRepository(),
    ) {
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.balin.lessons.index', [
            'title'   => 'درس‌های بالینی',
            'lessons' => $this->lessons->all(),
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.balin.lessons.form', [
            'title'  => 'درس جدید',
            'lesson' => null,
            'old'    => [],
            'errors' => [],
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $data   = $this->collect($request);
        $errors = $this->validate($data, null);

        if ($errors !== []) {
            return $this->page('layouts.app', 'admin.balin.lessons.form', [
                'title'  => 'درس جدید',
                'lesson' => null,
                'old'    => $data,
                'errors' => $errors,
            ], 422);
        }

        $data['cover_path'] = $this->storeCover($request, null);

        $id = $this->lessons->create($data + [
            'uuid'       => Str::uuid4(),
            'created_by' => Auth::id(),
        ]);

        ActivityLogger::log('balin.lesson.created', Auth::id(), 'balin_lesson', $id,
            ['title' => $data['title']], 'notice', $request);
        $this->flash('success', 'درس ساخته شد. حالا مرحله‌ها را اضافه کن.');

        return $this->redirect('/admin/balin/lessons/' . $this->lessons->findById($id)['uuid']);
    }

    /** The lesson workbench: its stages, its exams, its questions. */
    public function show(Request $request, array $params = []): Response
    {
        $lesson = $this->find((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.balin.lessons.show', [
            'title'     => $lesson['title'],
            'lesson'    => $lesson,
            'stages'    => $this->stages->forLesson((int) $lesson['id']),
            'exams'     => $this->checkpoints->forLesson((int) $lesson['id']),
            'questions' => (new BalinQuestionRepository())->forLesson((int) $lesson['id']),
            'learners'  => $this->lessons->studentsWithProgress((int) $lesson['id']),
        ]);
    }

    public function edit(Request $request, array $params = []): Response
    {
        $lesson = $this->find((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.balin.lessons.form', [
            'title'    => 'ویرایش درس',
            'lesson'   => $lesson,
            'old'      => [],
            'errors'   => [],
            'learners' => $this->lessons->studentsWithProgress((int) $lesson['id']),
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $lesson = $this->find((string) ($params['uuid'] ?? ''));
        $data   = $this->collect($request);
        $errors = $this->validate($data, (int) $lesson['id']);

        if ($errors !== []) {
            return $this->page('layouts.app', 'admin.balin.lessons.form', [
                'title'  => 'ویرایش درس',
                'lesson' => $lesson,
                'old'    => $data,
                'errors' => $errors,
            ], 422);
        }

        $data['cover_path']    = $this->storeCover($request, $lesson['cover_path']);
        $data['display_order'] = (int) $lesson['display_order'];

        if (!$this->lessons->update((int) $lesson['id'], $data, $request->int('version'))) {
            $this->flash('error', self::STALE);
            return $this->redirect('/admin/balin/lessons/' . $lesson['uuid'] . '/edit');
        }

        ActivityLogger::log('balin.lesson.updated', Auth::id(), 'balin_lesson', (int) $lesson['id'],
            ['title' => $data['title']], 'notice', $request);
        $this->flash('success', 'درس ذخیره شد.');

        return $this->redirect('/admin/balin/lessons/' . $lesson['uuid']);
    }

    public function setStatus(Request $request, array $params = []): Response
    {
        $lesson = $this->find((string) ($params['uuid'] ?? ''));
        $status = $request->string('status');

        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw HttpException::notFound();
        }

        $this->lessons->setStatus((int) $lesson['id'], $status);

        ActivityLogger::log('balin.lesson.status', Auth::id(), 'balin_lesson', (int) $lesson['id'],
            ['status' => $status], 'notice', $request);
        $this->flash('success', 'وضعیت درس تغییر کرد.');

        return $this->redirect('/admin/balin/lessons/' . $lesson['uuid']);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $lesson = $this->find((string) ($params['uuid'] ?? ''));

        $this->lessons->delete((int) $lesson['id']);

        ActivityLogger::log('balin.lesson.deleted', Auth::id(), 'balin_lesson', (int) $lesson['id'],
            ['title' => $lesson['title']], 'warning', $request);
        $this->flash('success', 'درس حذف شد.');

        return $this->redirect('/admin/balin/lessons');
    }

    // -------------------------------------------------------------- stages

    public function storeStage(Request $request, array $params = []): Response
    {
        $lesson = $this->find((string) ($params['uuid'] ?? ''));
        $title  = trim($request->string('title'));

        if ($title === '') {
            $this->flash('error', 'عنوان مرحله الزامی است.');
            return $this->redirect('/admin/balin/lessons/' . $lesson['uuid']);
        }

        $id = $this->stages->create([
            'uuid'              => Str::uuid4(),
            'lesson_id'         => (int) $lesson['id'],
            'title'             => $title,
            'subtitle'          => trim($request->string('subtitle')) ?: null,
            'description'       => trim($request->string('description')) ?: null,
            'is_final_case'     => $request->bool('is_final_case'),
            'xp_reward'         => max(0, $request->int('xp_reward')),
            'estimated_minutes' => max(0, $request->int('estimated_minutes')),
            'status'            => 'draft',
        ]);

        ActivityLogger::log('balin.stage.created', Auth::id(), 'balin_stage', $id,
            ['lesson' => (int) $lesson['id']], 'notice', $request);
        $this->flash('success', 'مرحله ساخته شد.');

        return $this->redirect('/admin/balin/stages/' . $this->stages->findById($id)['uuid']);
    }

    /**
     * Moves a stage one place up or down by swapping order values with its
     * neighbour — one row each, never a renumbering of the lesson.
     */
    public function moveStage(Request $request, array $params = []): Response
    {
        $stage     = $this->stages->findByUuid((string) ($params['uuid'] ?? ''));
        $direction = $request->string('direction');

        if ($stage === null || !in_array($direction, ['up', 'down'], true)) {
            throw HttpException::notFound();
        }

        $siblings = $this->stages->forLesson((int) $stage['lesson_id']);
        $index    = null;
        foreach ($siblings as $position => $row) {
            if ((int) $row['id'] === (int) $stage['id']) {
                $index = $position;
                break;
            }
        }

        $target = $direction === 'up' ? ($index ?? 0) - 1 : ($index ?? 0) + 1;

        if ($index !== null && isset($siblings[$target])) {
            $this->stages->setOrder((int) $stage['id'], (int) $siblings[$target]['display_order']);
            $this->stages->setOrder((int) $siblings[$target]['id'], (int) $stage['display_order']);
        }

        $lesson = $this->lessons->findById((int) $stage['lesson_id']);

        return $this->redirect('/admin/balin/lessons/' . $lesson['uuid']);
    }

    // ------------------------------------------------------------- helpers

    private function find(string $uuid): array
    {
        $lesson = $this->lessons->findByUuid($uuid);

        if ($lesson === null) {
            throw HttpException::notFound();
        }

        return $lesson;
    }

    private function collect(Request $request): array
    {
        $title = trim($request->string('title'));
        $slug  = trim($request->string('slug'));

        return [
            'title'             => $title,
            'slug'              => $slug !== '' ? Str::slug($slug) : Str::slug($title),
            'description'       => trim($request->string('description')) ?: null,
            'icon'              => trim($request->string('icon')) ?: null,
            'color'             => trim($request->string('color')) ?: null,
            'estimated_minutes' => max(0, $request->int('estimated_minutes')),
            'xp_reward'         => max(0, $request->int('xp_reward')),
            'extra_notes'       => trim($request->string('extra_notes')) ?: null,
        ];
    }

    private function validate(array $data, ?int $exceptId): array
    {
        $errors = [];

        if ($data['title'] === '') {
            $errors['title'] = 'عنوان درس الزامی است.';
        }
        if ($data['slug'] === '') {
            $errors['slug'] = 'نشانی یکتا (slug) الزامی است.';
        } elseif ($this->lessons->slugExists($data['slug'], $exceptId)) {
            $errors['slug'] = 'این نشانی قبلاً استفاده شده است.';
        }

        return $errors;
    }

    /**
     * A cover is a public image, so it goes through the shared asset
     * storage, which already parses the bytes and sanitises SVG.
     */
    private function storeCover(Request $request, ?string $existing): ?string
    {
        $file = $request->file('cover');

        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $existing;
        }

        try {
            return ImageAssetStorage::storeUploaded($file, 'balin');
        } catch (\Throwable $e) {
            $this->flash('error', 'آپلود تصویر کاور ناموفق بود: ' . $e->getMessage());
            return $existing;
        }
    }
}
