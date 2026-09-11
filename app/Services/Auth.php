<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Config;
use HeleXa\Core\Csrf;
use HeleXa\Core\Logger;
use HeleXa\Core\Request;
use HeleXa\Core\Str;
use HeleXa\Models\PermissionRepository;
use HeleXa\Models\RememberTokenRepository;
use HeleXa\Models\SessionRepository;
use HeleXa\Models\UserRepository;

/**
 * Authentication, session binding and authorization.
 *
 * Design rules:
 *  - The browser holds nothing but an opaque PHP session id plus a rotating token.
 *  - Every request re-validates the session row server-side. A cookie alone is
 *    never sufficient: the row must be active, unexpired and bound to this device.
 *  - Authorization is answered from the database, never from anything the client sends.
 */
final class Auth
{
    private const SESSION_KEY = '_helexa_auth';

    /** Dummy hash used to keep timing constant when the account does not exist. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZGVhZGJlZWZkZWFkYmVlZg$Nn0dV6PmnqLzTG3xwl0hkQ5rLbFhqU1G1Z3rXgQZQ0k';

    private static ?array $user        = null;
    private static ?array $permissions = null;

    // ---------------------------------------------------------------- login

    /**
     * @return array{ok:bool, code:string, message:string, user:?array}
     */
    public static function attempt(Request $request, string $identifier, string $password, bool $remember = false): array
    {
        $users    = new UserRepository();
        $sessions = new SessionRepository();
        $limiter  = new RateLimiter();

        $ip = $request->ip();
        $ua = $request->userAgent();

        // Stale rows must be cleared first, otherwise a browser that was simply
        // closed would keep a student locked out under the single-device policy.
        $sessions->sweepStale(
            Settings::int('session_idle_timeout', 1800),
            Settings::int('session_absolute_timeout', 7200)
        );

        if ($limiter->isBlocked($ip, $identifier)) {
            $limiter->record($identifier, null, $ip, $ua, false, 'rate_limited');
            return self::failure('RATE_LIMITED', 'به دلیل تلاش‌های ناموفق زیاد، ورود موقتاً مسدود شده است. کمی بعد دوباره تلاش کنید.');
        }

        $user = $users->findByIdentifier($identifier);

        // Always run a verification so response time does not reveal account
        // existence. An account with no password set — one that has only ever
        // signed in with a texted code — falls back to the dummy hash too, so
        // it fails here exactly like a wrong password rather than being
        // compared against NULL.
        $hasPassword = is_array($user) && is_string($user['password_hash']) && $user['password_hash'] !== '';
        $hash        = $hasPassword ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $verified    = password_verify($password, $hash) && $hasPassword;

        if (!is_array($user) || !$verified) {
            if (is_array($user)) {
                $users->registerFailedLogin(
                    (int) $user['id'],
                    Settings::int('login_max_attempts', 5),
                    Settings::int('login_lockout_seconds', 900)
                );
            }
            $limiter->record($identifier, is_array($user) ? (int) $user['id'] : null, $ip, $ua, false, 'bad_credentials');
            ActivityLogger::log('auth.login_failed', is_array($user) ? (int) $user['id'] : null, 'user', null,
                ['identifier' => $identifier], 'notice', $request);

            // Identical message for wrong user and wrong password.
            return self::failure('INVALID_CREDENTIALS', 'نام کاربری یا رمز عبور نادرست است.');
        }

        if ($users->isLocked($user)) {
            $limiter->record($identifier, (int) $user['id'], $ip, $ua, false, 'locked');
            return self::failure('LOCKED', 'حساب شما موقتاً قفل شده است. لطفاً بعداً تلاش کنید.');
        }

        if ($user['status'] !== 'active') {
            $limiter->record($identifier, (int) $user['id'], $ip, $ua, false, $user['status'] === 'suspended' ? 'suspended' : 'inactive');
            return self::failure('INACTIVE', 'حساب کاربری شما فعال نیست. با پشتیبانی تماس بگیرید.');
        }

        // Rehash transparently if the cost parameters have been raised since signup.
        if ($hasPassword && password_needs_rehash($hash, PASSWORD_ARGON2ID, self::hashOptions())) {
            $users->updatePassword((int) $user['id'], self::hashPassword($password));
        }

        $deviceHash = DeviceDetector::fingerprint($ua, $request->acceptLanguage());

        $single = self::enforceSingleDevice($user, $deviceHash, $sessions, $request);
        if ($single !== null) {
            $limiter->record($identifier, (int) $user['id'], $ip, $ua, false, 'single_device');
            return $single;
        }

        self::establishSession($user, $deviceHash, $request, $sessions);

        if ($remember && Settings::bool('remember_me_enabled', true)) {
            self::issueRememberToken($user, $deviceHash, $request);
        }

        $users->registerSuccessfulLogin((int) $user['id'], $ip);
        $limiter->record($identifier, (int) $user['id'], $ip, $ua, true, null);
        ActivityLogger::log('auth.login', (int) $user['id'], 'user', (int) $user['id'],
            ['role' => $user['role_slug']], 'info', $request);

        return ['ok' => true, 'code' => 'OK', 'message' => '', 'user' => $user];
    }

