<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;

/**
 * Per-user appearance and language: accent colour, light/dark/system, fa/en.
 */
final class Preferences
{
    /** key => [Persian name, light swatch, dark swatch] */
    public const ACCENTS = [
        'blueberry' => ['بلوبری',   '#2f6bff', '#5b8cff'],
        'orange'    => ['پرتقالی',  '#ea580c', '#fb923c'],
        'cherry'    => ['آلبالویی', '#be123c', '#fb7185'],
        'banana'    => ['موزی',     '#ca8a04', '#facc15'],
        'black'     => ['مشکی',     '#111827', '#9ca3af'],
        'cream'     => ['کرم',      '#a47148', '#d6b48c'],
        'mint'      => ['نعناعی',   '#059669', '#34d399'],
        'grape'     => ['انگوری',   '#7c3aed', '#a78bfa'],
        'sky'       => ['آسمانی',   '#0284c7', '#38bdf8'],
        'rose'      => ['گلبهی',    '#db2777', '#f472b6'],
        'pistachio' => ['پسته‌ای',  '#65a30d', '#a3e635'],
    ];

    public const MODES = ['system' => 'مطابق دستگاه', 'light' => 'روشن', 'dark' => 'تیره'];

    private const DEFAULTS = ['accent' => 'blueberry', 'mode' => 'system', 'lang' => 'fa'];

    private static ?array $current = null;

    /** @return array{accent:string, mode:string, lang:string} */
    public static function current(): array
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $prefs  = self::DEFAULTS;
        $userId = Auth::id();
        if ($userId !== null) {
            try {
                $row = Database::selectOne(
                    'SELECT accent, mode, lang FROM user_preferences WHERE user_id = :u LIMIT 1',
                    ['u' => $userId]
                );
                if ($row !== null) {
                    $prefs = self::clean($row);
                }
            } catch (\PDOException) {
                // Table not migrated yet: defaults.
            }
        }

        return self::$current = $prefs;
    }

    public static function save(int $userId, array $values): array
    {
        $prefs = self::clean($values + self::current());
        Database::execute(
            'INSERT INTO user_preferences (user_id, accent, mode, lang, updated_at)
             VALUES (:u, :a, :m, :l, NOW())
             ON DUPLICATE KEY UPDATE accent = VALUES(accent), mode = VALUES(mode), lang = VALUES(lang), updated_at = NOW()',
            ['u' => $userId, 'a' => $prefs['accent'], 'm' => $prefs['mode'], 'l' => $prefs['lang']]
        );

        return self::$current = $prefs;
    }

    private static function clean(array $v): array
    {
        return [
            'accent' => isset(self::ACCENTS[$v['accent'] ?? '']) ? $v['accent'] : self::DEFAULTS['accent'],
            'mode'   => isset(self::MODES[$v['mode'] ?? '']) ? $v['mode'] : self::DEFAULTS['mode'],
            'lang'   => isset(I18n::LANGS[$v['lang'] ?? '']) ? $v['lang'] : self::DEFAULTS['lang'],
        ];
    }
}