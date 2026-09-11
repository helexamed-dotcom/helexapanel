<?php
declare(strict_types=1);

/**
 * Database-backed tests for Balin Island.
 *
 * Loaded by tests/balin_test.php when it is run with --db. These exercise the
 * rules that only exist once there is a database underneath them: the unique
 * constraints, the transactions, the gating, the attempt limits.
 *
 * Connection details come from config/config.php unless BALIN_TEST_DSN-style
 * environment variables are set:
 *
 *     BALIN_TEST_HOST, BALIN_TEST_PORT, BALIN_TEST_DB,
 *     BALIN_TEST_USER, BALIN_TEST_PASS
 *
 * Every row written here is created under a throwaway student and removed at
 * the end, along with the lesson the fixtures hang off.
 */

use HeleXa\Core\Config;
use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Models\Balin\BalinAccessRepository;
use HeleXa\Models\Balin\BalinCheckpointRepository;
use HeleXa\Models\Balin\BalinProgressRepository;
use HeleXa\Models\Balin\BalinQuestionRepository;
use HeleXa\Models\Balin\BalinStageRepository;
use HeleXa\Models\Balin\BalinStatsRepository;
use HeleXa\Models\Balin\BalinXpRepository;
use HeleXa\Services\Balin\Access;
use HeleXa\Services\Balin\AnswerService;
use HeleXa\Services\Balin\BalinSettings;
use HeleXa\Services\Balin\CheckpointService;
use HeleXa\Services\Balin\Level;
use HeleXa\Services\Balin\Recalculator;
use HeleXa\Services\Balin\StageGate;
use HeleXa\Services\Balin\StageService;

function balin_run_db_tests(): int
{
    balin_connect();

    $fixtures = balin_seed();

    try {
        balin_test_answers($fixtures);
        balin_test_skill_mastery($fixtures);
        balin_test_stage_unlock($fixtures);
        balin_test_checkpoint($fixtures);
        balin_test_streak_and_level($fixtures);
    } finally {
        balin_teardown($fixtures);
    }

    return T::summary();
}

/* ------------------------------------------------------------ plumbing */

function balin_connect(): void
{
    $configFile = BASE_PATH . '/config/config.php';

    if (getenv('BALIN_TEST_DB') !== false) {
        Config::hydrate('app', [
            'app'      => ['timezone' => 'Asia/Tehran', 'name' => 'HeleXa Med'],
            'database' => [
                'host'     => getenv('BALIN_TEST_HOST') ?: '127.0.0.1',
                'port'     => (int) (getenv('BALIN_TEST_PORT') ?: 3306),
                'name'     => getenv('BALIN_TEST_DB'),
                'user'     => getenv('BALIN_TEST_USER') ?: 'root',
                'password' => getenv('BALIN_TEST_PASS') ?: '',
                'charset'  => 'utf8mb4',
            ],
        ]);
    } elseif (is_file($configFile)) {
        Config::loadFile('app', $configFile);
    } else {
        echo "\n\033[31mNo database configuration found. Set BALIN_TEST_DB or install the app first.\033[0m\n";
        exit(1);
    }

    date_default_timezone_set((string) Config::get('app.app.timezone', 'Asia/Tehran'));

    try {
        Database::connection();
    } catch (\Throwable $e) {
        echo "\n\033[31mCould not connect to the test database: " . $e->getMessage() . "\033[0m\n";
        exit(1);
    }
}

/**
 * Builds a self-contained lesson: three stages, questions tagged against two
 * skill tracks, and a gating checkpoint exam in front of stage three.
 *
 * @return array<string,mixed>
 */
