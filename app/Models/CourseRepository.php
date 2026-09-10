<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class CourseRepository extends BaseRepository
{
    public function all(?string $status = null): array
    {
        $sql    = 'SELECT c.*,
                          (SELECT COUNT(*) FROM course_contents cc WHERE cc.course_id = c.id AND cc.deleted_at IS NULL) AS content_count,
                          (SELECT COUNT(*) FROM student_courses sc WHERE sc.course_id = c.id AND sc.status = "active") AS student_count
                   FROM courses c WHERE c.deleted_at IS NULL';
        $params = [];
        if ($status !== null) {
            $sql .= ' AND c.status = :status';
            $params['status'] = $status;
        }
        return $this->select($sql . ' ORDER BY c.sort_order, c.id', $params);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT * FROM courses WHERE uuid = :uuid AND deleted_at IS NULL LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM courses WHERE id = :id AND deleted_at IS NULL LIMIT 1', ['id' => $id]);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT 1 FROM courses WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return $this->selectOne($sql . ' LIMIT 1', $params) !== null;
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO courses (uuid, title, slug, description, color, status, sort_order, created_by, created_at)
             VALUES (:uuid, :title, :slug, :description, :color, :status, :sort_order, :created_by, :now)',
            [
                'uuid'        => $data['uuid'],
                'title'       => $data['title'],
                'slug'        => $data['slug'],
                'description' => $data['description'] ?? null,
                'color'       => $data['color'] ?? null,
                'status'      => $data['status'] ?? 'draft',
                'sort_order'  => (int) ($data['sort_order'] ?? 0),
                'created_by'  => $data['created_by'] ?? null,
                'now'         => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE courses SET title = :title, slug = :slug, description = :description,
                    color = :color, status = :status, sort_order = :sort_order, updated_at = :now
             WHERE id = :id AND deleted_at IS NULL',
            [
                'title'       => $data['title'],
                'slug'        => $data['slug'],
                'description' => $data['description'] ?? null,
                'color'       => $data['color'] ?? null,
                'status'      => $data['status'],
                'sort_order'  => (int) ($data['sort_order'] ?? 0),
                'now'         => $this->now(),
                'id'          => $id,
            ]
        );
    }

    /** A course's icon lives on its own field and its own tiny form, separate
     * from the rest of the course fields — uploading an image has nothing to
     * do with validating a title or a slug. */
    public function setThumbnail(int $id, ?string $path): void
    {
        $this->execute(
            'UPDATE courses SET thumbnail_path = :path, updated_at = :now WHERE id = :id',
            ['path' => $path, 'now' => $this->now(), 'id' => $id]
        );
    }

    public function softDelete(int $id): void
    {
        $this->execute(
            'UPDATE courses SET deleted_at = :now, status = \'archived\',
                    slug = CONCAT(slug, \'-deleted-\', :suffix) WHERE id = :id AND deleted_at IS NULL',
            ['now' => $this->now(), 'suffix' => (string) $id, 'id' => $id]
        );
    }

    public function countPublished(): int
    {
        return (int) ($this->selectOne(
            "SELECT COUNT(*) AS c FROM courses WHERE status = 'published' AND deleted_at IS NULL"
        )['c'] ?? 0);
    }
}
