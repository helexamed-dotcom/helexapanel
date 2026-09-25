<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Core\Validator;
use HeleXa\Models\PermissionRepository;
use HeleXa\Models\TelegramRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;
use HeleXa\Services\PackageAccess;
use HeleXa\Services\Phone;
use HeleXa\Services\Telegram\Bot;
use HeleXa\Services\Telegram\TelegramAuth;

/**
 * The web side of the Telegram sign-in: the bot's webhook, and the page a
 * one-time link opens — choose a username and a password (a new account),
 * or a new password (an account that already has this number).
 */
final class TelegramAuthController extends Controller
{
    private TelegramRepository $tg;

    public function __construct()
    {
        $this->tg = new TelegramRepository();
    }

    /**
     * POST /telegram/webhook — updates from Telegram. Authenticated by the
     * secret Telegram repeats in a header (set when the webhook is set), not
     * by a session or a CSRF token; answered 200 whatever happens, so
     * Telegram does not retry a message that was handled.
     */
    public function webhook(Request $request, array $params = []): Response
    {
        if (!Bot::enabled() || !TelegramRepository::ready()) {
            return $this->json(['ok' => false], 404);
        }
        $secret = Bot::secret();
        $given = (string) ($request->header('X-Telegram-Bot-Api-Secret-Token') ?? '');
        if ($secret === '' || !hash_equals($secret, $given)) {
            return $this->json(['ok' => false], 403);
        }
        $update = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($update)) {
            try {
                (new TelegramAuth())->handle($update);
            } catch (\Throwable $e) {
                error_log('[telegram] ' . $e->getMessage());
            }
        }
        return $this->json(['ok' => true]);
    }

    /** GET /auth/telegram/{token} */
    public function show(Request $request, array $params = []): Response
    {
        $link = $this->link((string) ($params['token'] ?? ''));
        if ($link === null) {
            return $this->page('layouts.auth', 'auth.telegram', ['title' => 'لینک نامعتبر', 'link' => null, 'errors' => [], 'old' => [], 'botLink' => $this->botLink()], 410);
        }
        return $this->page('layouts.auth', 'auth.telegram', $this->viewData($link, [], [], (string) ($params['token'] ?? '')));
    }

    /** POST /auth/telegram/{token} */
    public function store(Request $request, array $params = []): Response
    {
        $secret = (string) ($params['token'] ?? '');
        $link = $this->link($secret);
        if ($link === null) {
            $this->flash('error', 'این لینک منقضی یا استفاده شده است. از ربات لینک تازه بگیر.');
            return $this->redirect('/login');
        }
        $users = new UserRepository();
        $isNew = $link['purpose'] === 'register';
        $existing = $isNew ? null : $users->findById((int) $link['user_id']);
        if (!$isNew && ($existing === null || ($existing['mobile'] ?? '') !== $link['phone'] || ($existing['role_slug'] ?? '') !== 'student')) {
            $this->flash('error', 'حساب این لینک تغییر کرده است. از ربات لینک تازه بگیر.');
            return $this->redirect('/login');
        }

        $data = [
            'full_name'        => trim(mb_substr($request->string('full_name'), 0, 191)),
            'username'         => strtolower(trim(Jalali::toLatinDigits($request->string('username')))),
            'password'         => (string) $request->input('password', ''),
            'password_confirm' => (string) $request->input('password_confirm', ''),
        ];
        $v = (new Validator($data))
            ->required('password', 'رمز عبور')
            ->password('password', 'رمز عبور')
            ->matches('password_confirm', 'password', 'تکرار رمز عبور با خود رمز یکسان نیست.');
        if ($isNew) {
            $v->required('full_name', 'نام و نام خانوادگی')->length('full_name', 'نام و نام خانوادگی', 3, 191)->required('username', 'نام کاربری');
        }
        if ($data['username'] !== '') {
            $v->username('username', 'نام کاربری');
        }
        $errors = $v->errors();
        if (!isset($errors['username']) && $data['username'] !== '') {
            if (preg_match('/^09\d{9}$/', $data['username']) === 1 && $data['username'] !== $link['phone']) {
                $errors['username'] = 'نام کاربری نمی‌تواند شماره موبایل کس دیگری باشد.';
            } elseif ($users->usernameExists($data['username'], $existing !== null ? (int) $existing['id'] : null)) {
                $errors['username'] = 'این نام کاربری را کس دیگری برداشته است؛ یکی دیگر انتخاب کن.';
            }
        }
        if ($isNew && $users->findByMobile((string) $link['phone']) !== null) {
            $this->flash('error', 'با این شماره همین حالا حساب ساخته شده است. وارد شو.');
            return $this->redirect('/login');
        }
        if ($errors !== []) {
            unset($data['password'], $data['password_confirm']);
            return $this->page('layouts.auth', 'auth.telegram', $this->viewData($link, $errors, $data, $secret), 422);
        }

        // Spend the link first: two tabs posting at once create one account.
        if (!$this->tg->spend((int) $link['id'])) {
            $this->flash('error', 'این لینک همین حالا استفاده شد.');
            return $this->redirect('/login');
        }

        if ($isNew) {
            $role = (new PermissionRepository())->roleBySlug('student');
            if ($role === null) {
                throw HttpException::notFound();
            }
            $id = $users->create([
                'uuid'                 => Str::uuid4(),
                'role_id'              => (int) $role['id'],
                'username'             => $data['username'],
                'mobile'               => $link['phone'],
                'phone_verified_at'    => date('Y-m-d H:i:s'),
                'password_hash'        => Auth::hashPassword($data['password']),
                'must_change_password' => 0,
                'full_name'            => $data['full_name'],
                'gender'               => in_array($request->string('gender'), ['male', 'female'], true) ? $request->string('gender') : null,
                'status'               => 'active',
                'registration_source'  => 'telegram',
            ]);
            $this->tg->link((int) $link['tg_user_id'], $id);
            ActivityLogger::log('student.telegram_registered', $id, 'user', $id, ['mobile' => Phone::mask((string) $link['phone'])], 'notice', $request);
            $user = $users->findById($id);
            if ($user !== null) {
                PackageAccess::grantFree($user, null);
                $typeId = $request->int('student_type_id');
                if ($typeId > 0) {
                    try {
                        \HeleXa\Services\StudentTypes::request($id, $typeId, '');
                    } catch (\Throwable $e) {
                        error_log('[telegram type] ' . $e->getMessage());
                    }
                }
            }
            Bot::send((int) $link['chat_id'], "🎉 ثبت‌نام کامل شد!\nاز این به بعد با شماره <b>" . Bot::h((string) $link['phone'])
                . '</b> یا نام کاربری <b>' . Bot::h($data['username']) . '</b> و رمزت وارد سایت شو.', ['remove_keyboard' => true]);
            Bot::send((int) $link['chat_id'], '👇', Bot::menuButtons());
        } else {
            $id = (int) $existing['id'];
            $users->updatePassword($id, Auth::hashPassword($data['password']));
            if ($data['username'] !== '' && $data['username'] !== $existing['username']) {
                Database::execute('UPDATE users SET username = :u, updated_at = :now WHERE id = :id',
                    ['u' => $data['username'], 'now' => date('Y-m-d H:i:s'), 'id' => $id]);
            }
            // A new password ends every «remember me» elsewhere.
            Auth::revokeRememberTokens($id, 'password_change');
            ActivityLogger::log('user.telegram_password_reset', $id, 'user', $id, [], 'notice', $request);
            Bot::send((int) $link['chat_id'], "🔑 رمز تازه‌ات ثبت شد. اگر این کار را خودت نکرده‌ای، فوراً به پشتیبانی خبر بده.",
                Bot::menuButtons());
            $data['username'] = $data['username'] !== '' ? $data['username'] : (string) $existing['username'];
        }

        $result = Auth::attempt($request, (string) $link['phone'], $data['password'], true);
        if ($result['ok']) {
            $this->flash('success', $isNew ? 'خوش آمدی! حسابت ساخته شد 🎉' : 'رمز تازه ثبت شد و وارد شدی.');
            return $this->redirect('/student');
        }
        $this->flash('success', 'انجام شد. حالا با شماره موبایل یا نام کاربری و رمزت وارد شو.');
        return $this->redirect('/login');
    }

    /* ======================================================== internals */

    private function link(string $secret): ?array
    {
        return TelegramRepository::ready() ? $this->tg->findValid($secret) : null;
    }

    private function botLink(): ?string
    {
        return Bot::enabled() && Bot::username() !== '' ? Bot::startLink() : null;
    }

    private function viewData(array $link, array $errors, array $old, string $secret): array
    {
        $suggest = '';
        $tgName = strtolower(preg_replace('/[^a-z0-9_.]/i', '', (string) ($link['tg_username'] ?? '')) ?? '');
        if ($link['purpose'] === 'register') {
            $suggest = strlen($tgName) >= 3 && !(new UserRepository())->usernameExists($tgName) ? $tgName : '';
        }
        $current = null;
        if ($link['purpose'] === 'reset' && $link['user_id'] !== null) {
            $u = (new UserRepository())->findById((int) $link['user_id']);
            $current = $u['username'] ?? null;
        }
        return [
            'title'        => $link['purpose'] === 'register' ? 'تکمیل ثبت‌نام' : 'تعیین رمز تازه',
            'link'         => $link,
            'secret'       => $secret,
            'phone'        => (string) $link['phone'],
            'suggest'      => $suggest,
            'current'      => $current,
            'errors'       => $errors,
            'old'          => $old + ['full_name' => (string) ($link['first_name'] ?? '')],
            'studentTypes' => $link['purpose'] === 'register' ? \HeleXa\Services\StudentTypes::all(true) : [],
            'botLink'      => $this->botLink(),
        ];
    }
}
