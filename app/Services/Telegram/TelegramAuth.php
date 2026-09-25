<?php
declare(strict_types=1);

namespace HeleXa\Services\Telegram;

use HeleXa\Core\Config;
use HeleXa\Core\Database;
use HeleXa\Models\TelegramRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Phone;
use HeleXa\Services\Settings;

/**
 * What the sign-in bot says, update by update.
 *
 *   /start           → hello, and the «📱 ارسال شماره من» button
 *   a contact        → only the sender's OWN number is accepted: Telegram's
 *                      request_contact button sends the number of the account
 *                      that pressed it, which Telegram verified by SMS. A
 *                      contact card forwarded or typed in carries another
 *                      user_id (or none) and is refused — that is the whole
 *                      point: no made-up numbers.
 *                      New number  → a one-time link to finish signing up
 *                                    (choose a username and a password).
 *                      Known number → a one-time link to choose a new
 *                                    password (and a username if wanted).
 *   🔑 / /password   → a new-password link, once the number is verified;
 *   the «تغییر رمز عبور» glass button does the same.
 *
 * The greeting texts and the two glass buttons under them («ورود به سایت»
 * with the admin's link, «تغییر رمز عبور») are set in the admin panel.
 *
 * Passwords are never typed into Telegram: the link opens a page on the
 * site, so the password is not left in a chat history.
 */
final class TelegramAuth
{
    private const LINKS_PER_HOUR = 6;

    private TelegramRepository $tg;

    public function __construct()
    {
        $this->tg = new TelegramRepository();
    }

    public function handle(array $update): void
    {
        if (is_array($update['callback_query'] ?? null)) {
            $this->callback($update['callback_query']);
            return;
        }
        $m = $update['message'] ?? null;
        if (!is_array($m) || ($m['chat']['type'] ?? '') !== 'private' || !is_array($m['from'] ?? null) || !empty($m['from']['is_bot'])) {
            return;
        }
        $fromId = (int) $m['from']['id'];
        $chatId = (int) $m['chat']['id'];
        $this->tg->touch($fromId, $chatId, $m['from']['username'] ?? null, $m['from']['first_name'] ?? null);

        if (is_array($m['contact'] ?? null)) {
            $this->contact($m, $fromId, $chatId);
            return;
        }
        $text = trim((string) ($m['text'] ?? ''));
        if (str_starts_with($text, '/start')) {
            $this->welcome($fromId, $chatId);
        } elseif ($text === '/password' || str_contains($text, 'تعیین رمز') || str_contains($text, 'تغییر رمز')) {
            $this->resetRequest($fromId, $chatId);
        } else {
            $this->help($fromId, $chatId);
        }
    }

    private function site(): string
    {
        return (string) Config::get('app.app.name', 'HeleXa Med');
    }

    /** A glass button pressed under one of the bot's messages. */
    private function callback(array $q): void
    {
        $from = $q['from'] ?? null;
        $chat = $q['message']['chat'] ?? null;
        if (!is_array($from) || !empty($from['is_bot']) || !is_array($chat) || ($chat['type'] ?? '') !== 'private') {
            return;
        }
        $fromId = (int) $from['id'];
        $chatId = (int) $chat['id'];
        $this->tg->touch($fromId, $chatId, $from['username'] ?? null, $from['first_name'] ?? null);
        Bot::answerCallback((string) ($q['id'] ?? ''));
        if (($q['data'] ?? '') === 'reset') {
            $this->resetRequest($fromId, $chatId);
        }
    }

    private function isLinked(int $fromId): bool
    {
        $acc = $this->tg->account($fromId);
        return $acc !== null && $acc['user_id'] !== null && $acc['verified_at'] !== null;
    }

    private function welcome(int $fromId, int $chatId): void
    {
        $acc = $this->tg->account($fromId);
        $name = (string) ($acc['first_name'] ?? '');
        if ($this->isLinked($fromId)) {
            Bot::send($chatId, Bot::render('telegram_text_member', $name), Bot::menuButtons());
            return;
        }
        Bot::send($chatId, Bot::render('telegram_text_welcome', $name), Bot::menuButtons());
        // The share-my-number button lives in the reply keyboard, which cannot
        // sit under the same message as the glass buttons.
        Bot::send($chatId, '👇 دکمه <b>«📱 ارسال شماره من»</b>', Bot::contactKeyboard());
    }

    private function help(int $fromId, int $chatId): void
    {
        $linked = $this->isLinked($fromId);
        Bot::send($chatId, $linked
            ? 'از دکمه‌های زیر استفاده کن:'
            : "برای ساختن حساب یا ورود، دکمه <b>«📱 ارسال شماره من»</b> را پایین صفحه بزن.", Bot::menuButtons());
        if (!$linked) {
            Bot::send($chatId, '👇', Bot::contactKeyboard());
        }
    }

