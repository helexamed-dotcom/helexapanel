<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Config;

/**
 * Reversible encryption for the handful of secrets the panel must be able to
 * read back — today, the SMS gateway password.
 *
 * A password is hashed because nobody needs the original. A gateway
 * credential is different: every outgoing text has to present it verbatim, so
 * it has to be recoverable, and the only question is what an attacker gets
 * from a database dump. Encrypted at rest, the answer is nothing without the
 * application key, which lives in a file outside the web root.
 *
 * AES-256-GCM. Authenticated, so a tampered ciphertext fails to decrypt
 * rather than yielding plausible garbage that would then be posted to a
 * third-party API.
 */
final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const VERSION = 'v1';

    /**
     * A key of its own, derived from the application key rather than being
     * it. Str::hmac() already keys HMACs with the raw app key; reusing the
     * same bytes for encryption would mean one primitive's weakness becoming
     * the other's.
     */
    private static function key(): string
    {
        $appKey = (string) Config::get('app.security.app_key', '');
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY_MISSING');
        }
        return hash_hkdf('sha256', $appKey, 32, 'helexa.crypto.v1');
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('openssl')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true)
            && (string) Config::get('app.security.app_key', '') !== '';
    }

    /** @return string "v1.<base64 iv>.<base64 tag>.<base64 ciphertext>" */
    public static function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12); // 96 bits, the size GCM is specified for
        $tag = '';

        $cipherText = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new \RuntimeException('ENCRYPT_FAILED');
        }

        return implode('.', [
            self::VERSION,
            base64_encode($iv),
            base64_encode($tag),
            base64_encode($cipherText),
        ]);
    }

    /**
     * Returns null rather than throwing when the value cannot be read.
     *
     * A stored secret can become undecryptable for an ordinary reason — the
     * application key was rotated — and the panel's answer to that should be
     * "re-enter your API key", not a 500 on every page that checks whether
     * SMS is configured.
     */
    public static function decrypt(string $payload): ?string
    {
        $parts = explode('.', $payload);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            return null;
        }

        $iv         = base64_decode($parts[1], true);
        $tag        = base64_decode($parts[2], true);
        $cipherText = base64_decode($parts[3], true);

        if ($iv === false || $tag === false || $cipherText === false || strlen($iv) !== 12) {
            return null;
        }

        try {
            $plain = openssl_decrypt($cipherText, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        } catch (\Throwable) {
            return null;
        }

        return $plain === false ? null : $plain;
    }
}
