<?php
declare(strict_types=1);

namespace HeleXa\Services;

/**
 * Interface language: Persian (default, RTL) or English (LTR).
 */
final class I18n
{
    public const LANGS = ['fa' => 'فارسی', 'en' => 'انگلیسی'];

    private static ?array $dict = null;

    public static function lang(): string
    {
        return Preferences::current()['lang'];
    }

    public static function dir(): string
    {
        return self::lang() === 'en' ? 'ltr' : 'rtl';
    }

    public static function t(string $fa): string
    {
        if (self::lang() !== 'en') {
            return $fa;
        }
        if (self::$dict === null) {
            $file = dirname(__DIR__) . '/Lang/en.php';
            $loaded = is_file($file) ? require $file : [];
            self::$dict = is_array($loaded) ? $loaded : [];
        }

        return self::$dict[$fa] ?? $fa;
    }
}