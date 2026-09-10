<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * Tree of sections. Adjacency list plus a materialized path so a subtree
 * can be fetched or moved without recursive queries.
 */
final class SectionRepository extends BaseRepository
{
    private const MAX_DEPTH = 4;

    public function forCourse(int $courseId): array
    {
        return $this->select(
            'SELECT * FROM course_sections
             WHERE course_id = :course AND deleted_at IS NULL
             ORDER BY path, sort_order, id',
            ['course' => $courseId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->selectOne(
            'SELECT * FROM course_sections WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $id]
        );
    }

    public function create(int $courseId, ?int $parentId, array $data): int
    {
        $depth = 0;
        $path  = '/';

        if ($parentId !== null) {
            $parent = $this->find($parentId);
            if ($parent === null || (int) $parent['course_id'] !== $courseId) {
                throw new \InvalidArgumentException('Parent section does not belong to this course.');
            }
            $depth = (int) $parent['depth'] + 1;
            if ($depth > self::MAX_DEPTH) {
                throw new \RuntimeException('حداکثر عمق درخت رعایت نشده است.');
            }
            $path = $parent['path'] . $parent['id'] . '/';
        }

        $id = $this->insert(
            'INSERT INTO course_sections
                (course_id, parent_id, title, description, section_type, status, depth, path, sort_order, created_at)
             VALUES (:course, :parent, :title, :description, :type, :status, :depth, :path, :sort_order, :now)',
            [
                'course'      => $courseId,
                'parent'      => $parentId,
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'type'        => $data['section_type'] ?? 'folder',
                'status'      => $data['status'] ?? 'published',
                'depth'       => $depth,
                'path'        => $path,
                'sort_order'  => $this->nextOrder($courseId, $parentId),
                'now'         => $this->now(),
            ]
        );

        return $id;
    }

    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE course_sections SET title = :title, description = :description,
                    section_type = :type, status = :status, updated_at = :now
             WHERE id = :id AND deleted_at IS NULL',
            [
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'type'        => $data['section_type'] ?? 'folder',
                'status'      => $data['status'] ?? 'published',
                'now'         => $this->now(),
                'id'          => $id,
            ]
        );
    }

    /** Cascades to descendants and to the contents inside them. */
    public function softDelete(int $id): void
    {
        $section = $this->find($id);
        if ($section === null) {
            return;
        }
        $subtreePath = $section['path'] . $section['id'] . '/';

        \HeleXa\Core\Database::transaction(function () use ($id, $subtreePath): void {
            $ids = array_column($this->select(
                'SELECT id FROM course_sections WHERE id = :id OR path LIKE :path',
                ['id' => $id, 'path' => $subtreePath . '%']
            ), 'id');

            $placeholders = [];
            $params       = [];
            foreach ($ids as $i => $sectionId) {
                $placeholders[]      = ':s' . $i;
                $params['s' . $i]    = (int) $sectionId;
            }
            $in = implode(',', $placeholders);

            $params['now'] = $this->now();
            $this->execute('UPDATE course_contents SET deleted_at = :now WHERE section_id IN (' . $in . ')', $params);
            $this->execute('UPDATE course_sections SET deleted_at = :now WHERE id IN (' . $in . ')', $params);
        });
    }

    public function move(int $id, string $direction): void
    {
        $section = $this->find($id);
        if ($section === null) {
            return;
        }

        $comparison = $direction === 'up' ? '<' : '>';
        $order      = $direction === 'up' ? 'DESC' : 'ASC';

        $neighbour = $this->selectOne(
            'SELECT id, sort_order FROM course_sections
             WHERE course_id = :course AND deleted_at IS NULL
               AND ' . ($section['parent_id'] === null ? 'parent_id IS NULL' : 'parent_id = :parent') . '
               AND sort_order ' . $comparison . ' :order
             ORDER BY sort_order ' . $order . ' LIMIT 1',
            $section['parent_id'] === null
                ? ['course' => (int) $section['course_id'], 'order' => (int) $section['sort_order']]
                : ['course' => (int) $section['course_id'], 'parent' => (int) $section['parent_id'], 'order' => (int) $section['sort_order']]
        );

        if ($neighbour === null) {
            return;
        }

        $this->execute('UPDATE course_sections SET sort_order = :o WHERE id = :id',
            ['o' => (int) $neighbour['sort_order'], 'id' => $id]);
        $this->execute('UPDATE course_sections SET sort_order = :o WHERE id = :id',
            ['o' => (int) $section['sort_order'], 'id' => (int) $neighbour['id']]);
    }

    private function nextOrder(int $courseId, ?int $parentId): int
    {
        $row = $this->selectOne(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 AS next FROM course_sections
             WHERE course_id = :course AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent'),
            $parentId === null ? ['course' => $courseId] : ['course' => $courseId, 'parent' => $parentId]
        );
        return (int) ($row['next'] ?? 1);
    }
}
