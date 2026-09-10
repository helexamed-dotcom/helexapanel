<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Logger;
use HeleXa\Models\LoginAttemptRepository;
use HeleXa\Models\RateLimitRepository;
use HeleXa\Models\RememberTokenRepository;
use HeleXa\Models\SessionRepository;
use HeleXa\Models\SettingRepository;
use HeleXa\Models\StudySessionRepository;
use HeleXa\Models\SyncEventRepository;
use HeleXa\Models\ViewerTokenRepository;

/**
 * Housekeeping.
 *
 * There is no cron on most shared hosting, so cleanup piggybacks on normal
 * traffic: at most once an hour, one request pays the cost. Everything here is
 * idempotent and wrapped, because maintenance must never break a page load.
 */
final class Maintenance
{
    private const INTERVAL = 3600;

    public static function maybeRun(): void
    {
        try {
            $last = Settings::int('maintenance_last_run', 0);
            if ($last > time() - self::INTERVAL) {
                return;
            }
            // Claim the slot first so two concurrent requests do not both run it.
            (new SettingRepository())->set('maintenance_last_run', (string) time(), 'int', null);
            Settings::flush();

            self::run();
        } catch (\Throwable $e) {
            Logger::warning('Maintenance skipped', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string,int> what was removed, for the admin panel */
    public static function run(): array
    {
        $result = [];

        $result['viewer_tokens'] = (new ViewerTokenRepository())->purgeExpired();
        $result['rate_limits']   = (new RateLimitRepository())->purgeExpired();
        $result['remember_tokens'] = (new RememberTokenRepository())->purgeExpired();

        $result['login_attempts'] = (new LoginAttemptRepository())
            ->purgeOlderThan(Settings::int('attempt_retention_days', 30));

        $result['sessions_closed'] = (new SessionRepository())->sweepStale(
            Settings::int('session_idle_timeout', 1800),
            Settings::int('session_absolute_timeout', 7200)
        );

        $result['study_sessions_closed'] = (new StudySessionRepository())
            ->sweepStale(Settings::int('study_idle_cutoff', 120));

        $result['activity_logs'] = self::purgeActivityLogs(Settings::int('log_retention_days', 180));

        // The sync ledger only needs to outlive the retry window; after that a
        // replayed event is far outside the accepted timestamp range anyway.
        $result['sync_events'] = (new SyncEventRepository())->purgeOlderThan(90);

        Logger::info('Maintenance completed', $result);

        return $result;
    }

    /**
     * Ages out routine log rows. Warning and critical entries are kept:
     * those are the ones an incident review would need.
     */
    private static function purgeActivityLogs(int $days): int
    {
        $days = max(30, $days);

        return \HeleXa\Core\Database::execute(
            "DELETE FROM activity_logs
             WHERE created_at < :cutoff AND severity IN ('info', 'notice')
             LIMIT 5000",
            ['cutoff' => date('Y-m-d H:i:s', time() - ($days * 86400))]
        );
    }
}
