<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Logger;

/**
 * Thin wrapper over the Telegram Bot API.
 *
 * The base URL is a constructor argument rather than a hard-coded constant so
 * tests can point it at a stand-in server instead of the real
 * api.telegram.org. Every method returns the decoded response array (never
 * throws on a Telegram-side failure) so a call site can decide for itself
 * whether a failed send should be retried.
 */
final class TelegramClient
{
    private string $token;
    private string $baseUrl;

    public function __construct(string $token, string $baseUrl = 'https://api.telegram.org')
    {
        $this->token   = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $options));
    }

    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): array
    {
        return $this->call('editMessageText', array_merge([
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], $options));
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $alert = false): array
    {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text !== null) {
            $params['text']       = $text;
            $params['show_alert'] = $alert;
        }
        return $this->call('answerCallbackQuery', $params);
    }

    /**
     * Deletes a message. Used immediately after a student types their
     * password, so it never lingers in the chat's own history — only Telegram
     * itself can permanently guarantee deletion on its servers, but this
     * removes it from what the app controls within seconds either way.
     */
    public function deleteMessage(int $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    public function setWebhook(string $url, string $secretToken): array
    {
        return $this->call('setWebhook', [
            'url'             => $url,
            'secret_token'    => $secretToken,
            'allowed_updates' => json_encode(['message', 'callback_query']),
            'drop_pending_updates' => true,
        ]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', []);
    }

    public function setMyCommands(array $commands): array
    {
        $list = [];
        foreach ($commands as $command => $description) {
            $list[] = ['command' => $command, 'description' => $description];
        }
        return $this->call('setMyCommands', ['commands' => json_encode($list, JSON_UNESCAPED_UNICODE)]);
    }

    public function getMe(): array
    {
        return $this->call('getMe', []);
    }

    /**
     * Resolves a file_id (received in a message.photo array) to a
     * downloadable path. The bytes themselves come from a different host
     * pattern (api.telegram.org/file/bot<token>/<path>, not /bot<token>/),
     * so this returns the path and downloadFile() fetches the bytes.
     */
    public function getFile(string $fileId): array
    {
        return $this->call('getFile', ['file_id' => $fileId]);
    }

    /** @return string|null raw file bytes, or null if the download failed */
    public function downloadFile(string $filePath): ?string
    {
        $url = sprintf('%s/file/bot%s/%s', $this->baseUrl, $this->token, $filePath);

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw   = curl_exec($curl);
        $errno = curl_errno($curl);
        curl_close($curl);

        if ($errno !== 0 || $raw === false || $raw === '') {
            return null;
        }
        return $raw;
    }

    /** @return array{ok:bool, result?:mixed, description?:string, http:int} */
    private function call(string $method, array $params): array
    {
        $url = sprintf('%s/bot%s/%s', $this->baseUrl, $this->token, $method);

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $raw    = curl_exec($curl);
        $errno  = curl_errno($curl);
        $error  = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($errno !== 0 || $raw === false) {
            Logger::warning('Telegram API transport error', ['method' => $method, 'error' => $error]);
            return ['ok' => false, 'description' => 'TRANSPORT_ERROR: ' . $error, 'http' => 0];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'description' => 'INVALID_RESPONSE', 'http' => $status];
        }

        $decoded['http'] = $status;
        if (($decoded['ok'] ?? false) !== true) {
            Logger::warning('Telegram API call failed', [
                'method' => $method, 'http' => $status, 'description' => $decoded['description'] ?? null,
            ]);
        }

        return $decoded;
    }
}
