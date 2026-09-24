<?php
declare(strict_types=1);

/**
 * HeleXa Med — multi-network sign-in detection and publish notifications.
 *
 *     php tests/ipwatch_test.php          # network grouping and the threshold rule
 *     php tests/ipwatch_test.php --db     # plus flag rows and content announcements
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

use HeleXa\Core\Config;
use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Services\IpWatch;
use HeleXa\Services\NotificationService;

$passed = 0;
$failed = [];
$same = static function (string $name, mixed $e, mixed $a) use (&$passed, &$failed): void {
    if ($e === $a) { $passed++; echo "  \033[32m✓\033[0m {$name}\n"; return; }
    $failed[] = $name;
    echo "  \033[31m✗ {$name}  expected " . var_export($e, true) . ', got ' . var_export($a, true) . "\033[0m\n";
};
$finish = static function () use (&$passed, &$failed): int {
    echo "\n" . str_repeat('─', 62) . "\n";
    if ($failed === []) { echo "\033[32m{$passed} checks passed\033[0m\n"; return 0; }
    echo "\033[31m" . count($failed) . " failed:\033[0m\n  • " . implode("\n  • ", $failed) . "\n";
    return 1;
};

echo "\n\033[1mNetwork grouping\033[0m\n";
$same('IPv4 → /24', '5.160.12', IpWatch::network('5.160.12.99'));
$same('same /24, other host', IpWatch::network('5.160.12.1'), IpWatch::network('5.160.12.250'));
$same('IPv4-mapped IPv6 is IPv4', '5.160.12', IpWatch::network('::ffff:5.160.12.7'));
$same('IPv6 → /48', IpWatch::network('2a01:5ec0:1:2::1'), IpWatch::network('2a01:5ec0:1:ffff::9'));
$same('different /48 differ', false, IpWatch::network('2a01:5ec0:1::1') === IpWatch::network('2a01:5ec0:2::1'));
$same('garbage passes through unchanged', 'unknown', IpWatch::network('unknown'));

echo "\n\033[1mThreshold rule\033[0m\n";
$carrier = ['5.160.12.3', '5.160.12.77', '5.160.12.200', '5.160.12.9'];
$same('many addresses from one carrier range → 1 network', 1, IpWatch::evaluate($carrier, 3)['networks']);
$same('…and no flag', false, IpWatch::evaluate($carrier, 3)['flag']);
$same('home + mobile (2 networks) → no flag at 3', false, IpWatch::evaluate(['5.160.12.3', '91.98.4.4'], 3)['flag']);
$same('three cities → flag', true, IpWatch::evaluate(['5.160.12.3', '91.98.4.4', '185.1.2.3'], 3)['flag']);
$same('threshold never below 2', true, IpWatch::evaluate(['1.1.1.1', '2.2.2.2'], 0)['flag']);
$same('a single network never flags even at threshold 1', false, IpWatch::evaluate(['1.1.1.1'], 1)['flag']);

if (!in_array('--db', $argv, true)) {
    echo "\n\033[90mDatabase checks skipped. Re-run with --db to include them.\033[0m\n";
    exit($finish());
}

Config::loadFile('app', CONFIG_PATH . '/config.php');
Config::loadFile('security', CONFIG_PATH . '/security.php');
date_default_timezone_set((string) Config::get('app.app.timezone', 'Asia/Tehran'));
Database::connection();

$suffix   = substr(Str::uuid4(), 0, 8);
$userId   = null;
$courseId = null;

try {
    $role   = Database::selectOne("SELECT id FROM roles WHERE slug = 'student' LIMIT 1");
    $userId = (new \HeleXa\Models\UserRepository())->create([
        'uuid' => Str::uuid4(), 'role_id' => (int) $role['id'],
        'username' => "ipw_{$suffix}", 'full_name' => 'آزمایشی IP',
        'password_hash' => \HeleXa\Services\Auth::hashPassword('Student12345'),
    ]);

    $signIn = static function (string $ip) use ($userId): void {
        (new \HeleXa\Models\SessionRepository())->create([
            'user_id' => $userId, 'php_session_id' => null, 'token_hash' => hash('sha256', random_bytes(16)),
            'device_hash' => str_repeat('a', 64), 'ip_address' => $ip, 'user_agent' => 'test',
            'browser' => null, 'operating_system' => null, 'device_type' => 'unknown',
        ]);
        IpWatch::afterSignIn($userId, 'student');
    };
    $flag = static fn (): ?array => Database::selectOne(
        "SELECT * FROM security_flags WHERE user_id = :u AND flag_day = :d", ['u' => $userId, 'd' => date('Y-m-d')]);

    echo "\n\033[1mFlags\033[0m\n";
    $signIn('5.160.12.3');
    $signIn('91.98.4.4');
    $same('two networks: no flag', null, $flag());
    $signIn('185.1.2.3');
    $same('third network: flag opened', 'open', $flag()['status'] ?? null);
    $same('three networks recorded', 3, (int) $flag()['networks']);
    $same('the student is listed as flagged', true, in_array($userId, IpWatch::flaggedUserIds(), true));

    Database::execute("UPDATE security_flags SET status = 'dismissed' WHERE user_id = :u", ['u' => $userId]);
    $signIn('185.1.2.99');
    $same('same networks again: stays dismissed', 'dismissed', $flag()['status']);
    $signIn('62.10.20.30');
    $same('a new network reopens it', 'open', $flag()['status']);
    $same('still one row for the day', 1, (int) Database::selectOne(
        'SELECT COUNT(*) AS c FROM security_flags WHERE user_id = :u', ['u' => $userId])['c']);

    IpWatch::afterSignIn($userId, 'admin');
    $same('admins are never checked (no error, no change)', 'open', $flag()['status']);

    echo "\n\033[1mPublish announcements\033[0m\n";
    $courseId = Database::insert(
        "INSERT INTO courses (uuid, title, slug, status, created_at) VALUES (:u, :t, :s, 'draft', NOW())",
        ['u' => Str::uuid4(), 't' => "دوره {$suffix}", 's' => "ipw-{$suffix}"]
    );
    $course = Database::selectOne('SELECT * FROM courses WHERE id = :id', ['id' => $courseId]);
    (new \HeleXa\Models\EnrollmentRepository())->assign($userId, $courseId, ['status' => 'active'], null);

    $content = ['id' => 900000000 + random_int(1, 99999), 'uuid' => Str::uuid4(), 'title' => 'جلسه تست'];
    $same('a draft course announces nothing', false, NotificationService::contentPublished($course, $content, null)['created']);

    $course['status'] = 'published';
    $first = NotificationService::contentPublished($course, $content, null);
    $same('a published course announces', true, $first['created']);
    $same('the enrolled student received it', 1, (int) Database::selectOne(
        'SELECT COUNT(*) AS c FROM user_notifications WHERE notification_id = :n AND user_id = :u',
        ['n' => $first['id'], 'u' => $userId])['c']);
    $same('publishing the same lesson again is silent', false, NotificationService::contentPublished($course, $content, null)['created']);

    Database::execute('DELETE FROM notifications WHERE id = :id', ['id' => $first['id']]);
} finally {
    if ($courseId !== null) { Database::execute('DELETE FROM courses WHERE id = :id', ['id' => $courseId]); }
    if ($userId !== null)   { Database::execute('DELETE FROM users WHERE id = :id', ['id' => $userId]); }
}

exit($finish());
