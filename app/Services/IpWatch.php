<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Core\Logger;

/**
 * Flags a student who signs in from several networks on the same day.
 *
 * A shared account looks like this: one person at home, another across town,
 * a third in another city, all on the same day. The single-device rule stops
 * them being signed in at the same moment; it does not stop them taking turns.
 *
 * What is counted is networks, not addresses. A phone on mobile data gets a
 * new address on nearly every reconnect, but inside the same carrier range, so
 * addresses are grouped by their /24 (IPv4) or /48 (IPv6) prefix. Home Wi-Fi
 * plus mobile data is two networks — which is why the default threshold is
 * three.
 *
 * A flag is a question for an admin, not a verdict. Nothing here suspends
 * anyone; the admin page does that, on a person's decision.
 */
final class IpWatch
{
    private static ?bool $available = null;

    /** "5.160.12.99" → "5.160.12", IPv6 → its first 48 bits. Pure. */
    public static function network(string $ip): string
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return implode('.', array_slice(explode('.', $ip), 0, 3));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                // An IPv4-mapped address (::ffff:a.b.c.d) is really IPv4.
                if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
                    return self::network((string) inet_ntop(substr($packed, 12)));
                }
                return bin2hex(substr($packed, 0, 6));
            }
        }

        return $ip;
    }

    /**
     * Pure: given one day's sign-in addresses, how many networks and is that
     * worth a flag.
     *
     * @param  array<int,string> $ips
     * @return array{networks:int, flag:bool}
     */
    public static function evaluate(array $ips, int $threshold): array
    {
        $networks = [];
        foreach ($ips as $ip) {
            $networks[self::network((string) $ip)] = true;
        }
        $count = count($networks);

        return ['networks' => $count, 'flag' => $count >= max(2, $threshold)];
    }

    public static function enabled(): bool
    {
        return Settings::bool('ip_watch_enabled', true) && self::available();
    }

    public static function threshold(): int
    {
        return max(2, min(Settings::int('ip_watch_threshold', 3), 20));
    }

    /** Whether the migration has run. Asked once per request. */
    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = Database::selectOne("SHOW TABLES LIKE 'security_flags'") !== null;
            } catch (\Throwable) {
                self::$available = false;
            }
        }
        return self::$available;
    }

    /**
     * Called right after a sign-in is recorded.
     *
     * Never throws: a detector must not be the reason someone cannot sign in.
     */
    public static function afterSignIn(int $userId, string $role): void
    {
        if ($role !== 'student' || !self::enabled()) {
            return;
        }

        try {
            $today = date('Y-m-d');
            $rows  = Database::select(
                'SELECT ip_address FROM sessions
                  WHERE user_id = :u AND login_at >= :from AND login_at <= :to',
                ['u' => $userId, 'from' => $today . ' 00:00:00', 'to' => $today . ' 23:59:59']
            );
            $ips    = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['ip_address'], $rows)));
            $result = self::evaluate($ips, self::threshold());

            if (!$result['flag']) {
                return;
            }

            $now = date('Y-m-d H:i:s');

            // One row per student per day. A later sign-in refreshes the
            // numbers; it reopens a dismissed flag only if the day got worse
            // than what the admin saw when dismissing it.
            Database::execute(
                "INSERT INTO security_flags (user_id, flag_type, flag_day, networks, sign_ins, ip_list, status, created_at)
                 VALUES (:u, 'multi_ip', :day, :n, :s, :ips, 'open', :now)
                 ON DUPLICATE KEY UPDATE
                    status     = IF(status = 'dismissed' AND VALUES(networks) > networks, 'open', status),
                    networks   = VALUES(networks),
                    sign_ins   = VALUES(sign_ins),
                    ip_list    = VALUES(ip_list),
                    updated_at = VALUES(created_at)",
                [
                    'u'   => $userId,
                    'day' => $today,
                    'n'   => $result['networks'],
                    's'   => count($rows),
                    'ips' => json_encode(array_slice($ips, 0, 50)),
                    'now' => $now,
                ]
            );

            ActivityLogger::log('security.multi_ip', $userId, 'user', $userId,
                ['networks' => $result['networks'], 'day' => $today], 'warning');
        } catch (\Throwable $e) {
            Logger::warning('IpWatch skipped: ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------- reads */

    /** User ids with an open or warned flag in the last $days days. */
    public static function flaggedUserIds(int $days = 30): array
    {
        if (!self::available()) {
            return [];
        }
        $rows = Database::select(
            "SELECT DISTINCT user_id FROM security_flags
              WHERE status IN ('open','warned') AND flag_day >= :since",
            ['since' => date('Y-m-d', strtotime('-' . max(1, $days) . ' days'))]
        );
        return array_map(static fn (array $r): int => (int) $r['user_id'], $rows);
    }

    public static function openCount(): int
    {
        if (!self::available()) {
            return 0;
        }
        return (int) (Database::selectOne("SELECT COUNT(*) AS c FROM security_flags WHERE status = 'open'")['c'] ?? 0);
    }

    /** The student's own recent flags, newest first. */
    public static function recentForUser(int $userId, int $days = 14): array
    {
        if (!self::available()) {
            return [];
        }
        return Database::select(
            "SELECT * FROM security_flags
              WHERE user_id = :u AND flag_day >= :since AND status <> 'dismissed'
              ORDER BY flag_day DESC",
            ['u' => $userId, 'since' => date('Y-m-d', strtotime('-' . max(1, $days) . ' days'))]
        );
    }
}
