<?php
declare(strict_types=1);

/**
 * HeleXa Med — flashcards test suite.
 *
 *     php tests/flashcards_test.php          # scheduler and importer, no database
 *     php tests/flashcards_test.php --db     # plus access, study and import flows
 *
 * The .xlsx case builds a real workbook with ZipArchive at run time, so the
 * reader is exercised against the same zip-of-XML structure Excel writes.
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
use HeleXa\Models\Flashcards\FcCatalogRepository;
use HeleXa\Models\Flashcards\FcStudyRepository;
use HeleXa\Services\Flashcards\CardImporter;
use HeleXa\Services\Flashcards\FcAccess;
use HeleXa\Services\Flashcards\Scheduler;
use HeleXa\Services\Flashcards\XlsxReader;

final class T
{
    public static int $passed = 0;
    public static array $failed = [];
    private static string $group = '';

    public static function group(string $n): void { self::$group = $n; echo "\n\033[1m{$n}\033[0m\n"; }

    public static function ok(string $name, bool $c, string $d = ''): void
    {
        if ($c) { self::$passed++; echo "  \033[32m✓\033[0m {$name}\n"; return; }
        self::$failed[] = self::$group . ' → ' . $name . ($d !== '' ? " ({$d})" : '');
        echo "  \033[31m✗ {$name}" . ($d !== '' ? "  {$d}" : '') . "\033[0m\n";
    }

    public static function same(string $name, mixed $e, mixed $a): void
    {
        self::ok($name, $e === $a, $e === $a ? '' : 'expected ' . var_export($e, true) . ', got ' . var_export($a, true));
    }

    public static function throws(string $name, callable $fn): void
    {
        try { $fn(); self::ok($name, false, 'no exception'); } catch (\RuntimeException) { self::ok($name, true); }
    }

    public static function summary(): int
    {
        $t = self::$passed + count(self::$failed);
        echo "\n" . str_repeat('─', 62) . "\n";
        if (self::$failed === []) { echo "\033[32m" . self::$passed . "/{$t} checks passed\033[0m\n"; return 0; }
        echo "\033[31m" . count(self::$failed) . " of {$t} failed:\033[0m\n";
        foreach (self::$failed as $f) { echo "  • {$f}\n"; }
        return 1;
    }
}

$now = new \DateTimeImmutable('2026-01-01 10:00:00');

/* ============================================================ scheduler */

T::group('Scheduler');

$new = null;
T::same('good on a new card → 1 day', 1, Scheduler::next($new, Scheduler::GOOD, $now)['interval_days']);
T::same('easy on a new card → 4 days', 4, Scheduler::next($new, Scheduler::EASY, $now)['interval_days']);
T::same('hard on a new card → 1 day', 1, Scheduler::next($new, Scheduler::HARD, $now)['interval_days']);

$again = Scheduler::next($new, Scheduler::AGAIN, $now);
T::same('again → back in ten minutes', '2026-01-01 10:10:00', $again['due_at']);
T::same('again on a new card is not a lapse', 0, $again['lapses']);

$s1 = Scheduler::next($new, Scheduler::GOOD, $now);
$s2 = Scheduler::next($s1, Scheduler::GOOD, $now);
T::same('second good → 3 days', 3, $s2['interval_days']);
$s3 = Scheduler::next($s2, Scheduler::GOOD, $now);
T::same('third good → interval × ease (3 × 2.5 → 8)', 8, $s3['interval_days']);
T::same('due date follows the interval', '2026-01-09 10:00:00', $s3['due_at']);

$lapse = Scheduler::next($s3, Scheduler::AGAIN, $now);
T::same('forgetting a learned card counts a lapse', 1, $lapse['lapses']);
T::same('and restarts the run', 0, $lapse['reps']);
T::same('and lowers ease', 230, $lapse['ease']);

$state = ['reps' => 5, 'lapses' => 0, 'ease' => 130, 'interval_days' => 10];
T::same('ease never drops below 1.3', 130, Scheduler::next($state, Scheduler::HARD, $now)['ease']);
$state = ['reps' => 9, 'lapses' => 0, 'ease' => 350, 'interval_days' => 300];
T::same('interval never exceeds a year', 365, Scheduler::next($state, Scheduler::EASY, $now)['interval_days']);
T::ok('intervals always grow on success',
    Scheduler::next(['reps' => 3, 'lapses' => 0, 'ease' => 130, 'interval_days' => 1], Scheduler::GOOD, $now)['interval_days'] > 1);

T::same('stage of a new card', 'new', Scheduler::stage(null));
T::same('stage after again', 'learning', Scheduler::stage($again));
T::same('stage at 21 days', 'mastered', Scheduler::stage(['reps' => 4, 'interval_days' => 21]));
T::same('preview for good on a new card', '1 روز', Scheduler::previews(null, $now)[3]);

/* ============================================================= importer */

T::group('Importer: pasted and CSV text');

