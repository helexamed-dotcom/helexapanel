<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Core\Logger;
use HeleXa\Models\NotificationRepository;
use PDOException;

/**
 * The single place an announcement is created.
 *
 * Everything that wants to tell a student something goes through here rather
 * than writing to the notifications table directly. Two reasons:
 *
 *  1. Idempotency. Each announcement carries a key derived from what caused it,
 *     and the database refuses a second row with the same key. A retried
 *     request, a double-clicked button or a repeated job therefore produces one
 *     announcement, not three.
 *
 *  2. Channels. Today the only delivery channel is the site itself. When the
 *     Telegram bot exists it registers here as a second channel; the code that
 *     activates a course does not change and, more importantly, a failure in a
 *     messaging channel can never roll back the activation that caused it.
 */
final class NotificationService
{
    /** @var array<string, callable(array):void> */
    private static array $channels = [];

    /**
     * Registers an extra delivery channel.
     * A channel receives the stored notification row and must not throw; the
     * service catches anyway, because a delivery problem is not a data problem.
     */
    public static function registerChannel(string $name, callable $handler): void
    {
        self::$channels[$name] = $handler;
    }

    /** @return array<int,string> */
    public static function channels(): array
    {
        return array_keys(self::$channels);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{created:bool, id:?int}
     */
    public static function publish(array $data): array
    {
        $repository = new NotificationRepository();

        try {
            $id = $repository->createWithKey([
                'title'           => $data['title'],
                'body'            => $data['body'] ?? null,
                'notif_type'      => $data['notif_type'] ?? 'system',
                'related_type'    => $data['related_type'] ?? null,
                'related_id'      => $data['related_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'audience'        => $data['audience'] ?? 'user',
                'term_id'         => $data['term_id'] ?? null,
                'group_id'        => $data['group_id'] ?? null,
                'university_id'   => $data['university_id'] ?? null,
                'major_id'        => $data['major_id'] ?? null,
                'course_id'       => $data['course_id'] ?? null,
                'package_id'      => $data['package_id'] ?? null,
                'user_id'         => $data['user_id'] ?? null,
                'link_url'        => $data['link_url'] ?? null,
                'expires_at'      => $data['expires_at'] ?? null,
                'created_by'      => $data['created_by'] ?? null,
            ]);
        } catch (PDOException $e) {
            // 23000 here means the idempotency key already exists: the very
            // outcome the key is for. Nothing to do and nothing to report.
            if ($e->getCode() === '23000') {
                return ['created' => false, 'id' => null];
            }
            throw $e;
        }

        self::dispatch($id, $data);

        return ['created' => true, 'id' => $id];
    }

    /**
     * Hands the stored announcement to every registered channel.
     * Runs after the row exists, and never lets a channel break the caller.
     */
    private static function dispatch(int $notificationId, array $data): void
    {
        foreach (self::$channels as $name => $handler) {
            try {
                $handler(['notification_id' => $notificationId] + $data);
            } catch (\Throwable $e) {
                Logger::warning('Notification channel failed', [
                    'channel'      => $name,
                    'notification' => $notificationId,
                    'error'        => $e->getMessage(),
                ]);
            }
        }
    }

    /* ------------------------------------------------- ready-made messages */

    /**
     * One course became available to one student.
     * The key ties the announcement to the enrolment row, so re-running the
     * same activation is silent rather than noisy.
     */
    public static function courseActivated(array $user, array $course, ?int $actorId): array
    {
        return self::publish([
            'title'           => '🎉 دوره جدید برای شما فعال شد',
            'body'            => sprintf(
                "دوره «%s» با موفقیت برای حساب شما فعال شد.\nاکنون می‌توانید از محتوای این دوره استفاده کنید.",
                (string) $course['title']
            ),
            'notif_type'      => 'course',
            'related_type'    => 'course',
            'related_id'      => (int) $course['id'],
            'idempotency_key' => sprintf('course_activated:%d:%d', (int) $user['id'], (int) $course['id']),
            'audience'        => 'user',
            'user_id'         => (int) $user['id'],
            'link_url'        => '/student/courses/' . $course['uuid'],
            'created_by'      => $actorId,
        ]);
    }

    /**
     * A whole package became available.
     *
     * Deliberately one announcement, not one per course: a student who was just
     * given five courses wants to know they received a package, and five
     * near-identical messages would read as a malfunction.
     */
    public static function packageActivated(array $user, array $package, int $courseCount, ?int $actorId): array
    {
        return self::publish([
            'title'           => '🎁 پکیج جدید برای شما فعال شد',
            'body'            => sprintf(
                "پکیج «%s» برای حساب شما فعال شد.\nتعداد دوره‌های این پکیج: %s دوره\nاکنون می‌توانید به دوره‌های این پکیج دسترسی داشته باشید.",
                (string) $package['title'],
                Jalali::digits((string) $courseCount)
            ),
            'notif_type'      => 'package',
            'related_type'    => 'package',
            'related_id'      => (int) $package['id'],
            'idempotency_key' => sprintf('package_activated:%d:%d', (int) $user['id'], (int) $package['id']),
            'audience'        => 'user',
            'user_id'         => (int) $user['id'],
            'link_url'        => '/student/courses',
            'created_by'      => $actorId,
        ]);
    }
}
