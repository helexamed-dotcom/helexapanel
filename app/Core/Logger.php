<?php
declare(strict_types=1);

namespace HeleXa\Core;

/**
 * File logger for storage/logs. Never writes to output.
 */
final class Logger
{
    private static ?string $path = null;

    public static function setPath(string $path): void
    {
        self::$path = rtrim($path, '/\\');
    }

    public static function info(string $message, array $context = []): void    { self::write('INFO', $message, $context); }
    public static function warning(string $message, array $context = []): void { self::write('WARNING', $message, $context); }
    public static function error(string $message, array $context = []): void   { self::write('ERROR', $message, $context); }
    public static function critical(string $message, array $context = []): void{ self::write('CRITICAL', $message, $context); }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$path === null || !is_dir(self::$path)) {
            return;
        }
        $file = self::$path . '/app-' . date('Y-m-d') . '.log';
        $line = sprintf(
            "[%s] %s: %s %s%s",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            PHP_EOL
        );
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
