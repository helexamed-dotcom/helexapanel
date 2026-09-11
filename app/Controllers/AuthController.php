<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Config;
use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Validator;
use HeleXa\Models\SessionRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Otp;
use HeleXa\Services\Phone;
use HeleXa\Services\PhoneRegistration;

/**
 * Sign-in, by either of the two routes a student may take: a password they
 * chose, or a code texted to their number.
 *
 * The OTP endpoints answer JSON because the login page drives them without
 * navigating — a page reload between "send me a code" and "here it is" would
 * lose the countdown and the typed number. The password form stays an
 * ordinary POST-and-render, so sign-in still works with JavaScript off.
 */
final class AuthController extends Controller
{
    /* ------------------------------------------------------------- pages */

    public function showLogin(Request $request, array $params = []): Response
    {
        return $this->page('layouts.auth', 'auth.login', $this->loginViewData());
    }

    /** @param array<string,string> $errors */
    private function loginViewData(array $errors = [], array $old = []): array
    {
        return [
            'title'  => 'ورود یا ثبت‌نام',
            'errors' => $errors,
            'old'    => array_merge(['identifier' => '', 'remember' => true], $old),
            // The SMS tab is only offered when a code could actually be sent.
            // Showing it while the gateway is unconfigured would be a button
            // that always fails.
            'otpEnabled'      => Otp::isEnabled(),
            'otpResend'       => Otp::resendSeconds(),
            'otpTtl'          => Otp::ttlSeconds(),
            'registrationOpen'=> Otp::registrationOpen(),
        ];
    }

    /* -------------------------------------------------- password sign-in */

    public function login(Request $request, array $params = []): Response
    {
        $identifier = $request->string('identifier');
        $password   = (string) $request->input('password', '');

        $validator = (new Validator(['identifier' => $identifier, 'password' => $password]))
            ->required('identifier', 'شماره موبایل یا نام کاربری')
            ->required('password', 'رمز عبور');

        if ($validator->fails()) {
            return $this->page('layouts.auth', 'auth.login',
                $this->loginViewData($validator->errors(), [
                    'identifier' => $identifier,
                    'remember'   => $request->bool('remember'),
                ]), 422);
        }

        // A number typed as +98…, with Persian digits or with dashes is the
        // same account as the stored 09… form, so it is normalised before the
        // lookup. Anything that is not a phone number is passed through
        // untouched and matched as a username.
        $normalized = Phone::normalize($identifier);
        $lookup     = $normalized !== '' ? $normalized : $identifier;

        $result = Auth::attempt($request, $lookup, $password, $request->bool('remember'));

        if (!$result['ok']) {
            return $this->page('layouts.auth', 'auth.login',
                $this->loginViewData(['identifier' => $result['message']], [
                    'identifier' => $identifier,
                    'remember'   => $request->bool('remember'),
                ]), 401);
        }

        return $this->afterLogin($result['user']);
    }

    public function logout(Request $request, array $params = []): Response
    {
        Auth::logout($request);
        return $this->redirect('/login');
    }

    /* ------------------------------------------------------ OTP sign-in */

    /**
     * Sends a code.
     *
     * Answers the same way whether or not the number belongs to an account.
     * Saying "no such user" here would turn the form into a way to test which
     * numbers are registered, and with self-registration on it would not even
     * be true — the account is created once the code is verified.
     */
    public function requestOtp(Request $request, array $params = []): Response
    {
        if (!Otp::isEnabled()) {
            return $this->json(['ok' => false, 'message' => 'ورود با کد پیامکی در حال حاضر در دسترس نیست.'], 503);
        }

        $phone = Phone::normalize($request->string('phone'));
        if ($phone === '') {
            return $this->json(['ok' => false, 'message' => 'شماره موبایل معتبر نیست. نمونه درست: ۰۹۱۲۳۴۵۶۷۸۹'], 422);
        }

        // With registration closed, a number nobody holds an account for must
        // not be texted at all: the code would be useless and the SMS bill
        // real.
        if (!Otp::registrationOpen() && (new UserRepository())->findByMobile($phone) === null) {
            return $this->json([
                'ok'      => false,
                'message' => 'حسابی با این شماره وجود ندارد. برای ثبت‌نام با پشتیبانی تماس بگیر.',
            ], 404);
        }

        $result = Otp::issue($request, $phone, Otp::PURPOSE_LOGIN);

        return $this->otpIssueResponse($result);
    }

