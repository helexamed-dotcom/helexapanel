<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Request;
use HeleXa\Core\Str;
use HeleXa\Models\PermissionRepository;
use HeleXa\Models\UserRepository;

/**
 * Turning a verified phone number into an account.
 *
 * Self-registration is a real change to who can get in, so it is deliberately
 * narrow: the account it creates is always a student, always active, and
 * carries nothing an admin did not intend to grant. Course access, packages
 * and the Balin island are all granted separately and none of them are
 * implied by having an account.
 *
 * The operator can close this door entirely from the panel
 * (otp_registration_enabled); with it shut, a texted code signs in existing
 * students and nothing else.
 */
final class PhoneRegistration
{
    /**
     * Finds the account for a verified number, creating one if the operator
     * allows it.
     *
     * @return array{ok:bool, code:string, message:string, user:?array, created:bool}
     */
    public static function resolve(Request $request, string $phone): array
    {
        $users = new UserRepository();
        $user  = $users->findByMobile($phone);

        if ($user !== null) {
            // The code proved they hold the SIM, which is exactly what the
            // verified flag records — including for an account an admin
            // created by hand and never verified.
            if ($user['phone_verified_at'] === null) {
                $users->markPhoneVerified((int) $user['id']);
                $user = $users->findById((int) $user['id']);
            }
            return ['ok' => true, 'code' => 'OK', 'message' => '', 'user' => $user, 'created' => false];
        }

        if (!Settings::bool('otp_registration_enabled', true)) {
            return [
                'ok'      => false,
                'code'    => 'REGISTRATION_CLOSED',
                'message' => 'حسابی با این شماره وجود ندارد. برای ثبت‌نام با پشتیبانی تماس بگیر.',
                'user'    => null,
                'created' => false,
            ];
        }

        return self::create($request, $phone);
    }

    /** @return array{ok:bool, code:string, message:string, user:?array, created:bool} */
    private static function create(Request $request, string $phone): array
    {
        $users = new UserRepository();
        $role  = (new PermissionRepository())->roleBySlug('student');

        if ($role === null) {
            return [
                'ok'      => false,
                'code'    => 'ROLE_MISSING',
                'message' => 'ثبت‌نام در حال حاضر ممکن نیست. با پشتیبانی تماس بگیر.',
                'user'    => null,
                'created' => false,
            ];
        }

        $now = date('Y-m-d H:i:s');

        try {
            $id = $users->create([
                'uuid'                => Str::uuid4(),
                'role_id'             => (int) $role['id'],
                'username'            => self::username($users, $phone),
                'mobile'              => $phone,
                'phone_verified_at'   => $now,
                // No password at all, rather than a random one nobody holds.
                // The account is reachable by texted code until its owner
                // chooses a password in their profile.
                'password_hash'       => null,
                'full_name'           => $phone,
                'status'              => 'active',
                'registration_source' => 'self_otp',
            ]);
        } catch (\Throwable $e) {
            // The likely cause is two verifications for one number racing:
            // both found no account, both tried to insert, and the UNIQUE
            // index on mobile stopped the second. The account the first one
            // made is the right answer, so look again before giving up.
            $existing = $users->findByMobile($phone);
            if ($existing !== null) {
                return ['ok' => true, 'code' => 'OK', 'message' => '', 'user' => $existing, 'created' => false];
            }

            \HeleXa\Core\Logger::error('Self-registration failed', ['error' => $e->getMessage()]);
            return [
                'ok'      => false,
                'code'    => 'CREATE_FAILED',
                'message' => 'ساخت حساب ناموفق بود. چند لحظه دیگر دوباره تلاش کن.',
                'user'    => null,
                'created' => false,
            ];
        }

        ActivityLogger::log('auth.self_registered', $id, 'user', $id,
            ['phone' => Phone::mask($phone)], 'notice', $request);

        return [
            'ok'      => true,
            'code'    => 'OK',
            'message' => '',
            'user'    => $users->findById($id),
            'created' => true,
        ];
    }

    /**
     * A username derived from the number.
     *
     * users.username is UNIQUE and NOT NULL, so every account needs one even
     * when nobody will ever type it. The number itself is the obvious choice
     * and is already unique; the suffix loop only matters if an admin had
     * previously created an unrelated account literally named after this
     * number.
     */
    private static function username(UserRepository $users, string $phone): string
    {
        if (!$users->usernameExists($phone)) {
            return $phone;
        }
        for ($i = 2; $i <= 50; $i++) {
            $candidate = $phone . '-' . $i;
            if (!$users->usernameExists($candidate)) {
                return $candidate;
            }
        }
        return $phone . '-' . bin2hex(random_bytes(3));
    }
}
