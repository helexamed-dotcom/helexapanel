<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Config;
use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\NotificationDeliveryRepository;
use HeleXa\Models\SettingRepository;
use HeleXa\Models\TelegramAccountRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Settings;
use HeleXa\Services\TelegramClient;

/**
 * Where an operator turns the bot on: paste the token BotFather gave them,
 * save, and this page registers the webhook with Telegram itself. The token
 * lives only in the settings table — the same place every other runtime
 * secret in this app lives — never in a code file.
 */
final class TelegramSettingsController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.telegram', [
            'title'   => 'ربات تلگرام',
            'enabled' => Settings::bool('telegram_bot_enabled', false),
            'token'   => (string) Settings::get('telegram_bot_token', ''),
            'username'=> (string) Settings::get('telegram_bot_username', ''),
            'hasSecret' => (string) Settings::get('telegram_webhook_secret', '') !== '',
            'stats'   => [
                'linked'       => (new TelegramAccountRepository())->countLinked(),
                'active_today' => (new TelegramAccountRepository())->countActiveToday(),
                'sent_today'   => (new NotificationDeliveryRepository())->countSentToday('telegram'),
                'reminders_today' => Database::selectOne(
                    "SELECT COUNT(*) AS c FROM notifications
                     WHERE DATE(published_at) = :today
                       AND (idempotency_key LIKE 'schedule_reminder:%' OR idempotency_key LIKE 'exam_reminder:%')",
                    ['today' => date('Y-m-d')]
                )['c'] ?? 0,
                'preferences'  => (new TelegramAccountRepository())->preferenceBreakdown(),
                'queue'        => (new NotificationDeliveryRepository())->countByStatus('telegram'),
            ],
            'siteConfigured' => (string) Config::get('app.app.url', '') !== '',
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $repository = new SettingRepository();
        $token      = trim($request->string('telegram_bot_token'));
        $enabled    = $request->bool('telegram_bot_enabled');

        // A bare structural check, not a call to Telegram: real validation
        // happens when "تست اتصال" or "فعال‌سازی webhook" is pressed.
        if ($token !== '' && preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token) !== 1) {
            $this->flash('error', 'قالب توکن ربات نامعتبر به نظر می‌رسد. آن را دوباره از BotFather کپی کنید.');
            return $this->redirect('/admin/telegram');
        }

        $repository->set('telegram_bot_token', $token, 'string', Auth::id());
        $repository->set('telegram_bot_enabled', $enabled ? '1' : '0', 'bool', Auth::id());

        if ($token !== '' && (string) Settings::get('telegram_webhook_secret', '') === '') {
            // Generated once, kept stable afterwards: rotating it on every
            // save would invalidate the webhook Telegram already has.
            $repository->set('telegram_webhook_secret', Str::token(24), 'string', Auth::id());
        }
        if ($token === '') {
            $repository->set('telegram_bot_enabled', '0', 'bool', Auth::id());
        }

        Settings::flush();
        ActivityLogger::log('telegram.settings_updated', Auth::id(), 'settings', null,
            ['enabled' => $enabled, 'token_set' => $token !== ''], 'critical', $request);
        $this->flash('success', 'تنظیمات ذخیره شد.');

        return $this->redirect('/admin/telegram');
    }

    /** Registers the webhook URL with Telegram itself — an outbound call, unlike saving settings. */
    public function setWebhook(Request $request, array $params = []): Response
    {
        $token  = (string) Settings::get('telegram_bot_token', '');
        $secret = (string) Settings::get('telegram_webhook_secret', '');
        $siteUrl = rtrim((string) Config::get('app.app.url', ''), '/');

        if ($token === '' || $secret === '' || $siteUrl === '') {
            $this->flash('error', 'ابتدا توکن ربات را ذخیره کنید و مطمئن شوید آدرس سایت در تنظیمات نصب درست است.');
            return $this->redirect('/admin/telegram');
        }

        $client = self::makeClient($token);
        $webhookUrl = $siteUrl . '/telegram/webhook/' . $secret;
        $result = $client->setWebhook($webhookUrl, $secret);

        if (($result['ok'] ?? false) !== true) {
            ActivityLogger::log('telegram.webhook_set_failed', Auth::id(), 'settings', null,
                ['error' => $result['description'] ?? 'unknown'], 'warning', $request);
            $this->flash('error', 'ثبت webhook ناموفق بود: ' . (string) ($result['description'] ?? 'خطای نامشخص'));
            return $this->redirect('/admin/telegram');
        }

        $me = $client->getMe();
        if (($me['ok'] ?? false) === true) {
            (new SettingRepository())->set(
                'telegram_bot_username', (string) ($me['result']['username'] ?? ''), 'string', Auth::id()
            );
            Settings::flush();
        }

        $client->setMyCommands([
            'start' => 'شروع و ورود به حساب', 'help' => 'راهنما', 'profile' => 'پروفایل من',
            'courses' => 'دوره‌های من', 'schedule' => 'برنامه هفتگی', 'exams' => 'برنامه امتحانات',
            'notifications' => 'اطلاعیه‌ها', 'settings' => 'تنظیمات', 'status' => 'وضعیت من',
            'support' => 'ارتباط با پشتیبانی', 'logout' => 'خروج',
        ]);

        ActivityLogger::log('telegram.webhook_set', Auth::id(), 'settings', null, [], 'notice', $request);
        $this->flash('success', 'webhook با موفقیت روی تلگرام ثبت شد. ربات آماده است.');

        return $this->redirect('/admin/telegram');
    }

    public function removeWebhook(Request $request, array $params = []): Response
    {
        $token = (string) Settings::get('telegram_bot_token', '');
        if ($token !== '') {
            self::makeClient($token)->deleteWebhook();
        }
        ActivityLogger::log('telegram.webhook_removed', Auth::id(), 'settings', null, [], 'notice', $request);
        $this->flash('success', 'webhook حذف شد. ربات دیگر پیام دریافت نمی‌کند.');

        return $this->redirect('/admin/telegram');
    }

    public function testConnection(Request $request, array $params = []): Response
    {
        $token = (string) Settings::get('telegram_bot_token', '');
        if ($token === '') {
            $this->flash('error', 'ابتدا توکن ربات را وارد و ذخیره کنید.');
            return $this->redirect('/admin/telegram');
        }

        $result = self::makeClient($token)->getMe();

        if (($result['ok'] ?? false) === true) {
            $bot = $result['result'];
            $this->flash('success', sprintf('اتصال موفق بود. ربات: @%s (%s)', $bot['username'] ?? '?', $bot['first_name'] ?? ''));
        } else {
            $this->flash('error', 'اتصال ناموفق بود: ' . (string) ($result['description'] ?? 'توکن را بررسی کنید.'));
        }

        return $this->redirect('/admin/telegram');
    }

    /** Same escape hatch as the webhook controller; see its docblock. */
    private static function makeClient(string $token): TelegramClient
    {
        $base = (string) Settings::get('telegram_api_base_url', '');
        return $base !== '' ? new TelegramClient($token, $base) : new TelegramClient($token);
    }
}
