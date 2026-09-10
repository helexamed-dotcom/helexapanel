<?php
declare(strict_types=1);

namespace HeleXa\Services\Telegram;

use HeleXa\Models\NotificationDeliveryRepository;
use HeleXa\Models\SettingRepository;
use HeleXa\Services\Settings;
use HeleXa\Services\TelegramClient;

/**
 * Drains queued Telegram deliveries.
 *
 * There is no persistent worker process on shared hosting, so this piggybacks
 * on ordinary web traffic the same way Maintenance does, but on a much
 * shorter interval: a course activation should reach Telegram within
 * seconds, not wait for the once-an-hour maintenance sweep.
 *
 * For anything genuinely time-based — a reminder that must fire at a
 * specific clock time regardless of whether anyone is browsing the site —
 * opportunistic draining on page loads is not enough by itself; that needs
 * an actual cron entry, which is documented separately and is not part of
 * this pass.
 */
final class TelegramQueue
{
    private const MIN_INTERVAL_SECONDS = 20;
    private const BATCH_SIZE           = 10;

    /** Called on ordinary requests; a no-op almost every time. */
    public static function maybeDrain(): void
    {
        if (!Settings::bool('telegram_bot_enabled', false)) {
            return;
        }

        $last = Settings::int('telegram_queue_last_drain', 0);
        if ($last > time() - self::MIN_INTERVAL_SECONDS) {
            return;
        }

        // Claimed first so two concurrent requests do not both drain at once.
        (new SettingRepository())->set('telegram_queue_last_drain', (string) time(), 'int', null);
        Settings::flush();

        try {
            self::drainDue(self::BATCH_SIZE);
        } catch (\Throwable $e) {
            \HeleXa\Core\Logger::warning('Telegram queue drain skipped', ['error' => $e->getMessage()]);
        }
    }

    /** @return array{sent:int, failed:int} */
    public static function drainDue(int $limit = 10): array
    {
        $token = (string) Settings::get('telegram_bot_token', '');
        if ($token === '') {
            return ['sent' => 0, 'failed' => 0];
        }

        $client      = self::makeClient($token);
        $deliveries  = new NotificationDeliveryRepository();
        $accounts    = new \HeleXa\Models\TelegramAccountRepository();
        $sent = $failed = 0;

        foreach ($deliveries->due('telegram', $limit) as $delivery) {
            $account = $accounts->findByUserId((int) $delivery['user_id']);
            if ($account === null) {
                // The student unlinked their account since this was queued;
                // there is nowhere left to deliver it.
                $deliveries->markFailed((int) $delivery['id'], 99, 'ACCOUNT_UNLINKED');
                continue;
            }

            $text = '📢 <b>' . \e($delivery['title']) . "</b>\n\n" . \e((string) ($delivery['body'] ?? ''));
            $options = [];
            if (!empty($delivery['link_url']) && str_starts_with((string) $delivery['link_url'], '/')) {
                $siteUrl = rtrim((string) \HeleXa\Core\Config::get('app.app.url', ''), '/');
                $options['reply_markup'] = json_encode([
                    'inline_keyboard' => [[['text' => '🌐 مشاهده در سایت', 'url' => $siteUrl . $delivery['link_url']]]],
                ]);
            }

            $response = $client->sendMessage((int) $account['chat_id'], trim($text), $options);

            if (($response['ok'] ?? false) === true) {
                $deliveries->markSent((int) $delivery['id']);
                $sent++;
            } else {
                $deliveries->markFailed(
                    (int) $delivery['id'],
                    (int) $delivery['attempts'] + 1,
                    (string) ($response['description'] ?? 'UNKNOWN_ERROR')
                );
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Same escape hatch as the webhook controller; see its docblock. */
    private static function makeClient(string $token): TelegramClient
    {
        $base = (string) Settings::get('telegram_api_base_url', '');
        return $base !== '' ? new TelegramClient($token, $base) : new TelegramClient($token);
    }
}
