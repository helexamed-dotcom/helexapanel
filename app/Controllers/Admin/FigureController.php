<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\FigureRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\MediaStore;
use HeleXa\Services\SubjectTree;

/**
 * «بازی با شکل» for the admin: upload an image (an atlas page, a diagram),
 * place hotspots on it by hand, name each one and optionally give it its own
 * question, hint and درسنامه. Saved over AJAX; spots in and out as JSON.
 */
final class FigureController extends Controller
{
    private const TONES = ['amber', 'orange', 'rose', 'pink', 'violet', 'indigo', 'blue', 'sky', 'teal', 'green', 'red', 'slate'];

    private FigureRepository $figures;

    public function __construct()
    {
        $this->figures = new FigureRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $this->ready();
        $filters = ['q' => mb_substr(trim($request->string('q')), 0, 80), 'status' => $request->string('status')];
        return $this->page('layouts.app', 'admin.figures.index', [
            'title'    => 'بازی با شکل',
            'rows'     => $this->figures->search($filters, false),
            'filters'  => $filters,
            'extraCss' => ['figures', 'shop-admin', 'mindmap'],
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        $this->ready();
        $fig = $this->figures->create(trim(mb_substr($request->string('title'), 0, 191)) ?: 'شکل تازه', (int) Auth::id());
        $file = $request->file('image');
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $this->storeImage($fig, $file);
        }
        return $this->redirect('/admin/figures/' . $fig['uuid'] . '/edit');
    }

    public function edit(Request $request, array $params = []): Response
    {
        $fig = $this->figureOr404((string) ($params['uuid'] ?? ''));
        $packages = [];
        try {
            $packages = (new PackageRepository())->all();
        } catch (\PDOException) {
        }
        return $this->page('layouts.app', 'admin.figures.edit', [
            'title'    => 'ویرایش بازی با شکل',
            'fig'      => $fig,
            'spots'    => $this->figures->spots((int) $fig['id']),
            'subjects' => SubjectTree::options(),
            'packages' => $packages,
            'tags'     => $this->figures->tagOptions(),
            'lessons'  => $this->figures->lessonOptions(),
            'tones'    => self::TONES,
            'extraCss' => ['mindmap', 'figures'],
            'extraJs'  => ['figure-editor'],
        ]);
    }

    /** POST /admin/figures/{uuid} — JSON {meta…, spots[]} from the editor. */
    public function save(Request $request, array $params = []): Response
    {
        $fig = $this->figureOr404((string) ($params['uuid'] ?? ''));
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->json(['ok' => false, 'message' => 'داده نامعتبر است.'], 422);
        }
        $spots = $this->cleanSpots(is_array($body['spots'] ?? null) ? $body['spots'] : []);
        $status = ($body['status'] ?? '') === 'published' ? 'published' : 'draft';
        if ($status === 'published' && (empty($fig['image_path']) || count($spots) < 2)) {
            return $this->json(['ok' => false, 'message' => 'برای انتشار، تصویر و دست‌کم دو نقطه لازم است.'], 422);
        }
        $subject = (int) ($body['subject_id'] ?? 0);
        $package = (int) ($body['package_id'] ?? 0);
        $this->figures->saveMeta((int) $fig['id'], [
            'title'      => trim(mb_substr((string) ($body['title'] ?? ''), 0, 191)) ?: $fig['title'],
            'summary'    => trim(mb_substr((string) ($body['summary'] ?? ''), 0, 300)) ?: null,
            'subject_id' => $subject > 0 ? $subject : null,
            'package_id' => $package > 0 ? $package : null,
            'tone'       => in_array($body['tone'] ?? '', self::TONES, true) ? $body['tone'] : 'amber',
            'status'     => $status,
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ]);
        $this->figures->syncSpots((int) $fig['id'], $spots);
        return $this->json(['ok' => true, 'count' => count($spots), 'status' => $status]);
    }

    /** The picture itself (replacing it keeps the spots, which are in percent). */
    public function image(Request $request, array $params = []): Response
    {
        $fig = $this->figureOr404((string) ($params['uuid'] ?? ''));
        $file = $request->file('image');
        if ($file === null) {
            return $this->json(['ok' => false, 'message' => 'تصویری دریافت نشد.'], 422);
        }
        $err = $this->storeImage($fig, $file);
        if ($err !== null) {
            return $this->json(['ok' => false, 'message' => $err], 422);
        }
        $fresh = $this->figures->findByUuid((string) $fig['uuid']) ?? [];
        return $this->json(['ok' => true, 'url' => '/media/figures/' . $fresh['image_path'], 'w' => (int) $fresh['image_w'], 'h' => (int) $fresh['image_h']]);
    }

    public function setStatus(Request $request, array $params = []): Response
    {
        $fig = $this->figureOr404((string) ($params['uuid'] ?? ''));
        if ($request->string('status') === 'published' && (empty($fig['image_path']) || count($this->figures->spots((int) $fig['id'])) < 2)) {
            $this->flash('error', 'برای انتشار، تصویر و دست‌کم دو نقطه لازم است.');
            return $this->redirect('/admin/figures');
        }
        $this->figures->setStatus((int) $fig['id'], $request->string('status'));
        $this->flash('success', $request->string('status') === 'published' ? 'منتشر شد.' : 'به پیش‌نویس برگشت.');
        return $this->redirect('/admin/figures');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $fig = $this->figureOr404((string) ($params['uuid'] ?? ''));
        $this->figures->softDelete((int) $fig['id']);
        ActivityLogger::log('figure.deleted', Auth::id(), 'figure', (int) $fig['id'], [], 'warning', $request);
        $this->flash('success', 'حذف شد.');
        return $this->redirect('/admin/figures');
    }

