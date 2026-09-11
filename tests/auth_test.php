<?php
declare(strict_types=1);

/**
 * HeleXa Med — phone authentication test suite.
 *
 *     php tests/auth_test.php          # pure rules only
 *     php tests/auth_test.php --db     # also the database-backed flows
 *
 * The database tests need config/config.php to point at an installation that
 * has run 2026_09_14_phone_auth.sql. They create one throwaway student and one
 * throwaway phone number, and remove both at the end.
 *
 * No test in here ever reaches the real SMS gateway: the gateway is driven
 * entirely by settings, so leaving it unconfigured makes every send fail
 * predictably, which is exactly the path the failure tests want. The tests
 * that need a code to exist read it back through the same hash the service
 * writes, rather than by intercepting the text.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('CONFIG_PATH', BASE_PATH . '/config');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('PRIVATE_PATH', STORAGE_PATH . '/private');
define('PUBLIC_PATH', BASE_PATH . '/public_html');

require BASE_PATH . '/app/Core/Autoloader.php';
(new \HeleXa\Core\Autoloader(APP_PATH))->register();
require APP_PATH . '/Helpers/functions.php';

\HeleXa\Core\Logger::setPath(STORAGE_PATH . '/logs');
\HeleXa\Core\View::setBasePath(APP_PATH . '/Views');

use HeleXa\Core\Config;
use HeleXa\Core\Database;
use HeleXa\Core\Request;
use HeleXa\Core\Str;
use HeleXa\Models\OtpRepository;
use HeleXa\Models\SettingRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\Crypto;
use HeleXa\Services\Otp;
use HeleXa\Services\Phone;
use HeleXa\Services\PhoneRegistration;
use HeleXa\Services\Settings;
use HeleXa\Services\SmsGateway;
use HeleXa\Services\SmsSettings;

/* ------------------------------------------------------------ harness */

final class A
{
    public static int $passed = 0;
    public static array $failed = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m" . $name . "\033[0m\n";
    }

