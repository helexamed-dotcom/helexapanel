<?php
declare(strict_types=1);

use HeleXa\Core\Str;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;

if (!function_exists('e')) {
    /** Escape for HTML text and attribute context. Used everywhere in views. */
    function e(mixed $value): string
    {
        return Str::escape($value === null ? '' : (string) $value);
    }
}

if (!function_exists('can')) {
    function can(string $permission): bool
    {
        return Auth::can($permission);
    }
}

if (!function_exists('fa')) {
    function fa(string|int $value): string
    {
        return Jalali::digits((string) $value);
    }
}

if (!function_exists('jdate')) {
    function jdate(?string $mysqlDateTime): string
    {
        if ($mysqlDateTime === null || $mysqlDateTime === '') {
            return '—';
        }
        $ts = strtotime($mysqlDateTime);
        return $ts === false ? '—' : Jalali::dateTime($ts);
    }
}

if (!function_exists('active_when')) {
    function active_when(string $currentPath, string $prefix): string
    {
        if ($prefix === '/student' || $prefix === '/admin') {
            return $currentPath === $prefix ? ' is-active' : '';
        }
        return str_starts_with($currentPath, $prefix) ? ' is-active' : '';
    }
}