    public function export(Request $request, array $params = []): Response
    {
        $fig = $this->figureOr404((string) ($params['uuid'] ?? ''));
        $doc = ['format' => 'helexa.figure', 'version' => 1, 'title' => $fig['title'], 'spots' => array_map(static fn (array $s): array => array_filter([
            'key' => $s['skey'], 'label' => $s['label'], 'x' => $s['x'], 'y' => $s['y'], 'r' => $s['r'],
            'question' => $s['question'], 'options' => $s['options'] ?: null, 'hint' => $s['hint'], 'explanation' => $s['explanation'],
            'lesson' => $s['lesson_uuid'] ?? null, 'tag' => $s['tag_title'] ?? null,
        ], static fn ($v) => $v !== null && $v !== ''), $this->figures->spots((int) $fig['id']))];
        return Response::make((string) json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="figure-' . substr((string) $fig['uuid'], 0, 8) . '.json"',
        ]);
    }

    /* ======================================================== internals */

    /**
     * A spot: key, label, x / y / r in percent of the image, an optional
     * question with choices (the first choice listed as correct is stored
     * with correct:true), a hint, an explanation, a tag, a درسنامه.
     *
     * @return list<array>
     */
    private function cleanSpots(array $raw): array
    {
        $tags = array_flip(array_map('intval', array_column($this->figures->tagOptions(), 'id')));
        $lessons = [];
        foreach ($this->figures->lessonOptions() as $l) {
            $lessons[(int) $l['id']] = true;
            $lessons['u:' . strtolower((string) $l['uuid'])] = (int) $l['id'];
        }
        $tagByTitle = [];
        foreach ($this->figures->tagOptions() as $t) {
            $tagByTitle[mb_strtolower((string) $t['title'])] = (int) $t['id'];
        }
        $out = [];
        $seen = [];
        foreach (array_slice($raw, 0, FigureRepository::MAX_SPOTS) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $label = trim(mb_substr(preg_replace('/\s+/u', ' ', (string) ($s['label'] ?? '')) ?? '', 0, 160));
            if ($label === '') {
                continue;
            }
            $key = (string) ($s['key'] ?? $s['skey'] ?? '');
            if (preg_match('/^[a-z0-9]{1,16}$/', $key) !== 1 || isset($seen[$key])) {
                $key = substr(bin2hex(random_bytes(5)), 0, 9);
            }
            $seen[$key] = true;
            $opts = [];
            foreach (is_array($s['options'] ?? null) ? array_slice($s['options'], 0, 6) : [] as $o) {
                $text = trim(mb_substr((string) (is_array($o) ? ($o['text'] ?? '') : $o), 0, 200));
                if ($text !== '') {
                    $opts[] = ['text' => $text, 'correct' => is_array($o) && !empty($o['correct'])];
                }
            }
            if ($opts !== [] && !in_array(true, array_column($opts, 'correct'), true)) {
                $opts[0]['correct'] = true;
            }
            $tag = (int) ($s['tag_id'] ?? 0);
            if ($tag === 0 && is_string($s['tag'] ?? null)) {
                $tag = $tagByTitle[mb_strtolower(trim($s['tag']))] ?? 0;
            }
            $lesson = (int) ($s['lesson_id'] ?? 0);
            if ($lesson === 0 && is_string($s['lesson'] ?? null)) {
                $lesson = (int) ($lessons['u:' . strtolower($s['lesson'])] ?? 0);
            }
            $out[] = [
                'skey'        => $key,
                'label'       => $label,
                'x'           => round(max(0, min(100, (float) ($s['x'] ?? 50))), 3),
                'y'           => round(max(0, min(100, (float) ($s['y'] ?? 50))), 3),
                'r'           => round(max(0.8, min(25, (float) ($s['r'] ?? 4))), 3),
                'question'    => trim(mb_substr((string) ($s['question'] ?? ''), 0, 500)) ?: null,
                'options'     => count($opts) >= 2 ? $opts : [],
                'hint'        => trim(mb_substr((string) ($s['hint'] ?? ''), 0, 300)) ?: null,
                'explanation' => trim(mb_substr((string) ($s['explanation'] ?? ''), 0, 1000)) ?: null,
                'tag_id'      => isset($tags[$tag]) ? $tag : null,
                'lesson_id'   => isset($lessons[$lesson]) ? $lesson : null,
            ];
        }
        return $out;
    }

    private function storeImage(array $fig, array $file): ?string
    {
        try {
            $store = new MediaStore('figures');
            $name = $store->store($file);
            [$w, $h] = $store->dimensions($name) ?? [0, 0];
            if (!empty($fig['image_path'])) {
                $store->forget($fig['image_path']);
            }
            $this->figures->setImage((int) $fig['id'], $name, $w, $h);
            return null;
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }

    private function ready(): void
    {
        if (!FigureRepository::ready()) {
            throw HttpException::notFound();
        }
    }

    private function figureOr404(string $uuid): array
    {
        $this->ready();
        $fig = $this->figures->findByUuid($uuid);
        if ($fig === null) {
            throw HttpException::notFound();
        }
        return $fig;
    }
}