    /**
     * Checks the code, then signs the student in — creating their account
     * first if this is the number's first visit and the operator allows it.
     */
    public function verifyOtp(Request $request, array $params = []): Response
    {
        $phone  = $request->string('phone');
        $code   = $request->string('code');
        $result = Otp::verify($request, $phone, Otp::PURPOSE_LOGIN, $code);

        if (!$result['ok']) {
            return $this->json(['ok' => false, 'message' => $result['message']], 422);
        }

        $resolved = PhoneRegistration::resolve($request, $result['phone']);
        if (!$resolved['ok']) {
            return $this->json(['ok' => false, 'message' => $resolved['message']], 403);
        }

        $login = Auth::loginVerified($request, $resolved['user'], $request->bool('remember'));
        if (!$login['ok']) {
            return $this->json(['ok' => false, 'message' => $login['message']], 403);
        }

        return $this->json([
            'ok'       => true,
            'redirect' => $this->destinationFor($login['user']),
        ]);
    }

    /* ------------------------------------------------- forgotten password */

    public function showForgotPassword(Request $request, array $params = []): Response
    {
        return $this->page('layouts.auth', 'auth.forgot', [
            'title'      => 'بازیابی رمز عبور',
            'otpEnabled' => Otp::isEnabled(),
            'otpResend'  => Otp::resendSeconds(),
            'otpTtl'     => Otp::ttlSeconds(),
            'minLength'  => (int) Config::get('security.password.min_length', 10),
        ]);
    }

    /**
     * Sends a reset code.
     *
     * Unlike the sign-in code, this one is only ever sent to a number that
     * already has an account — but the reply does not say so. A reset form
     * that answers differently for known and unknown numbers is a membership
     * oracle, and this one is reachable without signing in.
     */
    public function forgotPassword(Request $request, array $params = []): Response
    {
        if (!Otp::isEnabled()) {
            return $this->json(['ok' => false, 'message' => 'بازیابی رمز با پیامک در حال حاضر در دسترس نیست.'], 503);
        }

        $phone = Phone::normalize($request->string('phone'));
        if ($phone === '') {
            return $this->json(['ok' => false, 'message' => 'شماره موبایل معتبر نیست.'], 422);
        }

        $user = (new UserRepository())->findByMobile($phone);

        // Nothing is sent for a number with no account, but the same limits
        // are charged and the same answer is returned — including the
        // cooldown. Returning early here would mean an unknown number never
        // meets a cooldown while a known one does on the second press, and
        // pressing twice would then tell a stranger which numbers are
        // registered.
        $result = $user === null
            ? Otp::reserve($request, $phone, Otp::PURPOSE_RESET)
            : Otp::issue($request, $phone, Otp::PURPOSE_RESET);

        if ($user === null) {
            ActivityLogger::log('auth.reset_unknown_phone', null, 'user', null,
                ['phone' => Phone::mask($phone)], 'notice', $request);
        }

        /**
         * A delivery failure is reported as the neutral answer too.
         *
         * Only the real send can fail, so surfacing it here would mean a
         * registered number answers 503 while an unregistered one answers
         * 200 — the same disclosure the cooldown sharing above exists to
         * prevent, reopened the moment the gateway has a bad minute.
         *
         * Nothing is hidden from the people who can act on it: the failure
         * is already in the activity log with its reason, and the admin's
         * test-send page diagnoses it directly. The message below tells the
         * student what to do when no text arrives, which is the only part
         * of it they could act on anyway. The sign-in form still reports
         * delivery failures precisely, because no membership secret is at
         * stake there.
         */
        if ($result['ok'] || $result['code'] === 'SEND_FAILED') {
            return $this->json([
                'ok'          => true,
                'message'     => 'اگر این شماره در سامانه ثبت شده باشد، کد بازیابی پیامک می‌شود. '
                               . 'اگر تا یک دقیقه پیامکی دریافت نکردی، دوباره تلاش کن یا با پشتیبانی تماس بگیر.',
                'retry_after' => $result['retry_after'] > 0 ? $result['retry_after'] : Otp::resendSeconds(),
                'expires_in'  => $result['expires_in'] > 0 ? $result['expires_in'] : Otp::ttlSeconds(),
                'code_length' => $result['code_length'],
            ]);
        }

        return $this->otpIssueResponse($result);
    }

