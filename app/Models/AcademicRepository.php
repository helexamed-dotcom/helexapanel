<?php
declare(strict_types=1);

namespace HeleXa\Models;

/** Terms and student groups: the scope used by schedules, exams and notifications. */
final class AcademicRepository extends BaseRepository
{
    /* --------------------------------------------------------- universities */

    public function universities(bool $activeOnly = false): array
    {
        $sql = 'SELECT u.*,
                       (SELECT COUNT(*) FROM majors m WHERE m.university_id = u.id) AS major_count
                FROM universities u';
        if ($activeOnly) {
            $sql .= ' WHERE u.is_active = 1';
        }
        return $this->select($sql . ' ORDER BY u.sort_order, u.title');
    }

    public function findUniversity(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM universities WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function createUniversity(string $title, ?string $city): int
    {
        return $this->insert(
            'INSERT INTO universities (title, city, is_active, sort_order, created_at)
             VALUES (:title, :city, 1, 0, :now)',
            ['title' => $title, 'city' => $city, 'now' => $this->now()]
        );
    }

    public function toggleUniversity(int $id): void
    {
        $this->execute(
            'UPDATE universities SET is_active = 1 - is_active, updated_at = :now WHERE id = :id',
            ['now' => $this->now(), 'id' => $id]
        );
    }

    public function deleteUniversity(int $id): void
    {
        $this->execute('DELETE FROM universities WHERE id = :id', ['id' => $id]);
    }

    /* --------------------------------------------------------------- majors */

    public function majors(?int $universityId = null, bool $activeOnly = false): array
    {
        $conditions = [];
        $params     = [];

        if ($universityId !== null) {
            $conditions[]           = 'm.university_id = :university';
            $params['university']   = $universityId;
        }
        if ($activeOnly) {
            $conditions[] = 'm.is_active = 1 AND u.is_active = 1';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return $this->select(
            'SELECT m.*, u.title AS university_title,
                    (SELECT COUNT(*) FROM terms t WHERE t.major_id = m.id) AS term_count
             FROM majors m JOIN universities u ON u.id = m.university_id' . $where . '
             ORDER BY u.sort_order, u.title, m.sort_order, m.title',
            $params
        );
    }

    public function findMajor(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM majors WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function createMajor(int $universityId, string $title): int
    {
        return $this->insert(
            'INSERT INTO majors (university_id, title, is_active, sort_order, created_at)
             VALUES (:university, :title, 1, 0, :now)',
            ['university' => $universityId, 'title' => $title, 'now' => $this->now()]
        );
    }

    public function toggleMajor(int $id): void
    {
        $this->execute(
            'UPDATE majors SET is_active = 1 - is_active, updated_at = :now WHERE id = :id',
            ['now' => $this->now(), 'id' => $id]
        );
    }

    public function deleteMajor(int $id): void
    {
        $this->execute('DELETE FROM majors WHERE id = :id', ['id' => $id]);
    }

    /** True when the major really belongs to that university. */
    public function majorBelongsToUniversity(int $majorId, int $universityId): bool
    {
        return $this->selectOne(
            'SELECT 1 FROM majors WHERE id = :major AND university_id = :university LIMIT 1',
            ['major' => $majorId, 'university' => $universityId]
        ) !== null;
    }

    /* ---------------------------------------------------------------- terms */

    public function terms(?int $majorId = null): array
    {
        $sql = 'SELECT t.*, m.title AS major_title, u.title AS university_title, m.university_id
                FROM terms t
                LEFT JOIN majors m ON m.id = t.major_id
                LEFT JOIN universities u ON u.id = m.university_id';
        $params = [];

        if ($majorId !== null) {
            // A general term (major_id IS NULL) is offered to every major.
            $sql .= ' WHERE t.major_id = :major OR t.major_id IS NULL';
            $params['major'] = $majorId;
        }

        return $this->select($sql . ' ORDER BY u.sort_order, m.sort_order, t.sort_order, t.id', $params);
    }

    public function termBelongsToMajor(int $termId, ?int $majorId): bool
    {
        $row = $this->selectOne('SELECT major_id FROM terms WHERE id = :id LIMIT 1', ['id' => $termId]);
        if ($row === null) {
            return false;
        }
        if ($row['major_id'] === null) {
            return true;   // general term
        }
        return $majorId !== null && (int) $row['major_id'] === $majorId;
    }

    public function activeTerms(): array
    {
        return $this->select('SELECT * FROM terms WHERE is_active = 1 ORDER BY sort_order, id');
    }

    public function findTerm(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM terms WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function createTerm(string $title, ?int $number, ?int $majorId = null): int
    {
        return $this->insert(
            'INSERT INTO terms (major_id, title, number, is_active, sort_order, created_at)
             VALUES (:major, :title, :number, 1, :sort_order, :now)',
            [
                'major'      => $majorId,
                'title'      => $title,
                'number'     => $number,
                'sort_order' => $number ?? 0,
                'now'        => $this->now(),
            ]
        );
    }

    public function deleteTerm(int $id): void
    {
        $this->execute('DELETE FROM terms WHERE id = :id', ['id' => $id]);
    }

    public function groups(?int $termId = null): array
    {
        if ($termId === null) {
            return $this->select(
                'SELECT g.*, t.title AS term_title, m.title AS major_title, u.title AS university_title
                 FROM student_groups g
                 JOIN terms t ON t.id = g.term_id
                 LEFT JOIN majors m ON m.id = t.major_id
                 LEFT JOIN universities u ON u.id = m.university_id
                 ORDER BY u.sort_order, m.sort_order, t.sort_order, g.sort_order, g.id'
            );
        }
        return $this->select(
            'SELECT g.*, t.title AS term_title
             FROM student_groups g JOIN terms t ON t.id = g.term_id
             WHERE g.term_id = :term ORDER BY g.sort_order, g.id',
            ['term' => $termId]
        );
    }

    public function createGroup(int $termId, string $title): int
    {
        return $this->insert(
            'INSERT INTO student_groups (term_id, title, is_active, sort_order, created_at)
             VALUES (:term, :title, 1, 0, :now)',
            ['term' => $termId, 'title' => $title, 'now' => $this->now()]
        );
    }

    public function deleteGroup(int $id): void
    {
        $this->execute('DELETE FROM student_groups WHERE id = :id', ['id' => $id]);
    }

    /* ------------------------------------------------- student semesters */

    /** @return array<int,int> */
    public function semestersOf(int $userId): array
    {
        return array_map('intval', array_column(
            $this->select('SELECT term_id FROM user_semesters WHERE user_id = :user', ['user' => $userId]),
            'term_id'
        ));
    }

    /**
     * Replaces a student's term selection.
     *
     * Terms that do not belong to the student's major are dropped rather than
     * rejected: the caller is an admin form, and silently ignoring a stale
     * option is friendlier than failing the whole save.
     *
     * @param array<int,int> $termIds
     * @return array<int,int> the terms actually stored
     */
    public function syncSemesters(int $userId, array $termIds, ?int $majorId): array
    {
        $valid = [];
        foreach (array_unique(array_map('intval', $termIds)) as $termId) {
            if ($termId > 0 && $this->termBelongsToMajor($termId, $majorId)) {
                $valid[] = $termId;
            }
        }

        \HeleXa\Core\Database::transaction(function () use ($userId, $valid): void {
            $this->execute('DELETE FROM user_semesters WHERE user_id = :user', ['user' => $userId]);
            foreach ($valid as $termId) {
                $this->insert(
                    'INSERT INTO user_semesters (user_id, term_id, created_at) VALUES (:user, :term, :now)',
                    ['user' => $userId, 'term' => $termId, 'now' => $this->now()]
                );
            }
        });

        return $valid;
    }

    /** Guard against assigning a group that does not belong to the chosen term. */
    public function groupBelongsToTerm(int $groupId, int $termId): bool
    {
        return $this->selectOne(
            'SELECT 1 FROM student_groups WHERE id = :g AND term_id = :t LIMIT 1',
            ['g' => $groupId, 't' => $termId]
        ) !== null;
    }
}