function balin_seed(): array
{
    $now = date('Y-m-d H:i:s');

    $roleId = (int) Database::scalar("SELECT id FROM roles WHERE slug = 'student' LIMIT 1");
    $suffix = substr(Str::token(4), 0, 8);

    $userId = Database::insert(
        'INSERT INTO users (uuid, username, full_name, password_hash, role_id, status, gender, created_at)
         VALUES (:uuid, :username, :name, :hash, :role, :status, :gender, :now)',
        [
            'uuid'     => Str::uuid4(),
            'username' => 'balintest_' . $suffix,
            'name'     => 'دانشجوی آزمایشی بالین',
            'hash'     => password_hash(Str::token(16), PASSWORD_DEFAULT),
            'role'     => $roleId,
            'status'   => 'active',
            'gender'   => 'male',
            'now'      => $now,
        ]
    );

    (new BalinAccessRepository())->grant($userId, null, 'test fixture');

    $lessonId = Database::insert(
        "INSERT INTO balin_lessons (uuid, slug, title, status, xp_reward, created_at)
         VALUES (:uuid, :slug, 'درس آزمایشی', 'published', 100, :now)",
        ['uuid' => Str::uuid4(), 'slug' => 'balin-test-' . $suffix, 'now' => $now]
    );

    $stages = [];
    foreach ([1, 2, 3] as $index) {
        $stages[$index] = Database::insert(
            "INSERT INTO balin_stages (uuid, lesson_id, title, display_order, status, xp_reward, created_at)
             VALUES (:uuid, :lesson, :title, :order, 'published', 25, :now)",
            [
                'uuid'   => Str::uuid4(),
                'lesson' => $lessonId,
                'title'  => 'مرحله ' . $index,
                'order'  => $index * 1000,
                'now'    => $now,
            ]
        );
    }

    // Two tracks, so a question can be tagged with one and not the other.
    $trackA = (int) Database::scalar("SELECT id FROM balin_skill_tracks WHERE slug = 'history-taking' LIMIT 1");
    $trackB = (int) Database::scalar("SELECT id FROM balin_skill_tracks WHERE slug = 'differential' LIMIT 1");

    $questions = new BalinQuestionRepository();

    /** Builds a two-option question whose first option is the correct one. */
    $makeQuestion = static function (
        int $lessonId,
        ?int $stageId,
        string $difficulty,
        array $trackIds,
        string $label
    ) use ($questions): array {
        $id = $questions->createWithOptions(
            [
                'uuid'        => Str::uuid4(),
                'lesson_id'   => $lessonId,
                'stage_id'    => $stageId,
                'prompt'      => $label,
                'explanation' => 'توضیح ' . $label,
                'difficulty'  => $difficulty,
                'xp_reward'   => 10,
                'is_required' => true,
                'status'      => 'published',
            ],
            [
                ['label' => 'A', 'body' => 'پاسخ درست',   'is_correct' => true],
                ['label' => 'B', 'body' => 'پاسخ نادرست', 'is_correct' => false],
            ],
            $trackIds
        );

        $options = $questions->options($id);

        return [
            'id'      => $id,
            'row'     => $questions->findById($id),
            'correct' => (int) $options[0]['id'],
            'wrong'   => (int) $options[1]['id'],
        ];
    };

    $stageOneQuestions = [
        $makeQuestion($lessonId, $stages[1], 'easy',   [$trackA], 'س۱ آسان با برچسب'),
        $makeQuestion($lessonId, $stages[1], 'expert', [$trackA], 'س۲ تخصصی با برچسب'),
        // Deliberately untagged: it must count toward the lesson but toward
        // no skill track at all.
        $makeQuestion($lessonId, $stages[1], 'medium', [],        'س۳ متوسط بدون برچسب'),
    ];

    $stageTwoQuestion = $makeQuestion($lessonId, $stages[2], 'medium', [$trackB], 'س۴ مرحله دو');

    // Exam pool questions live outside any stage.
    $examQuestions = [];
    foreach (range(1, 4) as $index) {
        $examQuestions[] = $makeQuestion($lessonId, null, 'medium', [$trackB], 'سؤال آزمون ' . $index);
    }

    $checkpoints = new BalinCheckpointRepository();
    $examId = $checkpoints->create([
        'uuid'                   => Str::uuid4(),
        'lesson_id'              => $lessonId,
        'position_type'          => 'before_stage',
        'anchor_stage_id'        => $stages[3],
        'title'                  => 'آزمون دروازه‌ای آزمایشی',
        'primary_skill_track_id' => $trackB,
        'question_source_mode'   => 'fixed_list',
        'num_questions'          => 4,
        'pass_threshold_percent' => 70,
        'is_gating'              => true,
        'max_attempts'           => 2,
        'cooldown_hours_between_attempts' => 0,
        'xp_reward'              => 50,
        'status'                 => 'published',
    ]);

    foreach ($examQuestions as $order => $question) {
        $checkpoints->attachQuestion($examId, $question['id'], $order + 1);
    }

    return [
        'user_id'    => $userId,
        'lesson_id'  => $lessonId,
        'stages'     => $stages,
        'track_a'    => $trackA,
        'track_b'    => $trackB,
        'stage_one'  => $stageOneQuestions,
        'stage_two'  => $stageTwoQuestion,
        'exam_id'    => $examId,
        'exam_questions' => $examQuestions,
    ];
}