    /**
     * Checks the reset code and hands back a ticket.
     *
     * The new password arrives in a separate request, and that request must
     * not be trusted to name the account it is changing. The ticket is the
     * server's own note that this number proved itself; the browser only
     * carries an opaque reference to it.
     */
    public function verifyResetOtp(Request $request, array $params = []): Response
    {
        $result = Otp::verify($request, $request->string('phone'), Otp::PURPOSE_RESET, $request->string('code'));

        if (!$result['ok']) {
            return $this->json(['ok' => false, 'message' => $result['message']], 422);
        }

        return $this->json([
            'ok'     => true,
            'ticket' => Otp::issueTicket($result['otp_id']),
        ]);
    }

    /** Sets the new password against a ticket, and ends every other session. */
    public function resetPassword(Request $request, array $params = []): Response
    {
        $phone = Otp::redeemTicket($request->string('ticket'), Otp::PURPOSE_RESET);

        if ($phone === '') {
            return $this->json([
                'ok'      => false,
                'message' => 'اعتبار این درخواست تمام شده است. از ابتدا کد بازیابی بگیر.',
            ], 422);
        }

        $password = (string) $request->input('password', '');
        $confirm  = (string) $request->input('password_confirmation', '');

        $users = new UserRepository();
        $user  = $users->findByMobile($phone);

        if ($user === null) {
            return $this->json(['ok' => false, 'message' => 'حساب مرتبط با این شماره یافت نشد.'], 404);
        }

        $minLength = $user['role_slug'] === 'student'
            ? (int) Config::get('security.password.min_length', 10)
            : (int) Config::get('security.password.admin_min_length', 12);

        $validator = (new Validator([
            'password'              => $password,
            'password_confirmation' => $confirm,
        ]))
            ->password('password', 'رمز عبور جدید', $minLength)
            ->matches('password_confirmation', 'password', 'تکرار رمز عبور مطابقت ندارد.');

        if ($validator->fails()) {
            return $this->json([
                'ok'      => false,
                'message' => implode(' ', $validator->errors()),
            ], 422);
        }

        $users->updatePassword((int) $user['id'], Auth::hashPassword($password));

        // Whoever knew the old password — including whoever the student is
        // resetting because of — loses every session they were holding.
        (new SessionRepository())->terminateAllForUser((int) $user['id'], 'password_change');
        Auth::revokeRememberTokens((int) $user['id'], 'password_change');

        // The number proved itself, which is the same proof registration uses.
        if ($user['phone_verified_at'] === null) {
            $users->markPhoneVerified((int) $user['id']);
        }

        ActivityLogger::log('auth.password_reset', (int) $user['id'], 'user', (int) $user['id'],
            ['phone' => Phone::mask($phone)], 'critical', $request);

        return $this->json([
            'ok'      => true,
            'message' => 'رمز عبور تغییر کرد. حالا می‌توانی با رمز جدید وارد شوی.',
        ]);
    }

    /* ------------------------------------------------------------ helpers */

    private function otpIssueResponse(array $result): Response
    {
        if (!$result['ok']) {
            $status = match ($result['code']) {
                'COOLDOWN', 'PHONE_LIMIT', 'IP_LIMIT' => 429,
                'OTP_DISABLED', 'SEND_FAILED'         => 503,
                default                               => 422,
            };

            return $this->json([
                'ok'          => false,
                'message'     => $result['message'],
                'retry_after' => $result['retry_after'],
            ], $status);
        }

        return $this->json([
            'ok'          => true,
            'message'     => $result['message'],
            'retry_after' => $result['retry_after'],
            'expires_in'  => $result['expires_in'],
            // The page sizes its code box from this. It reveals a length, not
            // a value, and the student is holding the text anyway.
            'code_length' => $result['code_length'],
        ]);
    }

    private function afterLogin(array $user): Response
    {
        if ((int) $user['must_change_password'] === 1) {
            return $this->redirect('/account/password');
        }
        return $this->redirect($this->destinationFor($user));
    }

    private function destinationFor(array $user): string
    {
        if ((int) $user['must_change_password'] === 1) {
            return '/account/password';
        }
        return $user['role_slug'] === 'student' ? '/student' : '/admin';
    }
}
