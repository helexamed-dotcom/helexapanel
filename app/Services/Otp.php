<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Request;
use HeleXa\Core\Str;
use HeleXa\Models\OtpRepository;

/**
 * One-time codes sent by SMS.
 *
 * The rules that matter, and why each is here:
 *
 *  - Six digits is a 1-in-a-million guess, which is only safe because the
 *    number of guesses is capped. The attempt ceiling is the control; the
 *    length alone is not.
 *  - The code is stored as a keyed hash. A database dump therefore yields
 *    nothing presentable, and an offline search of the six-digit space still
 *    needs the application key, which is not in the database.
 *  - A code is spent the moment it is accepted, and retired when a newer one
 *    is issued, so exactly one code is ever live per number and purpose.
 *  - Three separate limits guard the send path: a cooldown between texts, a
 *    per-number hourly ceiling, and a per-address hourly ceiling. The first
 *    stops an impatient user, the second stops someone running up the SMS
 *    bill against one number, the third stops one host doing it across many.
 *
 * Everything here answers in Persian, because every caller renders the
 * message straight to a student.
 */
final class Otp
{
    public const PURPOSE_LOGIN    = 'login';
    public const PURPOSE_REGISTER = 'register';
    public const PURPOSE_RESET    = 'reset';

    /** How long a reset ticket outlives the code that produced it. */
    private const TICKET_TTL = 600;

    /* ------------------------------------------------------------ issuing */

