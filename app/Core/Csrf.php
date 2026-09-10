<?php
declare(strict_types=1);

namespace HeleXa\Core;

/**
 * Per-session CSRF token with constant-time comparison.
 * Rotated on login and on privilege changes.
 */
final class Csrf
{
    private const KEY = '_helexa_csrf';

    public static function token(): string
    {
        if (!isset($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            self::rotate();
        }
        return (string) $_SESSION[self::KEY];
    }

    public static function rotate(): string
    {
        $_SESSION[self::KEY] = Str::token(32);
        return (string) $_SESSION[self::KEY];
    }

    public static function verify(?string $candidate): bool
    {
        if (!is_string($candidate) || $candidate === '') {
            return false;
        }
        $stored = $_SESSION[self::KEY] ?? null;
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        return hash_equals($stored, $candidate);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . Str::escape(self::token()) . '">';
    }
}
