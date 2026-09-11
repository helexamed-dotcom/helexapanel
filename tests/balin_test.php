<?php
declare(strict_types=1);

/**
 * HeleXa Med — Balin Island test suite.
 *
 *     php tests/balin_test.php                 # formula and rule tests only
 *     php tests/balin_test.php --db            # also the database-backed tests
 *
 * The database tests need config/config.php to point at an installation that
 * has run the Balin migration. They write to and then remove their own rows,
 * under a user account they create and delete; nothing else is touched. Run
 * them against a staging copy, never production.
 */

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public_html');
define('PRIVATE_PATH', BASE_PATH . '/storage/private');

require BASE_PATH . '/app/Core/Autoloader.php';
(new \HeleXa\Core\Autoloader(BASE_PATH . '/app'))->register();

use HeleXa\Services\Balin\Access;
use HeleXa\Services\Balin\BalinSettings;
use HeleXa\Services\Balin\Level;
use HeleXa\Services\Balin\Mastery;
use HeleXa\Services\Balin\RankTitle;

/* ------------------------------------------------------------ harness */

final class T
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
        self::ok(
            $name,
            $expected === $actual,
            $expected === $actual ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }

    public static function near(string $name, float $expected, float $actual, float $epsilon = 0.01): void
    {
        self::ok(
            $name,
            abs($expected - $actual) <= $epsilon,
            abs($expected - $actual) <= $epsilon ? '' : "expected ~{$expected}, got {$actual}"
        );
    }

    public static function summary(): int
    {
        $total = self::$passed + count(self::$failed);
        echo "\n" . str_repeat('─', 60) . "\n";
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

/* ------------------------------------------------- level and XP curve */

T::group('Level curve (§26)');

// The exact pairs the spec pins the formula to.
foreach ([0 => 0, 20 => 1, 50 => 2, 100 => 3, 170 => 4, 260 => 5, 370 => 6, 500 => 7, 650 => 8] as $xp => $expected) {
    T::same("XP {$xp} → level {$expected}", $expected, Level::forXp($xp));
}

T::same('XP below the first level stays at 0', 0, Level::forXp(19));
T::same('XP 9 does not underflow the square root', 0, Level::forXp(9));
T::same('negative XP is clamped, not fatal', 0, Level::forXp(-500));
T::same('level 1 costs 20 XP', 20, Level::xpForLevel(1));
T::same('level 0 costs nothing', 0, Level::xpForLevel(0));

// Exactness on every boundary: floating-point sqrt must never show the
// level below the one a student just paid for.
$boundaryExact = true;
for ($level = 1; $level <= 400; $level++) {
    $cost = Level::xpForLevel($level);
    if (Level::forXp($cost) !== $level || Level::forXp($cost - 1) !== $level - 1) {
        $boundaryExact = false;
        break;
    }
}
T::ok('every boundary from level 1 to 400 is exact', $boundaryExact);

$progress = Level::progress(100);
T::same('progress reports the right level', 3, $progress['level']);
T::same('progress knows the next threshold', 170, $progress['next_level_xp']);
T::same('progress knows what is still owed', 70, $progress['xp_for_next']);
T::near('progress at a boundary is 0%', 0.0, $progress['percent']);

$mid = Level::progress(135);
T::ok('progress mid-level is between the ends', $mid['percent'] > 0 && $mid['percent'] < 100, $mid['percent'] . '%');
T::near('a zero-XP student is at 0%', 0.0, Level::progress(0)['percent']);

/* ------------------------------------------------------- rank titles */

T::group('Rank titles (§26-الف)');

$tiers = [];
$titles = ['تازه‌وارد جزیره', 'دانشجوی مقدماتی', 'دانشجوی بالینی', 'کارآموز', 'کارآموز کارکشته',
           'انترن', 'انترن ارشد', 'دستیار بالینی', 'رزیدنت سال اول', 'رزیدنت میانی',
           'رزیدنت ارشد', 'چیف رزیدنت', 'فلوی بالینی', 'متخصص جوان', 'متخصص بالینی',
           'متخصص ارشد', 'استاد بالینی', 'استاد برجسته هلکسا', 'افسانه جزیره بالین', 'اسطوره بالینی هلکسا'];
foreach ($titles as $index => $title) {
    $tiers[] = ['min_level' => $index * 10 + 1, 'max_level' => $index * 10 + 10, 'title' => $title, 'icon' => '🌱'];
}

T::same('level 1 → tier 1 step 1', 'تازه‌وارد جزیره · قدم ۱', RankTitle::forLevel(1, $tiers)['title']);
T::same('level 95 → رزیدنت میانی · قدم ۵', 'رزیدنت میانی · قدم ۵', RankTitle::forLevel(95, $tiers)['title']);
T::same('level 200 → last tier step 10', 'اسطوره بالینی هلکسا · قدم ۱۰', RankTitle::forLevel(200, $tiers)['title']);
T::same('level 10 is the last step of tier 1', 10, RankTitle::forLevel(10, $tiers)['step']);
T::same('level 11 opens tier 2', 1, RankTitle::forLevel(11, $tiers)['step']);

// TEST 46 — beyond the last tier must not error and must follow the
// configured fallback rather than an unwritten assumption.
$over = RankTitle::forLevel(201, $tiers, 'repeat_last');
T::same('TEST 46 · level 201 repeats the last tier as step 11', 'اسطوره بالینی هلکسا · قدم ۱۱', $over['title']);
T::ok('TEST 46 · level 201 is flagged as overflow', $over['is_overflow']);
T::same('TEST 46 · level 350 keeps counting', 'اسطوره بالینی هلکسا · قدم ۱۶۰', RankTitle::forLevel(350, $tiers, 'repeat_last')['title']);

$admin = RankTitle::forLevel(250, $tiers, 'admin_defined');
T::same('admin_defined mode shows the bare level instead', 'سطح ۲۵۰', $admin['title']);

T::same('level 0 names the first tier without a step', 'تازه‌وارد جزیره', RankTitle::forLevel(0, $tiers)['title']);
T::ok('an install with no tiers still returns a title', RankTitle::forLevel(5, [])['title'] !== '');

/* ----------------------------------------------------------- mastery */

T::group('Mastery, accuracy and CPS (§29, §30, §32)');

$weights = ['easy' => 1.0, 'medium' => 2.0, 'hard' => 3.0, 'expert' => 4.0];
T::near('easy weighs 1', 1.0, Mastery::weightFor('easy', false, $weights));
T::near('expert weighs 4', 4.0, Mastery::weightFor('expert', false, $weights));

T::near('a perfect set is 100%', 100.0, Mastery::fromAnswers([
    ['weight' => 1.0, 'is_correct' => true],
    ['weight' => 4.0, 'is_correct' => true],
]));
T::near('an empty set is 0, not a division by zero', 0.0, Mastery::fromAnswers([]));

// The whole point of weighting: same hit count, different clinical meaning.
$easyRight = Mastery::fromAnswers([
    ['weight' => 1.0, 'is_correct' => true],
    ['weight' => 4.0, 'is_correct' => false],
]);
$hardRight = Mastery::fromAnswers([
    ['weight' => 1.0, 'is_correct' => false],
    ['weight' => 4.0, 'is_correct' => true],
]);
T::near('one easy right out of two = 20%', 20.0, $easyRight);
T::near('one expert right out of two = 80%', 80.0, $hardRight);
T::ok('weighting separates the two', $hardRight > $easyRight);

T::near('a final case step weighs 1.5× more', 3.0, Mastery::weightFor('medium', true, $weights));

T::near('accuracy is a plain hit rate', 75.0, Mastery::accuracy(3, 4));
T::near('accuracy of nothing is 0', 0.0, Mastery::accuracy(0, 0));

// TEST 53 — a tiny sample must not be presented as a mastery figure.
T::ok('TEST 53 · 2 of 10 answers is not reliable', !Mastery::isReliable(2, 10));
T::ok('TEST 53 · 10 of 10 answers is reliable', Mastery::isReliable(10, 10));
T::ok('TEST 53 · a zero minimum still needs one answer', !Mastery::isReliable(0, 0));

T::near('CPS combines its five parts', 80.0, Mastery::clinicalPerformanceScore(80, 80, 80, 80, 80));
T::near('CPS of nothing is 0', 0.0, Mastery::clinicalPerformanceScore(0, 0, 0, 0, 0));
$cps = Mastery::clinicalPerformanceScore(90, 70, 60, 50, 40);
T::near('CPS weights accuracy heaviest', 0.30 * 90 + 0.25 * 70 + 0.20 * 60 + 0.15 * 50 + 0.10 * 40, $cps);
T::ok('CPS is clamped to 100', Mastery::clinicalPerformanceScore(500, 500, 500, 500, 500) <= 100);

T::near('consistency of 12 active days in 30', 40.0, Mastery::consistency(12, 30));
T::near('consistency on day zero does not divide by zero', 100.0, Mastery::consistency(1, 0));

/* ------------------------------------------------------ badge tiers */

T::group('Badge tiers (§44)');

$thresholds = ['bronze' => 10, 'silver' => 30, 'gold' => 75, 'platinum' => 150];
T::same('below bronze earns nothing', null, Mastery::badgeTier(9, $thresholds));
T::same('10 earns bronze', 'bronze', Mastery::badgeTier(10, $thresholds));
T::same('74 is still silver', 'silver', Mastery::badgeTier(74, $thresholds));
T::same('150 earns platinum', 'platinum', Mastery::badgeTier(150, $thresholds));
T::same('far past the top stays platinum', 'platinum', Mastery::badgeTier(9999, $thresholds));
T::same('a track with no thresholds earns nothing', null, Mastery::badgeTier(500, []));

/* --------------------------------------------------- publication gate */

T::group('Publication and access (§3, §39, §40)');

$case = static fn (string $status, bool $access): string => Access::evaluate($status, $access)['reason'];

T::same('published + access → content', Access::OK, $case(BalinSettings::STATUS_PUBLISHED, true));
T::same('published without access → denied', Access::NO_ACCESS, $case(BalinSettings::STATUS_PUBLISHED, false));
T::same('coming soon outranks access', Access::COMING_SOON, $case(BalinSettings::STATUS_COMING_SOON, true));
T::same('maintenance closes it for everyone', Access::MAINTENANCE, $case(BalinSettings::STATUS_MAINTENANCE, true));
T::same('disabled closes it for everyone', Access::DISABLED, $case(BalinSettings::STATUS_DISABLED, true));

T::ok('no state but published ever allows content', array_reduce(
    [BalinSettings::STATUS_COMING_SOON, BalinSettings::STATUS_MAINTENANCE, BalinSettings::STATUS_DISABLED],
    static fn (bool $carry, string $status): bool => $carry && !Access::evaluate($status, true)['allowed'],
    true
));

/* ------------------------------------------------------------ finish */

$exitCode = T::summary();

if (in_array('--db', $argv, true)) {
    require __DIR__ . '/balin_db_test.php';
    exit(balin_run_db_tests());
}

exit($exitCode);