    /**
     * Signs in someone whose identity was proved without a password — today,
     * by a code texted to their number.
     *
     * Everything the password path enforces after the credential check runs
     * here too: lockout, account status and the single-device policy. Only
     * the proof of identity differs, and a second way in must not also be a
     * way around the rules.
     *
     * @return array{ok:bool, code:string, message:string, user:?array}
     */
    public static function loginVerified(Request $request, array $user, bool $remember = false): array
    {
        $users    = new UserRepository();
        $sessions = new SessionRepository();

        $sessions->sweepStale(
            Settings::int('session_idle_timeout', 1800),
            Settings::int('session_absolute_timeout', 7200)
        );

        if ($users->isLocked($user)) {
            return self::failure('LOCKED', 'حساب شما موقتاً قفل شده است. لطفاً بعداً تلاش کنید.');
        }
        if ($user['status'] !== 'active') {
            return self::failure('INACTIVE', 'حساب کاربری شما فعال نیست. با پشتیبانی تماس بگیرید.');
        }

        $deviceHash = DeviceDetector::fingerprint($request->userAgent(), $request->acceptLanguage());

        $single = self::enforceSingleDevice($user, $deviceHash, $sessions, $request);
        if ($single !== null) {
            return $single;
        }

        self::establishSession($user, $deviceHash, $request, $sessions);

        if ($remember && Settings::bool('remember_me_enabled', true)) {
            self::issueRememberToken($user, $deviceHash, $request);
        }

        $users->registerSuccessfulLogin((int) $user['id'], $request->ip());
        ActivityLogger::log('auth.login_otp', (int) $user['id'], 'user', (int) $user['id'],
            ['role' => $user['role_slug']], 'info', $request);

        return ['ok' => true, 'code' => 'OK', 'message' => '', 'user' => $user];
    }

    /**
     * @return array{ok:bool, code:string, message:string, user:?array}|null null when the login may proceed
     */
    private static function enforceSingleDevice(array $user, string $deviceHash, SessionRepository $sessions, Request $request): ?array
    {
        if (!Settings::bool('single_device_enabled', true)) {
            return null;
        }
        // Admins are exempt unless the operator turns it on for them too.
        if ($user['role_slug'] !== 'student' && !Settings::bool('single_device_admins', false)) {
            return null;
        }

        $active = $sessions->activeForUser((int) $user['id']);
        if ($active === []) {
            return null;
        }

        // Same physical device reconnecting: replace the old row instead of blocking.
        $sameDevice = array_filter($active, static fn (array $row): bool => hash_equals((string) $row['device_hash'], $deviceHash));
        if (count($sameDevice) === count($active)) {
            foreach ($active as $row) {
                $sessions->terminate((int) $row['id'], 'new_device');
            }
            return null;
        }

        if (Settings::get('single_device_behavior', 'block_new') === 'force_logout_previous') {
            foreach ($active as $row) {
                $sessions->terminate((int) $row['id'], 'new_device');
            }
            ActivityLogger::log('auth.previous_sessions_forced_out', (int) $user['id'], 'user', (int) $user['id'],
                ['count' => count($active)], 'notice', $request);
            return null;
        }

        ActivityLogger::log('auth.single_device_block', (int) $user['id'], 'user', (int) $user['id'], [], 'notice', $request);
        return self::failure(
            'SINGLE_DEVICE',
            'این حساب در دستگاه دیگری فعال است. ابتدا از دستگاه قبلی خارج شوید یا با پشتیبانی تماس بگیرید.'
        );
    }

