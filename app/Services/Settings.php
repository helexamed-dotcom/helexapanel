<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Models\SettingRepository;

/**
 * Runtime settings loaded once per request from the settings table.
 * Config file values are the fallback when a key is missing.
 */
final class Settings
{
    private static ?array $cache = null;

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            foreach ((new SettingRepository())->all() as $row) {
                self::$cache[$row['setting_key']] = ['value' => $row['setting_value'], 'type' => $row['value_type']];
            }
        } catch (\Throwable) {
            // Before installation the table may not exist yet; defaults apply.
            self::$cache = [];
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $items = self::load();
        if (!isset($items[$key])) {
            return $default;
        }
        $raw  = $items[$key]['value'];
        return match ($items[$key]['type']) {
            'int'  => (int) $raw,
            'bool' => in_array($raw, ['1', 'true', 'on', 'yes'], true),
            'json' => json_decode((string) $raw, true) ?? $default,
            default => $raw,
        };
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key, $default);
        return is_bool($value) ? $value : $default;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
