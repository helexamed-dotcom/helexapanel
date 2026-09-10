<?php
declare(strict_types=1);

namespace HeleXa\Core;

/**
 * Immutable-ish configuration registry with dot notation access.
 * Nothing here is ever echoed to the client.
 */
final class Config
{
    private static array $items = [];

    public static function loadFile(string $namespace, string $path): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Configuration file is missing.');
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException('Configuration file did not return an array.');
        }
        self::$items[$namespace] = $data;
    }

    public static function hydrate(string $namespace, array $data): void
    {
        self::$items[$namespace] = $data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $cursor   = self::$items;
        foreach ($segments as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__MISSING__') !== '__MISSING__';
    }
}
