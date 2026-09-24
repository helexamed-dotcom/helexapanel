<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\MindmapRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\MediaStore;
use HeleXa\Services\MindmapTree;
use HeleXa\Services\SubjectTree;

/**
 * «نقشه‌های ذهنی» for the admin: the list, the XMind-like editor (saved
 * over AJAX), images for topics, and JSON / outline in and out.
 */
final class MindmapController extends Controller
{
    private const TONES = ['violet', 'indigo', 'blue', 'sky', 'teal', 'green', 'amber', 'orange', 'rose', 'pink', 'red', 'slate'];

    private MindmapRepository $maps;

    public function __construct()
    {
        $this->maps = new MindmapRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $this->ready();
        $filters = ['q' => mb_substr(trim($request->string('q')), 0, 80), 'status' => $request->string('status'), 'subject' => $request->int('subject')];
        return $this->page('layouts.app', 'admin.mindmaps.index', [
            'title'    => 'نقشه‌های ذهنی',
            'rows'     => $this->maps->search($filters, false),
            'filters'  => $filters,
            'subjects' => SubjectTree::roots(),
            'sample'   => json_encode(MindmapTree::sample(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'prompt'   => MindmapTree::aiPrompt(),
            'extraCss' => ['mindmap', 'shop-admin'],
        ]);
    }

    /** A new, empty map straight into the editor. */
    public function create(Request $request, array $params = []): Response
    {
        $this->ready();
        $title = trim(mb_substr($request->string('title'), 0, 191)) ?: 'نقشه ذهنی تازه';
        $map = $this->persistNew($title, ['id' => 'root', 'text' => $title, 'children' => []], 'classic', 'map');
        return $this->redirect('/admin/mindmaps/' . $map['uuid'] . '/edit');
    }

    public function edit(Request $request, array $params = []): Response
    {
        $map = $this->mapOr404((string) ($params['uuid'] ?? ''));
        $packages = [];
        try {
            $packages = (new PackageRepository())->all();
        } catch (\PDOException) {
        }
        return $this->page('layouts.app', 'admin.mindmaps.edit', [
            'title'    => 'ویرایش نقشه ذهنی',
            'map'      => $map,
            'tree'     => json_decode((string) $map['data'], true) ?: ['id' => 'root', 'text' => $map['title'], 'children' => []],
            'subjects' => SubjectTree::options(),
            'packages' => $packages,
            'lessons'  => $this->maps->lessonTitles(false),
            'tagIds'   => \HeleXa\Services\SharedTags::idsFor('mindmap_tags', (int) $map['id']),
            'allTags'  => \HeleXa\Services\SharedTags::all(),
            'themes'   => MindmapTree::THEMES,
            'layouts'  => MindmapTree::LAYOUTS,
            'tones'    => self::TONES,
            'colors'   => MindmapTree::COLORS,
            'extraCss' => ['mindmap'],
            'extraJs'  => ['mindmap', 'mindmap-editor'],
        ]);
    }

    /** POST /admin/mindmaps/{uuid} — JSON from the editor. */
    public function save(Request $request, array $params = []): Response
    {
        $map = $this->mapOr404((string) ($params['uuid'] ?? ''));
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            return $this->json(['ok' => false, 'message' => 'داده نقشه نامعتبر است.'], 422);
        }
        $tree = MindmapTree::clean($body['root'] ?? null, $this->maps->lessonIndex());
        $title = trim(mb_substr((string) ($body['title'] ?? ''), 0, 191)) ?: $tree['root']['text'];
        $subject = (int) ($body['subject_id'] ?? 0);
        $package = (int) ($body['package_id'] ?? 0);
        $saved = $this->maps->save((int) $map['id'], [
            'title'      => $title,
            'summary'    => trim(mb_substr((string) ($body['summary'] ?? ''), 0, 300)) ?: null,
            'subject_id' => $subject > 0 ? $subject : null,
            'package_id' => $package > 0 ? $package : null,
            'theme'      => array_key_exists((string) ($body['theme'] ?? ''), MindmapTree::THEMES) ? $body['theme'] : 'classic',
            'layout'     => array_key_exists((string) ($body['layout'] ?? ''), MindmapTree::LAYOUTS) ? $body['layout'] : 'map',
            'tone'       => in_array($body['tone'] ?? '', self::TONES, true) ? $body['tone'] : 'violet',
            'data'       => (string) json_encode($tree['root'], JSON_UNESCAPED_UNICODE),
            'node_count' => $tree['count'],
            'status'     => ($body['status'] ?? '') === 'published' ? 'published' : 'draft',
            'sort_order' => (int) ($body['sort_order'] ?? 0),
        ], (int) Auth::id());
        $this->maps->syncLinks((int) $saved['id'], $tree['lessons']);
        if (isset($body['tags']) && is_array($body['tags'])) {
            \HeleXa\Services\SharedTags::sync('mindmap_tags', (int) $saved['id'],
                \HeleXa\Services\SharedTags::fromValues($body['tags'], mb_substr((string) ($body['new_tags'] ?? ''), 0, 400)));
        }
        return $this->json(['ok' => true, 'count' => $tree['count'], 'status' => $saved['status'], 'saved_at' => jdate($saved['updated_at'])]);
    }

    public function setStatus(Request $request, array $params = []): Response
    {
        $map = $this->mapOr404((string) ($params['uuid'] ?? ''));
        $this->maps->setStatus((int) $map['id'], $request->string('status'));
        $this->flash('success', $request->string('status') === 'published' ? 'نقشه منتشر شد.' : 'به پیش‌نویس برگشت.');
        return $this->redirect('/admin/mindmaps');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $map = $this->mapOr404((string) ($params['uuid'] ?? ''));
        $this->maps->softDelete((int) $map['id']);
        ActivityLogger::log('mindmap.deleted', Auth::id(), 'mindmap', (int) $map['id'], [], 'warning', $request);
        $this->flash('success', 'نقشه حذف شد.');
        return $this->redirect('/admin/mindmaps');
    }

    /** Topic images, from the inspector or a paste. */
    public function upload(Request $request, array $params = []): Response
    {
        $store = new MediaStore('mindmaps');
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
        return $this->json(['ok' => true, 'name' => $name, 'url' => '/media/mindmaps/' . $name]);
    }

    /** A new map from a JSON file (or pasted JSON), or from an indented outline. */
    public function import(Request $request, array $params = []): Response
    {
        $this->ready();
        $raw = '';
        $file = $request->file('file');
        if ($file !== null && (int) ($file['error'] ?? 1) === UPLOAD_ERR_OK && (int) $file['size'] <= 2 * 1024 * 1024) {
            $raw = (string) file_get_contents((string) $file['tmp_name']);
        }
        if (trim($raw) === '') {
            $raw = (string) $request->input('text', '');
        }
        $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? '');
        if ($raw === '') {
            $this->flash('error', 'فایل یا متنی برای ورود نیامد.');
            return $this->redirect('/admin/mindmaps');
        }
        $json = json_decode($raw, true);
        $theme = 'rainbow';
        $layout = 'map';
        if (is_array($json)) {
            $root = isset($json['root']) && is_array($json['root']) ? $json['root'] : (isset($json['text']) ? $json : null);
            if ($root === null) {
                $this->flash('error', 'این JSON ساختار نقشه ذهنی ندارد (کلید root لازم است).');
                return $this->redirect('/admin/mindmaps');
            }
            $theme = array_key_exists((string) ($json['theme'] ?? ''), MindmapTree::THEMES) ? $json['theme'] : 'rainbow';
            $layout = array_key_exists((string) ($json['layout'] ?? ''), MindmapTree::LAYOUTS) ? $json['layout'] : 'map';
            $title = trim(mb_substr((string) ($json['title'] ?? $root['text'] ?? ''), 0, 191));
        } else {
            $root = MindmapTree::fromOutline($raw);
            if ($root === null) {
                $this->flash('error', 'متن قابل تبدیل به نقشه نبود.');
                return $this->redirect('/admin/mindmaps');
            }
            $title = (string) $root['text'];
        }
        $map = $this->persistNew($title ?: 'نقشه واردشده', $root, $theme, $layout);
        $this->flash('success', 'نقشه ساخته شد: ' . fa((string) $map['node_count']) . ' موضوع.');
        return $this->redirect('/admin/mindmaps/' . $map['uuid'] . '/edit');
    }