$tsv = "روی کارت\tپشت کارت\nAbdomen\tشکم\tراهنما\nThorax\tقفسه سینه\n\t بدون رو\n\n";
$r = CardImporter::fromText($tsv);
T::same('header row skipped, blank line ignored', 2, count($r['cards']));
T::same('row with no front counted as skipped', 1, $r['skipped']);
T::same('hint read from column C', 'راهنما', $r['cards'][0]['hint']);
T::same('missing hint is null', null, $r['cards'][1]['hint']);

$csv = "\xEF\xBB\xBFfront,back\n\"Heart, left\",\"قلب\nچپ\"\n";
$r = CardImporter::fromText($csv);
T::same('BOM stripped and quoted comma kept', 'Heart, left', $r['cards'][0]['front']);
T::same('quoted line break kept', "قلب\nچپ", $r['cards'][0]['back']);

$semi = "Liver;کبد\nKidney;کلیه\n";
T::same('semicolon CSV detected', 'کبد', CardImporter::fromText($semi)['cards'][0]['back']);

$win1256 = function_exists('iconv') ? iconv('UTF-8', 'Windows-1256', "Lung,ریه\n") : false;
if ($win1256 !== false) {
    T::same('Windows-1256 CSV converted', 'ریه', CardImporter::fromText($win1256)['cards'][0]['back']);
}

$withSession = CardImporter::fromText("Eye\tچشم\t\tجلسه ۲\n");
T::same('column D read as session', 'جلسه ۲', $withSession['cards'][0]['session']);
T::throws('text with no valid card is refused', fn () => CardImporter::fromText("only-front\n\n"));
T::throws('empty text is refused', fn () => CardImporter::fromText("   "));

T::group('Importer: xlsx');

