<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class ScheduleRepository extends BaseRepository
{
    public function all(): array
    {
        return $this->select(
            'SELECT s.*, t.title AS term_title, g.title AS group_title,
                    (SELECT COUNT(*) FROM schedule_items i WHERE i.schedule_id = s.id) AS item_count
             FROM schedules s
             JOIN terms t ON t.id = s.term_id
             LEFT JOIN student_groups g ON g.id = s.group_id
             ORDER BY t.sort_order, s.id'
        );
    }

    public function find(int $id): ?array
    {
        return $this->selectOne(
            'SELECT s.*, t.title AS term_title, g.title AS group_title
             FROM schedules s
             JOIN terms t ON t.id = s.term_id
             LEFT JOIN student_groups g ON g.id = s.group_id
             WHERE s.id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /**
     * The schedule a student should see.
     * A group-specific schedule wins over the term-wide one, and an expired
     * effective window is skipped, so an old term's plan never leaks through.
     */
    public function forStudent(?int $termId, ?int $groupId): ?array
    {
        if ($termId === null) {
            return null;
        }

        return $this->selectOne(
            'SELECT * FROM schedules
             WHERE term_id = :term AND is_active = 1
               AND (group_id IS NULL OR group_id = :group)
               AND (effective_from IS NULL OR effective_from <= :today1)
               AND (effective_to   IS NULL OR effective_to   >= :today2)
             ORDER BY (group_id IS NOT NULL) DESC, id DESC
             LIMIT 1',
            [
                'term'   => $termId,
                'group'  => $groupId ?? 0,
                'today1' => date('Y-m-d'),
                'today2' => date('Y-m-d'),
            ]
        );
    }

    /**
     * One schedule per term the student is enrolled in.
     * A group-specific schedule still wins over the term-wide one.
     *
     * @param array<int,int> $termIds
     * @return array<int, array{term_id:int, term_title:string, schedule:?array}>
     */
    public function forStudentTerms(array $termIds, ?int $groupId): array
    {
        $out = [];

        foreach (array_unique(array_map('intval', $termIds)) as $termId) {
            $term = $this->selectOne('SELECT id, title FROM terms WHERE id = :id LIMIT 1', ['id' => $termId]);
            if ($term === null) {
                continue;
            }
            $out[] = [
                'term_id'    => $termId,
                'term_title' => (string) $term['title'],
                'schedule'   => $this->forStudent($termId, $groupId),
            ];
        }

        return $out;
    }

    public function create(array $data, ?int $createdBy): int
    {
        return $this->insert(
            'INSERT INTO schedules (title, term_id, group_id, academic_year, effective_from, effective_to, is_active, created_by, created_at)
             VALUES (:title, :term, :group, :year, :from, :to, :active, :by, :now)',
            [
                'title'  => $data['title'],
                'term'   => $data['term_id'],
                'group'  => $data['group_id'],
                'year'   => $data['academic_year'] ?: null,
                'from'   => $data['effective_from'],
                'to'     => $data['effective_to'],
                'active' => (int) ($data['is_active'] ?? 1),
                'by'     => $createdBy,
                'now'    => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE schedules SET title = :title, term_id = :term, group_id = :group,
                    academic_year = :year, effective_from = :from, effective_to = :to,
                    is_active = :active, updated_at = :now
             WHERE id = :id',
            [
                'title'  => $data['title'],
                'term'   => $data['term_id'],
                'group'  => $data['group_id'],
                'year'   => $data['academic_year'] ?: null,
                'from'   => $data['effective_from'],
                'to'     => $data['effective_to'],
                'active' => (int) ($data['is_active'] ?? 1),
                'now'    => $this->now(),
                'id'     => $id,
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM schedules WHERE id = :id', ['id' => $id]);
    }

    /* ------------------------------------------------------------- items */

    public function items(int $scheduleId): array
    {
        return $this->select(
            'SELECT i.*, c.title AS course_title, c.uuid AS course_uuid,
                    s.title AS subject_title, s.color AS subject_color
             FROM schedule_items i
             LEFT JOIN courses c  ON c.id = i.course_id
             LEFT JOIN subjects s ON s.id = i.subject_id
             WHERE i.schedule_id = :schedule
             ORDER BY i.weekday, i.start_time, i.id',
            ['schedule' => $scheduleId]
        );
    }

    public function itemsForWeekday(int $scheduleId, int $weekday): array
    {
        return $this->select(
            'SELECT i.*, c.title AS course_title, c.uuid AS course_uuid,
                    s.title AS subject_title, s.color AS subject_color
             FROM schedule_items i
             LEFT JOIN courses c  ON c.id = i.course_id
             LEFT JOIN subjects s ON s.id = i.subject_id
             WHERE i.schedule_id = :schedule AND i.weekday = :weekday
             ORDER BY i.start_time, i.id',
            ['schedule' => $scheduleId, 'weekday' => $weekday]
        );
    }

    public function findItem(int $id, int $scheduleId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM schedule_items WHERE id = :id AND schedule_id = :schedule LIMIT 1',
            ['id' => $id, 'schedule' => $scheduleId]
        );
    }

    public function addItem(int $scheduleId, array $data): int
    {
        return $this->insert(
            'INSERT INTO schedule_items
                (schedule_id, weekday, start_time, end_time, title, subject_id, course_id, teacher, location, color, notes, sort_order)
             VALUES (:schedule, :weekday, :start, :end, :title, :subject, :course, :teacher, :location, :color, :notes, 0)',
            [
                'schedule' => $scheduleId,
                'weekday'  => $data['weekday'],
                'start'    => $data['start_time'],
                'end'      => $data['end_time'],
                'title'    => $data['title'],
                // Independent of each other: a class period can name a subject,
                // link to LMS content, both, or neither.
                'subject'  => $data['subject_id'] ?? null,
                'course'   => $data['course_id'],
                'teacher'  => $data['teacher'] ?: null,
                'location' => $data['location'] ?: null,
                'color'    => $data['color'],
                'notes'    => null,
            ]
        );
    }

    public function deleteItem(int $id, int $scheduleId): void
    {
        $this->execute(
            'DELETE FROM schedule_items WHERE id = :id AND schedule_id = :schedule',
            ['id' => $id, 'schedule' => $scheduleId]
        );
    }
}
