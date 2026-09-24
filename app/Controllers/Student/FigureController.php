<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\FigureRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\MediaStore;
use HeleXa\Services\Points;
use HeleXa\Services\SubjectTree;

/**
 * «بازی با شکل» for the student: the shelf of figures and the game — find
 * the named structure, answer about the structure that is pointed at, or
 * just explore. Every answer is checked here, never trusted from the page.
 */
final class FigureController extends Controller
{
    private FigureRepository $figures;

    public function __construct()
    {
        $this->figures = new FigureRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $filters = ['q' => mb_substr(trim($request->string('q')), 0, 80), 'subject' => $request->int('subject')];
        return $this->page('layouts.app', 'student.figures.index', [
            'title'    => 'بازی با شکل',
            'rows'     => FigureRepository::ready() ? $this->figures->search($filters, true) : [],
            'best'     => FigureRepository::ready() ? $this->figures->bestFor($userId) : [],
            'held'     => $this->heldPackages($userId),
            'filters'  => $filters,
            'subjects' => SubjectTree::roots(),
            'extraCss' => ['mindmap', 'lessons', 'figures'],
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $fig = $this->readable((string) ($params['uuid'] ?? ''), $userId);
        $spots = $this->figures->spots((int) $fig['id']);
        // The page gets positions and names (the explore mode shows them
        // anyway) but never which custom choice is right.
        $public = array_map(static fn (array $s): array => [
            'key'      => $s['skey'],
            'label'    => $s['label'],
            'x'        => $s['x'],
            'y'        => $s['y'],
            'r'        => $s['r'],
            'question' => $s['question'],
            'options'  => array_map(static fn (array $o): string => (string) $o['text'], $s['options']),
            'lesson'   => !empty($s['lesson_uuid']) && $s['lesson_status'] === 'published' ? ['uuid' => $s['lesson_uuid'], 'title' => $s['lesson_title']] : null,
            'explain'  => $s['explanation'],
        ], $spots);
        $best = $this->figures->bestFor($userId)[(int) $fig['id']] ?? null;
        return $this->page('layouts.app', 'student.figures.play', [
            'title'    => $fig['title'],
            'fig'      => $fig,
            'spots'    => $public,
            'best'     => $best,
            'extraCss' => ['figures'],
            'extraJs'  => ['figure-game'],
        ]);
    }

    /** POST /student/figures/{uuid}/answer — {mode, key, x, y} or {mode, key, choice}. */
    public function answer(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $fig = $this->readable((string) ($params['uuid'] ?? ''), $userId);
        $body = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : $request->all();
        $mode = ($body['mode'] ?? '') === 'ask' ? 'ask' : 'find';
        $spot = $this->figures->spot((int) $fig['id'], (string) ($body['key'] ?? ''));
        if ($spot === null) {
            return $this->json(['ok' => false, 'message' => 'این نقطه وجود ندارد.'], 404);
        }

        if ($mode === 'find') {
            $w = max(1, (int) $fig['image_w']);
            $h = max(1, (int) $fig['image_h']);
            $dx = ((float) ($body['x'] ?? -100) - (float) $spot['x']) / 100 * $w;
            $dy = ((float) ($body['y'] ?? -100) - (float) $spot['y']) / 100 * $h;
            // A little grace around the drawn circle: a fingertip is not a pin.
            $correct = sqrt($dx * $dx + $dy * $dy) <= (float) $spot['r'] / 100 * $w * 1.15;
            $right = $spot['label'];
        } else {
            $choice = trim((string) ($body['choice'] ?? ''));
            if ($spot['question'] !== null && $spot['options'] !== []) {
                $right = '';
                foreach ($spot['options'] as $o) {
                    if (!empty($o['correct'])) {
                        $right = (string) $o['text'];
                        break;
                    }
                }
            } else {
                $right = (string) $spot['label'];
            }
            $correct = $choice !== '' && mb_strtolower($choice) === mb_strtolower($right);
        }

        $reward = null;
        if ($correct) {
            $reward = Points::award($userId, Points::amount('figure_correct', 5), 'figure_correct', 'figure_spot', (int) $spot['id'],
                'fig:' . $userId . ':' . $spot['id'] . ':' . $mode . ':' . date('Ymd'));
        }
        $lesson = $this->figures->lessonForSpot($spot);
        return $this->json([
            'ok'          => true,
            'correct'     => $correct,
            'right'       => $right,
            'hint'        => $correct ? null : $spot['hint'],
            'explanation' => $spot['explanation'],
            'lesson'      => $lesson,
            'xp'          => $reward['xp'] ?? 0,
        ]);
    }

    /** POST /student/figures/{uuid}/finish — the round's score, for «بهترین امتیاز». */
    public function finish(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $fig = $this->readable((string) ($params['uuid'] ?? ''), $userId);
        $body = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : $request->all();
        $total = max(1, min(FigureRepository::MAX_SPOTS, (int) ($body['total'] ?? 0)));
        $this->figures->recordPlay($userId, (int) $fig['id'], ($body['mode'] ?? '') === 'ask' ? 'ask' : 'find',
            $total, max(0, min($total, (int) ($body['correct'] ?? 0))), max(0, min(36000, (int) ($body['seconds'] ?? 0))));
        return $this->json(['ok' => true, 'best' => $this->figures->bestFor($userId)[(int) $fig['id']] ?? null]);
    }

    public function media(Request $request, array $params = []): Response
    {
        $res = (new MediaStore('figures'))->response((string) ($params['name'] ?? ''));
        if ($res === null) {
            throw HttpException::notFound();
        }
        return $res;
    }

    private function readable(string $uuid, int $userId): array
    {
        $fig = FigureRepository::ready() ? $this->figures->findByUuid($uuid) : null;
        if ($fig === null || empty($fig['image_path'])) {
            throw HttpException::notFound();
        }
        $isAdmin = !Auth::isStudent() && Auth::can('figures.manage');
        if ($fig['status'] !== 'published' && !$isAdmin) {
            throw HttpException::notFound();
        }
        if (!$isAdmin && !empty($fig['package_id']) && !isset($this->heldPackages($userId)[(int) $fig['package_id']])) {
            throw HttpException::forbidden('این بازی برای پکیج دیگری است. از فروشگاه یا کد فعال‌سازی آن را فعال کن.');
        }
        return $fig;
    }

    /** @return array<int,true> */
    private function heldPackages(int $userId): array
    {
        $out = [];
        try {
            foreach (Database::select(
                "SELECT package_id FROM package_activations WHERE user_id = :u AND status = 'active'
                   AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())",
                ['u' => $userId]
            ) as $r) {
                $out[(int) $r['package_id']] = true;
            }
        } catch (\PDOException) {
        }
        return $out;
    }
}
