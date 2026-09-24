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

if (!function_exists('t')) {
    /** Interface string in the user's language; the Persian text is the key. */
    function t(string $fa): string
    {
        return \HeleXa\Services\I18n::t($fa);
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

if (!function_exists('asset')) {
    /**
     * A stylesheet or script URL stamped with the file's own modification
     * time.
     *
     * The service worker caches /assets/ stale-while-revalidate, so a
     * returning student is served the copy they already have. Server-rendered
     * HTML is never cached, which is the dangerous half: new markup arrives
     * against old CSS and JS, and the result is a header whose bell does
     * nothing, or a case that ignores its own reveal script. Remembering to
     * bump the worker's version by hand is what keeps failing, so the cache
     * key is derived from the file instead — change the file and the URL
     * changes with it, which no one has to remember.
     */
    function asset(string $path): string
    {
        static $stamps = [];

        if (!array_key_exists($path, $stamps)) {
            $file = PUBLIC_PATH . $path;
            $time = is_file($file) ? filemtime($file) : false;
            // A missing file is still worth linking: the page should render
            // and 404 that one asset rather than fail to build a URL.
            $stamps[$path] = $time === false ? null : substr(dechex($time), -6);
        }

        return $stamps[$path] === null ? $path : $path . '?v=' . $stamps[$path];
    }
}
