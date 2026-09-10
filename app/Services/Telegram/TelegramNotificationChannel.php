<?php
declare(strict_types=1);

namespace HeleXa\Services\Telegram;

use HeleXa\Models\NotificationDeliveryRepository;
use HeleXa\Models\TelegramAccountRepository;
use HeleXa\Core\Database;
use HeleXa\Services\NotificationService;
use HeleXa\Services\Settings;

/**
 * Registers Telegram as a delivery channel for NotificationService.
 *
 * This file only decides *who* should receive a Telegram copy of a
 * notification and queues it; it never calls the Telegram API itself. That
 * split matters: queuing happens inline with the request that created the
 * notification (fast, all local), while the actual HTTP call to Telegram
 * happens later from TelegramQueue::drainDue(), so a slow or failing
 * Telegram API can never slow down or fail a course activation.
 */
final class TelegramNotificationChannel
{
    private const PREFERENCE_BY_TYPE = [
        'schedule' => 'notify_schedule',
        'exam'     => 'notify_exams',
        'course'   => 'notify_general',
        'package'  => 'notify_general',
        'content'  => 'notify_general',
        'message'  => 'notify_general',
        'support'  => 'notify_support',
        'system'   => 'notify_announcements',
    ];

    public static function register(): void
    {
        NotificationService::registerChannel('telegram', [self::class, 'enqueue']);
    }

    /** @param array<string,mixed> $data the same array passed to NotificationService::publish(), plus notification_id */
    public static function enqueue(array $data): void
    {
        if (!Settings::bool('telegram_bot_enabled', false)) {
            return;
        }

        $notificationId = (int) ($data['notification_id'] ?? 0);
        if ($notificationId <= 0) {
            return;
        }

        // The fan-out into user_notifications already happened inside the
        // same transaction that created the notification; this reads that
        // same recipient list rather than re-deriving the audience rules.
        $recipients = Database::select(
            'SELECT user_id FROM user_notifications WHERE notification_id = :id',
            ['id' => $notificationId]
        );
        if ($recipients === []) {
            return;
        }

        $preferenceColumn = self::PREFERENCE_BY_TYPE[$data['notif_type'] ?? 'system'] ?? 'notify_general';
        $userIds = array_map(static fn (array $r): int => (int) $r['user_id'], $recipients);

        $notifiable = (new TelegramAccountRepository())->chatIdsForNotifiable($userIds, $preferenceColumn);
        if ($notifiable === []) {
            return;
        }

        $deliveries = new NotificationDeliveryRepository();
        foreach (array_keys($notifiable) as $userId) {
            $deliveries->enqueue($notificationId, $userId, 'telegram');
        }
    }
}
