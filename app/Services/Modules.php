<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;

/**
 * Every section a student can open, in one list.
 *
 * The launcher, the phone tab bar, the dashboard shortcuts and the route
 * guard all read this, so a section switched off in one place is gone from
 * all of them at once. Whether a section is on for the signed-in student is
 * decided in three layers, each of which can only take away:
 *
 *   1. the site-wide switch (Admin → تنظیمات → بخش‌ها), `modules_off`;
 *   2. the student's type (ترمی، علوم پایه، دستیاری …), whose own module list
 *      the admin sets on «نوع دانشجو»; a student without an approved type
 *      gets `modules_default`, or everything when that is empty;
 *   3. the module's own publication switch where it has one (Balin island
 *      and the question bank can be closed or "coming soon").
 *
 * Admins are never filtered: they preview everything.
 */
final class Modules
{
    /**
     * key => [label, short description, icon, tone, href, group]
     *
     * The order here is the order of the launcher.
     */
    public const CATALOG = [
        'lessons'    => ['درسنامه‌ها',     'درسنامه‌های متنی و نکته‌ها',        'lesson',   'indigo', '/student/lessons',    'study'],
        'courses'    => ['دوره‌ها',        'جزوه‌ها و ویدیوهای دوره',           'book',     'sky',    '/student/courses',    'study'],
        'mindmaps'   => ['نقشه ذهنی',      'مایندمپ درس‌ها',                   'mindmap',  'teal',   '/student/mindmaps',   'study'],
        'library'    => ['کتابخانه',       'منابع، مقاله‌ها و فایل‌ها',          'folder',   'green',  '/student/library',    'study'],
        'notes'      => ['یادداشت‌ها',     'دست‌نویس و یادداشت‌های من',         'note',     'pink',   '/student/notes',      'study'],
        'qbank'      => ['بانک سوال',      'تمرین تست درس به درس',             'qbank',    'violet', '/student/qbank',      'practice'],
        'my_exams'   => ['آزمون‌های من',   'آزمون خودساخته و تحلیل',            'target',   'red',    '/student/my-exams',   'practice'],
        'flashcards' => ['فلش‌کارت',       'مرور هوشمند کارت‌ها',               'cards',    'rose',   '/student/flashcards', 'practice'],
        'figures'    => ['بازی با شکل',    'اطلس و شکل‌های تعاملی',             'figure',   'amber',  '/student/figures',    'practice'],
        'balin'      => ['جزیره بالین',    'کیس‌های بالینی مرحله به مرحله',      'island',   'teal',   '/student/balin',      'practice'],
        'planner'    => ['برنامه و امتحان', 'کلاس‌ها، امتحان‌ها و تقویم',        'calendar', 'blue',   '/student/planner',    'plan'],
        'analytics'  => ['تحلیل عملکرد',   'زمان مطالعه و نقاط قوت',            'chart',    'green',  '/student/analytics',  'plan'],
        'shop'       => ['فروشگاه',        'پکیج‌ها و محصولات آموزشی',          'bag',      'orange', '/shop',               'shop'],
    ];

    public const GROUPS = [
        'study'    => 'مطالعه',
        'practice' => 'تمرین و بازی',
        'plan'     => 'برنامه',
        'shop'     => 'خرید',
    ];

    /** Sections that cannot be switched off: the student would be locked out. */
    private const ALWAYS = ['dashboard', 'profile', 'support'];

    private static ?array $enabled = null;

    public static function exists(string $key): bool
    {
        return isset(self::CATALOG[$key]);
    }

    public static function enabled(string $key): bool
    {
        if (in_array($key, self::ALWAYS, true)) {
            return true;
        }
        if (!isset(self::CATALOG[$key])) {
            return false;
        }
        return in_array($key, self::enabledKeys(), true);
    }

    /** @return list<string> */
    public static function enabledKeys(): array
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }

        $all = array_keys(self::CATALOG);
        $off = self::stringList(Settings::get('modules_off', []));
        $keys = array_values(array_diff($all, $off));

        if (Auth::check() && Auth::isStudent()) {
            $allowed = self::forStudentType((array) Auth::user());
            if ($allowed !== null) {
                $keys = array_values(array_intersect($keys, $allowed));
            }
        }

        // The modules that have their own publication switch.
        $keys = array_values(array_filter($keys, static function (string $key): bool {
            try {
                return match ($key) {
                    'balin'    => \HeleXa\Services\Balin\Access::menuVisible(),
                    'qbank',
                    'my_exams' => \HeleXa\Services\QuestionBank\QbAccess::menuVisible(),
                    'notes'    => Settings::bool('notes_enabled', true),
                    default    => true,
                };
            } catch (\Throwable) {
                return true;
            }
        }));

        return self::$enabled = $keys;
    }

    /**
     * The module list of this student's approved type, or of the default when
     * they have none. null means "no limit".
     *
     * @return list<string>|null
     */
    public static function forStudentType(array $user): ?array
    {
        $typeId = StudentTypes::typeIdOf($user);
        if ($typeId > 0) {
            try {
                $row = Database::selectOne(
                    'SELECT modules FROM student_types WHERE id = :id AND is_active = 1 LIMIT 1',
                    ['id' => $typeId]
                );
                if ($row !== null) {
                    $list = json_decode((string) $row['modules'], true);
                    return is_array($list) ? self::stringList($list) : null;
                }
            } catch (\PDOException) {
                // Not migrated yet: no limit.
            }
        }

        $default = Settings::get('modules_default', null);
        return is_array($default) && $default !== [] ? self::stringList($default) : null;
    }

    /**
     * The launcher's sections, grouped, with only what is on.
     *
     * @return array<string, list<array{key:string,label:string,desc:string,icon:string,tone:string,href:string}>>
     */
    public static function menu(): array
    {
        $out = [];
        foreach (self::CATALOG as $key => [$label, $desc, $icon, $tone, $href, $group]) {
            if (!self::enabled($key)) {
                continue;
            }
            $out[$group][] = compact('key', 'label', 'desc', 'icon', 'tone', 'href');
        }

        $ordered = [];
        foreach (array_keys(self::GROUPS) as $group) {
            if (!empty($out[$group])) {
                $ordered[$group] = $out[$group];
            }
        }
        return $ordered;
    }

    /** The module a student path belongs to, for the route guard. */
    public static function forPath(string $path): ?string
    {
        $map = [
            '/student/lessons'    => 'lessons',
            '/student/courses'    => 'courses',
            '/student/mindmaps'   => 'mindmaps',
            '/student/library'    => 'library',
            '/student/notes'      => 'notes',
            '/student/qbank'      => 'qbank',
            '/student/my-exams'   => 'my_exams',
            '/student/flashcards' => 'flashcards',
            '/student/figures'    => 'figures',
            '/student/balin'      => 'balin',
            '/student/planner'    => 'planner',
            '/student/schedule'   => 'planner',
            '/student/calendar'   => 'planner',
            '/student/exams'      => 'planner',
            '/student/midterms'   => 'planner',
            '/student/analytics'  => 'analytics',
            '/shop'               => 'shop',
        ];
        foreach ($map as $prefix => $key) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $key;
            }
        }
        return null;
    }

    public static function flush(): void
    {
        self::$enabled = null;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $value), static fn (string $k): bool => $k !== ''));
    }
}
