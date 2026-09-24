<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\CourseRepository;
use HeleXa\Models\LibraryRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\LibraryStorage;
use HeleXa\Services\NotificationService;

/**
 * The content library: videos, articles, images, files and links, each with
 * an optional cover shown at 4:3.
 *
 * This is where the sidebar's «محتوای آموزشی» entry leads. The cross-course
 * lesson index that used to live there is linked from the top of this page.
 */
final class LibraryController extends Controller
{
    private LibraryRepository $items;

    public function __construct()
    {
        $this->items = new LibraryRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $filters = ['q' => trim($request->string('q')), 'kind' => $request->string('kind'), 'status' => $request->string('status')];

        return $this->page('layouts.app', 'admin.library.index', [
            'title'   => 'محتوای آموزشی',
            'qbank'   => true, // shared page styles
            'items'   => $this->items->adminList($filters),
            'filters' => $filters,
            'kinds'   => LibraryRepository::KINDS,
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request, array $params = []): Response
    {
        return $this->form($this->itemOr404((string) ($params['uuid'] ?? '')));
    }

    public function store(Request $request, array $params = []): Response
    {
        try {
            $data = $this->collect($request, null);
            $made = $this->items->create($data, Auth::id());
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/library/create');
        }

        if ($data['status'] === 'published') {
            $this->announce($made['id'], $made['uuid'], $data);
        }
        ActivityLogger::log('library.created', Auth::id(), 'library_item', $made['id'], ['kind' => $data['kind']], 'notice', $request);
        $this->flash('success', 'محتوا افزوده شد.');

        return $this->redirect('/admin/library');
    }

    public function update(Request $request, array $params = []): Response
    {
        $item = $this->itemOr404((string) ($params['uuid'] ?? ''));

        try {
            $data = $this->collect($request, $item);
            $this->items->update((int) $item['id'], $data);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/library/' . $item['uuid'] . '/edit');
        }

        // Files that were replaced or no longer apply are removed only now,
        // after the row points elsewhere.
        foreach (['file_path', 'cover_path'] as $column) {
            if (!empty($item[$column]) && $item[$column] !== $data[$column]) {
                LibraryStorage::forget($item[$column]);
            }
        }

        if ($data['status'] === 'published' && $item['status'] !== 'published') {
            $this->announce((int) $item['id'], (string) $item['uuid'], $data);
        }
        ActivityLogger::log('library.updated', Auth::id(), 'library_item', (int) $item['id'], [], 'notice', $request);
        $this->flash('success', 'تغییرات ذخیره شد.');

        return $this->redirect('/admin/library');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $item = $this->itemOr404((string) ($params['uuid'] ?? ''));
        $this->items->softDelete((int) $item['id']);

        ActivityLogger::log('library.deleted', Auth::id(), 'library_item', (int) $item['id'], ['title' => $item['title']], 'notice', $request);
        $this->flash('success', '«' . $item['title'] . '» حذف شد.');

        return $this->redirect('/admin/library');
    }

    /** Admin preview of a stored file or cover, drafts included. */
    public function media(Request $request, array $params = []): Response
    {
        $item  = $this->itemOr404((string) ($params['uuid'] ?? ''));
        $which = (string) ($params['which'] ?? '');

        return self::serve($item, $which, $request);
    }

    /**
     * Shared by the admin and student routes once access is settled.
     * "cover" falls back to the file itself for an image item without a cover.
     */
    public static function serve(array $item, string $which, Request $request): Response
    {
        if ($which === 'cover') {
            $name = $item['cover_path'] ?: ($item['kind'] === 'image' ? $item['file_path'] : null);
            $resolved = LibraryStorage::resolve($name);
            $info = $resolved !== null ? @getimagesize($resolved['path']) : false;
            if ($info === false) {
                throw HttpException::notFound();
            }
            return LibraryStorage::stream((string) $name, (string) $info['mime'], 'inline', null, null);
        }

        if ($which !== 'file' || empty($item['file_path'])) {
            throw HttpException::notFound();
        }

        // Media plays in the page; documents download under their own name.
        $inline = in_array($item['kind'], ['video', 'image'], true)
            || in_array($item['file_mime'], ['application/pdf', 'audio/mpeg'], true);

        return LibraryStorage::stream(
            (string) $item['file_path'],
            (string) $item['file_mime'],
            $inline ? 'inline' : 'attachment',
            (string) ($item['file_name'] ?? 'file'),
            $request->header('Range')
        );
    }

    /* ----------------------------------------------------------- helpers */

    private function form(?array $item): Response
    {
        return $this->page('layouts.app', 'admin.library.form', [
            'title'   => $item === null ? 'افزودن محتوا' : 'ویرایش محتوا',
            'qbank'   => true,
            'item'    => $item,
            'kinds'   => LibraryRepository::KINDS,
            'courses' => (new CourseRepository())->all(),
            'maxMb'   => (int) (LibraryStorage::maxBytes() / 1048576),
            'phpMax'  => (string) ini_get('upload_max_filesize'),
        ]);
    }

    /**
     * Reads and validates the form. New files are stored here; if validation
     * then fails they are removed before the error is reported.
     */
    private function collect(Request $request, ?array $item): array
    {
        $kind = $request->string('kind');
        if (!isset(LibraryRepository::KINDS[$kind])) {
            throw new \RuntimeException('نوع محتوا را انتخاب کنید.');
        }

        $title = mb_substr(trim($request->string('title')), 0, 191);
        if ($title === '') {
            throw new \RuntimeException('عنوان لازم است.');
        }

        $courseId = $request->int('course_id') ?: null;
        if ($courseId !== null && (new CourseRepository())->findById($courseId) === null) {
            $courseId = null;
        }

        $data = [
            'kind'       => $kind,
            'title'      => $title,
            'summary'    => mb_substr(trim($request->string('summary')), 0, 500) ?: null,
            'body'       => null,
            'url'        => null,
            'file_path'  => null,
            'file_mime'  => null,
            'file_size'  => null,
            'file_name'  => null,
            'cover_path' => $item['cover_path'] ?? null,
            'course_id'  => $courseId,
            'sort_order' => $request->int('sort_order'),
            'status'     => $request->string('status') === 'published' ? 'published' : 'draft',
        ];

        $fresh = [];
        try {
            // Cover: new upload, explicit removal, or keep.
            $cover = $request->file('cover');
            if ($cover !== null && (int) ($cover['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $stored = LibraryStorage::store($cover, 'image');
                $fresh[] = $data['cover_path'] = $stored['path'];
            } elseif ($request->bool('remove_cover')) {
                $data['cover_path'] = null;
            }

            switch ($kind) {
                case 'article':
                    $body = trim((string) $request->input('body', ''));
                    if ($body === '') {
                        throw new \RuntimeException('متن مقاله خالی است.');
                    }
                    $data['body'] = mb_substr($body, 0, 200000);
                    break;

                case 'link':
                    $url = trim($request->string('url'));
                    // Only web links. javascript:, data: and friends never reach an href.
                    if (filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('~^https?://~i', $url) !== 1) {
                        throw new \RuntimeException('آدرس لینک باید با http:// یا https:// شروع شود.');
                    }
                    $data['url'] = mb_substr($url, 0, 500);
                    break;

                default: // video, image, file
                    $upload = $request->file('file');
                    $hasUpload = $upload !== null && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                    $sameKindFile = $item !== null && $item['kind'] === $kind && !empty($item['file_path']);

                    if ($hasUpload) {
                        $stored = LibraryStorage::store($upload, $kind === 'file' ? 'file' : $kind);
                        $fresh[] = $stored['path'];
                        $data['file_path'] = $stored['path'];
                        $data['file_mime'] = $stored['mime'];
                        $data['file_size'] = $stored['size'];
                        $data['file_name'] = $stored['name'];
                    } elseif ($sameKindFile) {
                        foreach (['file_path', 'file_mime', 'file_size', 'file_name'] as $k) {
                            $data[$k] = $item[$k];
                        }
                    } else {
                        throw new \RuntimeException('فایل «' . LibraryRepository::KINDS[$kind] . '» را انتخاب کنید.');
                    }
                    break;
            }
        } catch (\RuntimeException $e) {
            foreach ($fresh as $name) {
                LibraryStorage::forget($name);
            }
            throw $e;
        }

        return $data;
    }

    private function announce(int $id, string $uuid, array $data): void
    {
        NotificationService::publish([
            'title'           => '🎬 محتوای جدید در کتابخانه: ' . $data['title'],
            'body'            => (string) ($data['summary'] ?? ''),
            'notif_type'      => 'content',
            'related_type'    => 'library_item',
            'related_id'      => $id,
            'idempotency_key' => 'library_published:' . $id,
            'audience'        => $data['course_id'] !== null ? 'course' : 'all',
            'course_id'       => $data['course_id'],
            'link_url'        => '/student/library/' . $uuid,
            'created_by'      => Auth::id(),
        ]);
    }

    private function itemOr404(string $uuid): array
    {
        $item = $this->items->findByUuid($uuid);
        if ($item === null) {
            throw HttpException::notFound();
        }
        return $item;
    }
}