    private static function establishSession(array $user, string $deviceHash, Request $request, SessionRepository $sessions): void
    {
        // New session id on privilege change defeats session fixation.
        session_regenerate_id(true);
        Csrf::rotate();

        $rawToken = Str::token(32);

        /**
         * An empty session id is a broken PHP session, not a session id.
         *
         * session_id() returns '' when the session could not be started or
         * regenerated — an unwritable save path is the usual cause on shared
         * hosting. Writing that '' into a uniquely-indexed column poisoned the
         * whole table: the first such row inserted fine, and every later login
         * anywhere on the site then died with "Duplicate entry '' for key
         * uq_sessions_php_sid".
         *
         * NULL is the honest value, and a unique index tolerates any number of
         * them. The session simply will not validate afterwards, so the person
         * is asked to sign in again instead of meeting a 500.
         */
        $phpSessionId = session_id();
        if ($phpSessionId === '' || $phpSessionId === false) {
            $phpSessionId = null;
            Logger::error('Session started without an id; check that storage/sessions is writable.');
        }

        $sessionDbId = $sessions->create([
            'user_id'          => (int) $user['id'],
            'php_session_id'   => $phpSessionId,
            'token_hash'       => Str::hash($rawToken),
            'device_hash'      => $deviceHash,
            'ip_address'       => $request->ip(),
            'user_agent'       => $request->userAgent(),
            'browser'          => DeviceDetector::browser($request->userAgent()),
            'operating_system' => DeviceDetector::operatingSystem($request->userAgent()),
            'device_type'      => DeviceDetector::deviceType($request->userAgent()),
        ]);

        $_SESSION[self::SESSION_KEY] = [
            'user_id'     => (int) $user['id'],
            'session_id'  => $sessionDbId,
            'token'       => $rawToken,
            'device_hash' => $deviceHash,
            'role'        => $user['role_slug'],
            'issued_at'   => time(),
            'rotated_at'  => time(),
        ];
    }

    // ------------------------------------------------------- remember me

    private const REMEMBER_COOKIE = 'HLX_REMEMBER';

