<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\ContentRepository;
use HeleXa\Models\CourseRepository;
use HeleXa\Models\SectionRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\ContentStorage;

/** Sections (the tree) and contents (the HTML lessons) for one course. */
final class ContentController extends Controller
{
    private const CONTENT_TYPES = ['full_notes', 'summary_notes', 'question_bank', 'chat_learn', 'mind_map', 'custom'];
    private const SECTION_TYPES = ['folder', 'full_notes', 'summary_notes', 'question_bank', 'chat_learn', 'mind_map', 'custom'];

    private CourseRepository $courses;
    private SectionRepository $sections;
    private ContentRepository $contents;

    public function __construct()
    {
        $this->courses  = new CourseRepository();
        $this->sections = new SectionRepository();
        $this->contents = new ContentRepository();
    }

    /* ---------------------------------------------------------- sections */

    public function storeSection(Request $request, array $params = []): Response
    {
        $course   = $this->course((string) ($params['uuid'] ?? ''));
        $parentId = $request->int('parent_id') ?: null;

        try {
            $id = $this->sections->create((int) $course['id'], $parentId, [
                'title'        => $request->string('title'),
                'description'  => $request->string('description'),
                'section_type' => $this->pick($request->string('section_type', 'folder'), self::SECTION_TYPES, 'folder'),
                'status'       => $this->pick($request->string('status', 'published'), ['draft', 'published', 'hidden'], 'published'),
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/courses/' . $course['uuid'] . '/builder');
        }

        ActivityLogger::log('section.created', Auth::id(), 'section', $id,
            ['course' => (int) $course['id']], 'notice', $request);
        $this->flash('success', 'بخش جدید اضافه شد.');

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/builder');
    }

    public function updateSection(Request $request, array $params = []): Response
    {
        $course  = $this->course((string) ($params['uuid'] ?? ''));
        $section = $this->section((int) ($params['id'] ?? 0), (int) $course['id']);

        $this->sections->update((int) $section['id'], [
            'title'        => $request->string('title'),
            'description'  => $request->string('description'),
            'section_type' => $this->pick($request->string('section_type', 'folder'), self::SECTION_TYPES, 'folder'),
            'status'       => $this->pick($request->string('status', 'published'), ['draft', 'published', 'hidden'], 'published'),
        ]);

        ActivityLogger::log('section.updated', Auth::id(), 'section', (int) $section['id'], [], 'info', $request);
        $this->flash('success', 'بخش به‌روزرسانی شد.');

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/builder');
    }

    public function destroySection(Request $request, array $params = []): Response
    {
        $course  = $this->course((string) ($params['uuid'] ?? ''));
        $section = $this->section((int) ($params['id'] ?? 0), (int) $course['id']);

        $this->sections->softDelete((int) $section['id']);
        ActivityLogger::log('section.deleted', Auth::id(), 'section', (int) $section['id'], [], 'warning', $request);
        $this->flash('success', 'بخش و زیرمجموعه‌های آن حذف شدند.');

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/builder');
    }

    public function moveSection(Request $request, array $params = []): Response
    {
        $course  = $this->course((string) ($params['uuid'] ?? ''));
        $section = $this->section((int) ($params['id'] ?? 0), (int) $course['id']);

        $this->sections->move((int) $section['id'], $request->string('direction') === 'up' ? 'up' : 'down');

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/builder');
    }

    /* ---------------------------------------------------------- contents */

    public function createContent(Request $request, array $params = []): Response
    {
        $course = $this->course((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.courses.content_form', [
            'title'    => 'افزودن محتوا به ' . $course['title'],
            'course'   => $course,
            'content'  => null,
            'sections' => $this->sections->forCourse((int) $course['id']),
            'errors'   => [],
            'old'      => ['section_id' => $request->int('section_id')],
        ]);
    }

    public function storeContent(Request $request, array $params = []): Response
    {
        $course = $this->course((string) ($params['uuid'] ?? ''));
        $data   = $this->collectContent($request, (int) $course['id']);

        if ($data['title'] === '') {
            return $this->contentFormWithError($course, null, ['title' => 'عنوان محتوا الزامی است.'], $data);
        }

        $uuid = Str::uuid4();

        try {
            $stored = $this->ingest($request, $uuid, $data['title']);
        } catch (\RuntimeException $e) {
            return $this->contentFormWithError($course, null, ['html_file' => $e->getMessage()], $data);
        }

        $id = $this->contents->create($data + [
            'uuid'              => $uuid,
            'course_id'         => (int) $course['id'],
            'storage_path'      => $stored['path'],
            'original_filename' => $stored['original'],
            'checksum'          => $stored['checksum'],
            'byte_size'         => $stored['size'],
            'scan_report'       => $stored['report'],
            'sort_order'        => $this->contents->nextOrder((int) $course['id'], $data['section_id']),
            'created_by'        => Auth::id(),
        ]);

        ActivityLogger::log('content.created', Auth::id(), 'content', $id, [
            'course' => (int) $course['id'],
            'size'   => $stored['size'],
            'hosts'  => $stored['report']['unknown_hosts'],
        ], 'notice', $request);

        $this->flash('success', $this->uploadSummary($stored['report']));

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/builder');
    }

    public function editContent(Request $request, array $params = []): Response
    {
        $course  = $this->course((string) ($params['uuid'] ?? ''));
        $content = $this->content((string) ($params['content'] ?? ''), (int) $course['id']);

        // Large lessons are not loaded into the textarea: a multi-megabyte
        // editor field would lock up the browser. Those are replaced by upload.
        $code = null;
        if ($content['storage_path'] !== null) {
            try {
                $code = (new ContentStorage())->read((string) $content['storage_path']);
            } catch (\RuntimeException) {
                $code = null;
            }
        }

        return $this->page('layouts.app', 'admin.courses.content_form', [
            'title'    => 'ویرایش ' . $content['title'],
            'course'   => $course,
            'content'  => $content,
            'sections' => $this->sections->forCourse((int) $course['id']),
            'errors'   => [],
            'old'      => $content,
            'code'     => $code,
            'report'   => $content['scan_report'] !== null ? json_decode((string) $content['scan_report'], true) : null,
        ]);
    }

    public function updateContent(Request $request, array $params = []): Response
    {
        $course  = $this->course((string) ($params['uuid'] ?? ''));
        $content = $this->content((string) ($params['content'] ?? ''), (int) $course['id']);
        $data    = $this->collectContent($request, (int) $course['id']);

        if ($data['title'] === '') {
            return $this->contentFormWithError($course, $content, ['title' => 'عنوان محتوا الزامی است.'], $data);
        }

        $this->contents->updateMeta((int) $content['id'], $data);

        // Replacing the source is optional; the metadata form works on its own.
        if ($this->hasNewSource($request)) {
            try {
                $stored = $this->ingest($request, (string) $content['uuid'], $data['title']);
            } catch (\RuntimeException $e) {
                return $this->contentFormWithError($course, $content, ['html_file' => $e->getMessage()], $data);
            }
            $this->contents->replaceFile((int) $content['id'], [
                'storage_path'      => $stored['path'],
                'original_filename' => $stored['original'],
                'checksum'          => $stored['checksum'],
                'byte_size'         => $stored['size'],
                'scan_report'       => $stored['report'],
            ]);
            $this->flash('success', 'محتوا جایگزین شد. ' . $this->uploadSummary($stored['report']));
        } else {
            $this->flash('success', 'محتوا به‌روزرسانی شد.');
        }

        ActivityLogger::log('content.updated', Auth::id(), 'content', (int) $content['id'], [], 'notice', $request);

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/builder');
    }

    public function destroyContent(Request $request, array $params = []): Response
    {
        $course  = $this->course((string) ($params['uuid'] ?? ''));
        $content = $this->content((string) ($params['content'] ?? ''), (int) $course['id']);

        $this->contents->softDelete((int) $content['id']);
        ActivityLogger::log('content.deleted', Auth::id(), 'content', (int) $content['id'],
            ['title' => $content['title']], 'warning', $request);
        $this->flash('success', 'محتوا حذف شد.');

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/builder');
    }

    public function moveContent(Request $request, array $params = []): Response
    {
        $course  = $this->course((string) ($params['uuid'] ?? ''));
        $content = $this->content((string) ($params['content'] ?? ''), (int) $course['id']);

        $this->contents->move((int) $content['id'], $request->string('direction') === 'up' ? 'up' : 'down');

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/builder');
    }

    /* ---------------------------------------------------------- helpers */

    private function course(string $uuid): array
    {
        $course = $this->courses->findByUuid($uuid);
        if ($course === null) {
            throw HttpException::notFound('دوره یافت نشد.');
        }
        return $course;
    }

    private function section(int $id, int $courseId): array
    {
        $section = $this->sections->find($id);
        // The section must belong to the course in the URL, or an admin could
        // edit another course's tree by swapping the id.
        if ($section === null || (int) $section['course_id'] !== $courseId) {
            throw HttpException::notFound('بخش یافت نشد.');
        }
        return $section;
    }

    private function content(string $uuid, int $courseId): array
    {
        $content = $this->contents->findByUuid($uuid);
        if ($content === null || (int) $content['course_id'] !== $courseId) {
            throw HttpException::notFound('محتوا یافت نشد.');
        }
        return $content;
    }

    private function collectContent(Request $request, int $courseId): array
    {
        $sectionId = $request->int('section_id') ?: null;
        if ($sectionId !== null) {
            $section = $this->sections->find($sectionId);
            if ($section === null || (int) $section['course_id'] !== $courseId) {
                $sectionId = null;
            }
        }

        return [
            'title'        => $request->string('title'),
            'description'  => $request->string('description'),
            'content_type' => $this->pick($request->string('content_type', 'full_notes'), self::CONTENT_TYPES, 'full_notes'),
            'section_id'   => $sectionId,
            'is_printable' => $request->bool('is_printable') ? 1 : 0,
            // Default on: existing lessons keep behaving as they always did.
            'offline_enabled' => $request->bool('offline_enabled') ? 1 : 0,
            'status'       => $this->pick($request->string('status', 'draft'), ['draft', 'published', 'hidden'], 'draft'),
            'source_mode'  => $request->string('source_mode') === 'paste' ? 'paste' : 'upload',
            'html_code'    => $request->string('source_mode') === 'paste' ? (string) $request->input('html_code', '') : null,
        ];
    }

    private function contentFormWithError(array $course, ?array $content, array $errors, array $old): Response
    {
        return $this->page('layouts.app', 'admin.courses.content_form', [
            'title'    => $content === null ? 'افزودن محتوا' : 'ویرایش محتوا',
            'course'   => $course,
            'content'  => $content,
            'sections' => $this->sections->forCourse((int) $course['id']),
            'errors'   => $errors,
            'old'      => $old,
            'code'     => $old['html_code'] ?? null,
            'report'   => null,
        ], 422);
    }

    /** True when the admin supplied a new file or new pasted code. */
    private function hasNewSource(Request $request): bool
    {
        $file = $request->file('html_file');
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
        return $request->string('source_mode') === 'paste' && trim((string) $request->input('html_code', '')) !== '';
    }

    /**
     * One ingestion path for both input modes. Whether the admin uploaded a
     * file or pasted the markup, it ends up as the same private file, with the
     * same checksum and the same scan report.
     *
     * @return array{path:string, checksum:string, size:int, report:array, original:string}
     */
    private function ingest(Request $request, string $uuid, string $title): array
    {
        $storage = new ContentStorage();
        $file    = $request->file('html_file');
        $hasFile = $file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($request->string('source_mode') === 'paste') {
            $code = (string) $request->input('html_code', '');
            if (trim($code) === '' && $hasFile) {
                return $storage->store($file, $uuid);
            }
            return $storage->storeFromString($code, $uuid, Str::slug($title) . '.html');
        }

        if (!$hasFile) {
            throw new \RuntimeException('فایل HTML را انتخاب کنید یا کد را در تب «چسباندن کد» وارد کنید.');
        }

        return $storage->store($file, $uuid);
    }

    /** Turns the scanner output into one honest sentence for the admin. */
    private function uploadSummary(array $report): string
    {
        $parts = [sprintf('فایل ذخیره شد (%s).', $this->humanBytes((int) $report['bytes']))];

        if ($report['inline_images'] > 0) {
            $parts[] = sprintf('%d تصویر درون فایل جاسازی شده است.', (int) $report['inline_images']);
        }
        if ($report['unknown_hosts'] !== []) {
            $parts[] = 'این فایل از دامنه‌های بیرونی استفاده می‌کند: ' . implode('، ', $report['unknown_hosts'])
                     . ' — اگر این دامنه‌ها برای کاربر در دسترس نباشند، ظاهر جزوه ناقص می‌شود.';
        } elseif ($report['external_hosts'] !== []) {
            $parts[] = 'فونت از ' . implode('، ', $report['external_hosts']) . ' بارگذاری می‌شود.';
        }
        if ($report['uses_storage']) {
            $parts[] = 'این فایل از localStorage استفاده می‌کند؛ سیستم به‌جای آن وضعیت را روی سرور نگه می‌دارد.';
        }
        if ($report['uses_network']) {
            $parts[] = 'این فایل درخواست شبکه‌ای دارد که در نمایشگر مسدود می‌شود.';
        }

        return implode(' ', $parts);
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1) . ' مگابایت'
            : number_format($bytes / 1024, 0) . ' کیلوبایت';
    }

    private function pick(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
