<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Logger;
use HeleXa\Core\Request;
use HeleXa\Models\ActivityLogRepository;

final class ActivityLogger
{
    public static function log(
        string $action,
        ?int $userId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        array $metadata = [],
        string $severity = 'info',
        ?Request $request = null
    ): void {
        try {
            (new ActivityLogRepository())->write([
                'user_id'     => $userId,
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'ip'          => $request?->ip(),
                'user_agent'  => $request?->userAgent(),
                'metadata'    => $metadata,
                'severity'    => $severity,
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the request it is auditing.
            Logger::error('Activity log write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