    private function contact(array $m, int $fromId, int $chatId): void
    {
        $c = $m['contact'];
        // A shared contact belongs to the sender only when Telegram says so.
        if ((int) ($c['user_id'] ?? 0) !== $fromId) {
            Bot::send($chatId, "⛔️ این شماره مال خود حساب تلگرامت نیست.\nلطفاً فقط با دکمه <b>«📱 ارسال شماره من»</b> شماره خودت را بفرست.",
                Bot::contactKeyboard());
            return;
        }
        $phone = Phone::normalize((string) ($c['phone_number'] ?? ''));
        if ($phone === '') {
            Bot::send($chatId, 'فعلاً فقط شماره‌های موبایل ایران (+98) پذیرفته می‌شود. اگر تلگرامت با شماره دیگری است، با پشتیبانی در تماس باش.',
                Bot::contactKeyboard());
            return;
        }
        $this->tg->setPhone($fromId, $phone);

        $user = (new UserRepository())->findByMobile($phone);
        if ($user === null) {
            if (!self::registrationOpen()) {
                Bot::send($chatId, "✅ شماره <b>" . Bot::h($phone) . "</b> تأیید شد.\nاما ثبت‌نام در سایت فعلاً بسته است؛ برای ساختن حساب با پشتیبانی در تماس باش.",
                    ['remove_keyboard' => true]);
                return;
            }
            if (!$this->withinLimit($fromId, $chatId)) {
                return;
            }
            $secret = $this->tg->issue($fromId, $phone, 'register', null);
            ActivityLogger::log('telegram.register_link', null, 'telegram', $fromId, ['phone' => Phone::mask($phone)], 'info');
            Bot::send($chatId, "✅ شماره <b>" . Bot::h($phone) . "</b> تأیید شد.", ['remove_keyboard' => true]);
            Bot::send($chatId, "حالا روی دکمه زیر بزن و <b>نام کاربری</b> و <b>رمز عبور</b> خودت را بساز.\n"
                . "⏳ این لینک یک‌بارمصرف است و " . TelegramRepository::LINK_MINUTES . " دقیقه اعتبار دارد.",
                Bot::linkButton('✍️ تکمیل ثبت‌نام', self::linkUrl($secret)));
            return;
        }

        if (($user['role_slug'] ?? '') !== 'student') {
            Bot::send($chatId, 'این شماره متعلق به یک حساب مدیریتی است و از این راه نمی‌شود واردش شد.', ['remove_keyboard' => true]);
            return;
        }
        if (($user['status'] ?? 'active') !== 'active') {
            Bot::send($chatId, 'حساب این شماره فعال نیست. برای پیگیری با پشتیبانی در تماس باش.', ['remove_keyboard' => true]);
            return;
        }
        $this->tg->link($fromId, (int) $user['id']);
        Database::execute('UPDATE users SET phone_verified_at = COALESCE(phone_verified_at, :now) WHERE id = :id',
            ['now' => date('Y-m-d H:i:s'), 'id' => (int) $user['id']]);
        if (!$this->withinLimit($fromId, $chatId)) {
            return;
        }
        $secret = $this->tg->issue($fromId, $phone, 'reset', (int) $user['id']);
        ActivityLogger::log('telegram.reset_link', (int) $user['id'], 'user', (int) $user['id'], [], 'notice');
        Bot::send($chatId, "✅ شماره تأیید شد. با این شماره از قبل حساب داری (<b>" . Bot::h((string) $user['full_name']) . "</b>).",
            ['remove_keyboard' => true]);
        Bot::send($chatId, "برای ورود از شماره و رمزت استفاده کن. اگر رمز نداری یا فراموشش کرده‌ای، با دکمه زیر رمز تازه (و اگر خواستی نام کاربری) بساز.\n"
            . "⏳ لینک یک‌بارمصرف است و " . TelegramRepository::LINK_MINUTES . " دقیقه اعتبار دارد.",
            Bot::linkButton('🔑 تعیین رمز', self::linkUrl($secret)));
    }


    private function resetRequest(int $fromId, int $chatId): void
    {
        $acc = $this->tg->account($fromId);
        if ($acc === null || $acc['verified_at'] === null || $acc['user_id'] === null || $acc['phone'] === null) {
            Bot::send($chatId, 'اول شماره‌ات را با دکمه «📱 ارسال شماره من» بفرست.', Bot::contactKeyboard());
            return;
        }
        $user = (new UserRepository())->findById((int) $acc['user_id']);
        // The number must still be the account's: an admin may have changed it.
        if ($user === null || ($user['mobile'] ?? '') !== $acc['phone'] || ($user['role_slug'] ?? '') !== 'student' || ($user['status'] ?? '') !== 'active') {
            Bot::send($chatId, 'شماره حسابت تغییر کرده است. دوباره با دکمه «📱 ارسال شماره من» شماره‌ات را بفرست.', Bot::contactKeyboard());
            return;
        }
        if (!$this->withinLimit($fromId, $chatId)) {
            return;
        }
        $secret = $this->tg->issue($fromId, (string) $acc['phone'], 'reset', (int) $user['id']);
        ActivityLogger::log('telegram.reset_link', (int) $user['id'], 'user', (int) $user['id'], [], 'notice');
        Bot::send($chatId, "🔑 برای تغییر رمز عبور روی دکمه بزن.\n⏳ لینک یک‌بارمصرف است و " . TelegramRepository::LINK_MINUTES . ' دقیقه اعتبار دارد.',
            Bot::linkButton('🔑 ساختن رمز تازه', self::linkUrl($secret)));
    }

    private function withinLimit(int $fromId, int $chatId): bool
    {
        if ($this->tg->recentLinks($fromId, 60) >= self::LINKS_PER_HOUR) {
            Bot::send($chatId, '⏳ در یک ساعت گذشته چند لینک گرفته‌ای. کمی بعد دوباره امتحان کن.');
            return false;
        }
        return true;
    }

    public static function registrationOpen(): bool
    {
        return Settings::bool('telegram_registration_enabled', true);
    }

    public static function linkUrl(string $secret): string
    {
        return rtrim((string) Config::get('app.app.url', ''), '/') . '/auth/telegram/' . $secret;
    }
}
