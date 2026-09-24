<?php
declare(strict_types=1);

/**
 * HeleXa Med — question bank test suite.
 *
 *     php tests/qbank_test.php          # pure rules only
 *     php tests/qbank_test.php --db     # also the database-backed flows
 *
 * The database tests need 2026_09_16_question_bank.sql applied. They create a
 * throwaway student, subject tree, tag and question, and remove all of them at
 * the end. Run against a staging copy, not production.
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
use HeleXa\Models\QuestionBank\QbAccessRepository;
use HeleXa\Models\QuestionBank\QbPracticeRepository;
use HeleXa\Models\QuestionBank\QbQuestionRepository;
use HeleXa\Models\QuestionBank\QbSubjectRepository;
use HeleXa\Models\QuestionBank\QbTagRepository;
use HeleXa\Models\SettingRepository;
use HeleXa\Services\QuestionBank\QbAccess;
use HeleXa\Services\QuestionBank\QbImageStorage;
use HeleXa\Services\Settings;

final class T
{
    public static int $passed = 0;
    public static array $failed = [];
    private static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public static function ok(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m {$name}\n";
            return;
        }
        self::$failed[] = self::$group . ' → ' . $name . ($detail !== '' ? " ({$detail})" : '');
        echo "  \033[31m✗ {$name}" . ($detail !== '' ? "  {$detail}" : '') . "\033[0m\n";
    }

    public static function same(string $name, mixed $expected, mixed $actual): void
    {
        self::ok($name, $expected === $actual,
            $expected === $actual ? '' : 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    public static function throws(string $name, callable $fn): void
    {
        try {
            $fn();
            self::ok($name, false, 'no exception');
        } catch (\RuntimeException) {
            self::ok($name, true);
        }
    }

    public static function summary(): int
    {
        $total = self::$passed + count(self::$failed);
        echo "\n" . str_repeat('─', 62) . "\n";
        if (self::$failed === []) {
            echo "\033[32m" . self::$passed . "/{$total} checks passed\033[0m\n";
            return 0;
        }
        echo "\033[31m" . count(self::$failed) . " of {$total} failed:\033[0m\n";
        foreach (self::$failed as $f) {
            echo "  • {$f}\n";
        }
        return 1;
    }
}

/* ================================================== 1. the access gate */

T::group('Access decision');

T::same('published + grant → allowed', true, QbAccess::evaluate('published', true)['allowed']);
T::same('published + no grant → no_access', 'no_access', QbAccess::evaluate('published', false)['reason']);
T::same('coming soon beats a grant', 'coming_soon', QbAccess::evaluate('coming_soon', true)['reason']);
T::same('disabled beats a grant', 'disabled', QbAccess::evaluate('disabled', true)['reason']);

/* ================================================ 2. pasted images */

T::group('Data URL decoding');

$png = base64_encode("\x89PNG\r\n\x1a\nrest");
T::same('a png data URL decodes', "\x89PNG\r\n\x1a\nrest", QbImageStorage::decodeDataUrl('data:image/png;base64,' . $png));
T::same('a non-image data URL is refused', null, QbImageStorage::decodeDataUrl('data:text/html;base64,PGI+'));
T::same('a plain URL is not a data URL', null, QbImageStorage::decodeDataUrl('https://example.com/a.png'));
T::same('malformed base64 is refused', null, QbImageStorage::decodeDataUrl('data:image/png;base64,@@@@'));
T::same('an empty payload is refused', null, QbImageStorage::decodeDataUrl('data:image/png;base64,'));

T::group('Stored-name resolution');

foreach (['../config/config.php', 'a/b.png', 'a\\b.png', "x\0.png", '', '..'] as $hostile) {
    T::same('refuses ' . json_encode($hostile), null, QbImageStorage::resolve($hostile));
}

if (!in_array('--db', $argv, true)) {
    echo "\n\033[90mDatabase-backed groups skipped. Re-run with --db to include them.\033[0m\n";
    exit(T::summary());
}

/* ======================================================= database */

Config::loadFile('app', CONFIG_PATH . '/config.php');
Config::loadFile('security', CONFIG_PATH . '/security.php');
date_default_timezone_set((string) Config::get('app.app.timezone', 'Asia/Tehran'));

try {
    Database::connection();
} catch (\Throwable $e) {
    echo "\n\033[31mDatabase unavailable: {$e->getMessage()}\033[0m\n";
    exit(1);
}

$subjects  = new QbSubjectRepository();
$tagsRepo  = new QbTagRepository();
$questions = new QbQuestionRepository();
$access    = new QbAccessRepository();
$practice  = new QbPracticeRepository();
$settings  = new SettingRepository();