function balin_teardown(array $fixtures): void
{
    // The lesson cascades to stages, blocks, questions, options, answers and
    // exams; the user cascades to progress, XP, streaks and stats.
    Database::execute('DELETE FROM balin_lessons WHERE id = :id', ['id' => $fixtures['lesson_id']]);
    Database::execute('DELETE FROM users WHERE id = :id', ['id' => $fixtures['user_id']]);
}

/* --------------------------------------------------------------- tests */

/**
 * XP from answering alone.
 *
 * The running total also carries achievement and mission bonuses, which are
 * correct but would make these assertions about the answer rules depend on
 * unrelated gamification. Summing one ledger type keeps each test about the
 * thing it is testing.
 */
function balin_question_xp(int $userId): int
{
    return (int) Database::scalar(
        "SELECT COALESCE(SUM(amount), 0) FROM balin_xp_transactions
         WHERE user_id = :user AND type = 'question_correct'",
        ['user' => $userId]
    );
}

function balin_test_answers(array $fixtures): void
{
    T::group('Answering (§21, §22, §23, §56) — database');

    $service = new AnswerService();
    $userId  = $fixtures['user_id'];
    $xp      = new BalinXpRepository();

    $first = $fixtures['stage_one'][0];

    $result = $service->submit($userId, $first['row'], $first['correct'], false, $fixtures['stages'][1]);
    T::ok('a correct answer is stored', $result['stored']);
    T::ok('a correct answer is graded correct', $result['is_correct']);
    T::same('a correct answer pays its XP', 10, $result['xp_awarded']);
    T::same('the ledger holds exactly that', 10, balin_question_xp($userId));

    // The same question again: the unique key decides, not a check-then-write.
    $again = $service->submit($userId, $first['row'], $first['correct'], false, $fixtures['stages'][1]);
    T::ok('answering twice is refused', !$again['stored']);
    T::same('and is reported as already answered', 'ALREADY_ANSWERED', $again['reason']);
    T::same('and pays nothing the second time', 10, balin_question_xp($userId));

    // A wrong answer still records, still explains, still pays nothing.
    $second = $fixtures['stage_one'][1];
    $wrong  = $service->submit($userId, $second['row'], $second['wrong'], false, $fixtures['stages'][1]);
    T::ok('a wrong answer is stored', $wrong['stored']);
    T::ok('a wrong answer is graded wrong', !$wrong['is_correct']);
    T::same('a wrong answer pays nothing', 0, $wrong['xp_awarded']);
    T::same('and it names the correct option', $second['correct'], $wrong['correct_option_id']);
    T::ok('and it returns the explanation', ($wrong['explanation'] ?? '') !== '');

    // Hint before a correct answer halves the award.
    $third = $fixtures['stage_one'][2];
    $hinted = $service->submit($userId, $third['row'], $third['correct'], true, $fixtures['stages'][1]);
    T::same('a hint halves the award', 5, $hinted['xp_awarded']);
    T::same('the ledger reflects the reduced award', 15, balin_question_xp($userId));

    // The cached figures must agree with the ledger they are derived from —
    // the whole ledger this time, bonuses included.
    $ledger = $xp->totalFor($userId);
    $stats  = (new BalinStatsRepository())->findOrEmpty($userId);
    T::same('cached XP matches the ledger', $ledger, (int) $stats['total_xp']);
    T::same('cached level matches the formula', Level::forXp($ledger), (int) $stats['cached_level']);
    T::same('answered count is right', 3, (int) $stats['answered_count']);
    T::same('correct count is right', 2, (int) $stats['correct_count']);

    // Unlocking the first-answer achievement is what puts the total above the
    // question XP; both are ledger rows, so both are auditable.
    T::ok('achievement XP is a separate ledger entry', $ledger > balin_question_xp($userId));
}

