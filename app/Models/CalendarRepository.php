<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class CalendarRepository extends BaseRepository
{
    public function all(): array
    {
        return $this->select(
            'SELECT e.*, t.title AS term_title, g.title AS group_title
             FROM calendar_events e
             LEFT JOIN terms t ON t.id = e.term_id
             LEFT JOIN student_groups g ON g.id = e.group_id
             ORDER BY e.event_date DESC LIMIT 300'
        );
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM calendar_events WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** Events visible to one student: global, their term, their group, or personal. */
    public function forStudent(int $userId, ?int $termId, ?int $groupId, string $from, string $to): array
    {
        return $this->select(
            'SELECT * FROM calendar_events
             WHERE event_date BETWEEN :from AND :to
               AND (
                    (term_id IS NULL AND group_id IS NULL AND user_id IS NULL)
                 OR (term_id = :term AND group_id IS NULL)
                 OR (group_id = :group)
                 OR (user_id = :user)
               )
             ORDER BY event_date, start_time',
            [
                'from'  => $from,
                'to'    => $to,
                'term'  => $termId ?? 0,
                'group' => $groupId ?? 0,
                'user'  => $userId,
            ]
        );
    }

    /**
     * Calendar entries visible across every term the student is in.
     *
     * @param array<int,int> $termIds
     */
    public function forStudentTerms(int $userId, array $termIds, ?int $groupId, string $from, string $to): array
    {
        $params = ['from' => $from, 'to' => $to, 'group' => $groupId ?? 0, 'user' => $userId];
        $termClause = '';

        if ($termIds !== []) {
            [$in, $termParams] = \HeleXa\Services\AcademicScope::inClause($termIds);
            $termClause = ' OR (e.term_id IN (' . $in . ') AND e.group_id IS NULL)';
            $params = array_merge($params, $termParams);
        }

        return $this->select(
            'SELECT e.* FROM calendar_events e
             WHERE e.event_date BETWEEN :from AND :to
               AND (
                    (e.term_id IS NULL AND e.group_id IS NULL AND e.user_id IS NULL)
                 OR (e.group_id = :group)
                 OR (e.user_id = :user)' . $termClause . '
               )
             ORDER BY e.event_date, e.start_time',
            $params
        );
    }

    public function create(array $data, ?int $createdBy): int
    {
        return $this->insert(
            'INSERT INTO calendar_events (title, description, event_type, event_date, start_time, end_time,
                                          term_id, group_id, user_id, color, created_by, created_at)
             VALUES (:title, :description, :type, :date, :start, :end, :term, :group, :user, :color, :by, :now)',
            [
                'title'       => $data['title'],
                'description' => $data['description'] ?: null,
                'type'        => $data['event_type'],
                'date'        => $data['event_date'],
                'start'       => $data['start_time'],
                'end'         => $data['end_time'],
                'term'        => $data['term_id'],
                'group'       => $data['group_id'],
                'user'        => $data['user_id'] ?? null,
                'color'       => $data['color'],
                'by'          => $createdBy,
                'now'         => $this->now(),
            ]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM calendar_events WHERE id = :id', ['id' => $id]);
    }
}
