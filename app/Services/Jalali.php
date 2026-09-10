<?php
declare(strict_types=1);

namespace HeleXa\Services;

/**
 * Gregorian <-> Jalali conversion.
 * The database always stores Gregorian dates; this class exists only so the
 * interface can be fully Persian without corrupting sortable date columns.
 */
final class Jalali
{
    public const MONTHS = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    /** Index 0 is Saturday, matching schedule_items.weekday. */
    public const WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

    /** @return array{0:int,1:int,2:int} [year, month, day] */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $daysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $gy2  = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
              + intdiv($gy2 + 399, 400) + $gd + $daysInMonth[$gm - 1];

        $jy    = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;

        $jy   += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy  += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /** @return array{0:int,1:int,2:int} [year, month, day] */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
              + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

        $gy    = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy   += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }

        $gy   += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy  += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd    = $days + 1;
        $isLeap = (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0) ? 1 : 0;
        $monthDays = [0, 31, 28 + $isLeap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gm = 0;
        while ($gm < 13 && $gd > $monthDays[$gm]) {
            $gd -= $monthDays[$gm];
            $gm++;
        }

        return [$gy, $gm, $gd];
    }

    /** "۱۴۰۵/۰۶/۱۵" */
    public static function date(int $timestamp, string $separator = '/'): string
    {
        [$y, $m, $d] = self::fromGregorian((int) date('Y', $timestamp), (int) date('n', $timestamp), (int) date('j', $timestamp));
        return self::digits(sprintf('%04d%s%02d%s%02d', $y, $separator, $m, $separator, $d));
    }

    /** "شنبه ۱۵ شهریور ۱۴۰۵" */
    public static function longDate(int $timestamp): string
    {
        [$y, $m, $d] = self::fromGregorian((int) date('Y', $timestamp), (int) date('n', $timestamp), (int) date('j', $timestamp));
        return sprintf(
            '%s %s %s %s',
            self::WEEKDAYS[self::weekdayIndex($timestamp)],
            self::digits((string) $d),
            self::MONTHS[$m],
            self::digits((string) $y)
        );
    }

    public static function dateTime(int $timestamp): string
    {
        return self::date($timestamp) . ' - ' . self::digits(date('H:i', $timestamp));
    }

    /** 0 = Saturday ... 6 = Friday */
    public static function weekdayIndex(int $timestamp): int
    {
        return ((int) date('w', $timestamp) + 1) % 7;
    }

    /** Timestamp of the most recent Saturday at 00:00. */
    public static function startOfWeek(int $timestamp): int
    {
        $offset = self::weekdayIndex($timestamp);
        return (int) mktime(0, 0, 0, (int) date('n', $timestamp), (int) date('j', $timestamp) - $offset, (int) date('Y', $timestamp));
    }

    /**
     * Leap-year rule for the Jalali calendar (33-year cycle).
     * Determines whether Esfand has 29 or 30 days.
     */
    public static function isLeapYear(int $jy): bool
    {
        return in_array($jy % 33, [1, 5, 9, 13, 17, 22, 26, 30], true);
    }

    public static function daysInMonth(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }
        return self::isLeapYear($jy) ? 30 : 29;
    }

    /** @return array{0:int,1:int} [year, month] of the current Jalali date */
    public static function currentMonth(): array
    {
        [$jy, $jm] = self::fromGregorian((int) date('Y'), (int) date('n'), (int) date('j'));
        return [$jy, $jm];
    }

    /** Normalises a year/month pair, rolling over at the year boundary. */
    public static function shiftMonth(int $jy, int $jm, int $delta): array
    {
        $total = (($jy * 12) + ($jm - 1)) + $delta;
        return [intdiv($total, 12), ($total % 12) + 1];
    }

    /**
     * Builds a month grid for the calendar view.
     *
     * @return array{
     *   year:int, month:int, monthName:string, days:array<int,array{day:int,date:string,weekday:int,isToday:bool}>,
     *   leadingBlanks:int
     * }
     */
    public static function monthGrid(int $jy, int $jm): array
    {
        $count = self::daysInMonth($jy, $jm);
        $days  = [];
        $first = 0;

        for ($day = 1; $day <= $count; $day++) {
            [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $day);
            $timestamp      = (int) mktime(12, 0, 0, $gm, $gd, $gy);
            $weekday        = self::weekdayIndex($timestamp);

            if ($day === 1) {
                $first = $weekday;
            }

            $days[] = [
                'day'     => $day,
                'date'    => date('Y-m-d', $timestamp),
                'weekday' => $weekday,
                'isToday' => date('Y-m-d', $timestamp) === date('Y-m-d'),
            ];
        }

        return [
            'year'          => $jy,
            'month'         => $jm,
            'monthName'     => self::MONTHS[$jm] ?? '',
            'days'          => $days,
            'leadingBlanks' => $first,
        ];
    }

    public static function digits(string $value): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $value
        );
    }

    /** Converts Persian/Arabic digits typed by a user back to ASCII. */
    public static function toLatinDigits(string $value): string
    {
        return str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $value
        );
    }
}
