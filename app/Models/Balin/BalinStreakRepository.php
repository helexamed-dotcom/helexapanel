<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Daily activity streaks.
 *
 * A day is one row, keyed uniquely on (user, date). The date is already
 * normalised to the institution's timezone before it gets here, so "today"
 * means the same thing for a student at 1am Tehran time as it does for the
 * cron job that scores the weekly mission.
 *
 * The current streak is not incremented in place — it is counted from the
 * rows, so a missed day cannot leave a stale counter behind.
 */
final class BalinStreakRepository extends BaseRepository
{
    /** @return bool true when this call recorded the day for the first time */
    public function recordDay(int $userId, string $date): bool
    {
        $changed = $this->execute(
            'INSERT IGNORE INTO balin_streaks (user_id, streak_date, created_at)
             VALUES (:user, :date, :now)',
            ['user' => $userId, 'date' => $date, 'now' => $this->now()]
        );

        return $changed > 0;
    }

    public function hasDay(int $userId, string $date): bool
    {
        return $this->selectOne(
            'SELECT 1 FROM balin_streaks WHERE user_id = :user AND streak_date = :date LIMIT 1',
            ['user' => $userId, 'date' => $date]
        ) !== null;
    }

    /**
     * Counts back from today (or yesterday, if today has no activity yet)
     * until the first gap. Reading the rows rather than trusting a counter
     * means the answer is always right, even after a restore or a manual fix.
     */
    public function currentStreak(int $userId, string $today): int
    {
        $rows = $this->select(
            'SELECT streak_date FROM balin_streaks
             WHERE user_id = :user AND streak_date <= :today
             ORDER BY streak_date DESC
             LIMIT 400',
            ['user' => $userId, 'today' => $today]
        );

        if ($rows === []) {
            return 0;
        }

        $dates = array_map(static fn (array $row): string => (string) $row['streak_date'], $rows);
        $todayDate = new \DateTimeImmutable($today);

        // A streak stays alive on a day the student has not studied yet, so
        // the walk may legitimately start at yesterday.
        $cursor = $dates[0] === $today ? $todayDate : $todayDate->modify('-1 day');
        if ($dates[0] !== $cursor->format('Y-m-d')) {
            return 0;
        }

        $streak = 0;
        foreach ($dates as $date) {
            if ($date !== $cursor->format('Y-m-d')) {
                break;
            }
            $streak++;
            $cursor = $cursor->modify('-1 day');
        }

        return $streak;
    }

    public function longestStreak(int $userId): int
    {
        $rows = $this->select(
            'SELECT streak_date FROM balin_streaks WHERE user_id = :user ORDER BY streak_date',
            ['user' => $userId]
        );

        $longest = 0;
        $run     = 0;
        $previous = null;

        foreach ($rows as $row) {
            $date = new \DateTimeImmutable((string) $row['streak_date']);
            $run = ($previous !== null && $previous->modify('+1 day')->format('Y-m-d') === $date->format('Y-m-d'))
                ? $run + 1
                : 1;
            $longest  = max($longest, $run);
            $previous = $date;
        }

        return $longest;
    }

    public function activeDayCount(int $userId): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM balin_streaks WHERE user_id = :user',
            ['user' => $userId]
        )['c'] ?? 0);
    }

    /** Days active inside one week, for the weekly mission. */
    public function daysInRange(int $userId, string $from, string $to): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM balin_streaks
             WHERE user_id = :user AND streak_date BETWEEN :from AND :to',
            ['user' => $userId, 'from' => $from, 'to' => $to]
        )['c'] ?? 0);
    }

    public function storeState(int $userId, int $current, int $longest, string $lastActiveDate): void
    {
        $this->execute(
            'INSERT INTO balin_user_streak_state
                (user_id, current_streak, longest_streak, last_active_date, updated_at)
             VALUES (:user, :current, :longest, :last, :now)
             ON DUPLICATE KEY UPDATE
                current_streak = VALUES(current_streak), longest_streak = VALUES(longest_streak),
                last_active_date = VALUES(last_active_date), updated_at = VALUES(updated_at)',
            [
                'user'    => $userId,
                'current' => $current,
                'longest' => $longest,
                'last'    => $lastActiveDate,
                'now'     => $this->now(),
            ]
        );
    }

    public function state(int $userId): array
    {
        return $this->selectOne(
            'SELECT * FROM balin_user_streak_state WHERE user_id = :user LIMIT 1',
            ['user' => $userId]
        ) ?? [
            'user_id'           => $userId,
            'current_streak'    => 0,
            'longest_streak'    => 0,
            'last_active_date'  => null,
            'freezes_available' => 0,
        ];
    }

    /** @return array<int, array{user_id:int, score:float}> */
    public function leaderboardRows(int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));

        return $this->select(
            "SELECT user_id, current_streak AS score
             FROM balin_user_streak_state
             WHERE current_streak > 0
             ORDER BY current_streak DESC
             LIMIT {$limit}"
        );
    }
}
