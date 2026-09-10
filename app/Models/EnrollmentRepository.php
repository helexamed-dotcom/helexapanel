<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class EnrollmentRepository extends BaseRepository
{
    /**
     * The single query that answers "may this student open this course right now".
     * Status, start date and end date are all evaluated in SQL so no PHP branch
     * can accidentally skip one.
     */
    public function activeEnrollment(int $userId, int $courseId): ?array
    {
        return $this->selectOne(
            'SELECT sc.* FROM student_courses sc
             WHERE sc.user_id = :user AND sc.course_id = :course
               AND sc.status = \'active\'
               AND (sc.starts_at IS NULL OR sc.starts_at <= :now1)
               AND (sc.ends_at   IS NULL OR sc.ends_at   >= :now2)
             LIMIT 1',
            ['user' => $userId, 'course' => $courseId, 'now1' => $this->now(), 'now2' => $this->now()]
        );
    }

    public function coursesForStudent(int $userId): array
    {
        return $this->select(
            'SELECT c.*, sc.status AS enrollment_status, sc.starts_at, sc.ends_at,
                    (SELECT COUNT(*) FROM course_contents cc
                      WHERE cc.course_id = c.id AND cc.status = \'published\' AND cc.deleted_at IS NULL) AS content_count,
                    (SELECT COUNT(*) FROM student_content_status s
                      WHERE s.user_id = sc.user_id AND s.course_id = c.id AND s.status = \'completed\') AS completed_count
             FROM student_courses sc
             JOIN courses c ON c.id = sc.course_id
             WHERE sc.user_id = :user
               AND c.status = \'published\' AND c.deleted_at IS NULL
               AND sc.status = \'active\'
               AND (sc.starts_at IS NULL OR sc.starts_at <= :now1)
               AND (sc.ends_at   IS NULL OR sc.ends_at   >= :now2)
             ORDER BY c.sort_order, c.id',
            ['user' => $userId, 'now1' => $this->now(), 'now2' => $this->now()]
        );
    }

    public function forCourse(int $courseId): array
    {
        return $this->select(
            'SELECT sc.*, u.uuid AS user_uuid, u.full_name, u.username, u.status AS user_status
             FROM student_courses sc JOIN users u ON u.id = sc.user_id
             WHERE sc.course_id = :course AND u.deleted_at IS NULL
             ORDER BY u.full_name',
            ['course' => $courseId]
        );
    }

    public function assign(int $userId, int $courseId, array $data, ?int $assignedBy): void
    {
        $this->execute(
            'INSERT INTO student_courses (user_id, course_id, status, starts_at, ends_at, assigned_by, created_at)
             VALUES (:user, :course, :status, :starts_at, :ends_at, :by, :now)
             ON DUPLICATE KEY UPDATE status = VALUES(status), starts_at = VALUES(starts_at),
                                     ends_at = VALUES(ends_at), updated_at = VALUES(created_at)',
            [
                'user'      => $userId,
                'course'    => $courseId,
                'status'    => $data['status'] ?? 'active',
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at'   => $data['ends_at'] ?? null,
                'by'        => $assignedBy,
                'now'       => $this->now(),
            ]
        );
    }

    /**
     * Changes the status of enrolments the student already holds.
     *
     * Deliberately an UPDATE and not an upsert: suspending a package must not
     * quietly create a row for a course the student never received, because
     * reactivating the package would then hand them something they never had.
     *
     * @param array<int,int> $courseIds
     * @return int rows affected
     */
    public function setStatusForCourses(int $userId, array $courseIds, string $status, ?string $startsAt, ?string $endsAt): int
    {
        if ($courseIds === []) {
            return 0;
        }

        $placeholders = [];
        $params       = ['user' => $userId, 'status' => $status, 'starts' => $startsAt, 'ends' => $endsAt, 'now' => $this->now()];

        foreach (array_values(array_unique(array_map('intval', $courseIds))) as $index => $courseId) {
            $key                = 'c' . $index;
            $placeholders[]     = ':' . $key;
            $params[$key]       = $courseId;
        }

        return $this->execute(
            'UPDATE student_courses
             SET status = :status, starts_at = :starts, ends_at = :ends, updated_at = :now
             WHERE user_id = :user AND course_id IN (' . implode(', ', $placeholders) . ')',
            $params
        );
    }

    public function remove(int $userId, int $courseId): void
    {
        $this->execute(
            'DELETE FROM student_courses WHERE user_id = :user AND course_id = :course',
            ['user' => $userId, 'course' => $courseId]
        );
    }
}
