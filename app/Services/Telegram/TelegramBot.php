<?php
declare(strict_types=1);

namespace HeleXa\Services\Telegram;

use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\ExamRepository;
use HeleXa\Models\NotificationRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Models\RateLimitRepository;
use HeleXa\Models\ScheduleRepository;
use HeleXa\Models\SupportTicketRepository;
use HeleXa\Models\TelegramAccountRepository;
use HeleXa\Models\TelegramStateRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;
use HeleXa\Services\Settings;
use HeleXa\Services\TelegramClient;
use HeleXa\Services\TelegramKeyboard as KB;

/**
 * The bot's brain: one method per kind of Telegram update, dispatching to
 * small handlers. Every handler that touches student data resolves the
 * caller's HeleXa account first and works from that row onward — the
 * Telegram id itself is only ever used to look that row up, never as an
 * identity by itself.
 *
 * This mirrors the site: no separate business logic, no separate user
 * system. The same repositories the panel controllers use are used here.
 */
final class TelegramBot
{
    private TelegramClient $client;
    private TelegramAccountRepository $accounts;
    private TelegramStateRepository $states;

    public function __construct(TelegramClient $client)
    {
        $this->client   = $client;
        $this->accounts = new TelegramAccountRepository();
        $this->states   = new TelegramStateRepository();
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
            return;
        }
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
    }

    /* ============================================================ messages */

    private function handleMessage(array $message): void
    {
        $from   = $message['from'] ?? [];
        $telegramUserId = (int) ($from['id'] ?? 0);
        $chatId = (int) ($message['chat']['id'] ?? 0);

        if ($telegramUserId <= 0 || $chatId <= 0 || (bool) ($from['is_bot'] ?? false)) {
            return;
        }

        $this->accounts->touchSeen($telegramUserId);

        if (isset($message['contact'])) {
            $this->handleContactShared($telegramUserId, $chatId, $message['contact']);
            return;
        }

        if (isset($message['photo']) && is_array($message['photo']) && $message['photo'] !== []) {
            $this->handlePhotoMessage($telegramUserId, $chatId, $message);
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '/start' || str_starts_with($text, '/start ')) {
            $this->states->clear($telegramUserId);
            $this->cmdStart($telegramUserId, $chatId, $from);
            return;
        }
        if ($text === '/help') {
            $this->sendHelp($chatId);
            return;
        }
        if ($text === '/logout') {
            $this->askLogoutConfirmation($telegramUserId, $chatId);
            return;
        }

        $slashCommands = [
            '/profile' => 'profile', '/courses' => 'courses', '/schedule' => 'schedule',
            '/exams' => 'exams', '/notifications' => 'notifications', '/settings' => 'settings',
            '/status' => 'status', '/support' => 'support',
        ];
        if (isset($slashCommands[$text])) {
            $this->routeMenu($telegramUserId, $chatId, null, $slashCommands[$text]);
            return;
        }

        $state = $this->states->get($telegramUserId);
        if ($state !== null) {
            $this->handleStateInput($telegramUserId, $chatId, $message, $text, $state);
            return;
        }

        // Free text with no active flow: point back to the menu instead of
        // silently doing nothing, which reads as the bot being broken.
        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            $this->cmdStart($telegramUserId, $chatId, $from);
        } else {
            $this->client->sendMessage($chatId, 'از منوی زیر انتخاب کنید 👇', ['reply_markup' => json_encode(KB::mainMenu())]);
        }
    }

    private function handleContactShared(int $telegramUserId, int $chatId, array $contact): void
    {
        // A phone number shared through Telegram's own share-contact widget is
        // only accepted if it belongs to the same Telegram account that is
        // chatting — never a number typed in as free text and never someone
        // else's forwarded contact card.
        if ((int) ($contact['user_id'] ?? 0) !== $telegramUserId) {
            $this->client->sendMessage($chatId, 'این شماره متعلق به حساب تلگرام شما نیست.');
            return;
        }

        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            $this->cmdStart($telegramUserId, $chatId, []);
            return;
        }

        $this->states->clear($telegramUserId);
        $userId = (int) $account['user_id'];
        $raw    = preg_replace('/[^0-9+]/', '', (string) ($contact['phone_number'] ?? '')) ?? '';

        // Kept on the Telegram side regardless of shape, as a convenience the
        // student can see back in the bot.
        $this->accounts->setPhone($telegramUserId, $raw !== '' ? $raw : null);

        // Synced to the site profile only when it matches the same format the
        // web profile form itself requires — that column doubles as a login
        // identifier, so it is held to the stricter rule.
        $normalized = preg_replace('/^\+98/', '0', $raw) ?? $raw;
        $users      = new UserRepository();

        if (preg_match('/^09\d{9}$/', $normalized) === 1) {
            if ($users->mobileExists($normalized, $userId)) {
                $this->client->sendMessage($chatId,
                    '⚠️ شماره در ربات ذخیره شد، اما همین شماره قبلاً برای حساب دیگری در سایت ثبت شده'
                    . ' و روی پروفایل سایت شما اعمال نشد.');
            } else {
                $users->setMobile($userId, $normalized);
                $this->client->sendMessage($chatId, '✅ شماره تلفن شما ذخیره و روی پروفایل سایت هم اعمال شد.');
            }
        } else {
            $this->client->sendMessage($chatId, '✅ شماره تلفن شما در ربات ذخیره شد.');
        }

        $this->client->sendMessage($chatId, 'برای مدیریت پروفایل از منوی زیر استفاده کنید:', [
            'reply_markup' => json_encode(KB::removeReplyKeyboard()),
        ]);
        $this->sendProfile($chatId, $userId);
    }

    /**
     * A photo the student sent while a matching upload was pending.
     * Unsolicited photos (no active state) are silently ignored — a random
     * picture forwarded to the bot is not an avatar-change request.
     */
    private function handlePhotoMessage(int $telegramUserId, int $chatId, array $message): void
    {
        $state = $this->states->get($telegramUserId);
        if ($state === null) {
            return;
        }

        if ($state['step'] === 'support_active') {
            // Stays in this state afterward, same reasoning as the text case.
            $this->handleSupportPhoto($telegramUserId, $chatId, $message);
            return;
        }

        if ($state['step'] !== 'avatar_await_photo') {
            return;
        }
        $this->states->clear($telegramUserId);

        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            $this->cmdStart($telegramUserId, $chatId, []);
            return;
        }
        $userId = (int) $account['user_id'];

        // Telegram sends the same photo at several resolutions; the last
        // entry is the largest.
        $sizes = $message['photo'];
        $file  = end($sizes);
        $fileId = (string) ($file['file_id'] ?? '');
        if ($fileId === '') {
            $this->client->sendMessage($chatId, '❌ دریافت تصویر ناموفق بود. دوباره تلاش کنید.');
            return;
        }

        $info = $this->client->getFile($fileId);
        $path = (string) ($info['result']['file_path'] ?? '');
        if (($info['ok'] ?? false) !== true || $path === '') {
            $this->client->sendMessage($chatId, '❌ دریافت اطلاعات تصویر از تلگرام ناموفق بود.');
            return;
        }

        $bytes = $this->client->downloadFile($path);
        if ($bytes === null || $bytes === '') {
            $this->client->sendMessage($chatId, '❌ دانلود تصویر ناموفق بود.');
            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'tgavatar_');
        file_put_contents($temporary, $bytes);

        try {
            // Reuses the exact same validation the web upload form goes
            // through — real image bytes only, size cap, dimension cap —
            // rather than trusting that "Telegram sent it" is enough.
            $storage    = new \HeleXa\Services\AvatarStorage();
            $storedPath = $storage->store([
                'tmp_name' => $temporary,
                'size'     => strlen($bytes),
                'error'    => UPLOAD_ERR_OK,
            ], $userId);
        } catch (\RuntimeException $e) {
            @unlink($temporary);
            $this->client->sendMessage($chatId, '❌ ' . $e->getMessage(), ['reply_markup' => json_encode(KB::backTo('profile'))]);
            return;
        }
        @unlink($temporary);

        $users = new UserRepository();
        $old   = $users->findById($userId)['avatar_path'] ?? null;
        if (is_string($old) && $old !== '' && $old !== $storedPath) {
            (new \HeleXa\Services\AvatarStorage())->delete($old);
        }
        $users->setAvatar($userId, $storedPath);

        ActivityLogger::log('telegram.avatar_updated', $userId, 'user', $userId, [], 'info');
        $this->client->sendMessage($chatId, '✅ عکس پروفایل شما به‌روزرسانی شد.', [
            'reply_markup' => json_encode(KB::backTo('profile')),
        ]);
    }

    /* ============================================================== start */

    private function cmdStart(int $telegramUserId, int $chatId, array $from): void
    {
        $account = $this->accounts->findByTelegramId($telegramUserId);

        if ($account !== null) {
            $this->client->sendMessage(
                $chatId,
                sprintf("سلام %s 👋\nخوش آمدید به HeleXa Med.", \e($account['full_name'])),
                ['reply_markup' => json_encode(KB::mainMenu())]
            );
            return;
        }

        $welcome = "🌟 <b>سلام و خوش آمدید به HeleXa Med</b>\n\n"
            . "به دستیار آموزشی شخصی خودتان خوش آمدید.\n\n"
            . "از طریق این ربات می‌توانید:\n"
            . "📚 دوره‌های خود را مشاهده کنید\n"
            . "📅 برنامه هفتگی و امتحانات را ببینید\n"
            . "👤 پروفایل خود را مدیریت کنید\n"
            . "🔔 اطلاعیه‌ها را دریافت کنید\n"
            . "⚙️ تنظیمات خود را تغییر دهید\n\n"
            . "برای شروع وارد حساب HeleXa خود شوید.";

        $this->client->sendMessage($chatId, $welcome, [
            'reply_markup' => json_encode(KB::rows([['🔐 ورود به حساب', 'login:start']])),
        ]);
    }

    private function sendHelp(int $chatId): void
    {
        $this->client->sendMessage($chatId,
            "<b>راهنمای دستورات</b>\n\n"
            . "/start — شروع و ورود به حساب\n"
            . "/profile — پروفایل من\n"
            . "/courses — دوره‌های من\n"
            . "/schedule — برنامه هفتگی\n"
            . "/exams — برنامه امتحانات\n"
            . "/notifications — اطلاعیه‌ها\n"
            . "/settings — تنظیمات\n"
            . "/status — وضعیت من\n"
            . "/support — ارتباط با پشتیبانی\n"
            . "/logout — خروج از حساب"
        );
    }

    /* =============================================================== login */

    private const LOGIN_RATE_BUCKET = 'tg_login';
    private const LOGIN_RATE_LIMIT  = 8;
    private const LOGIN_RATE_WINDOW = 600;

    private function beginLogin(int $telegramUserId, int $chatId): void
    {
        $this->states->set($telegramUserId, 'login_username');
        $this->client->sendMessage($chatId, '👤 لطفاً نام کاربری خود را وارد کنید:');
    }

    private function handleStateInput(int $telegramUserId, int $chatId, array $message, string $text, array $state): void
    {
        switch ($state['step']) {
            case 'login_username':
                if ($text === '' || mb_strlen($text) > 64) {
                    $this->client->sendMessage($chatId, 'نام کاربری نامعتبر است. دوباره وارد کنید:');
                    return;
                }
                $this->states->set($telegramUserId, 'login_password', ['username' => $text]);
                $this->client->sendMessage($chatId, '🔒 لطفاً رمز عبور خود را وارد کنید:');
                return;

            case 'login_password':
                $this->finishLogin($telegramUserId, $chatId, $message, (string) $state['payload']['username'], $text);
                return;

            case 'password_current':
                $this->handlePasswordCurrent($telegramUserId, $chatId, $message, $text);
                return;
            case 'password_new':
                $this->handlePasswordNew($telegramUserId, $chatId, $message, $text);
                return;
            case 'password_confirm':
                $this->handlePasswordConfirm($telegramUserId, $chatId, $message, $text, $state['payload']);
                return;

            case 'avatar_await_photo':
                $this->client->sendMessage($chatId, 'لطفاً یک عکس ارسال کنید، یا برای انصراف «انصراف» را بزنید.');
                return;

            case 'support_active':
                // Deliberately does not clear the state afterward — a
                // support conversation is several messages, not one.
                $this->handleSupportText($telegramUserId, $chatId, $text);
                return;

            default:
                $this->states->clear($telegramUserId);
        }
    }

    /* -------------------------------------------------------- password change
       Same rule the site already enforces everywhere: at least 8 characters,
       English letters and digits only, both required. Every password message
       is deleted the moment it is read, exactly like the login flow. */

    private function handlePasswordCurrent(int $telegramUserId, int $chatId, array $message, string $current): void
    {
        $this->deleteIfPossible($chatId, (int) ($message['message_id'] ?? 0));

        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            $this->states->clear($telegramUserId);
            $this->cmdStart($telegramUserId, $chatId, []);
            return;
        }

        $user = (new UserRepository())->findById((int) $account['user_id']);
        if ($user === null || !password_verify($current, (string) $user['password_hash'])) {
            $this->states->clear($telegramUserId);
            $this->client->sendMessage($chatId, '❌ رمز عبور فعلی نادرست است. برای تلاش مجدد، دوباره از منو وارد شوید.',
                ['reply_markup' => json_encode(KB::backTo('profile'))]);
            return;
        }

        $this->states->set($telegramUserId, 'password_new');
        $this->client->sendMessage($chatId, '🔑 رمز عبور جدید را وارد کنید (حداقل ۸ کاراکتر، فقط حروف انگلیسی و عدد):');
    }

    private function handlePasswordNew(int $telegramUserId, int $chatId, array $message, string $new): void
    {
        $this->deleteIfPossible($chatId, (int) ($message['message_id'] ?? 0));

        if (($reason = $this->passwordPolicyError($new)) !== null) {
            $this->client->sendMessage($chatId, "❌ {$reason}\nدوباره رمز جدید را وارد کنید:");
            return;
        }

        $this->states->set($telegramUserId, 'password_confirm', ['new' => $new]);
        $this->client->sendMessage($chatId, '🔁 برای تأیید، رمز جدید را دوباره وارد کنید:');
    }

    private function handlePasswordConfirm(int $telegramUserId, int $chatId, array $message, string $confirm, array $payload): void
    {
        $this->deleteIfPossible($chatId, (int) ($message['message_id'] ?? 0));
        $this->states->clear($telegramUserId);

        $new = (string) ($payload['new'] ?? '');
        if ($new === '' || $confirm !== $new) {
            $this->client->sendMessage($chatId, '❌ رمز تأییدی با رمز جدید یکسان نبود. از منو دوباره تلاش کنید.',
                ['reply_markup' => json_encode(KB::backTo('profile'))]);
            return;
        }

        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            $this->cmdStart($telegramUserId, $chatId, []);
            return;
        }
        $userId = (int) $account['user_id'];
        $users  = new UserRepository();

        $users->updatePassword($userId, Auth::hashPassword($new));

        // Consistent with a password change on the website: every other
        // session and every remembered browser is revoked, regardless of
        // which interface the change was made from.
        (new \HeleXa\Models\SessionRepository())->terminateAllForUser($userId, 'password_change', null);
        Auth::revokeRememberTokens($userId, 'password_change');

        ActivityLogger::log('telegram.password_changed', $userId, 'user', $userId, [], 'notice');
        $this->client->sendMessage($chatId, '✅ رمز عبور شما با موفقیت تغییر کرد.', [
            'reply_markup' => json_encode(KB::backTo('profile')),
        ]);
    }

    /** Mirrors Validator::password() exactly, for a context with no HTTP form to validate. */
    private function passwordPolicyError(string $value): ?string
    {
        if (strlen($value) !== mb_strlen($value, 'UTF-8')) {
            return 'رمز عبور فقط می‌تواند شامل حروف انگلیسی و اعداد باشد.';
        }
        if (mb_strlen($value, 'UTF-8') < 8) {
            return 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
        }
        if (preg_match('/^[A-Za-z0-9]+$/', $value) !== 1) {
            return 'رمز عبور فقط می‌تواند شامل حروف انگلیسی و اعداد باشد؛ فاصله و کاراکتر خاص مجاز نیست.';
        }
        if (preg_match('/[A-Za-z]/', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
            return 'رمز عبور باید ترکیبی از حروف و عدد باشد.';
        }
        return null;
    }

    private function deleteIfPossible(int $chatId, int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }
        $deleted = $this->client->deleteMessage($chatId, $messageId);
        if (($deleted['ok'] ?? false) !== true) {
            ActivityLogger::log('telegram.password_message_not_deleted', null, 'telegram', $chatId,
                ['reason' => $deleted['description'] ?? 'unknown'], 'warning');
        }
    }

    private function finishLogin(int $telegramUserId, int $chatId, array $message, string $username, string $password): void
    {
        // The password never stays in the chat's own history longer than it
        // takes this request to run. Best effort: Telegram may refuse the
        // delete (message too old, permissions), and that is logged but not
        // treated as fatal — the login itself must still be able to proceed.
        $this->deleteIfPossible($chatId, (int) ($message['message_id'] ?? 0));

        $this->states->clear($telegramUserId);

        // A second, Telegram-specific layer on top of the account's own
        // lockout: this protects against one chat trying many different
        // usernames, which the per-account lockout alone would not catch.
        $limiter    = new RateLimitRepository();
        $now        = time();
        $windowStart = date('Y-m-d H:i:s', $now - ($now % self::LOGIN_RATE_WINDOW));
        $hits       = $limiter->hit(self::LOGIN_RATE_BUCKET, Str::hash('tg:' . $telegramUserId), $windowStart, self::LOGIN_RATE_WINDOW);

        if ($hits > self::LOGIN_RATE_LIMIT) {
            $this->client->sendMessage($chatId, '⛔️ تلاش‌های ورود بیش از حد مجاز بود. کمی بعد دوباره تلاش کنید.');
            return;
        }

        $result = Auth::verifyCredentialsOnly($username, $password);

        if (!$result['ok']) {
            ActivityLogger::log('telegram.login_failed', $result['user']['id'] ?? null, 'telegram', $telegramUserId,
                ['reason' => $result['reason']], 'notice');

            $message = match ($result['reason']) {
                'LOCKED'   => '🔒 حساب شما موقتاً قفل شده است. کمی بعد دوباره تلاش کنید.',
                'INACTIVE' => 'حساب کاربری شما فعال نیست. با پشتیبانی تماس بگیرید.',
                default    => 'نام کاربری یا رمز عبور نادرست است.',
            };
            $this->client->sendMessage($chatId, "❌ {$message}", [
                'reply_markup' => json_encode(KB::rows([['🔐 تلاش مجدد', 'login:start']])),
            ]);
            return;
        }

        $user = $result['user'];
        $this->accounts->link([
            'user_id'           => (int) $user['id'],
            'telegram_user_id'  => $telegramUserId,
            'chat_id'           => $chatId,
            'telegram_username' => $message['from']['username'] ?? null,
            'first_name'        => $message['from']['first_name'] ?? null,
        ]);

        ActivityLogger::log('telegram.login_succeeded', (int) $user['id'], 'telegram', $telegramUserId, [], 'notice');

        $this->client->sendMessage(
            $chatId,
            sprintf("🎉 ورود با موفقیت انجام شد!\nسلام %s 👋\nخوش آمدید به HeleXa Med.", \e($user['full_name'])),
            ['reply_markup' => json_encode(KB::mainMenu())]
        );
    }

    /* ========================================================== callbacks */

    private function handleCallback(array $callback): void
    {
        $from   = $callback['from'] ?? [];
        $telegramUserId = (int) ($from['id'] ?? 0);
        $message = $callback['message'] ?? [];
        $chatId  = (int) ($message['chat']['id'] ?? 0);
        $data    = (string) ($callback['data'] ?? '');
        $callbackId = (string) ($callback['id'] ?? '');

        if ($telegramUserId <= 0 || $chatId <= 0) {
            return;
        }

        // Telegram shows a loading spinner on the button until this is
        // answered; answering promptly is what makes the bot feel responsive.
        $this->client->answerCallbackQuery($callbackId);
        $this->accounts->touchSeen($telegramUserId);

        if ($data === 'login:start') {
            $this->beginLogin($telegramUserId, $chatId);
            return;
        }

        [$group, $action] = array_pad(explode(':', $data, 2), 2, '');

        if ($group === 'menu') {
            $this->routeMenu($telegramUserId, $chatId, $callbackId, $action);
            return;
        }
        if ($group === 'settings') {
            $this->handleSettingsAction($telegramUserId, $chatId, $action);
            return;
        }
        if ($group === 'logout') {
            $this->handleLogoutAction($telegramUserId, $chatId, $action);
            return;
        }
        if ($group === 'edit') {
            $this->handleEditAction($telegramUserId, $chatId, $action);
            return;
        }
        if ($group === 'support' && $action === 'end') {
            $this->endSupport($telegramUserId, $chatId);
            return;
        }
        if ($group === 'notif') {
            $this->handleNotificationAction($telegramUserId, $chatId, $action);
            return;
        }
    }

    private function routeMenu(int $telegramUserId, int $chatId, ?string $callbackId, string $action): void
    {
        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            $this->cmdStart($telegramUserId, $chatId, []);
            return;
        }

        $userId = (int) $account['user_id'];
        $user   = (new UserRepository())->findById($userId);
        if ($user === null || $user['status'] !== 'active') {
            $this->accounts->unlink($telegramUserId);
            $this->client->sendMessage($chatId, 'دسترسی حساب شما تغییر کرده است. لطفاً دوباره وارد شوید.');
            $this->cmdStart($telegramUserId, $chatId, []);
            return;
        }

        match ($action) {
            'courses'       => $this->sendCourses($chatId, $user),
            'schedule'      => $this->sendSchedule($chatId, $user),
            'exams'         => $this->sendExams($chatId, $user),
            'notifications' => $this->sendNotifications($chatId, $userId),
            'profile'       => $this->sendProfile($chatId, $userId),
            'status'        => $this->sendStatus($chatId, $user),
            'support'       => $this->enterSupport($telegramUserId, $chatId, $userId),
            'settings'      => $this->sendSettings($chatId, $telegramUserId),
            'logout'        => $this->askLogoutConfirmation($telegramUserId, $chatId),
            default         => $this->client->sendMessage($chatId, 'از منوی زیر انتخاب کنید 👇', [
                'reply_markup' => json_encode(KB::mainMenu()),
            ]),
        };
    }

    /* ======================================================= menu screens */

    private function sendCourses(int $chatId, array $user): void
    {
        $courses = (new EnrollmentRepository())->coursesForStudent((int) $user['id']);

        if ($courses === []) {
            $this->client->sendMessage($chatId, 'هنوز دوره‌ای برای شما فعال نشده است.', ['reply_markup' => json_encode(KB::backTo())]);
            return;
        }

        $lines = ["📚 <b>دوره‌های من</b>\n"];
        $i = 1;
        foreach ($courses as $course) {
            $total   = (int) $course['content_count'];
            $done    = (int) $course['completed_count'];
            $percent = $total > 0 ? (int) round($done / $total * 100) : 0;
            $lines[] = sprintf(
                "%s️⃣ <b>%s</b>\n   وضعیت: فعال · پیشرفت: %s٪%s",
                \fa((string) $i),
                \e($course['title']),
                \fa((string) $percent),
                $course['ends_at'] ? "\n   ⏳ تا " . \jdate($course['ends_at']) : ''
            );
            $i++;
        }

        $this->client->sendMessage($chatId, implode("\n\n", $lines), ['reply_markup' => json_encode(KB::backTo())]);
    }

    private function sendSchedule(int $chatId, array $user): void
    {
        $termIds = \HeleXa\Services\AcademicScope::termIds($user);
        $groupId = \HeleXa\Services\AcademicScope::groupId($user);

        if ($termIds === []) {
            $this->client->sendMessage($chatId, 'ترمی برای حساب شما ثبت نشده است.', ['reply_markup' => json_encode(KB::backTo())]);
            return;
        }

        $schedules = new ScheduleRepository();
        $today     = Jalali::weekdayIndex(time());
        $lines     = ["📅 <b>برنامه امروز</b> (" . \e(Jalali::WEEKDAYS[$today]) . ")\n"];
        $any       = false;

        foreach ($schedules->forStudentTerms($termIds, $groupId) as $entry) {
            if ($entry['schedule'] === null) {
                continue;
            }
            foreach ($schedules->itemsForWeekday((int) $entry['schedule']['id'], $today) as $item) {
                $any = true;
                $lines[] = sprintf(
                    "🕘 %s — %s\n<b>%s</b>%s%s",
                    \fa(substr((string) $item['start_time'], 0, 5)),
                    \fa(substr((string) $item['end_time'], 0, 5)),
                    \e($item['title']),
                    $item['teacher']  ? "\n🏫 " . \e($item['teacher'])  : '',
                    $item['location'] ? "\n📍 " . \e($item['location']) : ''
                );
            }
        }

        if (!$any) {
            $lines[] = 'برای امروز کلاسی ثبت نشده است.';
        }

        $this->client->sendMessage($chatId, implode("\n\n", $lines), ['reply_markup' => json_encode(KB::backTo())]);
    }

    private function sendExams(int $chatId, array $user): void
    {
        $termIds = \HeleXa\Services\AcademicScope::termIds($user);
        $groupId = \HeleXa\Services\AcademicScope::groupId($user);
        $exams   = (new ExamRepository())->forStudentTerms($termIds, $groupId, null, true);

        if ($exams === []) {
            $this->client->sendMessage($chatId, 'امتحان پیش‌رویی برای شما ثبت نشده است.', ['reply_markup' => json_encode(KB::backTo())]);
            return;
        }

        $kindLabel = ['final' => 'پایان‌ترم', 'midterm' => 'میان‌ترم', 'quiz' => 'کوییز', 'practical' => 'عملی', 'other' => 'سایر'];
        $lines = ["📝 <b>امتحانات پیش‌رو</b>\n"];

        foreach (array_slice($exams, 0, 10) as $exam) {
            $daysLeft = (int) floor((strtotime((string) $exam['exam_date']) - strtotime(date('Y-m-d'))) / 86400);
            $lines[] = sprintf(
                "📌 <b>%s</b> (%s)\n📅 %s%s%s",
                \e($exam['title']),
                \e($kindLabel[$exam['exam_kind']] ?? $exam['exam_kind']),
                \e(Jalali::date((int) strtotime((string) $exam['exam_date']))),
                $exam['start_time'] ? ' ساعت ' . \fa(substr((string) $exam['start_time'], 0, 5)) : '',
                $daysLeft >= 0 ? "\n⏳ " . \fa((string) $daysLeft) . ' روز مانده' : ''
            );
        }

        $this->client->sendMessage($chatId, implode("\n\n", $lines), ['reply_markup' => json_encode(KB::backTo())]);
    }

    private function sendNotifications(int $chatId, int $userId): void
    {
        $rows = (new NotificationRepository())->forUser($userId, 8);
        if ($rows === []) {
            $this->client->sendMessage($chatId, 'اطلاعیه‌ای برای شما ثبت نشده است.', ['reply_markup' => json_encode(KB::backTo())]);
            return;
        }

        $lines = ["🔔 <b>آخرین اطلاعیه‌ها</b>\n"];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                "%s <b>%s</b>\n%s\n<i>%s</i>",
                (int) $row['is_read'] === 0 ? '🔵' : '⚪️',
                \e($row['title']),
                $row['body'] ? \e(mb_substr((string) $row['body'], 0, 200)) : '',
                \e(\jdate($row['published_at']))
            );
        }

        $this->client->sendMessage($chatId, implode("\n\n", $lines), ['reply_markup' => json_encode(KB::backTo())]);
    }

    private function sendProfile(int $chatId, int $userId): void
    {
        $user = (new UserRepository())->findById($userId);
        if ($user === null) {
            return;
        }
        $academic  = new AcademicRepository();
        $termNames = [];
        foreach (\HeleXa\Services\AcademicScope::termIds($user) as $termId) {
            $term = $academic->findTerm($termId);
            if ($term !== null) {
                $termNames[] = $term['title'];
            }
        }
        $group = null;
        if ($user['group_id'] !== null) {
            foreach ($academic->groups() as $row) {
                if ((int) $row['id'] === (int) $user['group_id']) {
                    $group = $row['title'];
                    break;
                }
            }
        }
        $genderLabel = match ($user['gender']) {
            'male'   => 'مرد',
            'female' => 'زن',
            default  => 'ثبت نشده',
        };

        $account = $this->accounts->findByUserId($userId);
        $phone   = $account['phone_shared'] ?? null;

        $text = "👤 <b>پروفایل من</b>\n\n"
            . "نام: " . \e($user['full_name']) . "\n"
            . "نام کاربری: <code>" . \e($user['username']) . "</code>\n"
            . "دانشگاه: " . \e($user['university_title'] ?? '—') . "\n"
            . "رشته: " . \e($user['major_title'] ?? '—') . "\n"
            . "ترم‌ها: " . ($termNames === [] ? '—' : \e(implode('، ', $termNames))) . "\n"
            . "گروه: " . \e($group ?? '—') . "\n"
            . "جنسیت: " . \e($genderLabel) . "\n"
            . "شماره تلفن: " . \e($phone ?? 'ثبت نشده');

        $this->client->sendMessage($chatId, $text, [
            'reply_markup' => json_encode(KB::rows([
                ['✏️ ویرایش پروفایل', 'edit:menu'],
                ['⬅️ بازگشت', 'menu:menu'],
            ])),
        ]);
    }

    private function sendStatus(int $chatId, array $user): void
    {
        $userId    = (int) $user['id'];
        $termIds   = \HeleXa\Services\AcademicScope::termIds($user);
        $groupId   = \HeleXa\Services\AcademicScope::groupId($user);
        $courses   = (new EnrollmentRepository())->coursesForStudent($userId);
        $exams     = (new ExamRepository())->forStudentTerms($termIds, $groupId, null, true);

        $totalContent = array_sum(array_map(static fn (array $c): int => (int) $c['content_count'], $courses));
        $doneContent  = array_sum(array_map(static fn (array $c): int => (int) $c['completed_count'], $courses));
        $overall      = $totalContent > 0 ? (int) round($doneContent / $totalContent * 100) : 0;

        $text = "📊 <b>وضعیت من</b>\n\n"
            . "نام: " . \e($user['full_name']) . "\n"
            . "دوره‌های فعال: " . \fa((string) count($courses)) . "\n"
            . "پیشرفت کلی: " . \fa((string) $overall) . "٪\n"
            . "امتحانات پیش‌رو: " . \fa((string) count($exams));

        if ($exams !== []) {
            $next = $exams[0];
            $text .= "\n\n⏭ نزدیک‌ترین امتحان: " . \e($next['title']) . ' — ' . \e(Jalali::date((int) strtotime((string) $next['exam_date'])));
        }

        $this->client->sendMessage($chatId, $text, ['reply_markup' => json_encode(KB::backTo())]);
    }

    /* ============================================================ settings */

    private function sendSettings(int $chatId, int $telegramUserId): void
    {
        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            return;
        }

        $toggle = static fn (string $col, string $label): array => [
            ((int) $account[$col] === 1 ? '✅ ' : '⬜️ ') . $label,
            'settings:toggle:' . $col,
        ];

        $keyboard = KB::rows([
            $toggle('notify_general', 'اطلاع‌رسانی کلی'),
            $toggle('notify_schedule', 'برنامه فردا'),
            $toggle('notify_exams', 'امتحانات'),
            $toggle('notify_announcements', 'اطلاعیه‌ها'),
            $toggle('notify_support', 'پاسخ پشتیبانی'),
            ['⬅️ بازگشت', 'menu:menu'],
        ]);

        $this->client->sendMessage($chatId, "⚙️ <b>تنظیمات اطلاع‌رسانی</b>\nهرکدام را برای روشن/خاموش کردن بزنید:", [
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function handleSettingsAction(int $telegramUserId, int $chatId, string $action): void
    {
        [$sub, $column] = array_pad(explode(':', $action, 2), 2, '');
        if ($sub !== 'toggle') {
            return;
        }

        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            return;
        }

        try {
            $this->accounts->setPreference($telegramUserId, $column, (int) $account[$column] !== 1);
        } catch (\InvalidArgumentException) {
            return;
        }

        $this->sendSettings($chatId, $telegramUserId);
    }

    /* ============================================================= logout */

    /* ============================================================= support
       A single open ticket per student. Entering support puts the chat into
       a standing state — unlike login or the password flow, this state does
       NOT clear itself after one message, because a support conversation is
       naturally several messages back and forth. It only ends when the
       student taps "پایان گفتگو" or issues a command (checked earlier, in
       handleMessage, before state is even looked at). */

    private function enterSupport(int $telegramUserId, int $chatId, int $userId): void
    {
        $this->states->set($telegramUserId, 'support_active');

        $tickets = new SupportTicketRepository();
        $open    = $tickets->openFor($userId);

        if ($open !== null) {
            $recent = array_slice($tickets->messagesFor((int) $open['id']), -5);
            $lines  = ['🆘 <b>گفتگوی پشتیبانی</b> — پیام‌های اخیر:'];
            foreach ($recent as $message) {
                $who = $message['sender_type'] === 'admin' ? '👨‍💼 پشتیبانی' : '🧑 شما';
                $lines[] = $who . ': ' . (string) ($message['body'] ?? ($message['attachment_path'] ? '[تصویر]' : ''));
            }
            $lines[] = '';
            $lines[] = 'پیام بعدی را بنویسید یا عکس بفرستید:';
            $this->client->sendMessage($chatId, implode("\n", $lines), [
                'reply_markup' => json_encode(KB::supportEnd()),
            ]);
            return;
        }

        $this->client->sendMessage(
            $chatId,
            "🆘 <b>ارتباط با پشتیبانی</b>\n\nسؤال یا مشکل خود را بنویسید یا عکس بفرستید. یک ادمین پاسخ می‌دهد.",
            ['reply_markup' => json_encode(KB::supportEnd())]
        );
    }

    private function handleSupportText(int $telegramUserId, int $chatId, string $text): void
    {
        if ($text === '') {
            return;
        }
        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            $this->states->clear($telegramUserId);
            $this->cmdStart($telegramUserId, $chatId, []);
            return;
        }

        $userId  = (int) $account['user_id'];
        $tickets = new SupportTicketRepository();
        $ticket  = $tickets->openFor($userId);
        $ticketId = $ticket !== null ? (int) $ticket['id'] : $tickets->create($userId);

        $tickets->addMessage($ticketId, 'student', $userId, mb_substr($text, 0, 4000), null);

        $this->client->sendMessage($chatId, '✅ پیام شما ثبت شد. به‌محض پاسخ ادمین به شما اطلاع می‌دهیم.', [
            'reply_markup' => json_encode(KB::supportEnd()),
        ]);
    }

    private function handleSupportPhoto(int $telegramUserId, int $chatId, array $message): void
    {
        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            $this->states->clear($telegramUserId);
            $this->cmdStart($telegramUserId, $chatId, []);
            return;
        }
        $userId = (int) $account['user_id'];

        $sizes  = $message['photo'];
        $file   = end($sizes);
        $fileId = (string) ($file['file_id'] ?? '');
        if ($fileId === '') {
            $this->client->sendMessage($chatId, '❌ دریافت تصویر ناموفق بود.');
            return;
        }

        $info = $this->client->getFile($fileId);
        $path = (string) ($info['result']['file_path'] ?? '');
        if (($info['ok'] ?? false) !== true || $path === '') {
            $this->client->sendMessage($chatId, '❌ دریافت اطلاعات تصویر از تلگرام ناموفق بود.');
            return;
        }
        $bytes = $this->client->downloadFile($path);
        if ($bytes === null || $bytes === '') {
            $this->client->sendMessage($chatId, '❌ دانلود تصویر ناموفق بود.');
            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'tgsupport_');
        file_put_contents($temporary, $bytes);

        try {
            $storage        = new \HeleXa\Services\SupportAttachmentStorage();
            $attachmentName = $storage->store(['tmp_name' => $temporary, 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK]);
        } catch (\RuntimeException $e) {
            @unlink($temporary);
            $this->client->sendMessage($chatId, '❌ ' . $e->getMessage());
            return;
        }
        @unlink($temporary);

        $tickets  = new SupportTicketRepository();
        $ticket   = $tickets->openFor($userId);
        $ticketId = $ticket !== null ? (int) $ticket['id'] : $tickets->create($userId);
        $caption  = isset($message['caption']) ? mb_substr((string) $message['caption'], 0, 4000) : null;

        $tickets->addMessage($ticketId, 'student', $userId, $caption, $attachmentName);

        $this->client->sendMessage($chatId, '✅ تصویر شما ثبت شد. به‌محض پاسخ ادمین به شما اطلاع می‌دهیم.', [
            'reply_markup' => json_encode(KB::supportEnd()),
        ]);
    }

    private function endSupport(int $telegramUserId, int $chatId): void
    {
        $this->states->clear($telegramUserId);
        $this->client->sendMessage($chatId, 'گفتگو با پشتیبانی پایان یافت.', [
            'reply_markup' => json_encode(KB::mainMenu()),
        ]);
    }

    private function askLogoutConfirmation(int $telegramUserId, int $chatId): void
    {
        $this->client->sendMessage($chatId, 'آیا مطمئن هستید که می‌خواهید از حساب خارج شوید؟', [
            'reply_markup' => json_encode(KB::rows([
                ['بله، خروج', 'logout:confirm'],
                ['انصراف', 'logout:cancel'],
            ])),
        ]);
    }

    private function handleLogoutAction(int $telegramUserId, int $chatId, string $action): void
    {
        if ($action === 'confirm') {
            $this->accounts->unlink($telegramUserId);
            $this->states->clear($telegramUserId);
            ActivityLogger::log('telegram.logout', null, 'telegram', $telegramUserId, [], 'notice');
            $this->client->sendMessage($chatId, '🚪 از حساب خود خارج شدید.', [
                'reply_markup' => json_encode(KB::rows([['🔐 ورود مجدد', 'login:start']])),
            ]);
            return;
        }

        $this->client->sendMessage($chatId, 'خروج لغو شد.', ['reply_markup' => json_encode(KB::mainMenu())]);
    }

    /* ==================================================== profile editing
       University, major, term and group are deliberately absent from this
       list: those are enrolment facts an admin sets, the same rule the web
       profile form already follows. Only real personal preferences — gender,
       phone, photo, password — are editable here. */

    private function handleEditAction(int $telegramUserId, int $chatId, string $action): void
    {
        $account = $this->accounts->findByTelegramId($telegramUserId);
        if ($account === null) {
            $this->cmdStart($telegramUserId, $chatId, []);
            return;
        }
        $userId = (int) $account['user_id'];

        if ($action === 'menu') {
            $this->client->sendMessage($chatId, '✏️ کدام بخش را می‌خواهید ویرایش کنید؟', [
                'reply_markup' => json_encode(KB::editProfileMenu()),
            ]);
            return;
        }

        if ($action === 'gender') {
            $this->client->sendMessage($chatId, 'جنسیت خود را انتخاب کنید:', [
                'reply_markup' => json_encode(KB::genderChoice()),
            ]);
            return;
        }
        if ($action === 'gender:male' || $action === 'gender:female') {
            $gender = $action === 'gender:male' ? 'male' : 'female';
            (new UserRepository())->setGender($userId, $gender);
            ActivityLogger::log('telegram.profile_updated', $userId, 'user', $userId, ['field' => 'gender'], 'info');
            $this->client->sendMessage($chatId, '✅ جنسیت شما ذخیره شد.');
            $this->sendProfile($chatId, $userId);
            return;
        }

        if ($action === 'phone') {
            $current = $account['phone_shared'] ?? null;
            if ($current !== null) {
                $this->client->sendMessage(
                    $chatId,
                    'شماره فعلی شما: <code>' . \e($current) . "</code>\n\nمی‌خواهید چه کاری انجام دهید؟",
                    ['reply_markup' => json_encode(KB::rows([
                        ['🗑️ حذف شماره', 'edit:phone:remove'],
                        ['✏️ تغییر شماره', 'edit:phone:share'],
                        ['⬅️ انصراف', 'menu:profile'],
                    ]))]
                );
                return;
            }
            $this->promptPhoneShare($chatId);
            return;
        }
        if ($action === 'phone:share') {
            $this->promptPhoneShare($chatId);
            return;
        }
        if ($action === 'phone:remove') {
            $this->accounts->setPhone($telegramUserId, null);
            $this->client->sendMessage($chatId, '🗑️ شماره تلفن از ربات حذف شد. شماره روی پروفایل سایت شما دست‌نخورده باقی می‌ماند.');
            $this->sendProfile($chatId, $userId);
            return;
        }

        if ($action === 'avatar') {
            $this->states->set($telegramUserId, 'avatar_await_photo');
            $this->client->sendMessage($chatId, '🖼️ لطفاً تصویر پروفایل خود را ارسال کنید.', [
                'reply_markup' => json_encode(KB::rows([['⬅️ انصراف', 'menu:profile']])),
            ]);
            return;
        }

        if ($action === 'password') {
            $this->states->set($telegramUserId, 'password_current');
            $this->client->sendMessage($chatId, '🔐 برای تغییر رمز، ابتدا رمز عبور فعلی خود را وارد کنید:');
            return;
        }
    }

    private const PHONE_INTRO = "📱 <b>اشتراک‌گذاری شماره تلفن</b>\n\nاشتراک‌گذاری شماره تلفن کاملاً اختیاری است.\n"
        . 'در صورت تمایل می‌توانید شماره تلفن خود را برای تکمیل پروفایل HeleXa ثبت کنید.'
        . "\nهیچ اجباری برای ارسال شماره وجود ندارد.";

    private function promptPhoneShare(int $chatId): void
    {
        $this->client->sendMessage($chatId, self::PHONE_INTRO, [
            'reply_markup' => json_encode(KB::requestPhone()),
        ]);
    }

    private function handleNotificationAction(int $telegramUserId, int $chatId, string $action): void
    {
        // Reserved for a future "mark as read from Telegram" action.
    }
}