$suffix     = substr(Str::uuid4(), 0, 8);
$createdSub = [];
$createdTag = null;
$userId     = null;

$previousStatus = QbAccess::status();

try {
    /* --------------------------------------------------- 3. the tree */
    T::group('Subject tree');

    $root = $subjects->create(null, ['title' => "آزمایشی {$suffix}"], null);
    $createdSub[] = $root;
    $sub   = $subjects->create($root, ['title' => 'زیردرس'], null);
    $topic = $subjects->create($sub, ['title' => 'عنوان'], null);

    T::same('a root is depth 1', 1, (int) $subjects->find($root)['depth']);
    T::same('a child is depth 2', 2, (int) $subjects->find($sub)['depth']);
    T::same('a grandchild is depth 3', 3, (int) $subjects->find($topic)['depth']);
    T::throws('a fourth level is refused', fn () => $subjects->create($topic, ['title' => 'x'], null));
    T::throws('a duplicate root title is refused', fn () => $subjects->create(null, ['title' => "آزمایشی {$suffix}"], null));
    T::throws('a duplicate sibling title is refused', fn () => $subjects->create($root, ['title' => 'زیردرس'], null));
    T::ok('the same title under another parent is fine',
        $subjects->create($sub, ['title' => 'زیردرس'], null) > 0);
    T::ok('a topic is a descendant of its root', $subjects->isDescendantOf($topic, $root));
    T::ok('a root is not a descendant of its topic', !$subjects->isDescendantOf($root, $topic));

    /* --------------------------------------------------- 4. questions */
    T::group('Questions');

    $createdTag = $tagsRepo->create("برچسب {$suffix}", 'chip-blue', 0);

    $uuid = $questions->create([
        'subject_id' => $root, 'sub_subject_id' => $sub, 'topic_id' => $topic,
        'stem_text' => 'سوال آزمایشی؟', 'stem_image' => null, 'difficulty' => 'hard',
        'explanation_text' => 'توضیح', 'explanation_image' => null, 'status' => 'published',
    ], [
        ['body_text' => 'درست', 'body_image' => null, 'is_correct' => true],
        ['body_text' => 'غلط',  'body_image' => null, 'is_correct' => false],
    ], [$createdTag, $createdTag], null);

    $q  = $questions->findByUuid($uuid);
    $id = (int) $q['id'];

    T::same('options are stored', 2, count($questions->optionsFor($id)));
    T::same('a repeated tag id is stored once', 1, count($questions->tagsFor($id)));
    T::same('the subject filter matches through a زیردرس', 1,
        $questions->countMatching(['subject_id' => (string) $sub, 'q' => 'آزمایشی']));
    T::same('the text search works with native prepares', 1,
        $questions->countMatching(['q' => 'سوال آزمایشی']));

    T::throws('a stale version is refused', fn () => $questions->update($id, 999, [
        'subject_id' => $root, 'sub_subject_id' => null, 'topic_id' => null,
        'stem_text' => 'x', 'stem_image' => null, 'difficulty' => 'easy',
        'explanation_text' => null, 'explanation_image' => null, 'status' => 'draft',
    ], [['body_text' => 'a', 'body_image' => null, 'is_correct' => true]], []));
    T::same('and nothing changed', 'سوال آزمایشی؟', $questions->findByUuid($uuid)['stem_text']);

    /* --------------------------------------------------- 5. access */
    T::group('Student access');

    $role = Database::selectOne("SELECT id FROM roles WHERE slug = 'student' LIMIT 1");
    $userId = (new \HeleXa\Models\UserRepository())->create([
        'uuid' => Str::uuid4(), 'role_id' => (int) $role['id'],
        'username' => "qbtest_{$suffix}", 'full_name' => 'دانشجوی آزمایشی',
        'password_hash' => \HeleXa\Services\Auth::hashPassword('Student12345'),
    ]);

    $settings->set('qbank_status', 'published', 'string', null);
    Settings::flush();

    T::same('no grant by default', false, QbAccess::allowsSubject($userId, $root));
    $access->grant($userId, $root, null);
    $access->grant($userId, $root, null);
    T::same('a double grant stores one row', 1, count($access->subjectIdsFor($userId)));
    T::same('granted → allowed', true, QbAccess::allowsSubject($userId, $root));

    $settings->set('qbank_status', 'coming_soon', 'string', null);
    Settings::flush();
    T::same('closing the bank closes a granted subject', false, QbAccess::allowsSubject($userId, $root));
    $settings->set('qbank_status', 'published', 'string', null);
    Settings::flush();

    $subjects->toggle($root);
    T::same('a deactivated subject hides from the student', [], $access->subjectIdsFor($userId));
    $access->sync($userId, [], null);
    $subjects->toggle($root);
    T::same('sync with nothing revokes, including an inactive subject', [], $access->subjectIdsFor($userId));
    $access->grant($userId, $root, null);

    /* --------------------------------------------------- 6. practice */
    T::group('Practice');

    T::same('the published question is in the sequence', 1, $practice->count($userId, $root, []));
    $shown = $practice->at($userId, $root, [], 0);
    T::ok('the shown row carries no answer key', !array_key_exists('is_correct', $shown));
    foreach ($practice->optionsWithoutKey($id) as $option) {
        T::ok('an option row carries no answer key', !array_key_exists('is_correct', $option));
    }

    $keyed = $practice->optionsWithKey($id);
    $wrong = array_values(array_filter($keyed, fn ($o) => (int) $o['is_correct'] === 0))[0];
    $right = array_values(array_filter($keyed, fn ($o) => (int) $o['is_correct'] === 1))[0];

    T::same('"new" includes an untried question', 1, $practice->count($userId, $root, ['mode' => 'new']));
    $practice->recordAttempt($userId, $id, (int) $wrong['id'], $root, false);
    T::same('"new" drops it once tried', 0, $practice->count($userId, $root, ['mode' => 'new']));
    T::same('"wrong" includes it after a miss', 1, $practice->count($userId, $root, ['mode' => 'wrong']));
    $practice->recordAttempt($userId, $id, (int) $right['id'], $root, true);
    T::same('"wrong" drops it once answered right', 0, $practice->count($userId, $root, ['mode' => 'wrong']));

    T::same('"answered" includes it', 1, $practice->count($userId, $root, ['mode' => 'answered']));
    T::same('"correct" includes it now', 1, $practice->count($userId, $root, ['mode' => 'correct']));
    T::same('text search finds it', 1, $practice->count($userId, $root, ['q' => 'آزمایشی']));
    T::same('text search misses other text', 0, $practice->count($userId, $root, ['q' => 'نامربوط']));

    $last = $practice->lastAttempt($userId, $id);
    T::same('the last attempt is the right one', true, $last['is_correct']);
    T::same('the last pick is remembered', $right['uuid'], $last['option_uuid']);
    T::same('both attempts are counted', 2, $last['attempts']);

    T::same('a mark toggles on', true, $practice->toggleMark($userId, $id, 'saved'));
    T::same('"saved" finds it', 1, $practice->count($userId, $root, ['mode' => 'saved']));
    T::same('"review" does not', 0, $practice->count($userId, $root, ['mode' => 'review']));
    T::same('the same mark toggles off', false, $practice->toggleMark($userId, $id, 'saved'));
    T::same('and "saved" is empty again', 0, $practice->count($userId, $root, ['mode' => 'saved']));

    // Only the first attempt counts: wrong-then-right is 100% on the wrong option.
    $dist = $practice->distribution($id);
    T::same('one respondent', 1, $dist['total']);
    T::same('their first pick carries the share', 100, $dist['options'][$wrong['uuid']] ?? null);

    $listed = $practice->page($userId, $root, [], 10, 0);
    T::same('the list row carries the latest result', 1, (int) $listed[0]['last_correct']);

    $stats = $practice->statsFor($userId, $root);
    T::same('two attempts are counted', 2, $stats['answered']);
    T::same('one of them correct', 1, $stats['correct']);
    T::same('on one distinct question', 1, $stats['distinct']);

    $questions->setStatus($id, 'draft');
    T::same('a draft leaves the student sequence', 0, $practice->count($userId, $root, []));
    T::same('and cannot be found as published', null, $practice->findPublished($uuid));

    /* --------------------------------------------------- 7. deletion */
    T::group('Deleting a subject');

    $subjects->delete($root);
    $createdSub = [];
    $after = $questions->findByUuid($uuid);
    T::ok('the question survives', $after !== null);
    T::same('and becomes unfiled', [null, null, null],
        [$after['subject_id'], $after['sub_subject_id'], $after['topic_id']]);
    T::same('the grant went with the subject', [], $access->subjectIdsFor($userId));
} finally {
    /* --------------------------------------------------- clean-up */
    if (isset($id)) {
        Database::execute('DELETE FROM qb_questions WHERE id = :id', ['id' => $id]);
    }
    foreach ($createdSub as $sid) {
        Database::execute('DELETE FROM qb_subjects WHERE id = :id', ['id' => $sid]);
    }
    if ($createdTag !== null) {
        Database::execute('DELETE FROM qb_tags WHERE id = :id', ['id' => $createdTag]);
    }
    if ($userId !== null) {
        Database::execute('DELETE FROM users WHERE id = :id', ['id' => $userId]);
    }
    $settings->set('qbank_status', $previousStatus, 'string', null);
    Settings::flush();
}

exit(T::summary());
