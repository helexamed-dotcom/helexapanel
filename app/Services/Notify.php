<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Models\NotificationRepository;

/**
 * A notification to one student, from anywhere in the code: an approved
 * request, a paid order, a reply. Never allowed to fail the action that
 * caused it.
 */
final class Notify
{
    /** notif_type is one of: content, schedule, exam, course, package, message, support, system */
    public static function user(int $userId, string $title, string $body, ?string $link = null, string $type = 'system'): void
    {
        try {
            (new NotificationRepository())->create([
                'title'      => mb_substr($title, 0, 191),
                'body'       => $body,
                'notif_type' => $type,
                'audience'   => 'user',
                'user_id'    => $userId,
                'term_id'    => null,
                'group_id'   => null,
                'link_url'   => $link,
                'expires_at' => null,
            ], Auth::id());
        } catch (\Throwable $e) {
            error_log('[notify] ' . $e->getMessage());
        }
    }
}
