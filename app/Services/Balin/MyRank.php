<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Core\Database;
use HeleXa\Services\Jalali;
use HeleXa\Services\Settings;

/**
 * A student's own standing, without anyone else's name on it.
 *
 * With the public leaderboard switched off (the default), this is all a
 * student sees: where they stand today and this week, by study time and by
 * XP, among the students who actually studied in that window — "سوم از ۴۲
 * نفری که امروز مطالعه کردند". No list, no other student's name or number.
 */
final class MyRank
{
    /**
     * How the ranking section of the island behaves, set on «مرور جزیره»:
     *   off    — no ranking anywhere: no link, no page, nothing on «امروز من»;
     *   self   — each student sees only their own standing (the default);
     *   public — the full leaderboard with everyone's names.
     */
    public const MODES = ['off', 'self', 'public'];

    public static function mode(): string
    {
        $mode = (string) Settings::get('balin_ranking_mode', '');
        if (in_array($mode, self::MODES, true)) {
            return $mode;
        }
        // Before the three-way switch there was only an on/off for the board.
        return Settings::bool('balin_leaderboard_enabled', false) ? 'public' : 'self';
    }

    /** Whether the full public leaderboard is on. */
    public static function leaderboardEnabled(): bool
    {
        return self::mode() === 'public';
    }

    /**
     * @return array{
     *   today:array{study:array, xp:array},
     *   week:array{study:array, xp:array}
     * } each leaf: ['value'=>int, 'rank'=>?int, 'of'=>int]
     */
    public static function forStudent(int $userId): array
    {
        $today     = date('Y-m-d');
        // The Iranian week starts on Saturday; weekdayIndex() counts from it.
        $weekStart = date('Y-m-d', strtotime('-' . Jalali::weekdayIndex(time()) . ' days'));

        return [
            'today' => [
                'study' => self::study($userId, $today, $today),
                'xp'    => self::xp($userId, $today . ' 00:00:00'),
            ],
            'week' => [
                'study' => self::study($userId, $weekStart, $today),
                'xp'    => self::xp($userId, $weekStart . ' 00:00:00'),
            ],
        ];
    }

    /** Seconds studied in [from, to], and the rank among everyone who studied then. */
    private static function study(int $userId, string $from, string $to): array
    {
        try {
            $mine = (int) (Database::selectOne(
                'SELECT COALESCE(SUM(total_seconds), 0) AS s FROM study_daily_stats
                 WHERE user_id = :u AND stat_date BETWEEN :a AND :b',
                ['u' => $userId, 'a' => $from, 'b' => $to]
            )['s'] ?? 0);

            $of = (int) (Database::selectOne(
                'SELECT COUNT(*) AS c FROM (
                    SELECT user_id FROM study_daily_stats WHERE stat_date BETWEEN :a AND :b
                    GROUP BY user_id HAVING SUM(total_seconds) > 0) x',
                ['a' => $from, 'b' => $to]
            )['c'] ?? 0);

            $ahead = $mine > 0 ? (int) (Database::selectOne(
                'SELECT COUNT(*) AS c FROM (
                    SELECT user_id FROM study_daily_stats WHERE stat_date BETWEEN :a AND :b
                    GROUP BY user_id HAVING SUM(total_seconds) > :mine) x',
                ['a' => $from, 'b' => $to, 'mine' => $mine]
            )['c'] ?? 0) : 0;
        } catch (\PDOException) {
            return ['value' => 0, 'rank' => null, 'of' => 0];
        }

        return ['value' => $mine, 'rank' => $mine > 0 ? $ahead + 1 : null, 'of' => $of];
    }

    /** XP earned since a moment, and the rank among everyone who earned any. */
    private static function xp(int $userId, string $since): array
    {
        try {
            $mine = (int) (Database::selectOne(
                'SELECT COALESCE(SUM(amount), 0) AS s FROM balin_xp_transactions
                 WHERE user_id = :u AND created_at >= :since',
                ['u' => $userId, 'since' => $since]
            )['s'] ?? 0);

            $of = (int) (Database::selectOne(
                'SELECT COUNT(*) AS c FROM (
                    SELECT user_id FROM balin_xp_transactions WHERE created_at >= :since
                    GROUP BY user_id HAVING SUM(amount) > 0) x',
                ['since' => $since]
            )['c'] ?? 0);

            $ahead = $mine > 0 ? (int) (Database::selectOne(
                'SELECT COUNT(*) AS c FROM (
                    SELECT user_id FROM balin_xp_transactions WHERE created_at >= :since
                    GROUP BY user_id HAVING SUM(amount) > :mine) x',
                ['since' => $since, 'mine' => $mine]
            )['c'] ?? 0) : 0;
        } catch (\PDOException) {
            return ['value' => 0, 'rank' => null, 'of' => 0];
        }

        return ['value' => $mine, 'rank' => $mine > 0 ? $ahead + 1 : null, 'of' => $of];
    }

    /** "سوم", "دهم", "۲۳ام" — how a rank is read out in Persian. */
    public static function ordinal(int $n): string
    {
        $words = [1 => 'اول', 2 => 'دوم', 3 => 'سوم', 4 => 'چهارم', 5 => 'پنجم', 6 => 'ششم',
                  7 => 'هفتم', 8 => 'هشتم', 9 => 'نهم', 10 => 'دهم'];
        return $words[$n] ?? (fa((string) $n) . 'ام');
    }
}
