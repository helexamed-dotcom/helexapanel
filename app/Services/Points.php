<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Models\Balin\BalinXpRepository;
use HeleXa\Services\Balin\CompetitionService;
use HeleXa\Services\Balin\Level;
use HeleXa\Services\Balin\Recalculator;

/**
 * One score for the whole site.
 *
 * Every section pays into the same append-only ledger Balin island started
 * (balin_xp_transactions): answering a question right, reviewing flashcards,
 * the figure game, reading a درسنامه, finishing an exam. The level comes
 * from the lifetime total; the league from what was earned this week, so a
 * new student can reach the top league in a good week and an old one has
 * to keep working to stay there. Both live on the profile.
 */
final class Points
{
    /** key => [title, weekly XP needed, colour, emoji] — lowest first. */
    public const LEAGUES = [
        'bronze'   => ['لیگ برنز',   0,    'orange', '🥉'],
        'silver'   => ['لیگ نقره',   150,  'slate',  '🥈'],
        'gold'     => ['لیگ طلا',    400,  'amber',  '🥇'],
        'sapphire' => ['لیگ یاقوت',  800,  'blue',   '💎'],
        'ruby'     => ['لیگ لعل',    1400, 'rose',   '🔥'],
        'diamond'  => ['لیگ الماس',  2200, 'violet', '👑'],
    ];

    /** Ledger `type` values this service may write. */
    public const TYPES = [
        'question_correct', 'flashcard_review', 'figure_correct', 'lesson_read',
        'exam_finished', 'mindmap_done', 'admin_adjustment',
    ];

    /**
     * Pays once per key. Returns what changed, or null when nothing was paid
     * (duplicate key, zero amount, or the ledger not installed).
     *
     * @return array{xp:int, total:int, level:int, levelled_up:bool}|null
     */
    public static function award(int $userId, int $amount, string $type, string $sourceType, ?int $sourceId, string $key, array $meta = []): ?array
    {
        if ($amount <= 0 || !in_array($type, self::TYPES, true)) {
            return null;
        }
        try {
            $ledger = new BalinXpRepository();
            $before = Level::forXp($ledger->totalFor($userId));
            $result = $ledger->award($userId, $amount, $type, $sourceType, $sourceId,
                hash('sha256', $key), CompetitionService::currentId(), $meta);
            if (!$result['awarded']) {
                return null;
            }
            CompetitionService::mirrorXp($userId, $amount);
            $total = (int) (new Recalculator())->userStats($userId)['total_xp'];
            $level = Level::forXp($total);
            return ['xp' => $amount, 'total' => $total, 'level' => $level, 'levelled_up' => $level > $before];
        } catch (\Throwable $e) {
            // The work itself is already saved; a failed reward must not fail it.
            error_log('[points] ' . $e->getMessage());
            return null;
        }
    }

    /** The configured amount for an action, bounded. */
    public static function amount(string $action, int $default): int
    {
        return max(0, min(500, Settings::int('points_' . $action, $default)));
    }

    /** Start of the current week (Saturday 00:00), as a MySQL datetime. */
    public static function weekStart(): string
    {
        return date('Y-m-d 00:00:00', Jalali::startOfWeek(time()));
    }

    public static function weeklyXp(int $userId): int
    {
        try {
            return (int) (Database::selectOne(
                'SELECT COALESCE(SUM(amount), 0) AS s FROM balin_xp_transactions WHERE user_id = :u AND created_at >= :w',
                ['u' => $userId, 'w' => self::weekStart()]
            )['s'] ?? 0);
        } catch (\PDOException) {
            return 0;
        }
    }

    /** @return array{key:string,title:string,min:int,color:string,emoji:string,next:?array} */
    public static function league(int $weeklyXp): array
    {
        $current = 'bronze';
        foreach (self::LEAGUES as $key => [, $min]) {
            if ($weeklyXp >= self::threshold($key, $min)) {
                $current = $key;
            }
        }
        $keys = array_keys(self::LEAGUES);
        $pos  = array_search($current, $keys, true);
        $nextKey = $keys[$pos + 1] ?? null;
        [$title, $min, $color, $emoji] = self::LEAGUES[$current];

        return [
            'key' => $current, 'title' => $title, 'min' => self::threshold($current, $min), 'color' => $color, 'emoji' => $emoji,
            'next' => $nextKey === null ? null : [
                'key'   => $nextKey,
                'title' => self::LEAGUES[$nextKey][0],
                'need'  => max(0, self::threshold($nextKey, self::LEAGUES[$nextKey][1]) - $weeklyXp),
                'min'   => self::threshold($nextKey, self::LEAGUES[$nextKey][1]),
            ],
        ];
    }

    private static function threshold(string $key, int $default): int
    {
        return max(0, Settings::int('league_' . $key, $default));
    }

    /**
     * Everything the profile and dashboard show about a student's score.
     *
     * @return array{total:int, week:int, progress:array, rank:array, league:array, streak:int}|null
     */
    public static function summary(int $userId): ?array
    {
        try {
            $total  = (new BalinXpRepository())->totalFor($userId);
            $level  = Level::progress($total);
            $week   = self::weeklyXp($userId);
            $streak = (int) ((new \HeleXa\Models\Balin\BalinStreakRepository())->state($userId)['current_streak'] ?? 0);
            $rank   = (new \HeleXa\Services\Balin\ProfileService())->rankFor($level['level']);
        } catch (\Throwable $e) {
            error_log('[points summary] ' . $e->getMessage());
            return null;
        }

        return [
            'total'    => $total,
            'week'     => $week,
            'progress' => $level,
            'rank'     => $rank,
            'league'   => self::league($week),
            'streak'   => $streak,
        ];
    }

    /**
     * This week's table: everyone who earned something and has not hidden
     * themselves from the rankings. Private accounts show as their initials.
     *
     * @return list<array{user_id:int, full_name:string, username:string, avatar_path:?string, gender:?string, uuid:string, xp:int, place:int}>
     */
    public static function weeklyBoard(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        try {
            $rows = Database::select(
                "SELECT u.id AS user_id, u.uuid, u.full_name, u.username, u.avatar_path, u.gender, SUM(x.amount) AS xp
                 FROM balin_xp_transactions x
                 JOIN users u ON u.id = x.user_id AND u.deleted_at IS NULL AND u.status = 'active'
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE x.created_at >= :w AND COALESCE(p.show_in_leaderboard, 1) = 1
                 GROUP BY u.id HAVING xp > 0
                 ORDER BY xp DESC, u.id LIMIT {$limit}",
                ['w' => self::weekStart()]
            );
        } catch (\PDOException) {
            return [];
        }
        $place = 0;
        foreach ($rows as &$row) {
            $row['place'] = ++$place;
            $row['xp'] = (int) $row['xp'];
        }
        return $rows;
    }
}
