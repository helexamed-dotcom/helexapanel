<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * Finals, midterms, quizzes and practicals all live in one table separated by
 * exam_kind. A separate midterms table would have duplicated every column and
 * every query; the UI still presents them as distinct pages.
 */
final class ExamRepository extends BaseRepository
{
    public function all(?string $kind = null): array
    {
        $sql = 'SELECT e.*, c.title AS course_title, t.title AS term_title, g.title AS group_title,
                       s.title AS subject_title, s.color AS subject_color
                FROM exams e
                LEFT JOIN courses c ON c.id = e.course_id
                LEFT JOIN terms t ON t.id = e.term_id
                LEFT JOIN student_groups g ON g.id = e.group_id
                LEFT JOIN subjects s ON s.id = e.subject_id
                WHERE 1 = 1';
        $params = [];

        if ($kind !== null) {
            $sql .= ' AND e.exam_kind = :kind';
            $params['kind'] = $kind;
        }

        return $this->select($sql . ' ORDER BY e.exam_date DESC, e.start_time', $params);
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM exams WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** Exams a student should see: their term, and either their group or the whole term. */
    public function forStudent(?int $termId, ?int $groupId, ?string $kind = null, bool $upcomingOnly = true): array
    {
        if ($termId === null) {
            return [];
        }

        $sql = 'SELECT e.*, c.title AS course_title
                FROM exams e
                LEFT JOIN courses c ON c.id = e.course_id
                WHERE e.is_published = 1 AND e.term_id = :term
                  AND (e.group_id IS NULL OR e.group_id = :group)';
        $params = ['term' => $termId, 'group' => $groupId ?? 0];

        if ($kind !== null) {
            $sql .= ' AND e.exam_kind = :kind';
            $params['kind'] = $kind;
        }
        if ($upcomingOnly) {
            $sql .= ' AND e.exam_date >= :today';
            $params['today'] = date('Y-m-d');
        }

        return $this->select($sql . ' ORDER BY e.exam_date, e.start_time LIMIT 100', $params);
    }

    /**
     * Exams across every term the student is in, ordered by date so the next
     * one is always first regardless of which term it belongs to.
     *
     * @param array<int,int> $termIds
     */
    public function forStudentTerms(array $termIds, ?int $groupId, ?string $kind = null, bool $upcomingOnly = true): array
    {
        if ($termIds === []) {
            return [];
        }

        [$in, $params] = \HeleXa\Services\AcademicScope::inClause($termIds);

        $sql = 'SELECT e.*, c.title AS course_title, t.title AS term_title, s.color AS subject_color
                FROM exams e
                LEFT JOIN courses c ON c.id = e.course_id
                LEFT JOIN terms t ON t.id = e.term_id
                LEFT JOIN subjects s ON s.id = e.subject_id
                WHERE e.is_published = 1 AND e.term_id IN (' . $in . ')
                  AND (e.group_id IS NULL OR e.group_id = :group)';
        $params['group'] = $groupId ?? 0;

        if ($kind !== null) {
            $sql .= ' AND e.exam_kind = :kind';
            $params['kind'] = $kind;
        }
        if ($upcomingOnly) {
            $sql .= ' AND e.exam_date >= :today';
            $params['today'] = date('Y-m-d');
        }

        return $this->select($sql . ' ORDER BY e.exam_date, e.start_time LIMIT 200', $params);
    }

    /** @param array<int,int> $termIds */
    public function betweenDatesForTerms(array $termIds, ?int $groupId, string $from, string $to): array
    {
        if ($termIds === []) {
            return [];
        }

        [$in, $params] = \HeleXa\Services\AcademicScope::inClause($termIds);
        $params['group'] = $groupId ?? 0;
        $params['from']  = $from;
        $params['to']    = $to;

        return $this->select(
            'SELECT e.*, c.title AS course_title, s.color AS subject_color FROM exams e
             LEFT JOIN courses c ON c.id = e.course_id
             LEFT JOIN subjects s ON s.id = e.subject_id
             WHERE e.is_published = 1 AND e.term_id IN (' . $in . ')
               AND (e.group_id IS NULL OR e.group_id = :group)
               AND e.exam_date BETWEEN :from AND :to
             ORDER BY e.exam_date, e.start_time',
            $params
        );
    }

    public function onDate(?int $termId, ?int $groupId, string $date): array
    {
        if ($termId === null) {
            return [];
        }
        return $this->select(
            'SELECT e.*, c.title AS course_title FROM exams e
             LEFT JOIN courses c ON c.id = e.course_id
             WHERE e.is_published = 1 AND e.term_id = :term
               AND (e.group_id IS NULL OR e.group_id = :group)
               AND e.exam_date = :date
             ORDER BY e.start_time',
            ['term' => $termId, 'group' => $groupId ?? 0, 'date' => $date]
        );
    }

    public function betweenDates(?int $termId, ?int $groupId, string $from, string $to): array
    {
        if ($termId === null) {
            return [];
        }
        return $this->select(
            'SELECT e.*, c.title AS course_title FROM exams e
             LEFT JOIN courses c ON c.id = e.course_id
             WHERE e.is_published = 1 AND e.term_id = :term
               AND (e.group_id IS NULL OR e.group_id = :group)
               AND e.exam_date BETWEEN :from AND :to
             ORDER BY e.exam_date, e.start_time',
            ['term' => $termId, 'group' => $groupId ?? 0, 'from' => $from, 'to' => $to]
        );
    }

    public function create(array $data, ?int $createdBy): int
    {
        return $this->insert(
            'INSERT INTO exams (title, exam_kind, subject_id, course_id, term_id, group_id, exam_date, start_time, end_time,
                                location, description, is_published, created_by, created_at)
             VALUES (:title, :kind, :subject, :course, :term, :group, :date, :start, :end,
                     :location, :description, :published, :by, :now)',
            [
                'title'       => $data['title'],
                'kind'        => $data['exam_kind'],
                'subject'     => $data['subject_id'] ?? null,
                'course'      => $data['course_id'],
                'term'        => $data['term_id'],
                'group'       => $data['group_id'],
                'date'        => $data['exam_date'],
                'start'       => $data['start_time'],
                'end'         => $data['end_time'],
                'location'    => $data['location'] ?: null,
                'description' => $data['description'] ?: null,
                'published'   => (int) ($data['is_published'] ?? 1),
                'by'          => $createdBy,
                'now'         => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE exams SET title = :title, exam_kind = :kind, subject_id = :subject, course_id = :course, term_id = :term,
                    group_id = :group, exam_date = :date, start_time = :start, end_time = :end,
                    location = :location, description = :description, is_published = :published, updated_at = :now
             WHERE id = :id',
            [
                'title'       => $data['title'],
                'kind'        => $data['exam_kind'],
                'subject'     => $data['subject_id'] ?? null,
                'course'      => $data['course_id'],
                'term'        => $data['term_id'],
                'group'       => $data['group_id'],
                'date'        => $data['exam_date'],
                'start'       => $data['start_time'],
                'end'         => $data['end_time'],
                'location'    => $data['location'] ?: null,
                'description' => $data['description'] ?: null,
                'published'   => (int) ($data['is_published'] ?? 1),
                'now'         => $this->now(),
                'id'          => $id,
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM exams WHERE id = :id', ['id' => $id]);
    }
}
