<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Models\StudySessionRepository;

/**
 * Reporting over the verified study data.
 * Weeks run Saturday to Friday, matching the Iranian academic week.
 */
final class StudyAnalytics
{
    /**
     * @param int $weeksAgo 0 = current week, 1 = last week, ...
     * @return array{days:array<int,array{label:string,date:string,seconds:int,jalali:string}>, total:int, from:string, to:string}
     */
    public static function week(int $userId, int $weeksAgo = 0): array
    {
        $weeksAgo = max(0, min($weeksAgo, 12));

        $startTs = Jalali::startOfWeek(time()) - ($weeksAgo * 7 * 86400);
        $from    = date('Y-m-d', $startTs);
        $to      = date('Y-m-d', $startTs + (6 * 86400));

        $totals = (new StudySessionRepository())->dailyTotals($userId, $from, $to);

        $days = [];
        foreach (Jalali::WEEKDAYS as $index => $label) {
            $dayTs  = $startTs + ($index * 86400);
            $key    = date('Y-m-d', $dayTs);
            $days[] = [
                'label'   => $label,
                'date'    => $key,
                'jalali'  => Jalali::date($dayTs),
                'seconds' => $totals[$key] ?? 0,
                'isToday' => $key === date('Y-m-d'),
            ];
        }

        return [
            'days'  => $days,
            'total' => array_sum(array_column($days, 'seconds')),
            'from'  => $from,
            'to'    => $to,
        ];
    }

    public static function todaySeconds(int $userId): int
    {
        $today = date('Y-m-d');
        return (new StudySessionRepository())->totalBetween($userId, $today, $today);
    }

    public static function coursesThisWeek(int $userId, int $weeksAgo = 0): array
    {
        $startTs = Jalali::startOfWeek(time()) - ($weeksAgo * 7 * 86400);
        return (new StudySessionRepository())->totalsByCourse(
            $userId,
            date('Y-m-d', $startTs),
            date('Y-m-d', $startTs + (6 * 86400))
        );
    }

    /** "۲ ساعت و ۱۵ دقیقه" / "۴۵ دقیقه" / "۰ دقیقه" */
    public static function humanDuration(int $seconds): string
    {
        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0 && $minutes > 0) {
            return Jalali::digits((string) $hours) . ' ساعت و ' . Jalali::digits((string) $minutes) . ' دقیقه';
        }
        if ($hours > 0) {
            return Jalali::digits((string) $hours) . ' ساعت';
        }
        return Jalali::digits((string) $minutes) . ' دقیقه';
    }

    /** "01:23:45" for the viewer's timer. */
    public static function clock(int $seconds): string
    {
        return Jalali::digits(sprintf(
            '%02d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        ));
    }
}