    public static function ok(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m " . $name . ($detail !== '' ? "  \033[90m{$detail}\033[0m" : '') . "\n";
            return;
        }
        self::$failed[] = self::$group . ' → ' . $name . ($detail !== '' ? ' (' . $detail . ')' : '');
        echo "  \033[31m✗ " . $name . ($detail !== '' ? '  ' . $detail : '') . "\033[0m\n";
    }

    public static function same(string $name, mixed $expected, mixed $actual): void
    {
        self::ok($name, $expected === $actual, $expected === $actual
            ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    public static function summary(): int
    {
        $total = self::$passed + count(self::$failed);
        echo "\n" . str_repeat('─', 62) . "\n";
        if (self::$failed === []) {
            echo "\033[32m" . self::$passed . "/" . $total . " checks passed\033[0m\n";
            return 0;
        }
        echo "\033[31m" . count(self::$failed) . " of {$total} failed:\033[0m\n";
        foreach (self::$failed as $failure) {
            echo "  • " . $failure . "\n";
        }
        return 1;
    }
}

/* =========================================== 1. phone normalisation */

A::group('Phone normalisation');

$canonical = [
    '09123456789'    => '09123456789',
    '+989123456789'  => '09123456789',
    '00989123456789' => '09123456789',
    '989123456789'   => '09123456789',
    '9123456789'     => '09123456789',
    '0912 345 6789'  => '09123456789',
    '0912-345-6789'  => '09123456789',
    '۰۹۱۲۳۴۵۶۷۸۹'    => '09123456789',   // Persian digits
    '٠٩١٢٣٤٥٦٧٨٩'    => '09123456789',   // Arabic-Indic digits
    '(0912) 3456789' => '09123456789',
];
foreach ($canonical as $input => $expected) {
    // PHP turns a numeric-looking array key into an int, so the cast is the
    // test harness behaving, not the normaliser being lenient.
    A::same("«{$input}» → {$expected}", $expected, Phone::normalize((string) $input));
}

$rejected = [
    ''               => 'empty',
    '0812345678'     => 'not a mobile prefix',
    '0912345678'     => 'one digit short',
    '091234567890'   => 'one digit long',
    '08123456789'    => 'landline-style prefix',
    'not-a-number'   => 'letters',
    '+9891234567891' => 'too long with country code',
    '00989'          => 'truncated',
];
foreach ($rejected as $input => $why) {
    A::same("rejects «{$input}» ({$why})", '', Phone::normalize((string) $input));
}

A::ok('isValid agrees with normalize', Phone::isValid('+98 912 345 6789') && !Phone::isValid('0912'));
A::same('mask hides the middle', '0912***6789', Phone::mask('09123456789'));
A::same('mask refuses a non-number', '', Phone::mask('nope'));

// The property that actually matters: two spellings of one SIM must collide,
// because users.mobile is UNIQUE and two rows would be two accounts.
$spellings = ['09123456789', '+989123456789', '00989123456789', '۰۹۱۲۳۴۵۶۷۸۹', '0912-345-6789'];
$distinct  = array_unique(array_map([Phone::class, 'normalize'], $spellings));
A::same('every spelling of one number collapses to one value', 1, count($distinct));

/* ============================================== 2. crypto round-trip */

A::group('Credential encryption');

Config::hydrate('app', [
    'app'      => ['name' => 'HeleXa Med', 'timezone' => 'Asia/Tehran'],
    'security' => ['app_key' => str_repeat('f1e2d3c4', 8)],
]);

A::ok('crypto reports itself available', Crypto::isAvailable());

$secret = 'my-melipayamak-key-9f3x';
$cipher = Crypto::encrypt($secret);

A::ok('ciphertext does not contain the plaintext', !str_contains($cipher, $secret));
A::ok('ciphertext is versioned', str_starts_with($cipher, 'v1.'));
A::same('round-trips exactly', $secret, Crypto::decrypt($cipher));
A::ok('two encryptions of one value differ', Crypto::encrypt($secret) !== Crypto::encrypt($secret));
A::same('a tampered ciphertext fails closed', null, Crypto::decrypt(substr($cipher, 0, -4) . 'AAAA'));
A::same('a malformed payload fails closed', null, Crypto::decrypt('not-a-payload'));
A::same('a foreign version fails closed', null, Crypto::decrypt('v9.a.b.c'));

// Rotating the key must make old ciphertext unreadable rather than fatal.
$underOldKey = Crypto::encrypt('before-rotation');
Config::hydrate('app', [
    'app'      => ['name' => 'HeleXa Med', 'timezone' => 'Asia/Tehran'],
    'security' => ['app_key' => str_repeat('0a0b0c0d', 8)],
]);
A::same('a rotated key returns null, not an exception', null, Crypto::decrypt($underOldKey));

/* ================================================ 3. gateway refusals */

A::group('SMS gateway guard rails');

// Nothing configured: the client must refuse locally and never open a socket.
A::same('refuses while disabled', 'SMS_DISABLED', SmsGateway::send('09123456789', 'x')['code']);

if ($argc < 2 || $argv[1] !== '--db') {
    echo "\n\033[90m(database-backed flows skipped; run with --db)\033[0m\n";
    exit(A::summary());
}

/* ======================================================= database set-up */

Config::loadFile('app', CONFIG_PATH . '/config.php');
Config::loadFile('security', CONFIG_PATH . '/security.php');
date_default_timezone_set((string) Config::get('app.app.timezone', 'Asia/Tehran'));

// Sessions are written by Auth; a CLI run has none until one is started.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$users    = new UserRepository();
$otpRepo  = new OtpRepository();
$settings = new SettingRepository();

/** A distinct number per run, so a previous run's rows cannot affect limits. */
$PHONE   = '0912' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$PHONE2  = '0913' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$created = [];

function fakeRequest(string $ip = '198.51.100.7'): Request
{
    return new Request([], [], [
        'REQUEST_METHOD'     => 'POST',
        'REQUEST_URI'        => '/auth/request-otp',
        'REMOTE_ADDR'        => $ip,
        'HTTP_USER_AGENT'    => 'HeleXaTests/1.0',
        'HTTP_ACCEPT_LANGUAGE' => 'fa-IR',
    ]);
}

/** Reads the live code back the only honest way: by matching its stored hash. */
function currentCode(string $phone, string $purpose): ?string
{
    $row = Database::selectOne(
        'SELECT code_hash FROM otp_codes
         WHERE phone = :p AND purpose = :u AND used_at IS NULL AND consumed_at IS NULL
         ORDER BY id DESC LIMIT 1',
        ['p' => $phone, 'u' => $purpose]
    );
    if ($row === null) {
        return null;
    }
    for ($candidate = 100000; $candidate <= 999999; $candidate++) {
        if (hash_equals((string) $row['code_hash'], Str::hmac('otp:' . $candidate))) {
            return (string) $candidate;
        }
    }
    return null;
}

/** Lets a cooldown or a window elapse without the test sleeping through it. */
function ageCodes(string $phone, int $seconds): void
{
    Database::execute(
        'UPDATE otp_codes SET created_at = DATE_SUB(created_at, INTERVAL :s SECOND) WHERE phone = :p',
        ['s' => $seconds, 'p' => $phone]
    );
}

function clearCodes(string $phone): void
{
    Database::execute('DELETE FROM otp_codes WHERE phone = :p', ['p' => $phone]);
}

/* The gateway is pointed at nothing real. Rather than sending, the tests
   drive Otp::issue() with SMS switched off for the failure path, and write
   codes directly through the repository for the success paths — the same
   rows Otp::verify() reads. */
function issueCodeDirectly(string $phone, string $purpose, string $code, int $ttl = 120, int $maxAttempts = 5): int
{
    return (new OtpRepository())->create([
        'phone'        => $phone,
        'purpose'      => $purpose,
        'code_hash'    => Str::hmac('otp:' . $code),
        'max_attempts' => $maxAttempts,
        'expires_at'   => date('Y-m-d H:i:s', time() + $ttl),
        'ip_address'   => '198.51.100.7',
        'user_agent'   => 'HeleXaTests/1.0',
    ]);
}

try {
    Database::connection();
} catch (\Throwable $e) {
    echo "\n\033[31mDatabase unavailable: " . $e->getMessage() . "\033[0m\n";
    exit(1);
}

/* ================================================= 4. OTP verification */

A::group('One-time code verification');

clearCodes($PHONE);
$request = fakeRequest();

issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '123456');

$wrong = Otp::verify($request, $PHONE, Otp::PURPOSE_LOGIN, '000000');
A::same('a wrong code is refused', false, $wrong['ok']);
A::same('a wrong code says how many tries are left', 'WRONG_CODE', $wrong['code']);

$right = Otp::verify($request, $PHONE, Otp::PURPOSE_LOGIN, '123456');
A::same('the right code is accepted', true, $right['ok']);
A::same('verification returns the normalised number', $PHONE, $right['phone']);

$replay = Otp::verify($request, $PHONE, Otp::PURPOSE_LOGIN, '123456');
A::same('the same code cannot be used twice', false, $replay['ok']);
A::same('a spent code reads as no code', 'NO_CODE', $replay['code']);

// Persian digits in the box must work: the SMS itself renders them that way
// on many phones, and students paste what they see.
clearCodes($PHONE);
issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '654321');
A::same('a code typed in Persian digits is accepted', true,
    Otp::verify($request, $PHONE, Otp::PURPOSE_LOGIN, '۶۵۴۳۲۱')['ok']);

// An expired code is gone even though nothing used it.
clearCodes($PHONE);
issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '111111', -10);
A::same('an expired code is refused', 'NO_CODE',
    Otp::verify($request, $PHONE, Otp::PURPOSE_LOGIN, '111111')['code']);

