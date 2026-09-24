<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\SettingRepository;
use HeleXa\Models\TelegramRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Settings;
use HeleXa\Services\Telegram\Bot;

/**
 * «ورود با تلگرام»: the bot token, turning it on, and the webhook Telegram
 * sends the bot's messages to.
 */
final class TelegramController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $info = null;
        $me = null;
        if (Bot::token() !== '') {
            $w = Bot::call('getWebhookInfo');
            $info = $w['ok'] ? ($w['result'] ?? null) : ['error' => $w['description'] ?? ''];
            $m = Bot::call('getMe');
            $me = $m['ok'] ? ($m['result'] ?? null) : null;
        }
        return $this->page('layouts.app', 'admin.telegram', [
            'title'        => 'ورود با تلگرام',
            'enabled'      => Settings::bool('telegram_auth_enabled', false),
            'registration' => Settings::bool('telegram_registration_enabled', true),
            'hasToken'     => Bot::token() !== '',
            'username'     => Bot::username(),
            'webhook'      => Bot::webhookUrl(),
            'info'         => $info,
            'me'           => $me,
            'stats'        => TelegramRepository::ready() ? (new TelegramRepository())->stats() : null,
            'https'        => str_starts_with(Bot::webhookUrl(), 'https://'),
            'extraCss'     => ['shop-admin'],
        ]);
    }

    /**
     * Saves the settings. A new token is checked with getMe (which also gives
     * the bot's @username) before it is kept; then, when the bot is on, the
     * webhook is pointed at this site with a fresh secret.
     */
    public function save(Request $request, array $params = []): Response
    {
        $repo = new SettingRepository();
        $actor = (int) Auth::id();
        $token = trim($request->string('token'));
        if ($token !== '') {
            if (preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,60}$/', $token) !== 1) {
                $this->flash('error', 'توکن ربات شبیه توکن BotFather نیست (مثلاً 123456789:AA...).');
                return $this->redirect('/admin/telegram');
            }
            $me = Bot::call('getMe', [], $token);
            if (empty($me['ok'])) {
                $this->flash('error', 'تلگرام این توکن را نپذیرفت: ' . ($me['description'] ?? 'بدون پاسخ'));
                return $this->redirect('/admin/telegram');
            }
            Bot::saveToken($token, $actor);
            $repo->set('telegram_bot_username', (string) ($me['result']['username'] ?? ''), 'string', $actor);
        }
        $enabled = $request->bool('enabled');
        $repo->set('telegram_auth_enabled', $enabled ? '1' : '0', 'bool', $actor);
        $repo->set('telegram_registration_enabled', $request->bool('registration') ? '1' : '0', 'bool', $actor);
        Settings::flush();

        $message = 'تنظیمات ذخیره شد.';
        if ($enabled && Bot::token() !== '') {
            $hook = $this->setWebhook($actor);
            $message .= ' ' . $hook;
        }
        ActivityLogger::log('telegram.settings', $actor, 'settings', null, ['enabled' => $enabled], 'notice', $request);
        $this->flash('success', $message);
        return $this->redirect('/admin/telegram');
    }

    public function webhook(Request $request, array $params = []): Response
    {
        if (Bot::token() === '') {
            $this->flash('error', 'اول توکن ربات را ذخیره کنید.');
            return $this->redirect('/admin/telegram');
        }
        if ($request->string('action') === 'delete') {
            $r = Bot::call('deleteWebhook', ['drop_pending_updates' => true]);
            $this->flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'وب‌هوک برداشته شد؛ ربات دیگر پیامی به سایت نمی‌فرستد.' : ('خطا: ' . ($r['description'] ?? '')));
        } else {
            $this->flash('success', $this->setWebhook((int) Auth::id()));
        }
        return $this->redirect('/admin/telegram');
    }

    private function setWebhook(int $actor): string
    {
        $url = Bot::webhookUrl();
        if (!str_starts_with($url, 'https://')) {
            return 'وب‌هوک تنظیم نشد: نشانی سایت (app.url در config) باید با https شروع شود.';
        }
        $secret = Str::token(24);
        (new SettingRepository())->set('telegram_webhook_secret', $secret, 'string', $actor);
        Settings::flush();
        $r = Bot::call('setWebhook', [
            'url'                  => $url,
            'secret_token'         => $secret,
            'allowed_updates'      => ['message'],
            'drop_pending_updates' => true,
            'max_connections'      => 20,
        ]);
        return $r['ok'] ? 'وب‌هوک روی این سایت تنظیم شد ✓' : 'تنظیم وب‌هوک ناموفق بود: ' . ($r['description'] ?? '');
    }
}
