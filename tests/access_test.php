<?php
declare(strict_types=1);

/**
 * HeleXa Med — student tiers and per-lesson Balin access.
 *
 *     php tests/access_test.php          # tier rule only
 *     php tests/access_test.php --db     # plus tiers from real rows and lesson blocks
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
use HeleXa\Models\Balin\BalinAccessRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Services\StudentTier;

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

echo "\n\033[1mTier rule\033[0m\n";
$same('nothing → none',                    'none',   StudentTier::decide(false, false, false, false));
$same('a course → bronze',                 'bronze', StudentTier::decide(true, false, false, false));
$same('a package → silver',                'silver', StudentTier::decide(true, true, false, false));
$same('package without course row → silver', 'silver', StudentTier::decide(false, true, false, false));
$same('island and bank alone → none',      'none',   StudentTier::decide(false, false, true, true));
$same('course + island + bank, no package → bronze', 'bronze', StudentTier::decide(true, false, true, true));
$same('package + island, no bank → silver','silver', StudentTier::decide(true, true, true, false));
$same('all four → gold',                   'gold',   StudentTier::decide(true, true, true, true));

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
$lessonId = null;

try {
    $role   = Database::selectOne("SELECT id FROM roles WHERE slug = 'student' LIMIT 1");
    $userId = (new \HeleXa\Models\UserRepository())->create([
        'uuid' => Str::uuid4(), 'role_id' => (int) $role['id'],
        'username' => "acc_{$suffix}", 'full_name' => 'آزمایشی دسترسی',
        'password_hash' => \HeleXa\Services\Auth::hashPassword('Student12345'),
    ]);

    echo "\n\033[1mTier from rows\033[0m\n";
    $same('a fresh student has no tier', 'none', StudentTier::forStudent($userId)['tier']);

    $courseId = Database::insert(
        "INSERT INTO courses (uuid, title, slug, status, created_at) VALUES (:u, :t, :s, 'published', NOW())",
        ['u' => Str::uuid4(), 't' => "دوره {$suffix}", 's' => "acc-{$suffix}"]
    );
    (new EnrollmentRepository())->assign($userId, $courseId, ['status' => 'active'], null);
    $same('an active course → bronze', 'bronze', StudentTier::forStudent($userId)['tier']);

    (new EnrollmentRepository())->setStatus($userId, $courseId, 'suspended');
    $same('a suspended course does not count', 'none', StudentTier::forStudent($userId)['tier']);

    (new EnrollmentRepository())->assign($userId, $courseId,
        ['status' => 'active', 'ends_at' => date('Y-m-d H:i:s', time() - 86400)], null);
    $same('an expired window does not count', 'none', StudentTier::forStudent($userId)['tier']);

    $batch = StudentTier::forMany([$userId, 0, -5]);
    $same('the batch ignores invalid ids', [$userId], array_keys($batch));

    echo "\n\033[1mBalin lesson blocks\033[0m\n";
    $lessons  = new BalinLessonRepository();
    $lessonId = Database::insert(
        "INSERT INTO balin_lessons (uuid, slug, title, status, display_order, version, created_at)
         VALUES (:u, :s, :t, 'published', 999999, 1, NOW())",
        ['u' => Str::uuid4(), 's' => "acc-{$suffix}", 't' => "درس {$suffix}"]
    );
    (new BalinAccessRepository())->grant($userId, null);

    $listed = static fn (): bool => in_array($lessonId, array_map(static fn ($l) => (int) $l['id'], $lessons->publishedForStudent($userId)), true);

    $same('an unblocked lesson is listed', true, $listed());
    $lessons->syncBlocks($userId, [$lessonId], null);
    $same('a blocked lesson is not listed', false, $listed());
    $same('and reports as blocked', true, $lessons->isBlockedFor($userId, $lessonId));
    $lessons->syncBlocks($userId, [$lessonId, $lessonId], null);
    $same('blocking twice stores one row', 1, count($lessons->blockedIdsFor($userId)));
    $lessons->syncBlocks($userId, [], null);
    $same('an empty set reopens it', true, $listed());
} finally {
    if ($lessonId !== null) { Database::execute('DELETE FROM balin_lessons WHERE id = :id', ['id' => $lessonId]); }
    if ($courseId !== null) { Database::execute('DELETE FROM courses WHERE id = :id', ['id' => $courseId]); }
    if ($userId !== null)   { Database::execute('DELETE FROM users WHERE id = :id', ['id' => $userId]); }
}

exit($finish());