// The ceiling on guesses is what makes six digits safe.
clearCodes($PHONE);
issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '222222', 120, 5);
$lastCode = '';
for ($i = 0; $i < 5; $i++) {
    $lastCode = Otp::verify($request, $PHONE, Otp::PURPOSE_LOGIN, '999999')['code'];
}
A::same('the fifth wrong guess exhausts the code', 'TOO_MANY', $lastCode);
A::same('the real code no longer works after exhaustion', false,
    Otp::verify($request, $PHONE, Otp::PURPOSE_LOGIN, '222222')['ok']);

// A code is stored only as a keyed hash.
clearCodes($PHONE);
issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '345678');
$stored = Database::selectOne(
    'SELECT code_hash FROM otp_codes WHERE phone = :p ORDER BY id DESC LIMIT 1', ['p' => $PHONE]
);
A::ok('the code is not stored in the clear', !str_contains((string) $stored['code_hash'], '345678'));
A::same('the stored digest is the keyed hash', Str::hmac('otp:345678'), (string) $stored['code_hash']);

// Purposes are separate keyspaces: a sign-in code must not reset a password.
clearCodes($PHONE);
issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '456789');
A::same('a login code does not satisfy a reset', 'NO_CODE',
    Otp::verify($request, $PHONE, Otp::PURPOSE_RESET, '456789')['code']);

