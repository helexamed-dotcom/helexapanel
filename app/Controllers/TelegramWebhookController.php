<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\Settings;
use HeleXa\Services\Telegram\TelegramBot;
use HeleXa\Services\TelegramClient;

/**
 * The single public entry point Telegram calls.
 *
 * Two independent checks stand between an anonymous POST and the bot logic:
 * the URL itself contains a random path segment nobody could guess, and
 * Telegram additionally signs every real call with a secret token header
 * this handler compares byte-for-byte. Either one failing is treated as "not
 * Telegram" and the request is dropped.
 *
 * This route sits outside every session/CSRF middleware on purpose — Telegram
 * cannot present our session cookie or CSRF token, and does not need to: its
 * own secret token is the credential here.
 */
final class TelegramWebhookController extends Controller
{
    public function handle(Request $request, array $params = []): Response
    {
        if (!Settings::bool('telegram_bot_enabled', false)) {
            return Response::json(['ok' => false], 404);
        }

        $expected = (string) Settings::get('telegram_webhook_secret', '');

        // Two independent checks, both compared with the same fixed-time
        // comparison. The path segment keeps this endpoint from being found
        // by casual scanning; Telegram's own signed header is the credential
        // that actually proves the call came from Telegram's servers.
        $pathSecret = (string) ($params['secret'] ?? '');
        $headerSecret = (string) ($request->header('X-Telegram-Bot-Api-Secret-Token') ?? '');

        if ($expected === '' || !hash_equals($expected, $pathSecret) || !hash_equals($expected, $headerSecret)) {
            return Response::json(['ok' => false], 404);
        }

        $token = (string) Settings::get('telegram_bot_token', '');
        if ($token === '') {
            return Response::json(['ok' => false], 404);
        }

        $update = $request->all();
        if ($update === [] || !isset($update['update_id'])) {
            // Telegram retries on anything but a 2xx, so malformed input still
            // gets acknowledged rather than triggering a retry storm.
            return Response::json(['ok' => true]);
        }

        try {
            (new TelegramBot(self::makeClient($token)))->handleUpdate($update);
        } catch (\Throwable $e) {
            \HeleXa\Core\Logger::error('Telegram webhook handler failed', [
                'error' => $e->getMessage(),
                'update_id' => $update['update_id'] ?? null,
            ]);
        }

        // Always 200: a non-2xx tells Telegram to redeliver the same update,
        // and an error already logged for us to fix does not need a retry.
        return Response::json(['ok' => true]);
    }

    /**
     * Builds the Telegram client. The base URL is normally the real API;
     * telegram_api_base_url is an escape hatch for pointing at a stand-in
     * server during testing and is empty on every real deployment.
     */
    private static function makeClient(string $token): TelegramClient
    {
        $base = (string) Settings::get('telegram_api_base_url', '');
        return $base !== '' ? new TelegramClient($token, $base) : new TelegramClient($token);
    }
}
