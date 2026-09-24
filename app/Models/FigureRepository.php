<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Database;
use HeleXa\Core\Str;

/**
 * «بازی با شکل»: the images, their hotspots, and the rounds students play.
 */
final class FigureRepository extends BaseRepository
{
    public const MAX_SPOTS = 120;

    public static function ready(): bool
    {
        try {
            Database::selectOne('SELECT 1 FROM figures LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @param array{q?:string, subject?:int, status?:string} $f */
    public function search(array $f, bool $publishedOnly): array
    {
        $where = ['f.deleted_at IS NULL'];
        $p = [];
        if ($publishedOnly) {
            $where[] = "f.status = 'published'";
            $where[] = 'f.image_path IS NOT NULL';
        } elseif (in_array($f['status'] ?? '', ['draft', 'published'], true)) {
            $where[] = 'f.status = :st';
            $p['st'] = $f['status'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(f.title LIKE :q1 OR f.summary LIKE :q2)';
            $p['q1'] = $p['q2'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['q']) . '%';
        }
        if ((int) ($f['subject'] ?? 0) > 0) {
            $where[] = '(f.subject_id = :s1 OR f.subject_id IN (SELECT id FROM qb_subjects WHERE parent_id = :s2)
                         OR f.subject_id IN (SELECT c.id FROM qb_subjects c JOIN qb_subjects p ON p.id = c.parent_id WHERE p.parent_id = :s3))';
            $p['s1'] = $p['s2'] = $p['s3'] = (int) $f['subject'];
        }
        return $this->select(
            'SELECT f.*, s.title AS subject_title, ps.title AS parent_title,
                    (SELECT COUNT(*) FROM figure_spots fs WHERE fs.figure_id = f.id) AS spot_count
             FROM figures f LEFT JOIN qb_subjects s ON s.id = f.subject_id LEFT JOIN qb_subjects ps ON ps.id = s.parent_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY f.sort_order, f.id DESC LIMIT 300',
            $p
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM figures WHERE uuid = :u AND deleted_at IS NULL', ['u' => $uuid]);
    }

    public function create(string $title, ?int $actorId): array
    {
        $uuid = Str::uuid4();
        $this->insert(
            'INSERT INTO figures (uuid, title, created_by, created_at) VALUES (:uuid, :t, :by, :now)',
            ['uuid' => $uuid, 't' => $title, 'by' => $actorId, 'now' => $this->now()]
        );
        return $this->findByUuid($uuid) ?? [];
    }

    public function saveMeta(int $id, array $d): void
    {
        $this->execute(
            'UPDATE figures SET title = :t, summary = :s, subject_id = :sub, package_id = :pk, tone = :tn, status = :st, sort_order = :so, updated_at = :now WHERE id = :id',
            ['t' => $d['title'], 's' => $d['summary'], 'sub' => $d['subject_id'], 'pk' => $d['package_id'], 'tn' => $d['tone'],
             'st' => $d['status'], 'so' => $d['sort_order'], 'id' => $id, 'now' => $this->now()]
        );
    }

    public function setImage(int $id, ?string $path, int $w, int $h): void
    {
        $this->execute('UPDATE figures SET image_path = :p, image_w = :w, image_h = :h, updated_at = :now WHERE id = :id',
            ['p' => $path, 'w' => $w, 'h' => $h, 'id' => $id, 'now' => $this->now()]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->execute('UPDATE figures SET status = :s, updated_at = :now WHERE id = :id',
            ['s' => $status === 'published' ? 'published' : 'draft', 'id' => $id, 'now' => $this->now()]);
    }

    public function softDelete(int $id): void
    {
        $this->execute('UPDATE figures SET deleted_at = :now WHERE id = :id', ['id' => $id, 'now' => $this->now()]);
    }

    /* ============================================================ spots */

    public function spots(int $figureId): array
    {
        $rows = $this->select(
            'SELECT fs.*, l.uuid AS lesson_uuid, l.title AS lesson_title, l.status AS lesson_status, t.title AS tag_title
             FROM figure_spots fs LEFT JOIN lessons l ON l.id = fs.lesson_id AND l.deleted_at IS NULL
             LEFT JOIN qb_tags t ON t.id = fs.tag_id
             WHERE fs.figure_id = :f ORDER BY fs.sort_order, fs.id',
            ['f' => $figureId]
        );
        foreach ($rows as &$r) {
            $r['options'] = $r['options'] !== null ? (json_decode((string) $r['options'], true) ?: []) : [];
            $r['x'] = (float) $r['x'];
            $r['y'] = (float) $r['y'];
            $r['r'] = (float) $r['r'];
        }
        return $rows;
    }

    /**
     * Replaces the hotspots with $spots (already validated), keeping each
     * spot's row — and so its id — when its key survives.
     *
     * @param list<array> $spots
     */
    public function syncSpots(int $figureId, array $spots): void
    {
        Database::transaction(function () use ($figureId, $spots): void {
            $keys = array_column($spots, 'skey');
            if ($keys === []) {
                $this->execute('DELETE FROM figure_spots WHERE figure_id = :f', ['f' => $figureId]);
            } else {
                $in = implode(',', array_map(static fn (string $k): string => Database::connection()->quote($k), $keys));
                $this->execute("DELETE FROM figure_spots WHERE figure_id = :f AND skey NOT IN ({$in})", ['f' => $figureId]);
            }
            foreach ($spots as $i => $s) {
                $this->execute(
                    'INSERT INTO figure_spots (figure_id, skey, label, x, y, r, question, options, hint, explanation, tag_id, lesson_id, sort_order)
                     VALUES (:f, :k, :l, :x, :y, :r, :q, :o, :h, :e, :t, :les, :so)
                     ON DUPLICATE KEY UPDATE label = VALUES(label), x = VALUES(x), y = VALUES(y), r = VALUES(r), question = VALUES(question),
                        options = VALUES(options), hint = VALUES(hint), explanation = VALUES(explanation), tag_id = VALUES(tag_id),
                        lesson_id = VALUES(lesson_id), sort_order = VALUES(sort_order)',
                    [
                        'f' => $figureId, 'k' => $s['skey'], 'l' => $s['label'], 'x' => $s['x'], 'y' => $s['y'], 'r' => $s['r'],
                        'q' => $s['question'], 'o' => $s['options'] === [] ? null : json_encode($s['options'], JSON_UNESCAPED_UNICODE),
                        'h' => $s['hint'], 'e' => $s['explanation'], 't' => $s['tag_id'], 'les' => $s['lesson_id'], 'so' => $i,
                    ]
                );
            }
        });
    }

    public function spot(int $figureId, string $key): ?array
    {
        $r = $this->selectOne('SELECT * FROM figure_spots WHERE figure_id = :f AND skey = :k', ['f' => $figureId, 'k' => $key]);
        if ($r !== null) {
            $r['options'] = $r['options'] !== null ? (json_decode((string) $r['options'], true) ?: []) : [];
        }
        return $r;
    }

    /* ============================================================ plays */

    public function recordPlay(int $userId, int $figureId, string $mode, int $total, int $correct, int $seconds): void
    {
        $this->insert(
            'INSERT INTO figure_plays (user_id, figure_id, mode, total, correct, seconds, created_at) VALUES (:u, :f, :m, :t, :c, :s, :now)',
            ['u' => $userId, 'f' => $figureId, 'm' => $mode, 't' => $total, 'c' => $correct, 's' => $seconds, 'now' => $this->now()]
        );
    }

    /** @return array<int,array{best:int, plays:int}> figure id => best percent and rounds played */
    public function bestFor(int $userId): array
    {
        $out = [];
        foreach ($this->select(
            'SELECT figure_id, MAX(ROUND(correct * 100 / GREATEST(total, 1))) AS best, COUNT(*) AS plays FROM figure_plays WHERE user_id = :u GROUP BY figure_id',
            ['u' => $userId]
        ) as $r) {
            $out[(int) $r['figure_id']] = ['best' => (int) $r['best'], 'plays' => (int) $r['plays']];
        }
        return $out;
    }

    /** Tags and lessons for the editor's pickers. */
    public function tagOptions(): array
    {
        try {
            return $this->select('SELECT id, title FROM qb_tags ORDER BY title');
        } catch (\PDOException) {
            return [];
        }
    }

    public function lessonOptions(): array
    {
        try {
            return $this->select('SELECT id, uuid, title, status FROM lessons WHERE deleted_at IS NULL ORDER BY title');
        } catch (\PDOException) {
            return [];
        }
    }

    /** A درسنامه that teaches a spot: its own, else one sharing its tag. */
    public function lessonForSpot(array $spot): ?array
    {
        if (!empty($spot['lesson_id'])) {
            return $this->selectOne("SELECT uuid, title FROM lessons WHERE id = :l AND status = 'published' AND deleted_at IS NULL", ['l' => (int) $spot['lesson_id']]);
        }
        if (!empty($spot['tag_id'])) {
            try {
                return $this->selectOne(
                    "SELECT l.uuid, l.title FROM lesson_tags lt JOIN lessons l ON l.id = lt.lesson_id AND l.status = 'published' AND l.deleted_at IS NULL
                     WHERE lt.tag_id = :t ORDER BY l.sort_order LIMIT 1",
                    ['t' => (int) $spot['tag_id']]
                );
            } catch (\PDOException) {
            }
        }
        return null;
    }
}