function balin_test_skill_mastery(array $fixtures): void
{
    T::group('Skill tracks (§F.1) — database');

    $userId = $fixtures['user_id'];
    $stats  = new BalinStatsRepository();
    $mastery = $stats->skillMastery($userId);

    // Track A carries two answered questions: one easy right (weight 1), one
    // expert wrong (weight 4). So 1 of 5 weighted units = 20%.
    $trackA = $mastery[$fixtures['track_a']] ?? null;
    T::ok('the tagged track has a cached row', $trackA !== null);
    T::same('only the tagged questions count', 2, (int) ($trackA['answered_count'] ?? 0));
    T::near('weighted mastery reflects difficulty', 20.0, (float) ($trackA['mastery_percent'] ?? 0));

    // TEST 47 — the untagged question must not have reached any track.
    $total = 0;
    foreach ($mastery as $row) {
        $total += (int) $row['answered_count'];
    }
    T::same('TEST 47 · an untagged question counts toward no track', 2, $total);

    // But it does count toward the lesson, which is the other half of the rule.
    $lessonRows = $stats->lessonMastery($userId);
    T::same('the untagged question still counts toward the lesson',
        3, (int) ($lessonRows[$fixtures['lesson_id']]['answered_count'] ?? 0));

    // TEST 52 — lesson mastery must not move when a track is deactivated.
    $before = (float) ($lessonRows[$fixtures['lesson_id']]['mastery_percent'] ?? 0);
    Database::execute('UPDATE balin_skill_tracks SET is_active = 0 WHERE id = :id', ['id' => $fixtures['track_a']]);
    (new Recalculator())->lessonMastery($userId, $fixtures['lesson_id']);
    $after = (float) (($stats->lessonMastery($userId))[$fixtures['lesson_id']]['mastery_percent'] ?? 0);
    Database::execute('UPDATE balin_skill_tracks SET is_active = 1 WHERE id = :id', ['id' => $fixtures['track_a']]);

    T::near('TEST 52 · deactivating a track leaves lesson mastery alone', $before, $after);
}

function balin_test_stage_unlock(array $fixtures): void
{
    T::group('Stage unlock and gating (§11, §35, §48) — database');

    $userId   = $fixtures['user_id'];
    $gate     = new StageGate();
    $stages   = new BalinStageRepository();
    $progress = new BalinProgressRepository();
    $service  = new StageService();

    $stageOne   = $stages->findById($fixtures['stages'][1]);
    $stageTwo   = $stages->findById($fixtures['stages'][2]);
    $stageThree = $stages->findById($fixtures['stages'][3]);

    T::ok('the first stage is open from the start', $gate->isUnlocked($userId, $stageOne));
    T::ok('a later stage starts locked', !$gate->isUnlocked($userId, $stageTwo));

    // Stage one's three required questions are all answered by now.
    T::ok('requirements are met once every required question is answered',
        $service->requirementsMet($userId, $stageOne));

    $completion = $service->complete($userId, $stageOne);
    T::ok('the stage completes', $completion['completed']);
    T::ok('and it is the first time', $completion['first_time']);
    T::same('and it pays its XP once', 25, $completion['xp_awarded']);

    // Replaying it pays nothing.
    $replay = $service->complete($userId, $stageOne);
    T::ok('replaying reports completion', $replay['completed']);
    T::ok('but not as a first time', !$replay['first_time']);
    T::same('and pays nothing again', 0, $replay['xp_awarded']);

    T::ok('finishing stage one opens stage two', $gate->isUnlocked($userId, $stageTwo));

    // Finish stage two so only the gating exam stands in front of stage three.
    $second = $fixtures['stage_two'];
    (new AnswerService())->submit($userId, $second['row'], $second['correct'], false, $fixtures['stages'][2]);
    $service->complete($userId, $stageTwo);

    T::ok('stage two is recorded complete', $progress->isCompleted($userId, $fixtures['stages'][2]));

    // TEST 48 — the previous stage is done, but the gating exam is not passed.
    T::ok('TEST 48 · a gating exam keeps the next stage locked',
        !$gate->isUnlocked($userId, $stageThree));

    $annotated = $gate->annotate($userId, $fixtures['lesson_id'], $stages->forLesson($fixtures['lesson_id'], true));
    $third = null;
    foreach ($annotated as $row) {
        if ((int) $row['id'] === $fixtures['stages'][3]) {
            $third = $row;
        }
    }
    T::same('TEST 48 · and the map agrees', StageGate::LOCKED, $third['state'] ?? '');
    T::ok('TEST 48 · and names the exam that is blocking it',
        str_contains((string) ($third['lock_reason'] ?? ''), 'آزمون'));
}

