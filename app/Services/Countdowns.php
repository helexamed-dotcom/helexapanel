<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Core\Str;

/**
 * The dashboard's countdowns — «روزشمار».
 *
 * The admin names an event and its moment (the date in the Persian calendar,
 * an optional time), picks a colour, and may limit it to some student types.
 * Stored as one JSON list in the `dashboard_countdowns` setting: there are
 * only ever a handful, and a table would be heavier than the thing it holds.
 *
 * Each entry: {id, title, subtitle, at: "Y-m-d H:i:s", color, types: [ids]}
 */
final class Countdowns
{
    public const COLORS = [
        'violet' => 'بنفش', 'blue' => 'آبی', 'teal' => 'فیروزه‌ای', 'green' => 'سبز',
        'amber' => 'کهربایی', 'orange' => 'نارنجی', 'rose' => 'گلی', 'slate' => 'دودی',
    ];

    /** @return list<array> every countdown, soonest first */
    public static function all(): array
    {
        $list = Settings::get('dashboard_countdowns', []);
        $list = is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
        usort($list, static fn (array $a, array $b): int => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));
        return $list;
    }

    /**
     * The ones this student should see: still ahead (or ended within the last
     * day, so "today!" is shown on the day itself) and meant for their type.
     *
     * @return list<array>
     */
    public static function forStudent(array $user): array
    {
        $typeId = StudentTypes::typeIdOf($user);
        $cutoff = date('Y-m-d H:i:s', time() - 86400);
        return array_values(array_filter(self::all(), static function (array $c) use ($typeId, $cutoff): bool {
            if ((string) ($c['at'] ?? '') < $cutoff) {
                return false;
            }
            $types = array_map('intval', (array) ($c['types'] ?? []));
            return $types === [] || in_array($typeId, $types, true);
        }));
    }

    /** @return array{ok:bool, message:string} */
    public static function add(string $title, string $subtitle, string $jalaliDate, string $time, string $color, array $types): array
    {
        $title = trim(mb_substr($title, 0, 80));
        if ($title === '') {
            return ['ok' => false, 'message' => 'عنوان را بنویسید.'];
        }
        $at = self::parseJalali($jalaliDate, $time);
        if ($at === null) {
            return ['ok' => false, 'message' => 'تاریخ را به شکل ۱۴۰۵/۰۸/۲۰ وارد کنید.'];
        }

        $list = self::all();
        $list[] = [
            'id'       => Str::token(6),
            'title'    => $title,
            'subtitle' => trim(mb_substr($subtitle, 0, 140)),
            'at'       => $at,
            'color'    => isset(self::COLORS[$color]) ? $color : 'violet',
            'types'    => array_values(array_unique(array_filter(array_map('intval', $types)))),
        ];
        self::save($list);

        return ['ok' => true, 'message' => 'روزشمار اضافه شد.'];
    }

    public static function remove(string $id): void
    {
        self::save(array_values(array_filter(self::all(), static fn (array $c): bool => ($c['id'] ?? '') !== $id)));
    }

    /**
     * "1405/8/20" (Persian or Latin digits, / or -) and "08:30" → a MySQL
     * datetime in the site's time zone. null when the date is not real.
     */
    public static function parseJalali(string $date, string $time = ''): ?string
    {
        $date = Jalali::toLatinDigits(trim($date));
        if (preg_match('~^(1[34]\d\d)[/\-.](\d{1,2})[/\-.](\d{1,2})$~', $date, $m) !== 1) {
            return null;
        }
        [$jy, $jm, $jd] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > Jalali::daysInMonth($jy, $jm)) {
            return null;
        }
        $time = Jalali::toLatinDigits(trim($time));
        if (preg_match('~^([01]?\d|2[0-3]):([0-5]\d)$~', $time, $t) !== 1) {
            $t = [0, '0', '0'];
        }
        [$gy, $gm, $gd] = Jalali::toGregorian($jy, $jm, $jd);

        return sprintf('%04d-%02d-%02d %02d:%02d:00', $gy, $gm, $gd, (int) $t[1], (int) $t[2]);
    }

    private static function save(array $list): void
    {
        $json = json_encode($list, JSON_UNESCAPED_UNICODE);
        Database::execute(
            "INSERT INTO settings (setting_key, setting_value, value_type, is_public, updated_by, updated_at)
             VALUES ('dashboard_countdowns', :v, 'json', 0, :u, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = 'json',
                                     updated_by = VALUES(updated_by), updated_at = NOW()",
            ['v' => $json, 'u' => Auth::id()]
        );
        Settings::flush();
    }
}
