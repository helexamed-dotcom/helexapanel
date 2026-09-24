<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\LessonRepository;
use HeleXa\Models\QuestionBank\QbTagRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\MediaStore;
use HeleXa\Services\Points;
use HeleXa\Services\RichText;
use HeleXa\Services\SubjectTree;

/**
 * «درسنامه‌ها» for the student: the library, the reader with its own
 * highlighter, and the links out to the questions, mind maps and figure
 * games that share a lesson's tags.
 */
final class LessonController extends Controller
{
    private const HIGHLIGHT_LIMIT = 400;

    private LessonRepository $lessons;

    public function __construct()
    {
        $this->lessons = new LessonRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $userId  = (int) Auth::id();
        $filters = ['q' => mb_substr(trim($request->string('q')), 0, 80), 'subject' => $request->int('subject'), 'tag' => $request->int('tag')];
        $rows = LessonRepository::ready() ? $this->lessons->search($filters, true) : [];
        $held = $this->heldPackages($userId);

        return $this->page('layouts.app', 'student.lessons.index', [
            'title'    => 'درسنامه‌ها',
            'rows'     => $rows,
            'held'     => $held,
            'states'   => LessonRepository::ready() ? $this->lessons->statesFor($userId) : [],
            'filters'  => $filters,
            'subjects' => SubjectTree::roots(),
            'tags'     => $this->usedTags(),
            'extraCss' => ['lessons'],
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $lesson = $this->readable((string) ($params['uuid'] ?? ''), $userId);
        $this->lessons->touch($userId, (int) $lesson['id']);
        $state = $this->lessons->readState($userId, (int) $lesson['id']) ?? [];
        $tags  = $this->lessons->tagsFor((int) $lesson['id']);

        return $this->page('layouts.app', 'student.lessons.show', [
            'title'      => $lesson['title'],
            'lesson'     => $lesson,
            'path'       => SubjectTree::pathOf($lesson['subject_id'] === null ? null : (int) $lesson['subject_id']),
            'toc'        => RichText::headings((string) $lesson['body_html']),
            'tags'       => $tags,
            'state'      => $state,
            'related'    => $this->related($lesson, $tags),
            'extraCss'   => ['lessons'],
            'extraJs'    => ['lesson-reader'],
        ]);
    }

    /**
     * The student's own layer: highlights and text colours, the reading
     * position, the bookmark, and «خواندم» — which pays points once.
     */
    public function saveState(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $lesson = $this->readable((string) ($params['uuid'] ?? ''), $userId);
        $body = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : $request->all();
        $fields = [];

        if (isset($body['highlights']) && is_array($body['highlights'])) {
            $fields['highlights'] = json_encode($this->cleanHighlights($body['highlights']), JSON_UNESCAPED_UNICODE);
        }
        if (isset($body['progress'])) {
            $fields['progress'] = max(0, min(100, (int) $body['progress']));
        }
        if (isset($body['bookmarked'])) {
            $fields['bookmarked'] = $body['bookmarked'] ? 1 : 0;
        }
        $reward = null;
        if (!empty($body['read'])) {
            $state = $this->lessons->readState($userId, (int) $lesson['id']);
            if (empty($state['read_at'])) {
                $fields['read_at'] = date('Y-m-d H:i:s');
                $fields['progress'] = 100;
                $reward = Points::award($userId, Points::amount('lesson_read', 15), 'lesson_read', 'lesson', (int) $lesson['id'],
                    'lesson_read:' . $userId . ':' . $lesson['id']);
            }
        }
        $this->lessons->saveState($userId, (int) $lesson['id'], $fields);

        return $this->json(['ok' => true, 'xp' => $reward]);
    }

    /** Lesson images, for anyone signed in who may read lessons. */
    public function media(Request $request, array $params = []): Response
    {
        $res = (new MediaStore('lessons'))->response((string) ($params['name'] ?? ''));
        if ($res === null) {
            throw HttpException::notFound();
        }
        return $res;
    }

    /* -------------------------------------------------------- internals */

    private function readable(string $uuid, int $userId): array
    {
        $lesson = LessonRepository::ready() ? $this->lessons->findByUuid($uuid) : null;
        if ($lesson === null) {
            throw HttpException::notFound();
        }
        $isAdmin = !Auth::isStudent() && Auth::can('lessons.manage');
        if ($lesson['status'] !== 'published' && !$isAdmin) {
            throw HttpException::notFound();
        }
        if (!$isAdmin && !empty($lesson['package_id']) && !isset($this->heldPackages($userId)[(int) $lesson['package_id']])) {
            throw HttpException::forbidden('این درسنامه برای پکیج دیگری است. از فروشگاه یا کد فعال‌سازی آن را فعال کن.');
        }
        return $lesson;
    }

    /** @return array<int,true> package ids the student holds right now */
    private function heldPackages(int $userId): array
    {
        $out = [];
        try {
            foreach (Database::select(
                "SELECT pa.package_id FROM package_activations pa WHERE pa.user_id = :u AND pa.status = 'active'
                   AND (pa.starts_at IS NULL OR pa.starts_at <= NOW()) AND (pa.ends_at IS NULL OR pa.ends_at >= NOW())",
                ['u' => $userId]
            ) as $r) {
                $out[(int) $r['package_id']] = true;
            }
        } catch (\PDOException) {
            // packages not installed
        }
        return $out;
    }

    private function usedTags(): array
    {
        if (!LessonRepository::ready()) {
            return [];
        }
        $counts = $this->lessons->tagCounts();
        return array_values(array_filter((new QbTagRepository())->all(true), static fn (array $t): bool => isset($counts[(int) $t['id']])));
    }

    /**
     * What else teaches the same thing: questions that share a tag, mind maps
     * and figure games that point here or share a tag, and the درسنامه‌های
     * of the same درس.
     */
    private function related(array $lesson, array $tags): array
    {
        $tagIds = array_map('intval', array_column($tags, 'id'));
        $out = ['questions' => 0, 'qbank_link' => null, 'mindmaps' => [], 'figures' => [], 'siblings' => []];
        try {
            if ($tagIds !== []) {
                $list = implode(',', $tagIds);
                $row = Database::selectOne(
                    "SELECT COUNT(DISTINCT q.id) AS c, MIN(s.uuid) AS subject_uuid, MIN(qt.tag_id) AS tag_id
                     FROM qb_question_tags qt JOIN qb_questions q ON q.id = qt.question_id AND q.status = 'published' AND q.deleted_at IS NULL
                     JOIN qb_subjects s ON s.id = q.subject_id
                     WHERE qt.tag_id IN ({$list})"
                );
                $out['questions'] = (int) ($row['c'] ?? 0);
                if ($out['questions'] > 0 && !empty($row['subject_uuid'])) {
                    $out['qbank_link'] = '/student/qbank/' . $row['subject_uuid'] . '?tag=' . (int) $row['tag_id'];
                }
            }
            $direct = (int) Database::selectOne(
                "SELECT COUNT(*) AS c FROM qb_question_lessons x JOIN qb_questions q ON q.id = x.question_id AND q.status = 'published' WHERE x.lesson_id = :l",
                ['l' => (int) $lesson['id']]
            )['c'];
            $out['questions'] = max($out['questions'], $direct);
            if ($lesson['subject_id'] !== null) {
                $out['siblings'] = array_values(array_filter(
                    $this->lessons->search(['subject' => (int) $lesson['subject_id']], true, 8),
                    static fn (array $l): bool => (int) $l['id'] !== (int) $lesson['id']
                ));
            }
        } catch (\PDOException) {
            // a module not installed: its block is left out
        }
        try {
            $out['mindmaps'] = Database::select(
                "SELECT DISTINCT m.uuid, m.title FROM mindmaps m
                 LEFT JOIN mindmap_links ml ON ml.mindmap_id = m.id
                 WHERE m.status = 'published' AND m.deleted_at IS NULL AND (ml.lesson_id = :l OR m.subject_id = :s)
                 LIMIT 6",
                ['l' => (int) $lesson['id'], 's' => (int) ($lesson['subject_id'] ?? -1)]
            );
        } catch (\PDOException) {
        }
        try {
            if ($tagIds !== []) {
                $list = implode(',', $tagIds);
                $out['figures'] = Database::select(
                    "SELECT DISTINCT f.uuid, f.title FROM figures f JOIN figure_spots fs ON fs.figure_id = f.id
                     WHERE f.status = 'published' AND f.deleted_at IS NULL AND (fs.tag_id IN ({$list}) OR fs.lesson_id = :l) LIMIT 6",
                    ['l' => (int) $lesson['id']]
                );
            }
        } catch (\PDOException) {
        }
        return $out;
    }

    /**
     * A highlight is {b: block id, s: start, e: end, c: colour key, k: kind,
     * q: the quoted text}. Anything else in the array is dropped.
     */
    private function cleanHighlights(array $list): array
    {
        $out = [];
        foreach (array_slice($list, 0, self::HIGHLIGHT_LIMIT) as $h) {
            if (!is_array($h) || !is_string($h['b'] ?? null) || preg_match('/^[a-z0-9]{3,12}$/', $h['b']) !== 1) {
                continue;
            }
            $s = (int) ($h['s'] ?? -1);
            $e = (int) ($h['e'] ?? -1);
            if ($s < 0 || $e <= $s || $e - $s > 5000) {
                continue;
            }
            $kind = in_array($h['k'] ?? '', ['hl', 'tc', 'ul', 'bold'], true) ? $h['k'] : 'hl';
            $color = preg_match('/^(yellow|green|pink|blue|orange|violet|red|teal)$/', (string) ($h['c'] ?? '')) === 1 ? $h['c'] : 'yellow';
            $out[] = ['id' => substr(preg_replace('/[^a-z0-9]/', '', (string) ($h['id'] ?? '')) ?: bin2hex(random_bytes(4)), 0, 12),
                      'b' => $h['b'], 's' => $s, 'e' => $e, 'k' => $kind, 'c' => $color,
                      'q' => mb_substr((string) ($h['q'] ?? ''), 0, 300), 'n' => mb_substr(trim((string) ($h['n'] ?? '')), 0, 500)];
        }
        return $out;
    }
}
