<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Str;

final class LibraryRepository extends BaseRepository
{
    public const KINDS = [
        'video'   => 'ویدیو',
        'article' => 'مقاله',
        'image'   => 'تصویر',
        'file'    => 'فایل',
        'link'    => 'لینک',
    ];

    /**
     * The admin list: every undeleted item.
     *
     * @param array{q?:string, kind?:string, status?:string} $filters
     */
    public function adminList(array $filters): array
    {
        [$where, $params] = $this->filters($filters);
        if (in_array($filters['status'] ?? '', ['draft', 'published'], true)) {
            $where[]          = 'l.status = :status';
            $params['status'] = $filters['status'];
        }

        return $this->select(
            'SELECT l.*, c.title AS course_title
             FROM library_items l LEFT JOIN courses c ON c.id = l.course_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY l.sort_order, l.id DESC LIMIT 300',
            $params
        );
    }

    /**
     * What one student may see: published items for everyone, plus items tied
     * to a course the student holds right now.
     */
    public function forStudent(int $userId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->studentScope($userId, $filters);

        return $this->select(
            'SELECT l.id, l.uuid, l.kind, l.title, l.summary, l.cover_path, l.file_size, l.created_at, l.view_count,
                    c.title AS course_title
             FROM library_items l LEFT JOIN courses c ON c.id = l.course_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY l.sort_order, l.id DESC
             LIMIT ' . max(1, min($limit, 60)) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function countForStudent(int $userId, array $filters): int
    {
        [$where, $params] = $this->studentScope($userId, $filters);

        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM library_items l WHERE ' . implode(' AND ', $where),
            $params
        )['c'] ?? 0);
    }

    /** One item, only if this student may open it. */
    public function findForStudent(int $userId, string $uuid): ?array
    {
        [$where, $params] = $this->studentScope($userId, []);
        $where[]        = 'l.uuid = :uuid';
        $params['uuid'] = $uuid;

        return $this->selectOne(
            'SELECT l.*, c.title AS course_title FROM library_items l
             LEFT JOIN courses c ON c.id = l.course_id
             WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
            $params
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT * FROM library_items WHERE uuid = :uuid AND deleted_at IS NULL LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    public function create(array $data, ?int $authorId): array
    {
        $uuid = Str::uuid4();
        $id   = $this->insert(
            'INSERT INTO library_items
                (uuid, kind, title, summary, body, url, file_path, file_mime, file_size, file_name,
                 cover_path, course_id, sort_order, status, created_by, created_at)
             VALUES
                (:uuid, :kind, :title, :summary, :body, :url, :file_path, :file_mime, :file_size, :file_name,
                 :cover, :course, :sort, :status, :author, :now)',
            $this->bind($data) + ['uuid' => $uuid, 'author' => $authorId, 'now' => $this->now()]
        );

        return ['id' => $id, 'uuid' => $uuid];
    }

    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE library_items SET
                kind = :kind, title = :title, summary = :summary, body = :body, url = :url,
                file_path = :file_path, file_mime = :file_mime, file_size = :file_size, file_name = :file_name,
                cover_path = :cover, course_id = :course, sort_order = :sort, status = :status, updated_at = :now
             WHERE id = :id',
            $this->bind($data) + ['now' => $this->now(), 'id' => $id]
        );
    }

    public function softDelete(int $id): void
    {
        $this->execute('UPDATE library_items SET deleted_at = :now WHERE id = :id', ['now' => $this->now(), 'id' => $id]);
    }

    public function countView(int $id): void
    {
        $this->execute('UPDATE library_items SET view_count = view_count + 1 WHERE id = :id', ['id' => $id]);
    }

    /* ---------------------------------------------------------- helpers */

    private function bind(array $d): array
    {
        return [
            'kind'      => $d['kind'],
            'title'     => $d['title'],
            'summary'   => $d['summary'] ?? null,
            'body'      => $d['body'] ?? null,
            'url'       => $d['url'] ?? null,
            'file_path' => $d['file_path'] ?? null,
            'file_mime' => $d['file_mime'] ?? null,
            'file_size' => $d['file_size'] ?? null,
            'file_name' => $d['file_name'] ?? null,
            'cover'     => $d['cover_path'] ?? null,
            'course'    => $d['course_id'] ?? null,
            'sort'      => (int) ($d['sort_order'] ?? 0),
            'status'    => ($d['status'] ?? '') === 'published' ? 'published' : 'draft',
        ];
    }

    /** @return array{0:array<int,string>,1:array<string,mixed>} */
    private function filters(array $filters): array
    {
        $where  = ['l.deleted_at IS NULL'];
        $params = [];

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like    = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $where[] = '(l.title LIKE :q1 OR l.summary LIKE :q2 OR l.body LIKE :q3)';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like];
        }
        if (isset(self::KINDS[$filters['kind'] ?? ''])) {
            $where[]        = 'l.kind = :kind';
            $params['kind'] = $filters['kind'];
        }

        return [$where, $params];
    }

    /** @return array{0:array<int,string>,1:array<string,mixed>} */
    private function studentScope(int $userId, array $filters): array
    {
        [$where, $params] = $this->filters($filters);
        $where[] = "l.status = 'published'";
        // Same window rules the lesson viewer enforces.
        $where[] = "(l.course_id IS NULL OR EXISTS (
                        SELECT 1 FROM student_courses sc
                         WHERE sc.course_id = l.course_id AND sc.user_id = :scope_user AND sc.status = 'active'
                           AND (sc.starts_at IS NULL OR sc.starts_at <= :scope_now1)
                           AND (sc.ends_at   IS NULL OR sc.ends_at   >= :scope_now2)))";
        $params += ['scope_user' => $userId, 'scope_now1' => $this->now(), 'scope_now2' => $this->now()];

        return [$where, $params];
    }
}