    public function export(Request $request, array $params = []): Response
    {
        $map = $this->mapOr404((string) ($params['uuid'] ?? ''));
        $root = json_decode((string) $map['data'], true) ?: [];
        if ($request->string('as') === 'outline') {
            return Response::make(MindmapTree::toOutline($root), 200, [
                'Content-Type'        => 'text/plain; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="mindmap-' . substr((string) $map['uuid'], 0, 8) . '.txt"',
            ]);
        }
        $doc = ['format' => 'helexa.mindmap', 'version' => 1, 'title' => $map['title'], 'theme' => $map['theme'], 'layout' => $map['layout'], 'root' => $root];
        return Response::make((string) json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="mindmap-' . substr((string) $map['uuid'], 0, 8) . '.json"',
        ]);
    }

    /* ======================================================== internals */

    private function persistNew(string $title, array $root, string $theme, string $layout): array
    {
        $tree = MindmapTree::clean($root, $this->maps->lessonIndex());
        $map = $this->maps->save(null, [
            'title' => $title, 'summary' => null, 'subject_id' => null, 'package_id' => null, 'theme' => $theme, 'layout' => $layout,
            'tone' => 'violet', 'data' => (string) json_encode($tree['root'], JSON_UNESCAPED_UNICODE), 'node_count' => $tree['count'],
            'status' => 'draft', 'sort_order' => 0,
        ], (int) Auth::id());
        $this->maps->syncLinks((int) $map['id'], $tree['lessons']);
        ActivityLogger::log('mindmap.created', Auth::id(), 'mindmap', (int) $map['id'], ['nodes' => $tree['count']], 'info');
        return $map;
    }

    private function ready(): void
    {
        if (!MindmapRepository::ready()) {
            throw HttpException::notFound();
        }
    }

    private function mapOr404(string $uuid): array
    {
        $this->ready();
        $map = $this->maps->findByUuid($uuid);
        if ($map === null) {
            throw HttpException::notFound();
        }
        return $map;
    }
}
