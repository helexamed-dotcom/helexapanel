<?php
declare(strict_types=1);

/**
 * HeleXa Med — authentication test suite.
 *
 *     php tests/auth_test.php          # pure rules only
 *     php tests/auth_test.php --db     # also the database-backed flows
 *
 * This file used to be dominated by the texted-code sign-in: fifteen groups,
 * twelve of which drove the OTP service, the SMS gateway and the credential
 * encryption behind it. All three are gone, and so are those groups — a test
 * for a deleted class is not a passing test, it is a fatal error on the first
 * line that names it.
 *
 * What survives is what still describes real behaviour: phone numbers are
 * normalised so two spellings of one SIM cannot become two accounts, and a
 * password is the only thing that opens an account.
 *
 * The database tests create one throwaway student and remove it at the end.
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
use HeleXa\Models\UserRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\Phone;

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

/* --------------------------------------------------------------------- */

if (!in_array('--db', $argv, true)) {
    echo "\n\033[90mDatabase-backed groups skipped. Re-run with --db to include them.\033[0m\n";
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

try {
    Database::connection();
} catch (\Throwable $e) {
    echo "\n\033[31mDatabase unavailable: " . $e->getMessage() . "\033[0m\n";
    exit(1);
}

$users   = new UserRepository();
$created = [];

/** A distinct number per run, so a previous run's rows cannot collide. */
$PHONE = '0912' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);

function fakeRequest(string $ip = '198.51.100.7'): Request
{
    return new Request([], [], [
        'REQUEST_METHOD'       => 'POST',
        'REQUEST_URI'          => '/login',
        'REMOTE_ADDR'          => $ip,
        'HTTP_USER_AGENT'      => 'HeleXaTests/1.0',
        'HTTP_ACCEPT_LANGUAGE' => 'fa-IR',
    ]);
}

$request = fakeRequest();

/* ============================================== 2. password sign-in */

A::group('Password sign-in');

$studentRole = Database::selectOne("SELECT id FROM roles WHERE slug = 'student' LIMIT 1");
if ($studentRole === null) {
    echo "\n\033[31mNo student role found; is the schema installed?\033[0m\n";
    exit(1);
}

$userId = $users->create([
    'uuid'                => Str::uuid4(),
    'role_id'             => (int) $studentRole['id'],
    'username'            => 'test_' . substr($PHONE, -7),
    'mobile'              => $PHONE,
    'full_name'           => 'حساب آزمایشی',
    'password_hash'       => Auth::hashPassword('Student12345'),
    'status'              => 'active',
    // password_changed_at and created_at are not passed: create() stamps both
    // with its own clock and ignores anything supplied for them.
]);
$created[] = $userId;

$stored = $users->findById($userId);
A::ok('the account was created', $stored !== null);
A::ok('the stored hash is argon2id', str_starts_with((string) $stored['password_hash'], '$argon2id$'));
A::ok('the hash is not the password', $stored['password_hash'] !== 'Student12345');

A::same('an empty password is refused', false, Auth::attempt($request, $PHONE, '', false)['ok']);
A::same('a wrong password is refused', false,
    Auth::attempt($request, $PHONE, 'Student12346', false)['ok']);

// The wording must not separate "no such account" from "wrong password":
// a different message for each turns the form into a membership oracle.
$unknown = Auth::attempt($request, '09120000000', 'whatever', false);
$wrong   = Auth::attempt($request, $PHONE, 'Student12346', false);
A::same('an unknown number and a wrong password read the same',
    $unknown['message'], $wrong['message']);

// A number typed any of its ways reaches the same account.
A::same('a +98 spelling finds the same account',
    $users->findByMobile($PHONE)['id'] ?? null,
    $users->findByMobile(Phone::normalize('+98' . substr($PHONE, 1)))['id'] ?? null);

/* ================================================== 3. no hash leaks */

A::group('Admin list queries');

$listed = $users->paginate(['role' => 'student', 'search' => $PHONE], 10, 0);
A::ok('the test account is listed', $listed !== []);

if ($listed !== []) {
    A::ok('the list carries no password hash', !array_key_exists('password_hash', $listed[0]));
    A::ok('the list says whether a password exists', array_key_exists('has_password', $listed[0]));
    A::same('this account has a password', 1, (int) $listed[0]['has_password']);
}

/* ===================================================== 4. clean-up */

A::group('Clean-up');

foreach ($created as $id) {
    Database::execute('DELETE FROM sessions WHERE user_id = :id', ['id' => $id]);
    Database::execute('DELETE FROM remember_tokens WHERE user_id = :id', ['id' => $id]);
    Database::execute('DELETE FROM activity_logs WHERE user_id = :id', ['id' => $id]);
    Database::execute('DELETE FROM login_attempts WHERE user_id = :id', ['id' => $id]);
    Database::execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
}

A::same('the test account is gone', null, $users->findByMobile($PHONE));

exit(A::summary());