/* ==================================================== 5. send limits */

A::group('Send limits');

clearCodes($PHONE);
$settings->set('sms_enabled', '0', 'bool', null);
Settings::flush();

// With the gateway off, issuing must fail and must not leave a live code
// behind — otherwise the cooldown would run against a text nobody received.
$first = Otp::issue($request, $PHONE, Otp::PURPOSE_LOGIN);
A::same('issuing fails when SMS is unavailable', false, $first['ok']);
A::same('the failure names delivery, not validation', 'SEND_FAILED', $first['code']);
A::same('no live code survives a failed send', null, currentCode($PHONE, Otp::PURPOSE_LOGIN));

// The cooldown is measured from the attempt, so a second request is refused.
$second = Otp::issue($request, $PHONE, Otp::PURPOSE_LOGIN);
A::same('a second request inside the cooldown is refused', 'COOLDOWN', $second['code']);
A::ok('the refusal says how long to wait', $second['retry_after'] > 0 && $second['retry_after'] <= 60);

// Past the cooldown it is allowed again (and fails on delivery, as before).
ageCodes($PHONE, 120);
A::same('past the cooldown the request is allowed through', 'SEND_FAILED',
    Otp::issue($request, $PHONE, Otp::PURPOSE_LOGIN)['code']);

// The per-number hourly ceiling.
clearCodes($PHONE);
for ($i = 0; $i < 5; $i++) {
    Otp::issue($request, $PHONE, Otp::PURPOSE_LOGIN);
    ageCodes($PHONE, 120);
}
A::same('the sixth code in an hour is refused', 'PHONE_LIMIT',
    Otp::issue($request, $PHONE, Otp::PURPOSE_LOGIN)['code']);

// Ageing the rows past the window restores the allowance.
ageCodes($PHONE, 4000);
A::ok('the allowance returns after an hour',
    Otp::issue($request, $PHONE, Otp::PURPOSE_LOGIN)['code'] !== 'PHONE_LIMIT');

// An invalid number is rejected before any limit is touched.
A::same('a malformed number never reaches the gateway', 'INVALID_PHONE',
    Otp::issue($request, '0912', Otp::PURPOSE_LOGIN)['code']);

clearCodes($PHONE);

/* ============================================ 6. one live code per number */

A::group('One live code at a time');

clearCodes($PHONE);
issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '111222');
$otpRepo->consumeLive($PHONE, Otp::PURPOSE_LOGIN);
issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '333444');

A::same('the superseded code is dead', false,
    Otp::verify($request, $PHONE, Otp::PURPOSE_LOGIN, '111222')['ok']);

clearCodes($PHONE);
issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '555666');
$otpRepo->consumeLive($PHONE, Otp::PURPOSE_LOGIN);
issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '777888');
A::same('the newest code is the live one', true,
    Otp::verify($request, $PHONE, Otp::PURPOSE_LOGIN, '777888')['ok']);

/* ================================================ 7. self-registration */

A::group('Self-registration');

clearCodes($PHONE);
$settings->set('otp_registration_enabled', '1', 'bool', null);
Settings::flush();

$before  = $users->findByMobile($PHONE);
A::same('the number has no account yet', null, $before);

$made = PhoneRegistration::resolve($request, $PHONE);
A::same('registration succeeds', true, $made['ok']);
A::same('registration reports creating an account', true, $made['created']);

$newUser = $made['user'];
$created[] = (int) $newUser['id'];

A::same('the account carries the number', $PHONE, (string) $newUser['mobile']);
A::same('the account is a student', 'student', (string) $newUser['role_slug']);
A::same('the account is active', 'active', (string) $newUser['status']);
A::same('the account is marked self-registered', 'self_otp', (string) $newUser['registration_source']);
A::ok('the number is recorded as verified', !empty($newUser['phone_verified_at']));
A::same('the account has no password at all', null, $newUser['password_hash']);

// A second verification of the same number must find the account, not make one.
$again = PhoneRegistration::resolve($request, $PHONE);
A::same('a returning number is not registered twice', false, $again['created']);
A::same('a returning number resolves to the same account',
    (int) $newUser['id'], (int) $again['user']['id']);

