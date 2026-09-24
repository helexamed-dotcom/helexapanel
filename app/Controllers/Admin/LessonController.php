<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\LessonRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Models\QuestionBank\QbTagRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\LessonTransfer;
use HeleXa\Services\MediaStore;
use HeleXa\Services\RichText;

/**
 * «درسنامه‌ها» for the admin: write them in a Word-like editor, file them
 * under a درس, tag them (the same tags as the questions and the figure
 * game), and move them in and out as JSON.
 */
final class LessonController extends Controller
{
    private LessonRepository $lessons;

    public function __construct()
    {
        $this->lessons = new LessonRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        if (!LessonRepository::ready()) {
            $this->flash('error', 'ابتدا مهاجرت 2026_09_27_lessons.sql را اجرا کنید.');
            return $this->page('layouts.app', 'admin.lessons.index', ['title' => 'درسنامه‌ها', 'rows' => [], 'filters' => [], 'subjects' => []]);
        }
        $filters = ['q' => mb_substr(trim($request->string('q')), 0, 80), 'subject' => $request->int('subject'), 'status' => $request->string('status')];

        return $this->page('layouts.app', 'admin.lessons.index', [
            'title'    => 'درسنامه‌ها',
            'rows'     => $this->lessons->search($filters, false),
            'filters'  => $filters,
            'subjects' => $this->subjectOptions(),
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, array $params = []): Response
    {
        return $this->form($this->lessonOr404((string) ($params['uuid'] ?? '')));
    }

    public function store(Request $request, array $params = []): Response
    {
        return $this->persist($request, null);
    }

    public function update(Request $request, array $params = []): Response
    {
        return $this->persist($request, $this->lessonOr404((string) ($params['uuid'] ?? '')));
    }

    public function setStatus(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404((string) ($params['uuid'] ?? ''));
        $this->lessons->setStatus((int) $lesson['id'], $request->string('status'));
        ActivityLogger::log('lesson.status', Auth::id(), 'lesson', (int) $lesson['id'], ['status' => $request->string('status')], 'info', $request);
        $this->flash('success', $request->string('status') === 'published' ? 'منتشر شد.' : 'به پیش‌نویس برگشت.');

        return $this->redirect('/admin/lessons');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $lesson = $this->lessonOr404((string) ($params['uuid'] ?? ''));
        $this->lessons->softDelete((int) $lesson['id']);
        ActivityLogger::log('lesson.deleted', Auth::id(), 'lesson', (int) $lesson['id'], [], 'warning', $request);
        $this->flash('success', 'درسنامه حذف شد.');

        return $this->redirect('/admin/lessons');
    }

    /** The editor's image button and paste: one image in, its URL out. */
    public function upload(Request $request, array $params = []): Response
    {
        $store = new MediaStore('lessons');
        try {
            $file = $request->file('image');
            if ($file !== null) {
                $name = $store->store($file);
            } else {
                $bytes = MediaStore::decodeDataUrl($request->string('data'));
                if ($bytes === null) {
                    return $this->json(['ok' => false, 'message' => 'تصویری دریافت نشد.'], 422);
                }
                $name = $store->storeBlob($bytes);
            }
        } catch (\RuntimeException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->json(['ok' => true, 'url' => '/media/lessons/' . $name]);
    }

    /* ------------------------------------------------------------ JSON */

    public function transfer(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.lessons.transfer', [
            'title'    => 'ورود و خروج JSON درسنامه‌ها',
            'subjects' => $this->subjectOptions(),
            'sample'   => LessonTransfer::sample(),
            'guide'    => LessonTransfer::aiPrompt(),
        ]);
    }

    public function export(Request $request, array $params = []): Response
    {
        $rows = $this->lessons->search(['subject' => $request->int('subject')], false, 500);
        $json = json_encode(LessonTransfer::export($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Response::make((string) $json, 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="helexa-lessons-' . date('Y-m-d') . '.json"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function import(Request $request, array $params = []): Response
    {
        $raw = trim($request->string('json'));
        $file = $request->file('file');
        if ($raw === '' && $file !== null && (int) ($file['error'] ?? 1) === UPLOAD_ERR_OK && (int) $file['size'] <= 8 * 1024 * 1024) {
            $raw = (string) file_get_contents((string) $file['tmp_name']);
        }
        $result = LessonTransfer::import($raw, (int) Auth::id(), $request->bool('publish'), $request->int('subject'));
        ActivityLogger::log('lesson.imported', Auth::id(), 'lesson', null, ['created' => $result['created'], 'updated' => $result['updated']], 'info', $request);
        $this->flash($result['errors'] === [] ? 'success' : 'error',
            sprintf('%s درسنامه ساخته و %s به‌روز شد.', fa((string) $result['created']), fa((string) $result['updated']))
            . ($result['errors'] !== [] ? ' خطاها: ' . implode(' — ', array_slice($result['errors'], 0, 5)) : ''));

        return $this->redirect('/admin/lessons/transfer');
    }

    /* -------------------------------------------------------- internals */

    private function form(?array $lesson): Response
    {
        return $this->page('layouts.app', 'admin.lessons.form', [
            'title'     => $lesson === null ? 'درسنامه جدید' : 'ویرایش درسنامه',
            'lesson'    => $lesson,
            'tagIds'    => $lesson === null ? [] : array_column($this->lessons->tagsFor((int) $lesson['id']), 'id'),
            'allTags'   => (new QbTagRepository())->all(true),
            'subjects'  => $this->subjectOptions(),
            'packages'  => (new PackageRepository())->all(),
            'colors'    => LessonRepository::COLORS,
            'extraCss'  => ['lessons'],
            'extraJs'   => ['lesson-editor'],
        ]);
    }

    private function persist(Request $request, ?array $lesson): Response
    {
        $title = trim(mb_substr($request->string('title'), 0, 191));
        $body  = RichText::clean((string) $request->input('body_html', ''));
        if ($title === '' || RichText::plain($body) === '' && !str_contains($body, '<img')) {
            $this->flash('error', 'عنوان و متن درسنامه لازم است.');
            return $this->redirect($lesson === null ? '/admin/lessons/create' : '/admin/lessons/' . $lesson['uuid'] . '/edit');
        }

        $id = $this->lessons->save($lesson === null ? null : (int) $lesson['id'], [
            'title'           => $title,
            'summary'         => trim(mb_substr($request->string('summary'), 0, 500)),
            'subject_id'      => $request->int('subject_id'),
            'package_id'      => $request->int('package_id'),
            'color'           => $request->string('color'),
            'body_html'       => $body,
            'reading_minutes' => RichText::readingMinutes($body),
            'status'          => $request->string('status'),
            'sort_order'      => $request->int('sort_order'),
        ], (int) Auth::id());

        $tags = $request->input('tags', []);
        $this->lessons->syncTags($id, is_array($tags) ? $tags : []);

        $cover = $request->file('cover');
        if ($cover !== null && (int) ($cover['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $store = new MediaStore('lessons');
                $name = $store->store($cover);
                if ($lesson !== null && !empty($lesson['cover_path'])) {
                    $store->forget($lesson['cover_path']);
                }
                $this->lessons->setCover($id, $name);
            } catch (\RuntimeException $e) {
                $this->flash('error', 'تصویر جلد: ' . $e->getMessage());
            }
        } elseif ($request->bool('remove_cover') && $lesson !== null) {
            (new MediaStore('lessons'))->forget($lesson['cover_path']);
            $this->lessons->setCover($id, null);
        }

        ActivityLogger::log($lesson === null ? 'lesson.created' : 'lesson.updated', Auth::id(), 'lesson', $id, [], 'info', $request);
        $this->flash('success', 'درسنامه ذخیره شد.');
        $saved = $this->lessons->find($id);

        return $this->redirect($request->bool('stay') && $saved ? '/admin/lessons/' . $saved['uuid'] . '/edit' : '/admin/lessons');
    }

    private function lessonOr404(string $uuid): array
    {
        $lesson = $this->lessons->findByUuid($uuid);
        if ($lesson === null) {
            throw HttpException::notFound();
        }
        return $lesson;
    }

    private function subjectOptions(): array
    {
        return \HeleXa\Services\SubjectTree::options();
    }
}
