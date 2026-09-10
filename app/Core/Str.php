<?php
declare(strict_types=1);

namespace HeleXa\Core;

final class Str
{
    /** Cryptographically secure random hex token. */
    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function uuid4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** SHA-256 hex. Used for storing token digests, never for passwords. */
    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /** Keyed hash so a stolen database row cannot be replayed without the app key. */
    public static function hmac(string $value): string
    {
        $key = (string) Config::get('app.security.app_key', '');
        return hash_hmac('sha256', $value, $key);
    }

    /**
     * Human-typable temporary password. Ambiguous characters are excluded so
     * an admin can read it aloud without confusion.
     */
    public static function temporaryPassword(int $length = 14): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max      = strlen($alphabet) - 1;
        $out      = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        // Guarantee the policy (letters + digits) regardless of the draw.
        return substr($out, 0, $length - 2) . random_int(10, 99);
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function slug(string $value): string
    {
        $value = trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '', '-');
        return $value === '' ? bin2hex(random_bytes(4)) : mb_strtolower($value, 'UTF-8');
    }

    /** Normalizes an IP to its network prefix so mobile IP rotation does not log users out. */
    public static function ipPrefix(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed !== false) {
                return inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
            }
        }
        return 'unknown';
    }
}