function balin_test_checkpoint(array $fixtures): void
{
    T::group('Checkpoint exams (§F.2) — database');

    $userId      = $fixtures['user_id'];
    $service     = new CheckpointService();
    $checkpoints = new BalinCheckpointRepository();
    $xp          = new BalinXpRepository();
    $exam        = $checkpoints->findById($fixtures['exam_id']);

    $gate = $service->canStart($userId, $exam);
    T::ok('a fresh exam can be started', $gate['allowed']);
    T::same('with the full allowance', 2, $gate['attempts_left']);

    // Attempt one: one right out of four = 25%, below the 70% threshold.
    $started = $service->start($userId, $exam);
    T::ok('an attempt opens', $started['attempt'] !== null);
    T::same('and draws the whole fixed list', 4, count($started['questions']));

    $failing = [];
    foreach ($fixtures['exam_questions'] as $index => $question) {
        $failing[$question['id']] = $index === 0 ? $question['correct'] : $question['wrong'];
    }

    $xpBefore = $xp->totalFor($userId);
    $result   = $service->submit($userId, $exam, $started['attempt'], $failing);

    T::ok('the attempt is graded', $result['graded']);
    T::near('the score is what was answered', 25.0, $result['score_percent']);
    T::ok('below the threshold is a fail', !$result['passed']);
    T::same('a failed attempt pays nothing', 0, $result['xp_awarded']);
    T::same('and the ledger is unchanged', $xpBefore, $xp->totalFor($userId));
    T::ok('the report breaks the paper down', count($result['breakdown']) === 4);
    T::ok('and groups it by skill', $service->skillBreakdown($result['breakdown']) !== []);

    // TEST 48 again, from the other side: a failed gating exam still locks.
    $stageThree = (new BalinStageRepository())->findById($fixtures['stages'][3]);
    T::ok('TEST 48 · a failed gating exam keeps the stage locked',
        !(new StageGate())->isUnlocked($userId, $stageThree));

    // Attempt two: all four right.
    $secondGate = $service->canStart($userId, $exam);
    T::ok('a second attempt is allowed', $secondGate['allowed']);
    T::same('with one left', 1, $secondGate['attempts_left']);

    $passing = [];
    foreach ($fixtures['exam_questions'] as $question) {
        $passing[$question['id']] = $question['correct'];
    }

    $secondAttempt = $service->start($userId, $exam);
    $passResult    = $service->submit($userId, $exam, $secondAttempt['attempt'], $passing);

    T::ok('a passing score passes', $passResult['passed']);
    T::near('and scores 100', 100.0, $passResult['score_percent']);
    T::ok('TEST 50 · the first pass is recognised as the first', $passResult['first_pass']);
    T::same('TEST 50 · and pays the reward', 50, $passResult['xp_awarded']);

    T::ok('passing the gating exam opens the stage',
        (new StageGate())->isUnlocked($userId, $stageThree));

    // TEST 49 — the allowance is now spent.
    $spent = $service->canStart($userId, $exam);
    T::ok('TEST 49 · a third attempt is refused', !$spent['allowed']);
    T::same('TEST 49 · for the stated reason', CheckpointService::BLOCKED_MAX_ATTEMPTS, $spent['reason']);
    T::ok('TEST 49 · with a message a student can read', $spent['message'] !== '');

    $blocked = $service->start($userId, $exam);
    T::ok('TEST 49 · and starting is blocked rather than erroring', $blocked['attempt'] === null);

    // TEST 50 — raise the allowance and pass again; no second payout.
    Database::execute('UPDATE balin_checkpoint_exams SET max_attempts = 5 WHERE id = :id', ['id' => $fixtures['exam_id']]);
    $exam = $checkpoints->findById($fixtures['exam_id']);   // re-read: the rules come from the row
    $xpAfterFirstPass = $xp->totalFor($userId);

    $thirdAttempt = $service->start($userId, $exam);
    T::ok('a raised allowance permits another attempt', $thirdAttempt['attempt'] !== null);

    $repeat = $service->submit($userId, $exam, $thirdAttempt['attempt'], $passing);
    T::ok('the repeat also passes', $repeat['passed']);
    T::ok('TEST 50 · but is not the first pass', !$repeat['first_pass']);
    T::same('TEST 50 · and pays nothing', 0, $repeat['xp_awarded']);
    T::same('TEST 50 · leaving the ledger untouched', $xpAfterFirstPass, $xp->totalFor($userId));

    // TEST 51 — a cooldown is measured against the institution clock.
    Database::execute(
        'UPDATE balin_checkpoint_exams SET cooldown_hours_between_attempts = 24 WHERE id = :id',
        ['id' => $fixtures['exam_id']]
    );
    $cooled = $service->canStart($userId, $checkpoints->findById($fixtures['exam_id']));
    T::ok('TEST 51 · a cooldown blocks the next attempt', !$cooled['allowed']);
    T::same('TEST 51 · for the stated reason', CheckpointService::BLOCKED_COOLDOWN, $cooled['reason']);
    T::ok('TEST 51 · and names when it opens', $cooled['next_attempt_at'] !== null);

    // The stated time must sit in the institution's zone, not the raw UTC one.
    $zone     = BalinSettings::timezone();
    $expected = (new DateTimeImmutable('now', $zone))->modify('+24 hours');
    $actual   = new DateTimeImmutable((string) $cooled['next_attempt_at'], $zone);
    T::ok(
        'TEST 51 · the next attempt time is in the institution timezone',
        abs($actual->getTimestamp() - $expected->getTimestamp()) < 600,
        'expected ≈' . $expected->format('Y-m-d H:i') . ', got ' . $actual->format('Y-m-d H:i')
    );

    // Answers recorded inside an exam count once, like any other answer.
    $answered = (int) Database::scalar(
        'SELECT COUNT(*) FROM balin_answers WHERE user_id = :user AND attempt_id IS NOT NULL',
        ['user' => $userId]
    );
    T::same('exam answers are recorded once each, not once per attempt', 4, $answered);
}

