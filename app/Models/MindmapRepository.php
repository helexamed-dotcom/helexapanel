<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Database;
use HeleXa\Core\Str;

/**
 * «نقشه‌های ذهنی»: the maps, the درسنامه‌ها their nodes point at, and each
 * student's «مرور کردم».
 */
final class MindmapRepository extends BaseRepository
{
    public static function ready(): bool
    {
        try {
            Database::selectOne('SELECT 1 FROM mindmaps LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @param array{q?:string, subject?:int, status?:string} $f */
    public function search(array $f, bool $publishedOnly, int $limit = 300): array
    {
        $where = ['m.deleted_at IS NULL'];
        $p = [];
        if ($publishedOnly) {
            $where[] = "m.status = 'published'";
        } elseif (in_array($f['status'] ?? '', ['draft', 'published'], true)) {
            $where[] = 'm.status = :st';
            $p['st'] = $f['status'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(m.title LIKE :q1 OR m.summary LIKE :q2)';
            $p['q1'] = $p['q2'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['q']) . '%';
        }
        if ((int) ($f['subject'] ?? 0) > 0) {
            $where[] = '(m.subject_id = :s1 OR m.subject_id IN (SELECT id FROM qb_subjects WHERE parent_id = :s2)
                         OR m.subject_id IN (SELECT c.id FROM qb_subjects c JOIN qb_subjects p ON p.id = c.parent_id WHERE p.parent_id = :s3))';
            $p['s1'] = $p['s2'] = $p['s3'] = (int) $f['subject'];
        }
        return $this->select(
            'SELECT m.id, m.uuid, m.title, m.summary, m.subject_id, m.package_id, m.theme, m.layout, m.tone, m.node_count, m.status,
                    m.sort_order, m.created_at, m.updated_at, s.title AS subject_title, ps.title AS parent_title
             FROM mindmaps m LEFT JOIN qb_subjects s ON s.id = m.subject_id LEFT JOIN qb_subjects ps ON ps.id = s.parent_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY m.sort_order, m.id DESC LIMIT ' . max(1, min(500, $limit)),
            $p
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM mindmaps WHERE uuid = :u AND deleted_at IS NULL', ['u' => $uuid]);
    }

    public function save(?int $id, array $d, ?int $actorId): array
    {
        $row = [
            't' => $d['title'], 'sm' => $d['summary'], 's' => $d['subject_id'], 'pk' => $d['package_id'], 'th' => $d['theme'],
            'ly' => $d['layout'], 'tn' => $d['tone'], 'd' => $d['data'], 'nc' => $d['node_count'], 'st' => $d['status'],
            'so' => $d['sort_order'], 'now' => $this->now(),
        ];
        if ($id !== null) {
            $this->execute(
                'UPDATE mindmaps SET title = :t, summary = :sm, subject_id = :s, package_id = :pk, theme = :th, layout = :ly, tone = :tn,
                    data = :d, node_count = :nc, status = :st, sort_order = :so, updated_at = :now WHERE id = :id',
                $row + ['id' => $id]
            );
        } else {
            $id = $this->insert(
                'INSERT INTO mindmaps (uuid, title, summary, subject_id, package_id, theme, layout, tone, data, node_count, status, sort_order, created_by, created_at)
                 VALUES (:uuid, :t, :sm, :s, :pk, :th, :ly, :tn, :d, :nc, :st, :so, :by, :now)',
                $row + ['uuid' => Str::uuid4(), 'by' => $actorId]
            );
        }
        return $this->selectOne('SELECT * FROM mindmaps WHERE id = :id', ['id' => $id]) ?? [];
    }

    /** @param list<int> $lessonIds */
    public function syncLinks(int $mapId, array $lessonIds): void
    {
        Database::transaction(function () use ($mapId, $lessonIds): void {
            $this->execute('DELETE FROM mindmap_links WHERE mindmap_id = :m', ['m' => $mapId]);
            foreach (array_unique(array_map('intval', $lessonIds)) as $l) {
                $this->execute('INSERT IGNORE INTO mindmap_links (mindmap_id, lesson_id) VALUES (:m, :l)', ['m' => $mapId, 'l' => $l]);
            }
        });
    }

    public function setStatus(int $id, string $status): void
    {
        $this->execute('UPDATE mindmaps SET status = :s, updated_at = :now WHERE id = :id',
            ['s' => $status === 'published' ? 'published' : 'draft', 'id' => $id, 'now' => $this->now()]);
    }

    public function softDelete(int $id): void
    {
        $this->execute('UPDATE mindmaps SET deleted_at = :now WHERE id = :id', ['id' => $id, 'now' => $this->now()]);
    }

    /** @return array<string,int> uuid => id of every درسنامه that exists */
    public function lessonIndex(): array
    {
        $out = [];
        try {
            foreach ($this->select('SELECT id, uuid FROM lessons WHERE deleted_at IS NULL') as $r) {
                $out[strtolower((string) $r['uuid'])] = (int) $r['id'];
            }
        } catch (\PDOException) {
        }
        return $out;
    }

    /** uuid => title / status of the درسنامه‌ها, for the editor's picker and the viewer's links. */
    public function lessonTitles(bool $publishedOnly): array
    {
        $out = [];
        try {
            foreach ($this->select('SELECT uuid, title, status FROM lessons WHERE deleted_at IS NULL' . ($publishedOnly ? " AND status = 'published'" : '') . ' ORDER BY title') as $r) {
                $out[strtolower((string) $r['uuid'])] = ['title' => $r['title'], 'status' => $r['status']];
            }
        } catch (\PDOException) {
        }
        return $out;
    }

    public function touch(int $userId, int $mapId): void
    {
        $this->execute(
            'INSERT INTO mindmap_reads (user_id, mindmap_id, opened_at) VALUES (:u, :m, :now) ON DUPLICATE KEY UPDATE opened_at = VALUES(opened_at)',
            ['u' => $userId, 'm' => $mapId, 'now' => $this->now()]
        );
    }

    /** True the first time only. */
    public function markDone(int $userId, int $mapId): bool
    {
        return $this->execute('UPDATE mindmap_reads SET done_at = :now WHERE user_id = :u AND mindmap_id = :m AND done_at IS NULL',
            ['u' => $userId, 'm' => $mapId, 'now' => $this->now()]) === 1;
    }

    /** @return array<int,array> mindmap id => opened_at / done_at */
    public function readsFor(int $userId): array
    {
        $out = [];
        foreach ($this->select('SELECT mindmap_id, opened_at, done_at FROM mindmap_reads WHERE user_id = :u', ['u' => $userId]) as $r) {
            $out[(int) $r['mindmap_id']] = $r;
        }
        return $out;
    }

    public function readState(int $userId, int $mapId): ?array
    {
        return $this->selectOne('SELECT * FROM mindmap_reads WHERE user_id = :u AND mindmap_id = :m', ['u' => $userId, 'm' => $mapId]);
    }
}
