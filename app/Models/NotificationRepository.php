<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Database;

/**
 * Announcements with an audience.
 *
 * Recipients are materialised into user_notifications when the announcement is
 * published, rather than being computed on every read. That costs one INSERT
 * ... SELECT up front and makes "is it read yet" a plain indexed lookup, which
 * is what the header badge needs on every single page load.
 */
final class NotificationRepository extends BaseRepository
{
    public function create(array $data, ?int $createdBy): int
    {
        return Database::transaction(function () use ($data, $createdBy): int {
            $id = $this->insert(
                'INSERT INTO notifications
                    (title, body, notif_type, audience, term_id, group_id, university_id, major_id,
                     course_id, package_id, link_url, created_by, published_at, expires_at)
                 VALUES (:title, :body, :type, :audience, :term, :group, :university, :major,
                         :course, :package, :link, :by, :published, :expires)',
                [
                    'title'      => $data['title'],
                    'body'       => $data['body'] ?: null,
                    'type'       => $data['notif_type'],
                    'audience'   => $data['audience'],
                    'term'       => $data['term_id'],
                    'group'      => $data['group_id'],
                    'university' => $data['university_id'] ?? null,
                    'major'      => $data['major_id'] ?? null,
                    'course'     => $data['course_id'] ?? null,
                    'package'    => $data['package_id'] ?? null,
                    'link'       => $data['link_url'] ?: null,
                    'by'         => $createdBy,
                    'published'  => $this->now(),
                    'expires'    => $data['expires_at'],
                ]
            );

            $this->fanOut($id, $data);
            return $id;
        });
    }

    /**
     * Creates an announcement carrying an idempotency key.
     *
     * The unique index on that key is what actually prevents duplicates, so a
     * concurrent retry fails at the database rather than racing a SELECT.
     * The caller catches the integrity error and treats it as "already sent".
     */
    public function createWithKey(array $data): int
    {
        return Database::transaction(function () use ($data): int {
            $id = $this->insert(
                'INSERT INTO notifications
                    (title, body, notif_type, related_type, related_id, idempotency_key,
                     audience, term_id, group_id, university_id, major_id, course_id, package_id,
                     link_url, created_by, published_at, expires_at)
                 VALUES (:title, :body, :type, :related_type, :related_id, :key,
                         :audience, :term, :group, :university, :major, :course, :package,
                         :link, :by, :published, :expires)',
                [
                    'title'        => $data['title'],
                    'body'         => $data['body'] ?: null,
                    'type'         => $data['notif_type'],
                    'related_type' => $data['related_type'],
                    'related_id'   => $data['related_id'],
                    'key'          => $data['idempotency_key'],
                    'audience'     => $data['audience'],
                    'term'         => $data['term_id'],
                    'group'        => $data['group_id'],
                    'university'   => $data['university_id'] ?? null,
                    'major'        => $data['major_id'] ?? null,
                    'course'       => $data['course_id'] ?? null,
                    'package'      => $data['package_id'] ?? null,
                    'link'         => $data['link_url'],
                    'by'           => $data['created_by'],
                    'published'    => $this->now(),
                    'expires'      => $data['expires_at'],
                ]
            );

            $this->fanOut($id, $data);
            return $id;
        });
    }