function balin_test_streak_and_level(array $fixtures): void
{
    T::group('Streak, level cache and access (§27, §39, §45) — database');

    $userId = $fixtures['user_id'];
    $recalc = new Recalculator();

    $first = $recalc->streak($userId);
    T::ok('today is already recorded by the activity above', !$first['recorded']);
    T::same('and the streak counts it', 1, $first['current']);

    $again = $recalc->streak($userId);
    T::ok('recording the same day twice changes nothing', !$again['recorded']);
    T::same('and does not inflate the streak', 1, $again['current']);

    // The level cache is derived, so it must track the ledger exactly.
    $stats   = $recalc->userStats($userId);
    $ledger  = (new BalinXpRepository())->totalFor($userId);
    T::same('the cached total matches the ledger', $ledger, $stats['total_xp']);
    T::same('the cached level matches the formula', Level::forXp($ledger), $stats['cached_level']);

    // Access: revoking must not touch anything the student earned.
    $access = new BalinAccessRepository();
    $access->revoke($userId, null, 'test');
    T::ok('a revoked student is denied', !$access->isEnabled($userId));
    T::same('and the gate says so', Access::NO_ACCESS,
        Access::evaluate(BalinSettings::STATUS_PUBLISHED, false)['reason']);
    T::same('but their XP survives', $ledger, (new BalinXpRepository())->totalFor($userId));
    T::same('and so does their progress',
        2, (new BalinProgressRepository())->countCompletedStages($userId));

    $access->grant($userId, null, 'test restore');
    T::ok('restoring access brings them straight back', $access->isEnabled($userId));
}
