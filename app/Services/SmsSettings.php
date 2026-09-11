<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Models\SettingRepository;

/**
 * The SMS gateway's configuration, owned by the admin panel rather than by a
 * file on disk.
 *
 * The operator asked to change these without touching code, so they live in
 * the settings table like every other runtime value. The one that is a
 * credential — the gateway password, which MeliPayamak calls the API key — is
 * encrypted there and is never returned to a browser, an API response or a
 * log line. Only masked() is safe to render.
 */
final class SmsSettings
{
    public const KEY_ENABLED  = 'sms_enabled';
    public const KEY_USERNAME = 'sms_username';
    public const KEY_API      = 'sms_api_key_enc';
    public const KEY_FROM     = 'sms_from';
    public const KEY_PROVIDER = 'sms_provider';

    /**
     * MeliPayamak's own one-time-code service.
     *
     * The panel holds only an API key; MeliPayamak generates the code, writes
     * the message and sends it, then returns the code so it can be checked
     * later. It needs no dedicated sender line, which is why most accounts can
     * use it on day one — and why it is the default.
     *
     * The trade-off is that the message wording belongs to MeliPayamak, so the
     * template setting has no effect under this provider.
     */
    public const PROVIDER_CONSOLE_OTP = 'console_otp';

    /**
     * Ordinary text sending over a sender line the account owns.
     *
     * This one carries the message this panel wrote, so the template setting
     * applies — but it needs a username, a web-service password and a sender
     * number that the account has been granted.
     */
    public const PROVIDER_SMART_SMS = 'smart_sms';

    public static function enabled(): bool
    {
        return Settings::bool(self::KEY_ENABLED, false);
    }

    public static function provider(): string
    {
        $value = (string) Settings::get(self::KEY_PROVIDER, self::PROVIDER_CONSOLE_OTP);

        return in_array($value, [self::PROVIDER_CONSOLE_OTP, self::PROVIDER_SMART_SMS], true)
            ? $value
            : self::PROVIDER_CONSOLE_OTP;
    }

    /** True when MeliPayamak, not this panel, produces the code. */
    public static function providerMintsCode(): bool
    {
        return self::provider() === self::PROVIDER_CONSOLE_OTP;
    }

    public static function username(): string
    {
        return trim((string) Settings::get(self::KEY_USERNAME, ''));
    }

    public static function from(): string
    {
        return trim((string) Settings::get(self::KEY_FROM, ''));
    }

    /**
     * The decrypted gateway password.
     *
     * Only the gateway client calls this. It returns '' rather than throwing
     * when the stored value cannot be read — a rotated application key leaves
     * an unreadable ciphertext behind, and that should surface as "SMS is not
     * configured" rather than as a fatal error on an unrelated page.
     */
    public static function apiKey(): string
    {
        $stored = (string) Settings::get(self::KEY_API, '');
        if ($stored === '') {
            return '';
        }
        return Crypto::decrypt($stored) ?? '';
    }

    /** Enough of the key to recognise it, never enough to use it. */
    public static function maskedApiKey(): string
    {
        $key = self::apiKey();
        if ($key === '') {
            return '';
        }
        $tail = mb_substr($key, -4);
        return str_repeat('*', 12) . $tail;
    }

    /**
     * Whether enough has been filled in for a send to be attempted.
     *
     * The two providers need different things, and demanding a sender number
     * from an account that has none would make the simpler provider look
     * broken when it is merely unconfigured for the other one.
     */
    public static function isConfigured(): bool
    {
        if (self::provider() === self::PROVIDER_CONSOLE_OTP) {
            return self::apiKey() !== '';
        }
        return self::username() !== '' && self::apiKey() !== '' && self::from() !== '';
    }

    /** Everything needed to decide whether a text can be sent right now. */
    public static function isOperational(): bool
    {
        return self::enabled() && self::isConfigured() && Crypto::isAvailable();
    }

    public static function saveCredentials(string $username, ?string $apiKey, string $from, ?int $userId): void
    {
        $repository = new SettingRepository();
        $repository->set(self::KEY_USERNAME, $username, 'string', $userId);
        $repository->set(self::KEY_FROM, $from, 'string', $userId);

        // Null means "leave it alone". The form renders the key masked, so
        // submitting the form unchanged must not overwrite the real key with
        // a row of asterisks.
        if ($apiKey !== null) {
            $repository->set(self::KEY_API, $apiKey === '' ? '' : Crypto::encrypt($apiKey), 'string', $userId);
        }
        Settings::flush();
    }

    public static function setEnabled(bool $enabled, ?int $userId): void
    {
        (new SettingRepository())->set(self::KEY_ENABLED, $enabled ? '1' : '0', 'bool', $userId);
        Settings::flush();
    }

    public static function setProvider(string $provider, ?int $userId): void
    {
        if (!in_array($provider, [self::PROVIDER_CONSOLE_OTP, self::PROVIDER_SMART_SMS], true)) {
            $provider = self::PROVIDER_CONSOLE_OTP;
        }
        (new SettingRepository())->set(self::KEY_PROVIDER, $provider, 'string', $userId);
        Settings::flush();
    }
}