// With registration closed, an unknown number is turned away.
$settings->set('otp_registration_enabled', '0', 'bool', null);
Settings::flush();
$closed = PhoneRegistration::resolve($request, $PHONE2);
A::same('a new number is refused while registration is closed', false, $closed['ok']);
A::same('the refusal is explicit', 'REGISTRATION_CLOSED', $closed['code']);
A::same('and no account was created', null, $users->findByMobile($PHONE2));

// An existing account still signs in with registration closed.
$existing = PhoneRegistration::resolve($request, $PHONE);
A::same('an existing account is unaffected by the switch', true, $existing['ok']);

$settings->set('otp_registration_enabled', '1', 'bool', null);
Settings::flush();

/* ============================================= 8. password-less login */

A::group('Password rules for a code-only account');

$attempt = Auth::attempt($request, $PHONE, '', false);
A::same('an empty password is refused', false, $attempt['ok']);
A::same('it fails as bad credentials', 'INVALID_CREDENTIALS', $attempt['code']);

$attempt = Auth::attempt($request, $PHONE, 'anything-at-all', false);
A::same('no password means no password login', false, $attempt['ok']);
A::same('the message does not reveal that the account exists',
    'نام کاربری یا رمز عبور نادرست است.', $attempt['message']);

// Once a password is set, the same account signs in with it.
$users->updatePassword((int) $newUser['id'], Auth::hashPassword('Student12345'));
$withPassword = $users->findById((int) $newUser['id']);
A::ok('the account now has a hash', is_string($withPassword['password_hash']) && $withPassword['password_hash'] !== '');
A::ok('the hash is not the password', $withPassword['password_hash'] !== 'Student12345');
A::ok('the stored hash is argon2id', str_starts_with((string) $withPassword['password_hash'], '$argon2id$'));

A::same('a wrong password is still refused', false,
    Auth::attempt($request, $PHONE, 'Student12346', false)['ok']);

/* ===================================================== 9. reset tickets */

A::group('Password reset tickets');

clearCodes($PHONE);
$resetId = issueCodeDirectly($PHONE, Otp::PURPOSE_RESET, '135790');

$verified = Otp::verify($request, $PHONE, Otp::PURPOSE_RESET, '135790');
A::same('the reset code verifies', true, $verified['ok']);

$ticket = Otp::issueTicket($verified['otp_id']);
A::ok('a ticket is issued', $ticket !== '' && strlen($ticket) === 64);

$ticketRow = Database::selectOne(
    'SELECT ticket_hash FROM otp_codes WHERE id = :id', ['id' => $verified['otp_id']]
);
A::ok('the ticket is stored hashed', (string) $ticketRow['ticket_hash'] !== $ticket);
A::same('the stored ticket is the digest', Str::hash($ticket), (string) $ticketRow['ticket_hash']);

A::same('the ticket redeems to the verified number', $PHONE,
    Otp::redeemTicket($ticket, Otp::PURPOSE_RESET));
A::same('a ticket is single use', '', Otp::redeemTicket($ticket, Otp::PURPOSE_RESET));
A::same('an invented ticket redeems to nothing', '',
    Otp::redeemTicket(str_repeat('a', 64), Otp::PURPOSE_RESET));
A::same('an empty ticket redeems to nothing', '', Otp::redeemTicket('', Otp::PURPOSE_RESET));

// A ticket minted for a reset must not be usable elsewhere.
clearCodes($PHONE);
$loginId = issueCodeDirectly($PHONE, Otp::PURPOSE_LOGIN, '246800');
Otp::verify($request, $PHONE, Otp::PURPOSE_LOGIN, '246800');
$loginTicket = Otp::issueTicket($loginId);
A::same('a login ticket does not redeem as a reset ticket', '',
    Otp::redeemTicket($loginTicket, Otp::PURPOSE_RESET));

/* ============================================ 10. SMS settings storage */

A::group('SMS settings storage');

$settings->set('sms_username', 'demo-user', 'string', null);
$settings->set('sms_from', '50004000', 'string', null);
Settings::flush();

SmsSettings::saveCredentials('demo-user', 'super-secret-key-1234', '50004000', null);

$rawRow = Database::selectOne(
    "SELECT setting_value FROM settings WHERE setting_key = 'sms_api_key_enc'"
);
A::ok('the API key is not stored in the clear',
    !str_contains((string) $rawRow['setting_value'], 'super-secret-key-1234'));
