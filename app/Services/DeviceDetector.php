<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Str;

/**
 * Lightweight user-agent parsing for the admin's session monitor,
 * plus a stable device fingerprint used by the single-device policy.
 *
 * The fingerprint is a deterrent, not an identity proof: a determined user
 * can replay the same headers. It is keyed with the app secret so the value
 * cannot be reproduced from a database dump alone.
 */
final class DeviceDetector
{
    public static function browser(string $userAgent): string
    {
        $map = [
            'Edg/'      => 'Edge',
            'OPR/'      => 'Opera',
            'Chrome/'   => 'Chrome',
            'Firefox/'  => 'Firefox',
            'Safari/'   => 'Safari',
            'MSIE'      => 'Internet Explorer',
            'Trident/'  => 'Internet Explorer',
        ];
        foreach ($map as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }
        return 'Unknown';
    }

    public static function operatingSystem(string $userAgent): string
    {
        $map = [
            'Windows NT 10' => 'Windows 10/11',
            'Windows NT'    => 'Windows',
            'iPhone'        => 'iOS',
            'iPad'          => 'iPadOS',
            'Android'       => 'Android',
            'Mac OS X'      => 'macOS',
            'Linux'         => 'Linux',
        ];
        foreach ($map as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }
        return 'Unknown';
    }

    public static function deviceType(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'unknown';
        }
        if (preg_match('/bot|crawler|spider|curl|wget|python-requests/i', $userAgent) === 1) {
            return 'bot';
        }
        if (preg_match('/iPad|Tablet/i', $userAgent) === 1) {
            return 'tablet';
        }
        if (preg_match('/Mobile|Android|iPhone/i', $userAgent) === 1) {
            return 'mobile';
        }
        return 'desktop';
    }

    /** Deliberately excludes the IP: mobile networks rotate addresses constantly. */
    public static function fingerprint(string $userAgent, string $acceptLanguage): string
    {
        return Str::hmac(implode('|', [
            self::browser($userAgent),
            self::operatingSystem($userAgent),
            self::deviceType($userAgent),
            $userAgent,
            $acceptLanguage,
        ]));
    }
}