if (!XlsxReader::available()) {
    echo "  \033[33m! ZipArchive/XMLReader missing on this PHP — xlsx import will ask users to paste instead\033[0m\n";
} else {
    $path = tempnam(sys_get_temp_dir(), 'fcx') . '.xlsx';
    $zip  = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Cards" sheetId="1" r:id="rId7"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId7" Type="worksheet" Target="worksheets/cards.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml',
        '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<si><t>روی کارت</t></si><si><t>پشت کارت</t></si>'
        . '<si><t>Spleen</t></si><si><r><t>طحا</t></r><r><t>ل</t></r></si></sst>');
    $zip->addFromString('xl/worksheets/cards.xml',
        '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
        . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c></row>'
        . '<row r="3"><c r="A3" t="inlineStr"><is><t>Colon</t></is></c><c r="C3" t="inlineStr"><is><t>روده</t></is></c></row>'
        . '<row r="4"><c r="A4"><v>42</v></c><c r="B4" t="str"><f>A4</f><v>چهل و دو</v></c></row>'
        . '</sheetData></worksheet>');
    $zip->close();

    $rows = XlsxReader::rows($path, 100);
    T::same('sheet found through the relationship, not by name', 4, count($rows));
    T::same('rich-text shared string concatenated', 'طحال', $rows[1][1]);
    T::same('a gap cell is filled so column C stays index 2', ['Colon', '', 'روده'], $rows[2]);
    T::same('formula cell uses its cached value', 'چهل و دو', $rows[3][1]);

    $r = CardImporter::fromUpload(['tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK, 'name' => 'x.xlsx']);
    T::same('xlsx → two cards (header skipped, row without back skipped)', 2, count($r['cards']));
    T::same('number cell read as text', '42', $r['cards'][1]['front']);
    @unlink($path);
}

$legacy = tempnam(sys_get_temp_dir(), 'fcl');
file_put_contents($legacy, "\xD0\xCF\x11\xE0\x00\x00binary");
T::throws('an old binary .xls is refused with advice', fn () => CardImporter::fromUpload(
    ['tmp_name' => $legacy, 'size' => 14, 'error' => UPLOAD_ERR_OK, 'name' => 'old.xls']));
@unlink($legacy);

if (!in_array('--db', $argv, true)) {
    echo "\n\033[90mDatabase-backed groups skipped. Re-run with --db to include them.\033[0m\n";
    exit(T::summary());
}

/* ============================================================== database */

Config::loadFile('app', CONFIG_PATH . '/config.php');
Config::loadFile('security', CONFIG_PATH . '/security.php');
date_default_timezone_set((string) Config::get('app.app.timezone', 'Asia/Tehran'));
try { Database::connection(); } catch (\Throwable $e) { echo "DB unavailable: {$e->getMessage()}\n"; exit(1); }

$catalog = new FcCatalogRepository();
$study   = new FcStudyRepository();
$suffix  = substr(Str::uuid4(), 0, 8);
$users   = [];
$courseId = null;

try {
    $role = Database::selectOne("SELECT id FROM roles WHERE slug = 'student' LIMIT 1");
    foreach (['a', 'b'] as $who) {
        $users[$who] = (new \HeleXa\Models\UserRepository())->create([
            'uuid' => Str::uuid4(), 'role_id' => (int) $role['id'],
            'username' => "fct_{$who}_{$suffix}", 'full_name' => "آزمایشی {$who}",
            'password_hash' => \HeleXa\Services\Auth::hashPassword('Student12345'),
        ]);
    }
    [$a, $b] = [$users['a'], $users['b']];

    T::group('Decks and access');

    T::throws('a deck with neither parent is refused', function () use ($catalog) {
        try { $catalog->createDeck(null, null, 'x'); } catch (\LogicException $e) { throw new \RuntimeException('ok'); }
    });

    $courseId = $catalog->saveCourse(null, ['title' => "درس آزمایشی {$suffix}", 'status' => 'draft'], null);
    $session  = $catalog->createDeck($courseId, null, 'جلسه ۱');
    $sessUuid = $catalog->deckById($session)['uuid'];
    $catalog->addCard($session, 'Abdomen', 'شکم', null);
    $cardUuid = $catalog->cardsOfDeck($session)[0]['uuid'];
    $cardId   = (int) $catalog->cardsOfDeck($session)[0]['id'];

    T::same('no grant → no session', null, FcAccess::deck($a, $sessUuid));
    $study->syncAccess($a, [$courseId], null);
    T::same('granted but draft → no session', null, FcAccess::deck($a, $sessUuid));
    $catalog->saveCourse($courseId, ['title' => "درس آزمایشی {$suffix}", 'status' => 'published'], null);
    $open = FcAccess::deck($a, $sessUuid);
    T::ok('granted and published → session', $open !== null);
    T::same('a course session is read-only', false, $open['editable']);
    T::same('another student still cannot open it', null, FcAccess::deck($b, $sessUuid));

    $own     = $catalog->createDeck(null, $a, 'دسته من');
    $ownUuid = $catalog->deckById($own)['uuid'];
    T::same('an owner may edit their deck', true, FcAccess::deck($a, $ownUuid)['editable']);
    T::same('nobody else may open it', null, FcAccess::deck($b, $ownUuid));

    $later = $catalog->createDeck($courseId, null, 'جلسه ۲');
    T::ok('a session added later reaches the holder without a new grant',
        FcAccess::deck($a, $catalog->deckById($later)['uuid']) !== null);

    T::group('Study');

    $q = $study->queue($a, [$session], 'due', 20, 50, false);
    T::same('a new card is offered in the due queue', 1, count($q));

    T::same('star on an unseen card', true, $study->toggleStar($a, $cardId));
    T::same('a star alone is not a review: still offered as new', 1, count($study->queue($a, [$session], 'due', 20, 50, false)));
    T::same('and not counted as seen', [], $study->deckStats($a, [$session]));
    T::same('starred mode finds it', 1, count($study->queue($a, [$session], 'starred', 20, 50, false)));

    $study->rate($a, $cardId, Scheduler::GOOD);
    T::same('after good, nothing due today', 0, count($study->queue($a, [$session], 'due', 0, 50, false)));
    T::same('the star survived the rating', true, (int) $study->stateFor($a, $cardId)['starred'] === 1);
    T::same('seen count is 1', 1, $study->deckStats($a, [$session])[$session]['seen']);

    $study->rate($a, $cardId, Scheduler::AGAIN);
    T::same('after again, the card is in "hard"', 1, count($study->queue($a, [$session], 'hard', 0, 50, false)));

    $o = $study->overview($a, [$session]);
    T::same('two reviews today', 2, $o['today']);
    T::same('a streak of one day', 1, $o['streak']);

    T::same('another student has their own schedule', 1, count($study->queue($b, [$session], 'due', 20, 50, false)));

    $study->resetDeck($a, $session);
    T::same('reset forgets the schedule', null, $study->stateFor($a, $cardId));

    $catalog->updateCard($cardId, 'Abdomen (n.)', 'شکم', null);
    T::same('editing a card keeps its id', $cardUuid, $catalog->cardsOfDeck($session)[0]['uuid']);

    T::group('Course import');

    $cache = [];
    $rows  = CardImporter::fromText("Eye\tچشم\t\tجلسه ۳\nEar\tگوش\t\tجلسه ۳\nNose\tبینی\n");
    $added = $catalog->bulkInsert($rows['cards'], function (array $c) use (&$cache, $catalog, $courseId): int {
        $name = $c['session'] ?? 'عمومی';
        return $cache[$name] ??= $catalog->sessionNamed($courseId, $name);
    });
    T::same('three cards imported', 3, $added);
    T::same('two sessions created by name', 2, count($cache));
    T::same('sessionNamed reuses an existing session', $cache['جلسه ۳'], $catalog->sessionNamed($courseId, 'جلسه ۳'));
} finally {
    if ($courseId !== null) { $catalog->deleteCourse($courseId); }
    foreach ($users as $id) {
        Database::execute('DELETE FROM users WHERE id = :id', ['id' => $id]);
    }
}

exit(T::summary());
