<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\NoteRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\ContentAccess;
use HeleXa\Services\InkData;
use HeleXa\Services\NoteStorage;
use HeleXa\Services\Settings;

/**
 * «یادداشت‌های من»: sticky notes with typing, handwriting and PDF/image
 * attachments — pinned on a lesson or standalone.
 */
final class NoteController extends Controller
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    private const PER_PAGE = 36;
    private const MAX_NOTES = 2000;

    private NoteRepository $notes;

    public function __construct()
    {
        $this->notes = new NoteRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $this->ensureEnabled();
        $userId  = (int) Auth::id();
        $filters = [
            'q'     => mb_substr(trim($request->string('q')), 0, 100),
            'scope' => in_array($request->string('scope'), ['lessons', 'free'], true) ? $request->string('scope') : '',
        ];
        $page  = max(1, $request->int('page', 1));
        $total = $this->notes->countFor($userId, $filters);

        return $this->page('layouts.app', 'student.notes.index', [
            'title'   => 'یادداشت‌های من',
            'notesUi' => true,
            'notes'   => $this->notes->listFor($userId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'filters' => $filters,
            'page'    => $page,
            'pages'   => max(1, (int) ceil($total / self::PER_PAGE)),
            'total'   => $total,
        ]);
    }

    /** Creates a note. JSON from the lesson viewer, a form from the notes page. */
    public function store(Request $request, array $params = []): Response
    {
        $this->ensureEnabled();
        $userId = (int) Auth::id();
        $json   = $request->isAjax();

        if ($this->notes->countFor($userId) >= self::MAX_NOTES) {
            return $this->fail($json, 'به سقف تعداد یادداشت‌ها رسیده‌اید.', '/student/notes');
        }

        $uuid = strtolower($request->string('uuid'));
        if ($uuid === '' || preg_match(self::UUID_RE, $uuid) !== 1) {
            $uuid = Str::uuid4();
        } elseif ($this->notes->exists($uuid)) {
            $existing = $this->notes->find($userId, $uuid);
            if ($existing !== null && $json) {
                return $this->json(['ok' => true, 'uuid' => $uuid, 'duplicate' => true]);
            }
            $uuid = Str::uuid4();
        }

        $data = [
            'color' => $request->string('color', 'yellow'),
            'title' => mb_substr(trim($request->string('title')), 0, 191) ?: null,
        ];

        $contentUuid = $request->string('content_uuid');
        if ($contentUuid !== '') {
            $content = ContentAccess::authorize(Auth::user(), $contentUuid);
            $data['content_id'] = (int) $content['id'];
            $data['pos_x'] = max(0, min(20000, $request->int('x')));
            $data['pos_y'] = max(0, min(2000000, $request->int('y')));
            $data['pos_w'] = max(50, min(10000, $request->int('w', 800)));
        }

        $this->notes->create($userId, $uuid, $data);

        if ($json) {
            return $this->json(['ok' => true, 'uuid' => $uuid]);
        }
        return $this->redirect('/student/notes/' . $uuid);
    }

    public function show(Request $request, array $params = []): Response
    {
        $this->ensureEnabled();
        $note = $this->noteOr404($params);

        return $this->page('layouts.app', 'student.notes.show', [
            'title'   => $note['title'] ?: 'یادداشت',
            'notesUi' => true,
            'note'    => $note,
            'pdfjs'   => self::pdfJsAvailable(),
        ]);
    }

    public function data(Request $request, array $params = []): Response
    {
        $this->ensureEnabled();
        $note = $this->noteOr404($params);

        return $this->json(['ok' => true, 'note' => $this->present($note)])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /** Partial update: only the fields that were sent are written. */
    public function update(Request $request, array $params = []): Response
    {
        $this->ensureEnabled();
        $note   = $this->noteOr404($params);
        $all    = $request->all();
        $fields = [];

        if (array_key_exists('title', $all)) {
            $fields['title'] = mb_substr(trim((string) (is_scalar($all['title']) ? $all['title'] : '')), 0, 191) ?: null;
        }
        if (array_key_exists('body', $all)) {
            $body = is_scalar($all['body']) ? str_replace("\r\n", "\n", (string) $all['body']) : '';
            $fields['body'] = $body === '' ? null : mb_substr($body, 0, 200000);
        }
        if (array_key_exists('color', $all) && in_array($all['color'], NoteRepository::COLORS, true)) {
            $fields['color'] = $all['color'];
        }
        if (array_key_exists('ink', $all) && Settings::bool('ink_enabled', true)) {
            $strokes = InkData::clean($all['ink']);
            try {
                $fields['ink'] = $strokes === [] ? null : InkData::encode($strokes);
            } catch (\RuntimeException $e) {
                return $this->json(['ok' => false, 'error' => 'TOO_LARGE', 'message' => $e->getMessage()], 413);
            }
            $fields['ink_count'] = count($strokes);
        }
        if (array_key_exists('ink_ratio', $all) && is_numeric($all['ink_ratio'])) {
            $fields['ink_ratio'] = round(max(0.5, min(40.0, (float) $all['ink_ratio'])), 3);
        }
        if (array_key_exists('x', $all) && $note['content_id'] !== null) {
            $fields['pos_x'] = max(0, min(20000, (int) $all['x']));
            $fields['pos_y'] = max(0, min(2000000, (int) ($all['y'] ?? 0)));
            $fields['pos_w'] = max(50, min(10000, (int) ($all['w'] ?? 800)));
        }

        $this->notes->update((int) $note['id'], $fields);

        return $this->json(['ok' => true, 'saved_at' => date('c')]);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $this->ensureEnabled();
        $note = $this->noteOr404($params);
        $this->notes->softDelete((int) $note['id']);

        if ($request->isAjax()) {
            return $this->json(['ok' => true]);
        }
        $this->flash('success', 'یادداشت حذف شد.');
        return $this->redirect('/student/notes');
    }

    /* ------------------------------------------------------------- files */

    public function upload(Request $request, array $params = []): Response
    {
        $this->ensureEnabled();
        $note = $this->noteOr404($params);

        $max = max(1, min(Settings::int('notes_max_files', 20), 100));
        if ($this->notes->countFiles((int) $note['id']) >= $max) {
            return $this->json(['ok' => false, 'message' => 'حداکثر ' . fa((string) $max) . ' فایل برای هر یادداشت.'], 422);
        }

        $file = $request->file('file');
        if ($file === null) {
            return $this->json(['ok' => false, 'message' => 'فایلی انتخاب نشده است.'], 422);
        }

        try {
            $stored = NoteStorage::store($file);
        } catch (\RuntimeException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $this->notes->addFile((int) $note['id'], (int) Auth::id(), Str::uuid4(), $stored);
        $this->notes->touch((int) $note['id']);

        return $this->json(['ok' => true, 'files' => $this->presentFiles($note)]);
    }

    public function file(Request $request, array $params = []): Response
    {
        $note = $this->noteOr404($params);
        $file = $this->fileOr404($note, $params);

        return NoteStorage::stream($file, $request->header('Range'), $request->bool('download'));
    }

    public function fileInk(Request $request, array $params = []): Response
    {
        $note = $this->noteOr404($params);
        $file = $this->fileOr404($note, $params);

        if ($request->method() === 'GET') {
            return $this->json(['ok' => true, 'pages' => (object) InkData::decode($file['ink'])])
                ->withHeader('Cache-Control', 'no-store, private');
        }

        $pages = $request->input('pages', []);
        $clean = [];
        if (is_array($pages)) {
            foreach ($pages as $page => $strokes) {
                $number = (int) $page;
                if ($number < 1 || $number > 2000) {
                    continue;
                }
                $list = InkData::clean($strokes);
                if ($list !== []) {
                    $clean[(string) $number] = $list;
                }
            }
        }
        $json = (string) json_encode((object) $clean, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > InkData::MAX_BYTES * 2) {
            return $this->json(['ok' => false, 'message' => 'حجم نوشته‌های این فایل از حد مجاز بیشتر است.'], 413);
        }
        $this->notes->setFileInk((int) $file['id'], $json);
        $this->notes->touch((int) $note['id']);

        return $this->json(['ok' => true]);
    }

    public function deleteFile(Request $request, array $params = []): Response
    {
        $note = $this->noteOr404($params);
        $file = $this->fileOr404($note, $params);

        $this->notes->deleteFile((int) $file['id']);
        NoteStorage::forget((string) $file['file_path']);

        return $this->json(['ok' => true, 'files' => $this->presentFiles($note)]);
    }

    /* ----------------------------------------------------------- helpers */

    public static function pdfJsAvailable(): bool
    {
        return is_file(PUBLIC_PATH . '/assets/vendor/pdfjs/pdf.min.js')
            && is_file(PUBLIC_PATH . '/assets/vendor/pdfjs/pdf.worker.min.js');
    }

    private function present(array $note): array
    {
        return [
            'uuid'          => $note['uuid'],
            'title'         => (string) ($note['title'] ?? ''),
            'body'          => (string) ($note['body'] ?? ''),
            'color'         => $note['color'],
            'ink'           => InkData::decode($note['ink']),
            'ink_ratio'     => (float) $note['ink_ratio'],
            'content_uuid'  => $note['content_uuid'],
            'content_title' => $note['content_title'],
            'updated_at'    => $note['updated_at'] ?? $note['created_at'],
            'files'         => $this->presentFiles($note),
        ];
    }

    private function presentFiles(array $note): array
    {
        $base = '/student/notes/' . $note['uuid'] . '/files/';

        return array_map(static fn (array $f): array => [
            'uuid'    => $f['uuid'],
            'name'    => $f['file_name'],
            'mime'    => $f['file_mime'],
            'size'    => (int) $f['file_size'],
            'url'     => $base . $f['uuid'],
            'has_ink' => (bool) $f['has_ink'],
        ], $this->notes->files((int) $note['id']));
    }

    private function noteOr404(array $params): array
    {
        $note = $this->notes->find((int) Auth::id(), (string) ($params['uuid'] ?? ''));
        if ($note === null) {
            throw HttpException::notFound('یادداشت پیدا نشد.');
        }
        return $note;
    }

    private function fileOr404(array $note, array $params): array
    {
        $file = $this->notes->findFile((int) $note['id'], (string) ($params['file'] ?? ''));
        if ($file === null) {
            throw HttpException::notFound();
        }
        return $file;
    }

    private function ensureEnabled(): void
    {
        if (!Settings::bool('notes_enabled', true)) {
            throw HttpException::notFound();
        }
    }

    private function fail(bool $json, string $message, string $back): Response
    {
        if ($json) {
            return $this->json(['ok' => false, 'message' => $message], 422);
        }
        $this->flash('error', $message);
        return $this->redirect($back);
    }
}