A::same('the API key decrypts back', 'super-secret-key-1234', SmsSettings::apiKey());
A::same('the mask keeps only the tail', '************1234', SmsSettings::maskedApiKey());
A::ok('the mask does not contain the key',
    !str_contains(SmsSettings::maskedApiKey(), 'super-secret'));

// Saving with null must leave the stored key alone — that is what makes the
// masked form field safe to submit unchanged.
SmsSettings::saveCredentials('demo-user-2', null, '50004001', null);
A::same('a null key leaves the stored credential untouched',
    'super-secret-key-1234', SmsSettings::apiKey());
A::same('the other fields still updated', 'demo-user-2', SmsSettings::username());

A::same('configuration is now complete', true, SmsSettings::isConfigured());
A::same('but sending stays off until the switch is on', false, SmsSettings::isOperational());

SmsSettings::setEnabled(true, null);
A::same('the switch makes it operational', true, SmsSettings::isOperational());

// Clearing is distinct from leaving alone.
SmsSettings::saveCredentials('demo-user-2', '', '50004001', null);
A::same('an empty string clears the key', '', SmsSettings::apiKey());
A::same('and configuration is incomplete again', false, SmsSettings::isConfigured());

/* ============================================== 11. gateway refusals */

A::group('Gateway refusals with real settings');

SmsSettings::setEnabled(false, null);
A::same('disabled beats configured', 'SMS_DISABLED', SmsGateway::send($PHONE, 'x')['code']);

SmsSettings::setEnabled(true, null);
A::same('an incomplete configuration is refused locally',
    'SMS_NOT_CONFIGURED', SmsGateway::send($PHONE, 'x')['code']);

SmsSettings::saveCredentials('demo-user', 'key-abcd', '50004000', null);
A::same('an invalid destination is refused locally',
    'INVALID_PHONE', SmsGateway::send('0912', 'x')['code']);

/* ================================================ 12. OTP disabled */

A::group('Master switches');

$settings->set('otp_enabled', '0', 'bool', null);
Settings::flush();
A::same('issuing is refused while OTP is off', 'OTP_DISABLED',
    Otp::issue($request, $PHONE, Otp::PURPOSE_LOGIN)['code']);
A::same('isEnabled reports off', false, Otp::isEnabled());

$settings->set('otp_enabled', '1', 'bool', null);
Settings::flush();
A::same('isEnabled follows the gateway too', SmsSettings::isOperational(), Otp::isEnabled());

/* ================================================== 13. no hash leaks */

A::group('Admin list queries');

$listed = $users->paginate(['role' => 'student', 'search' => $PHONE], 10, 0);
A::ok('the test account is listed', $listed !== []);

if ($listed !== []) {
    A::ok('the list carries no password hash', !array_key_exists('password_hash', $listed[0]));
    A::ok('the list says whether a password exists', array_key_exists('has_password', $listed[0]));
    A::ok('the list carries the verification timestamp', array_key_exists('phone_verified_at', $listed[0]));
    A::same('this account has a password', 1, (int) $listed[0]['has_password']);
}

/* ===================================================== 14. clean-up */

A::group('Clean-up');

clearCodes($PHONE);
clearCodes($PHONE2);

foreach ($created as $id) {
    Database::execute('DELETE FROM sessions WHERE user_id = :id', ['id' => $id]);
    Database::execute('DELETE FROM remember_tokens WHERE user_id = :id', ['id' => $id]);
    Database::execute('DELETE FROM activity_logs WHERE user_id = :id', ['id' => $id]);
    Database::execute('DELETE FROM login_attempts WHERE user_id = :id', ['id' => $id]);
    Database::execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
}

// The settings this run rewrote go back to their shipped defaults, so a test
// run never leaves an installation with demo credentials in it.
foreach ([
    'sms_enabled' => ['0', 'bool'], 'sms_username' => ['', 'string'],
    'sms_from' => ['', 'string'], 'sms_api_key_enc' => ['', 'string'],
    'otp_enabled' => ['1', 'bool'], 'otp_registration_enabled' => ['1', 'bool'],
] as $key => [$value, $type]) {
    $settings->set($key, $value, $type, null);
}
Settings::flush();

A::same('the test account is gone', null, $users->findByMobile($PHONE));
A::same('no codes remain', 0, $otpRepo->countSince($PHONE, date('Y-m-d H:i:s', time() - 86400)));
A::same('demo credentials were removed', '', SmsSettings::apiKey());

exit(A::summary());
