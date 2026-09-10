<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class ContentStatusRepository extends BaseRepository
{
    public function find(int $userId, int $contentId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM student_content_status WHERE user_id = :user AND content_id = :content LIMIT 1',
            ['user' => $userId, 'content' => $contentId]
        );
    }

    public function forCourse(int $userId, int $courseId): array
    {
        $rows = $this->select(
            'SELECT content_id, status, total_seconds FROM student_content_status
             WHERE user_id = :user AND course_id = :course',
            ['user' => $userId, 'course' => $courseId]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['content_id']] = $row;
        }
        return $map;
    }

    public function registerOpen(int $userId, int $contentId, int $courseId): void
    {
        $this->execute(
            'INSERT INTO student_content_status
                (user_id, content_id, course_id, status, open_count, first_opened_at, last_opened_at, updated_at)
             VALUES (:user, :content, :course, \'studying\', 1, :now1, :now2, :now3)
             ON DUPLICATE KEY UPDATE
                open_count     = open_count + 1,
                last_opened_at = VALUES(last_opened_at),
                status         = IF(status = \'unread\', \'studying\', status),
                updated_at     = VALUES(updated_at)',
            ['user' => $userId, 'content' => $contentId, 'course' => $courseId,
             'now1' => $this->now(), 'now2' => $this->now(), 'now3' => $this->now()]
        );
    }

    public function setStatus(int $userId, int $contentId, int $courseId, string $status): void
    {
        $this->execute(
            'INSERT INTO student_content_status
                (user_id, content_id, course_id, status, completed_at, updated_at)
             VALUES (:user, :content, :course, :status, :completed, :now)
             ON DUPLICATE KEY UPDATE status = VALUES(status),
                                     completed_at = VALUES(completed_at),
                                     updated_at = VALUES(updated_at)',
            [
                'user'      => $userId,
                'content'   => $contentId,
                'course'    => $courseId,
                'status'    => $status,
                'completed' => $status === 'completed' ? $this->now() : null,
                'now'       => $this->now(),
            ]
        );
    }

    /** Accumulates verified study time against the lesson. */
    public function addSeconds(int $userId, int $contentId, int $courseId, int $seconds): void
    {
        $this->execute(
            'INSERT INTO student_content_status
                (user_id, content_id, course_id, status, total_seconds, last_opened_at, updated_at)
             VALUES (:user, :content, :course, \'studying\', :seconds, :opened, :now)
             ON DUPLICATE KEY UPDATE
                total_seconds  = total_seconds + VALUES(total_seconds),
                status         = IF(status = \'unread\', \'studying\', status),
                last_opened_at = VALUES(last_opened_at),
                updated_at     = VALUES(updated_at)',
            [
                'user'    => $userId,
                'content' => $contentId,
                'course'  => $courseId,
                'seconds' => $seconds,
                'opened'  => $this->now(),
                'now'     => $this->now(),
            ]
        );
    }

    /** Lessons the student left in progress, newest first. */
    public function continueStudying(int $userId, int $limit = 6): array
    {
        $limit = max(1, min($limit, 20));
        return $this->select(
            'SELECT s.status, s.total_seconds, s.last_opened_at,
                    cc.uuid AS content_uuid, cc.title AS content_title,
                    c.uuid AS course_uuid, c.title AS course_title, c.color
             FROM student_content_status s
             JOIN course_contents cc ON cc.id = s.content_id
             JOIN courses c ON c.id = s.course_id
             JOIN student_courses sc ON sc.course_id = c.id AND sc.user_id = s.user_id
             WHERE s.user_id = :user AND s.status = \'studying\'
               AND cc.deleted_at IS NULL AND cc.status = \'published\'
               AND c.deleted_at IS NULL AND c.status = \'published\'
               AND sc.status = \'active\'
               AND (sc.starts_at IS NULL OR sc.starts_at <= :now1)
               AND (sc.ends_at   IS NULL OR sc.ends_at   >= :now2)
             ORDER BY s.last_opened_at DESC
             LIMIT ' . $limit,
            ['user' => $userId, 'now1' => $this->now(), 'now2' => $this->now()]
        );
    }

    /** Progress breakdown per course, used by the performance page. */
    public function breakdownByCourse(int $userId): array
    {
        return $this->select(
            'SELECT c.id, c.uuid, c.title, c.color,
                    (SELECT COUNT(*) FROM course_contents cc
                      WHERE cc.course_id = c.id AND cc.status = \'published\' AND cc.deleted_at IS NULL) AS total,
                    SUM(s.status = \'completed\')    AS completed,
                    SUM(s.status = \'studying\')     AS studying,
                    SUM(s.status = \'review_later\') AS review_later,
                    COALESCE(SUM(s.total_seconds), 0) AS seconds
             FROM student_courses sc
             JOIN courses c ON c.id = sc.course_id
             LEFT JOIN student_content_status s ON s.course_id = c.id AND s.user_id = sc.user_id
             WHERE sc.user_id = :user AND sc.status = \'active\'
               AND c.status = \'published\' AND c.deleted_at IS NULL
             GROUP BY c.id, c.uuid, c.title, c.color
             ORDER BY c.sort_order, c.id',
            ['user' => $userId]
        );
    }

    /** Server-side stand-in for localStorage, scoped to one student and one lesson. */
    public function clientState(int $userId, int $contentId): array
    {
        $row = $this->find($userId, $contentId);
        if ($row === null || $row['client_state'] === null) {
            return [];
        }
        $decoded = json_decode((string) $row['client_state'], true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveClientState(int $userId, int $contentId, int $courseId, array $state): void
    {
        $this->execute(
            'INSERT INTO student_content_status (user_id, content_id, course_id, status, client_state, updated_at)
             VALUES (:user, :content, :course, \'studying\', :state, :now)
             ON DUPLICATE KEY UPDATE client_state = VALUES(client_state), updated_at = VALUES(updated_at)',
            [
                'user'    => $userId,
                'content' => $contentId,
                'course'  => $courseId,
                'state'   => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'now'     => $this->now(),
            ]
        );
    }
}
