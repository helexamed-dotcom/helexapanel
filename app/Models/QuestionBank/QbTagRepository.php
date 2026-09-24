<?php
declare(strict_types=1);

namespace HeleXa\Models\QuestionBank;

use HeleXa\Core\Str;
use HeleXa\Models\BaseRepository;

/**
 * برچسب سوال — the admin's own labels (علوم پایه، تالیفی، …).
 *
 * Deliberately flat. A tag cuts across the whole syllabus, so it has no
 * parent; the moment tags nest they are a second taxonomy and the question
 * "is this a subject or a tag?" stops having an answer.
 */
final class QbTagRepository extends BaseRepository
{
    /**
     * Chip classes the panel already ships, so a tag cannot inject a colour.
     * The form offers these by name; anything else falls back to the default.
     */
    public const COLORS = ['chip-gray', 'chip-blue', 'chip-green', 'chip-amber', 'chip-red', 'chip-purple', 'chip-teal'];

    /** @return array<int,array<string,mixed>> */
    public function all(bool $activeOnly = false): array
    {
        $filter = $activeOnly ? ' WHERE is_active = 1' : '';

        return $this->select(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM qb_question_tags qt
                       JOIN qb_questions q ON q.id = qt.question_id AND q.deleted_at IS NULL
                      WHERE qt.tag_id = t.id) AS question_count
             FROM qb_tags t' . $filter . ' ORDER BY t.sort_order, t.title'
        );
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM qb_tags WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function create(string $title, string $color, int $sortOrder): int
    {
        $title = trim($title);
        if ($title === '') {
            throw new \RuntimeException('عنوان برچسب نمی‌تواند خالی باشد.');
        }
        if ($this->titleExists($title, null)) {
            throw new \RuntimeException('برچسبی با این عنوان از قبل هست.');
        }

        return $this->insert(
            'INSERT INTO qb_tags (uuid, title, color, sort_order, is_active, created_at)
             VALUES (:uuid, :title, :color, :sort_order, 1, :now)',
            [
                'uuid'       => Str::uuid4(),
                'title'      => $title,
                'color'      => in_array($color, self::COLORS, true) ? $color : 'chip-gray',
                'sort_order' => $sortOrder,
                'now'        => $this->now(),
            ]
        );
    }

    public function update(int $id, string $title, string $color, int $sortOrder, bool $active): void
    {
        $title = trim($title);
        if ($title === '') {
            throw new \RuntimeException('عنوان برچسب نمی‌تواند خالی باشد.');
        }
        if ($this->titleExists($title, $id)) {
            throw new \RuntimeException('برچسبی با این عنوان از قبل هست.');
        }

        $this->execute(
            'UPDATE qb_tags SET title = :title, color = :color, sort_order = :sort_order, is_active = :active
              WHERE id = :id',
            [
                'title'      => $title,
                'color'      => in_array($color, self::COLORS, true) ? $color : 'chip-gray',
                'sort_order' => $sortOrder,
                'active'     => (int) $active,
                'id'         => $id,
            ]
        );
    }

    /** The join rows go with it by cascade; the questions themselves are untouched. */
    public function delete(int $id): void
    {
        $this->execute('DELETE FROM qb_tags WHERE id = :id', ['id' => $id]);
    }

    private function titleExists(string $title, ?int $exceptId): bool
    {
        $params = ['title' => $title];
        $sql    = 'SELECT id FROM qb_tags WHERE title = :title';

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except';
            $params['except'] = $exceptId;
        }

        return $this->selectOne($sql . ' LIMIT 1', $params) !== null;
    }
}
