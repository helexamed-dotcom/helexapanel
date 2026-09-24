<?php
declare(strict_types=1);

namespace HeleXa\Services\Telegram;

use HeleXa\Core\Config;
use HeleXa\Core\Logger;
use HeleXa\Models\SettingRepository;
use HeleXa\Services\Crypto;
use HeleXa\Services\Settings;

/**
 * The Telegram Bot API, just the calls the sign-in bot needs.
 *
 * The bot token is the bot: whoever holds it can read every message sent
 * to the bot and answer as it. It is kept encrypted in the settings table
 * (with the application key, as the SMS password was) and never written to
 * a log — not even inside a URL, which is where the Bot API puts it.
 */
final class Bot
{
    private const API = 'https://api.telegram.org/bot';
    private const TIMEOUT = 15;

    public static function enabled(): bool
    {
        return Settings::bool('telegram_auth_enabled', false) && self::token() !== '' && function_exists('curl_init');
    }

    public static function token(): string
    {
        $raw = (string) Settings::get('telegram_bot_token', '');
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, 'v1.') && Crypto::isAvailable()) {
            return (string) (Crypto::decrypt($raw) ?? '');
        }
        return $raw;
    }

    public static function username(): string
    {
        return ltrim((string) Settings::get('telegram_bot_username', ''), '@');
    }

    /** The secret Telegram repeats in X-Telegram-Bot-Api-Secret-Token on every update. */
    public static function secret(): string
    {
        return (string) Settings::get('telegram_webhook_secret', '');
    }

    public static function webhookUrl(): string
    {
        return rtrim((string) Config::get('app.app.url', ''), '/') . '/telegram/webhook';
    }

    /** t.me link that opens the bot (and sends /start with a payload). */
    public static function startLink(string $payload = 'login'): string
    {
        return 'https://t.me/' . rawurlencode(self::username()) . '?start=' . rawurlencode($payload);
    }

    public static function saveToken(string $token, ?int $actorId): void
    {
        $stored = $token === '' ? '' : (Crypto::isAvailable() ? Crypto::encrypt($token) : $token);
        (new SettingRepository())->set('telegram_bot_token', $stored, 'string', $actorId);
        Settings::flush();
    }

    /**
     * One Bot API call.
     *
     * @return array{ok:bool, result?:mixed, description?:string}
     */
    public static function call(string $method, array $params = [], ?string $token = null): array
    {
        $token ??= self::token();
        if ($token === '' || !function_exists('curl_init')) {
            return ['ok' => false, 'description' => 'ربات تنظیم نشده است.'];
        }
        $json = (string) json_encode($params, JSON_UNESCAPED_UNICODE);
        $h = curl_init(self::API . $token . '/' . $method);
        curl_setopt_array($h, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($json)],
        ]);
        $body = curl_exec($h);
        $error = curl_error($h);
        curl_close($h);
        if ($body === false) {
            // The URL carries the token, so only the method and the error are logged.
            Logger::error('Telegram API transport failure', ['method' => $method, 'error' => $error]);
            return ['ok' => false, 'description' => 'ارتباط با تلگرام برقرار نشد.'];
        }
        $res = json_decode((string) $body, true);
        if (!is_array($res)) {
            return ['ok' => false, 'description' => 'پاسخ نامعتبر از تلگرام.'];
        }
        if (empty($res['ok'])) {
            Logger::warning('Telegram API error', ['method' => $method, 'description' => $res['description'] ?? '']);
        }
        return $res;
    }

    public static function send(int $chatId, string $text, ?array $markup = null): array
    {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($markup !== null) {
            $params['reply_markup'] = $markup;
        }
        return self::call('sendMessage', $params);
    }

    /** The keyboard with Telegram's own «share my number» button. */
    public static function contactKeyboard(bool $withReset = false): array
    {
        $rows = [[['text' => '📱 ارسال شماره من', 'request_contact' => true]]];
        if ($withReset) {
            $rows[] = [['text' => '🔑 تعیین رمز تازه']];
        }
        return ['keyboard' => $rows, 'resize_keyboard' => true, 'one_time_keyboard' => false, 'is_persistent' => true,
                'input_field_placeholder' => 'برای ادامه، دکمه «ارسال شماره من» را بزن'];
    }

    public static function linkButton(string $text, string $url): array
    {
        return ['inline_keyboard' => [[['text' => $text, 'url' => $url]]]];
    }

    /** Text for Telegram's HTML mode. */
    public static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