    /** Writes one row per recipient in a single statement. */
    private function fanOut(int $notificationId, array $data): void
    {
        $where  = ["r.slug = 'student'", 'u.deleted_at IS NULL', "u.status = 'active'"];
        $params = ['notification' => $notificationId, 'now' => $this->now()];
        $joins  = '';

        switch ($data['audience']) {
            case 'term':
                $where[]         = 'u.term_id = :term';
                $params['term']  = $data['term_id'];
                break;
            case 'group':
                $where[]         = 'u.group_id = :group';
                $params['group'] = $data['group_id'];
                break;
            case 'university':
                $where[]               = 'u.university_id = :university';
                $params['university']  = $data['university_id'];
                break;
            case 'major':
                $where[]          = 'u.major_id = :major';
                $params['major']  = $data['major_id'];
                break;
            case 'course':
                // Active enrolment only: a course audience should reach the
                // people who actually have access right now, not everyone
                // who was ever assigned to it.
                $joins .= ' JOIN student_courses sc ON sc.user_id = u.id AND sc.course_id = :course
                            AND sc.status = "active" AND (sc.ends_at IS NULL OR sc.ends_at >= :now2)';
                $params['course'] = $data['course_id'];
                $params['now2']   = $this->now();
                break;
            case 'package':
                $joins .= ' JOIN package_activations pa ON pa.user_id = u.id AND pa.package_id = :package
                            AND pa.status = "active"';
                $params['package'] = $data['package_id'];
                break;
            case 'user':
                $where[]         = 'u.id = :user';
                $params['user']  = $data['user_id'];
                break;
        }

        $this->execute(
            'INSERT IGNORE INTO user_notifications (notification_id, user_id, is_read, created_at)
             SELECT :notification, u.id, 0, :now
             FROM users u JOIN roles r ON r.id = u.role_id' . $joins . '
             WHERE ' . implode(' AND ', $where),
            $params
        );
    }

    public function all(int $limit = 100): array
    {
        $limit = max(1, min($limit, 300));
        return $this->select(
            'SELECT n.*, t.title AS term_title, g.title AS group_title,
                    uni.title AS university_title, m.title AS major_title,
                    c.title AS course_title, p.title AS package_title,
                    (SELECT COUNT(*) FROM user_notifications un WHERE un.notification_id = n.id) AS recipients,
                    (SELECT COUNT(*) FROM user_notifications un WHERE un.notification_id = n.id AND un.is_read = 1) AS read_count
             FROM notifications n
             LEFT JOIN terms t ON t.id = n.term_id
             LEFT JOIN student_groups g ON g.id = n.group_id
             LEFT JOIN universities uni ON uni.id = n.university_id
             LEFT JOIN majors m ON m.id = n.major_id
             LEFT JOIN courses c ON c.id = n.course_id
             LEFT JOIN packages p ON p.id = n.package_id
             ORDER BY n.id DESC LIMIT ' . $limit
        );
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        return $this->select(
            'SELECT n.id, n.title, n.body, n.notif_type, n.link_url, n.published_at,
                    un.is_read, un.read_at
             FROM user_notifications un JOIN notifications n ON n.id = un.notification_id
             WHERE un.user_id = :user AND un.deleted_at IS NULL
               AND (n.expires_at IS NULL OR n.expires_at >= :now)
             ORDER BY n.published_at DESC, n.id DESC LIMIT ' . $limit,
            ['user' => $userId, 'now' => $this->now()]
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM user_notifications un JOIN notifications n ON n.id = un.notification_id
             WHERE un.user_id = :user AND un.is_read = 0 AND un.deleted_at IS NULL
               AND (n.expires_at IS NULL OR n.expires_at >= :now)',
            ['user' => $userId, 'now' => $this->now()]
        )['c'] ?? 0);
    }

    public function markRead(int $userId, int $notificationId): void
    {
        $this->execute(
            'UPDATE user_notifications SET is_read = 1, read_at = :now
             WHERE user_id = :user AND notification_id = :notification AND is_read = 0',
            ['now' => $this->now(), 'user' => $userId, 'notification' => $notificationId]
        );
    }

    public function markAllRead(int $userId): int
    {
        return $this->execute(
            'UPDATE user_notifications SET is_read = 1, read_at = :now WHERE user_id = :user AND is_read = 0',
            ['now' => $this->now(), 'user' => $userId]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM notifications WHERE id = :id', ['id' => $id]);
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM notifications WHERE id = :id LIMIT 1', ['id' => $id]);
    }
}
