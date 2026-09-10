<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * The list of "class subjects" used by the weekly schedule and exams.
 *
 * Deliberately independent of CourseRepository: a subject here is just a
 * label an admin manages (e.g. "آناتومی اعصاب") so schedule items and exams
 * stay consistent, with no requirement that the subject correspond to any
 * content-bearing course in the LMS.
 */
final class SubjectRepository extends BaseRepository
{
    public function all(?int $majorId = null, bool $activeOnly = false): array
    {
        $sql = 'SELECT s.*, m.title AS major_title, u.title AS university_title
                FROM subjects s
                LEFT JOIN majors m ON m.id = s.major_id
                LEFT JOIN universities u ON u.id = m.university_id';
        $params = [];
        $where  = [];

        if ($majorId !== null) {
            // A general subject (major_id IS NULL) is offered to every major.
            $where[]          = '(s.major_id = :major OR s.major_id IS NULL)';
            $params['major']  = $majorId;
        }
        if ($activeOnly) {
            $where[] = 's.is_active = 1';
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return $this->select($sql . ' ORDER BY u.sort_order, m.sort_order, s.sort_order, s.title', $params);
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM subjects WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO subjects (major_id, title, color, is_active, sort_order, created_at)
             VALUES (:major, :title, :color, 1, :sort_order, :now)',
            [
                'major'      => $data['major_id'],
                'title'      => $data['title'],
                'color'      => $data['color'],
                'sort_order' => $data['sort_order'] ?? 0,
                'now'        => $this->now(),
            ]
        );
    }

    public function toggle(int $id): void
    {
        $this->execute(
            'UPDATE subjects SET is_active = 1 - is_active, updated_at = :now WHERE id = :id',
            ['now' => $this->now(), 'id' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM subjects WHERE id = :id', ['id' => $id]);
    }
}