    /**
     * Issues a long-lived handle.
     *
     * The cookie carries "selector.validator". The selector is stored as-is so
     * the row can be found in one indexed lookup; only the validator's hash is
     * stored, so reading the table gives an attacker nothing presentable.
     */
    public static function issueRememberToken(array $user, string $deviceHash, Request $request): void
    {
        $days      = max(1, min(365, Settings::int('remember_me_days', 30)));
        $selector  = Str::token(16);
        $validator = Str::token(32);
        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));

        (new RememberTokenRepository())->create([
            'user_id'        => (int) $user['id'],
            'selector'       => $selector,
            'validator_hash' => Str::hash($validator),
            'device_hash'    => $deviceHash,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'expires_at'     => $expiresAt,
        ]);

        self::writeRememberCookie($selector . '.' . $validator, time() + ($days * 86400), $request);
    }

    /**
     * Restores a session from the cookie.
     *
     * Every check the login form performs is repeated here: account status,
     * lockout, the single-device policy. A remembered browser is a convenience,
     * not a way around any of those.
     */
    public static function attemptRemember(Request $request): ?array
    {
        if (!Settings::bool('remember_me_enabled', true)) {
            return null;
        }

        $raw = $request->cookie(self::REMEMBER_COOKIE);
        if (!is_string($raw) || substr_count($raw, '.') !== 1) {
            return null;
        }

        [$selector, $validator] = explode('.', $raw, 2);
        if (strlen($selector) !== 32 || strlen($validator) !== 64) {
            self::clearRememberCookie($request);
            return null;
        }

        $repository = new RememberTokenRepository();
        $row        = $repository->findUsable($selector);

        if ($row === null) {
            // Either the token expired or it was already revoked. Presenting a
            // revoked selector is worth noticing, but it is not proof of theft.
            self::clearRememberCookie($request);
            return null;
        }

        if (!hash_equals((string) $row['validator_hash'], Str::hash($validator))) {
            // The selector exists but the validator does not match. Because each
            // use rotates the validator, this means someone is replaying an old
            // copy of the cookie: revoke every token this account holds.
            $repository->revokeAllForUser((int) $row['user_id'], 'theft_suspected');
            // If a copy of the cookie is circulating, the live sessions it may
            // already have opened are suspect too. Ending them costs the real
            // owner one sign-in; leaving them costs them their account.
            $terminated = (new SessionRepository())
                ->terminateAllForUser((int) $row['user_id'], 'security');
            ActivityLogger::log('auth.remember_theft_suspected', (int) $row['user_id'], 'user',
                (int) $row['user_id'], ['selector' => $selector, 'sessions_terminated' => $terminated],
                'critical', $request);
            self::clearRememberCookie($request);
            return null;
        }

        $users = new UserRepository();
        $user  = $users->findById((int) $row['user_id']);

        if ($user === null || $user['status'] !== 'active' || $users->isLocked($user)) {
            $repository->revoke((int) $row['id'], 'admin');
            self::clearRememberCookie($request);
            return null;
        }

        $sessions   = new SessionRepository();
        $deviceHash = DeviceDetector::fingerprint($request->userAgent(), $request->acceptLanguage());

        $sessions->sweepStale(
            Settings::int('session_idle_timeout', 1800),
            Settings::int('session_absolute_timeout', 7200)
        );

        if (self::enforceSingleDevice($user, $deviceHash, $sessions, $request) !== null) {
            // Another device holds the single allowed session; the cookie stays
            // valid, the restore simply does not happen.
            return null;
        }

        self::establishSession($user, $deviceHash, $request, $sessions);

        // One-time use: the validator is replaced on every restore.
        $days      = max(1, min(365, Settings::int('remember_me_days', 30)));
        $validator = Str::token(32);
        $expiresAt = date('Y-m-d H:i:s', time() + ($days * 86400));

        $repository->rotate((int) $row['id'], Str::hash($validator), $expiresAt);
        self::writeRememberCookie($selector . '.' . $validator, time() + ($days * 86400), $request);

        $users->registerSuccessfulLogin((int) $user['id'], $request->ip());
        ActivityLogger::log('auth.remember_restored', (int) $user['id'], 'user', (int) $user['id'], [], 'info', $request);

        self::$user = $user;

        return $user;
    }

    public static function revokeRememberTokens(int $userId, string $reason): int
    {
        return (new RememberTokenRepository())->revokeAllForUser($userId, $reason);
    }

    private static function writeRememberCookie(string $value, int $expires, Request $request): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::REMEMBER_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $request->isSecure(),
            'httponly' => true,
            'samesite' => (string) Config::get('app.security.cookie_samesite', 'Lax'),
        ]);
    }

    private static function clearRememberCookie(Request $request): void
    {
        self::writeRememberCookie('', time() - 42000, $request);
    }

    // ----------------------------------------------------------- validation

    /**
     * Re-validates the session on every request.
     * Returns null when the caller must be treated as anonymous.
     */
    public static function validate(Request $request): ?array
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($state) || !isset($state['user_id'], $state['session_id'], $state['token'])) {
            return null;
        }

        $sessions = new SessionRepository();
        $row      = $sessions->findActiveByPhpSessionId(session_id());

        if ($row === null || (int) $row['id'] !== (int) $state['session_id'] || (int) $row['user_id'] !== (int) $state['user_id']) {
            self::forget();
            return null;
        }

        if (!hash_equals((string) $row['token_hash'], Str::hash((string) $state['token']))) {
            // Token mismatch means a stolen or replayed cookie; kill the row.
            $sessions->terminate((int) $row['id'], 'security');
            self::forget();
            return null;
        }

        $expectedDevice = DeviceDetector::fingerprint($request->userAgent(), $request->acceptLanguage());
        if (!hash_equals((string) $row['device_hash'], $expectedDevice)) {
            $sessions->terminate((int) $row['id'], 'security');
            ActivityLogger::log('auth.device_mismatch', (int) $row['user_id'], 'session', (int) $row['id'], [], 'warning', $request);
            self::forget();
            return null;
        }

        $isAdminArea  = ($state['role'] ?? 'student') !== 'student';
        $idleTimeout  = $isAdminArea
            ? Settings::int('admin_idle_timeout', 900)
            : Settings::int('session_idle_timeout', 1800);
        $absTimeout   = Settings::int('session_absolute_timeout', 7200);

        if (strtotime((string) $row['last_activity']) < time() - $idleTimeout) {
            $sessions->terminate((int) $row['id'], 'idle_timeout');
            self::forget();
            return null;
        }
        if (strtotime((string) $row['login_at']) < time() - $absTimeout) {
            $sessions->terminate((int) $row['id'], 'absolute_timeout');
            self::forget();
            return null;
        }

        $user = (new UserRepository())->findById((int) $row['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            $sessions->terminate((int) $row['id'], 'security');
            self::forget();
            return null;
        }

        // Throttled activity write, plus periodic token rotation.
        if (strtotime((string) $row['last_activity']) < time() - 30) {
            $sessions->touch((int) $row['id']);
        }
        if ((int) ($state['rotated_at'] ?? 0) < time() - 600) {
            $newToken = Str::token(32);
            $sessions->rotateToken((int) $row['id'], Str::hash($newToken));
            $_SESSION[self::SESSION_KEY]['token']      = $newToken;
            $_SESSION[self::SESSION_KEY]['rotated_at'] = time();
        }

        self::$user = $user;
        return $user;
    }

    // -------------------------------------------------------------- helpers

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function id(): ?int
    {
        return self::$user === null ? null : (int) self::$user['id'];
    }

    public static function check(): bool
    {
        return self::$user !== null;
    }

    public static function currentSessionId(): ?int
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        return is_array($state) && isset($state['session_id']) ? (int) $state['session_id'] : null;
    }

    public static function isSuperAdmin(): bool
    {
        return self::$user !== null && self::$user['role_slug'] === 'super_admin';
    }

    public static function isAdmin(): bool
    {
        return self::$user !== null && in_array(self::$user['role_slug'], ['admin', 'super_admin'], true);
    }

    public static function isStudent(): bool
    {
        return self::$user !== null && self::$user['role_slug'] === 'student';
    }

    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        if (self::$user === null) {
            return self::$permissions = [];
        }
        if (self::isSuperAdmin()) {
            return self::$permissions = array_column((new PermissionRepository())->all(), 'slug');
        }
        return self::$permissions = (new PermissionRepository())->effectiveFor(
            (int) self::$user['id'],
            (int) self::$user['role_id']
        );
    }

    public static function can(string $permission): bool
    {
        if (self::$user === null) {
            return false;
        }
        if (self::isSuperAdmin()) {
            return true;
        }
        return in_array($permission, self::permissions(), true);
    }

    public static function logout(Request $request, string $reason = 'user_logout'): void
    {
        // Signing out must also end the remembered browser, or "log me out"
        // would silently mean "log me out until the next page load".
        $raw = $request->cookie(self::REMEMBER_COOKIE);
        if (is_string($raw) && str_contains($raw, '.')) {
            [$selector] = explode('.', $raw, 2);
            $repository = new RememberTokenRepository();
            $row        = $repository->findAny($selector);
            if ($row !== null) {
                $repository->revoke((int) $row['id'], 'logout');
            }
        }
        self::clearRememberCookie($request);

        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($state) && isset($state['session_id'])) {
            (new SessionRepository())->terminate((int) $state['session_id'], $reason);
            ActivityLogger::log('auth.logout', (int) ($state['user_id'] ?? 0), 'session', (int) $state['session_id'], [], 'info', $request);
        }
        self::forget();
    }

    public static function forget(): void
    {
        self::$user        = null;
        self::$permissions = null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function hashOptions(): array
    {
        return [
            'memory_cost' => (int) Config::get('app.security.argon_memory', 65536),
            'time_cost'   => (int) Config::get('app.security.argon_time', 4),
            'threads'     => (int) Config::get('app.security.argon_threads', 1),
        ];
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algo, $algo === PASSWORD_DEFAULT ? [] : self::hashOptions());
    }

    private static function failure(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'user' => null];
    }
}
