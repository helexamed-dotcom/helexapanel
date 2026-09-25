<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\MindmapRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\MediaStore;
use HeleXa\Services\Points;
use HeleXa\Services\SubjectTree;

/**
 * «نقشه‌های ذهنی» for the student: the library and the viewer — pan,
 * zoom, open and close branches, search, a topic's note and image, and
 * the درسنامه it points at.
 */
final class MindmapController extends Controller
{
    private MindmapRepository $maps;

    public function __construct()
    {
        $this->maps = new MindmapRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $filters = ['q' => mb_substr(trim($request->string('q')), 0, 80), 'subject' => $request->int('subject')];
        return $this->page('layouts.app', 'student.mindmaps.index', [
            'title'    => 'نقشه‌های ذهنی',
            'rows'     => MindmapRepository::ready() ? $this->maps->search($filters, true) : [],
            'reads'    => MindmapRepository::ready() ? $this->maps->readsFor($userId) : [],
            'held'     => $this->heldPackages($userId),
            'filters'  => $filters,
            'subjects' => SubjectTree::roots(),
            'extraCss' => ['mindmap', 'lessons'],
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $map = $this->readable((string) ($params['uuid'] ?? ''), $userId);
        $this->maps->touch($userId, (int) $map['id']);
        $state = $this->maps->readState($userId, (int) $map['id']);
        return $this->page('layouts.app', 'student.mindmaps.show', [
            'title'    => $map['title'],
            'map'      => $map,
            'tree'     => json_decode((string) $map['data'], true) ?: ['id' => 'root', 'text' => $map['title'], 'children' => []],
            'lessons'  => $this->maps->lessonTitles(true),
            'done'     => !empty($state['done_at']),
            'extraCss' => ['mindmap'],
            'extraJs'  => ['mindmap', 'mindmap-viewer'],
        ]);
    }

    /** «مرور کردم» — paid once. */
    public function done(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $map = $this->readable((string) ($params['uuid'] ?? ''), $userId);
        $this->maps->touch($userId, (int) $map['id']);
        $reward = null;
        if ($this->maps->markDone($userId, (int) $map['id'])) {
            $reward = Points::award($userId, Points::amount('mindmap_done', 10), 'mindmap_done', 'mindmap', (int) $map['id'],
                'mindmap_done:' . $userId . ':' . $map['id']);
        }
        return $this->json(['ok' => true, 'xp' => $reward]);
    }

    public function media(Request $request, array $params = []): Response
    {
        $res = (new MediaStore('mindmaps'))->response((string) ($params['name'] ?? ''));
        if ($res === null) {
            throw HttpException::notFound();
        }
        return $res;
    }

    private function readable(string $uuid, int $userId): array
    {
        $map = MindmapRepository::ready() ? $this->maps->findByUuid($uuid) : null;
        if ($map === null) {
            throw HttpException::notFound();
        }
        $isAdmin = !Auth::isStudent() && Auth::can('lessons.manage');
        if ($map['status'] !== 'published' && !$isAdmin) {
            throw HttpException::notFound();
        }
        if (!$isAdmin && !empty($map['package_id']) && !isset($this->heldPackages($userId)[(int) $map['package_id']])) {
            throw HttpException::forbidden('این نقشه برای پکیج دیگری است. از فروشگاه یا کد فعال‌سازی آن را فعال کن.');
        }
        return $map;
    }

    /** @return array<int,true> */
    private function heldPackages(int $userId): array
    {
        // A full-access package holds every package's content.
        return \HeleXa\Services\AccessProfile::heldMap($userId);
    }
}