    /**
     * Issues a code and texts it.
     *
     * @return array{ok:bool, code:string, message:string, retry_after:int, expires_in:int}
     */
    public static function issue(Request $request, string $phone, string $purpose): array
    {
        if (!Settings::bool('otp_enabled', true)) {
            return self::failure('OTP_DISABLED', 'ورود با کد پیامکی در حال حاضر غیرفعال است.');
        }

        $phone = Phone::normalize($phone);
        if ($phone === '') {
            return self::failure('INVALID_PHONE', 'شماره موبایل معتبر نیست. نمونه درست: ۰۹۱۲۳۴۵۶۷۸۹');
        }

        $guard = self::checkSendLimits($request, $phone, $purpose);
        if ($guard !== null) {
            return $guard;
        }

        $repository = new OtpRepository();
        $cooldown   = self::resendSeconds();

        // From here a code exists, so any earlier one must stop being valid:
        // two live codes would double an attacker's guessing budget.
        $repository->consumeLive($phone, $purpose);

        $code = self::generateCode();
        $ttl  = self::ttlSeconds();

        $id = $repository->create([
            'phone'        => $phone,
            'purpose'      => $purpose,
            'code_hash'    => self::hash($code),
            'max_attempts' => self::maxAttempts(),
            'expires_at'   => date('Y-m-d H:i:s', time() + $ttl),
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        $result = SmsGateway::send($phone, self::renderMessage($code));

        if (!$result['ok']) {
            // The code is retired rather than left live: the student never saw
            // it, and leaving it valid would also leave the cooldown running
            // against a text that does not exist.
            $repository->markConsumed($id);
            ActivityLogger::log('auth.otp_send_failed', null, 'otp', $id,
                ['phone' => Phone::mask($phone), 'reason' => $result['code']], 'warning', $request);

            return self::failure('SEND_FAILED', self::deliveryMessage($result['code']));
        }

        ActivityLogger::log('auth.otp_sent', null, 'otp', $id,
            ['phone' => Phone::mask($phone), 'purpose' => $purpose], 'info', $request);

        return [
            'ok'          => true,
            'code'        => 'OK',
            'message'     => 'کد تأیید پیامک شد.',
            'retry_after' => $cooldown,
            'expires_in'  => $ttl,
        ];
    }

    /**
     * What the student is told when the gateway refuses.
     *
     * A misconfigured panel is the operator's problem, not the student's, and
     * naming the gateway's internal complaint to them helps nobody — but
     * "try again later" when the real cause is an empty SMS balance would
     * have them retrying forever. So the two cases read differently without
     * either exposing configuration.
     */
    private static function deliveryMessage(string $gatewayCode): string
    {
        $configuration = ['SMS_DISABLED', 'SMS_NOT_CONFIGURED', 'CRYPTO_UNAVAILABLE', 'CURL_MISSING'];

        return in_array($gatewayCode, $configuration, true)
            ? 'ارسال پیامک در حال حاضر در دسترس نیست. با پشتیبانی تماس بگیر.'
            : 'ارسال پیامک ناموفق بود. چند لحظه دیگر دوباره تلاش کن.';
    }

    /**
     * The three limits that gate a send, in the order a caller meets them.
     *
     * Shared by issue() and reserve() so the forgotten-password form cannot
     * drift into behaving differently from the sign-in form — the moment they
     * differ, the difference is a way to tell registered numbers from
     * unregistered ones.
     *
     * @return array|null null when the send may proceed
     */
    private static function checkSendLimits(Request $request, string $phone, string $purpose): ?array
    {
        $repository = new OtpRepository();

        // Cooldown first: it is the limit a normal user actually meets, and
        // answering it precisely ("try again in 23 seconds") is the difference
        // between a usable form and a mysterious one.
        $cooldown = self::resendSeconds();
        $lastAt   = $repository->lastIssuedAt($phone, $purpose);
        if ($lastAt !== null) {
            $elapsed = time() - strtotime($lastAt);
            if ($elapsed < $cooldown) {
                $wait = $cooldown - $elapsed;
                return self::failure(
                    'COOLDOWN',
                    sprintf('برای ارسال دوباره کد، %s ثانیه صبر کن.', Jalali::digits((string) $wait)),
                    $wait
                );
            }
        }

        $hourAgo = date('Y-m-d H:i:s', time() - 3600);

        if ($repository->countSince($phone, $hourAgo) >= Settings::int('otp_max_per_hour', 5)) {
            ActivityLogger::log('auth.otp_phone_limit', null, 'otp', null,
                ['phone' => Phone::mask($phone)], 'warning', $request);
            return self::failure('PHONE_LIMIT', 'تعداد درخواست کد برای این شماره زیاد بوده است. یک ساعت دیگر تلاش کن.');
        }

        if ($repository->countForIpSince($request->ip(), $hourAgo) >= Settings::int('otp_max_per_ip_per_hour', 20)) {
            ActivityLogger::log('auth.otp_ip_limit', null, 'otp', null, [], 'warning', $request);
            return self::failure('IP_LIMIT', 'تعداد درخواست‌ها از این دستگاه زیاد بوده است. بعداً تلاش کن.');
        }

        return null;
    }

    /**
     * Runs every guard issue() runs, and records the attempt, but sends
     * nothing.
     *
     * This exists for the forgotten-password form, which must answer
     * identically whether or not the number belongs to an account. Returning
     * early for an unknown number would be its own disclosure: the known one
     * meets a cooldown on a second press and the unknown one never does, so
     * pressing twice would tell a stranger which numbers are registered.
     * Charging the unknown number the same cooldown and the same hourly
     * budget makes the two paths indistinguishable from outside.
     *
     * The row it writes is retired the moment it is written, so no code is
     * ever live for a number that was never texted.
     *
     * @return array{ok:bool, code:string, message:string, retry_after:int, expires_in:int}
     */
    public static function reserve(Request $request, string $phone, string $purpose): array
    {
        if (!Settings::bool('otp_enabled', true)) {
            return self::failure('OTP_DISABLED', 'ورود با کد پیامکی در حال حاضر غیرفعال است.');
        }

        $phone = Phone::normalize($phone);
        if ($phone === '') {
            return self::failure('INVALID_PHONE', 'شماره موبایل معتبر نیست. نمونه درست: ۰۹۱۲۳۴۵۶۷۸۹');
        }

        $guard = self::checkSendLimits($request, $phone, $purpose);
        if ($guard !== null) {
            return $guard;
        }

        $repository = new OtpRepository();
        $id = $repository->create([
            'phone'        => $phone,
            'purpose'      => $purpose,
            // A code that is retired before it can be read. The value is
            // random rather than fixed so the stored rows of reserved and
            // real attempts are not distinguishable either.
            'code_hash'    => self::hash(self::generateCode()),
            'max_attempts' => self::maxAttempts(),
            'expires_at'   => date('Y-m-d H:i:s', time() + self::ttlSeconds()),
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);
        $repository->markConsumed($id);

        return [
            'ok'          => true,
            'code'        => 'OK',
            'message'     => 'کد تأیید پیامک شد.',
            'retry_after' => self::resendSeconds(),
            'expires_in'  => self::ttlSeconds(),
        ];
    }

    /* ----------------------------------------------------------- verifying */

    /**
     * Checks a submitted code.
     *
     * @return array{ok:bool, code:string, message:string, phone:string, otp_id:int}
     */
    public static function verify(Request $request, string $phone, string $purpose, string $submitted): array
    {
        $phone = Phone::normalize($phone);
        if ($phone === '') {
            return self::verifyFailure('INVALID_PHONE', 'شماره موبایل معتبر نیست.');
        }

        $submitted = preg_replace('/\D/', '', Jalali::toLatinDigits($submitted)) ?? '';
        if ($submitted === '') {
            return self::verifyFailure('EMPTY_CODE', 'کد تأیید را وارد کن.');
        }

        $repository = new OtpRepository();
        $row        = $repository->findLive($phone, $purpose);

        // One message for "never issued", "already used" and "expired". Which
        // of the three it was is not something the form can act on, and
        // separating them would confirm to a stranger that a code is
        // outstanding for a number they do not own.
        if ($row === null) {
            return self::verifyFailure('NO_CODE', 'کد تأیید منقضی شده یا معتبر نیست. کد جدید بگیر.');
        }

        if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
            $repository->markConsumed((int) $row['id']);
            return self::verifyFailure('TOO_MANY', 'تعداد تلاش‌های نادرست زیاد بود. کد جدید بگیر.');
        }

        if (!hash_equals((string) $row['code_hash'], self::hash($submitted))) {
            $attempts  = $repository->registerAttempt((int) $row['id']);
            $remaining = max(0, (int) $row['max_attempts'] - $attempts);

            if ($remaining === 0) {
                $repository->markConsumed((int) $row['id']);
                ActivityLogger::log('auth.otp_exhausted', null, 'otp', (int) $row['id'],
                    ['phone' => Phone::mask($phone)], 'warning', $request);
                return self::verifyFailure('TOO_MANY', 'تعداد تلاش‌های نادرست زیاد بود. کد جدید بگیر.');
            }

            return self::verifyFailure('WRONG_CODE', sprintf(
                'کد وارد شده درست نیست. %s تلاش دیگر باقی مانده است.',
                Jalali::digits((string) $remaining)
            ));
        }

        // Spent immediately, so a replay of the same code finds it used.
        $repository->markUsed((int) $row['id']);

        return [
            'ok'      => true,
            'code'    => 'OK',
            'message' => '',
            'phone'   => $phone,
            'otp_id'  => (int) $row['id'],
        ];
    }

    /* ------------------------------------------------------------- tickets */

    /**
     * Mints the handle that carries a verified number into the next request.
     *
     * The reset form submits a new password separately from the code that
     * authorised it. If the browser were asked to say which number it is
     * resetting, anyone could say any number — so the server keeps that fact
     * itself and hands back an opaque reference to it.
     */
    public static function issueTicket(int $otpId): string
    {
        $ticket = Str::token(32);
        (new OtpRepository())->attachTicket(
            $otpId,
            Str::hash($ticket),
            date('Y-m-d H:i:s', time() + self::TICKET_TTL)
        );
        return $ticket;
    }

    /** @return string the verified phone number, or '' when the ticket is not usable */
    public static function redeemTicket(string $ticket, string $purpose): string
    {
        if ($ticket === '') {
            return '';
        }

        $repository = new OtpRepository();
        $row        = $repository->findByTicket(Str::hash($ticket), $purpose);

        if ($row === null) {
            return '';
        }

        // One use only: a reset ticket left alive after the password changed
        // would be a second key to the account.
        $repository->clearTicket((int) $row['id']);

        return (string) $row['phone'];
    }

    /* ------------------------------------------------------------- helpers */

    public static function ttlSeconds(): int
    {
        return max(30, min(900, Settings::int('otp_ttl_seconds', 120)));
    }

    public static function resendSeconds(): int
    {
        return max(15, min(600, Settings::int('otp_resend_seconds', 60)));
    }

    public static function maxAttempts(): int
    {
        return max(1, min(10, Settings::int('otp_max_attempts', 5)));
    }

    public static function isEnabled(): bool
    {
        return Settings::bool('otp_enabled', true) && SmsSettings::isOperational();
    }

    public static function registrationOpen(): bool
    {
        return self::isEnabled() && Settings::bool('otp_registration_enabled', true);
    }

    /**
     * Codes never start with a zero.
     *
     * A leading zero survives the round trip only if every layer treats the
     * code as a string; one numeric cast anywhere turns "012345" into 12345
     * and the comparison fails for reasons nobody can reproduce. Excluding
     * them costs a tenth of the space and removes the whole class of bug.
     */
    private static function generateCode(): string
    {
        return (string) random_int(100000, 999999);
    }

    /** Keyed, so the stored digest cannot be searched without the app key. */
    private static function hash(string $code): string
    {
        return Str::hmac('otp:' . $code);
    }

    private static function renderMessage(string $code): string
    {
        $template = (string) Settings::get('otp_message_template', 'کد ورود شما: {code}');
        if (!str_contains($template, '{code}')) {
            // A template with no placeholder would send a text with no code in
            // it. Appending is better than silently sending something useless.
            $template .= ' {code}';
        }
        return str_replace('{code}', $code, $template);
    }

    private static function failure(string $code, string $message, int $retryAfter = 0): array
    {
        return [
            'ok'          => false,
            'code'        => $code,
            'message'     => $message,
            'retry_after' => $retryAfter,
            'expires_in'  => 0,
        ];
    }

    private static function verifyFailure(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'phone' => '', 'otp_id' => 0];
    }
}
