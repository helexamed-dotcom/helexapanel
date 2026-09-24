<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ContentStatusRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\ExamRepository;
use HeleXa\Models\StudyMarkRepository;
use HeleXa\Services\AcademicScope;
use HeleXa\Services\Auth;
use HeleXa\Services\Countdowns;
use HeleXa\Services\Jalali;
use HeleXa\Services\Modules;
use HeleXa\Services\Settings;
use HeleXa\Services\StudyAnalytics;

/**
 * The student's home: what used to be three pages — «داشبورد», «امروز من»
 * and «درس‌های من» — in one.
 *
 * Top to bottom it answers: how far to the thing I am preparing for (the
 * admin's countdowns), what should I do today (goal, due cards, wrong
 * answers, today's classes and the nearest exams), what did I say I would
 * read (the reading list, editable in place), and where was I (continue
 * studying, the week). Every block whose section is off for this student,
 * or whose module is not installed, is simply left out.
 */
final class DashboardController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $user   = (array) Auth::user();
        $userId = (int) $user['id'];

        $termIds = AcademicScope::termIds($user);
        $groupId = AcademicScope::groupId($user);
        $planner = Modules::enabled('planner');

        $seconds = StudyAnalytics::todaySeconds($userId);
        $goal    = max(10, Settings::int('daily_goal_minutes', 60)) * 60;
        $week    = StudyAnalytics::week($userId, 0);

        return $this->page('layouts.app', 'student.home', [
            'title'        => 'داشبورد',
            'extraCss'     => ['home'],
            'extraJs'      => ['home'],
            'user'         => $user,
            'todayText'    => Jalali::longDate(time()),
            'countdowns'   => Countdowns::forStudent($user),
            'serverMs'     => (int) round(microtime(true) * 1000),
            'seconds'      => $seconds,
            'goal'         => $goal,
            'week'         => $week,
            'lastWeek'     => (int) StudyAnalytics::week($userId, 1)['total'],
            'classes'      => $planner ? \HeleXa\Services\StudentSchedule::forWeekday($user, Jalali::weekdayIndex(time())) : [],
            'exams'        => $planner ? array_slice((new ExamRepository())->forStudentTerms($termIds, $groupId, null, true), 0, 4) : [],
            'fcDue'        => Modules::enabled('flashcards') ? $this->flashcardsDue($userId) : 0,
            'qb'           => Modules::enabled('qbank') ? $this->qbank($userId) : null,
            'marks'        => $this->marks($userId),
            'kinds'        => StudyMarkRepository::KINDS,
            'continue'     => Modules::enabled('courses') ? (new ContentStatusRepository())->continueStudying($userId, 4) : [],
            'courseCount'  => Modules::enabled('courses') ? count((new EnrollmentRepository())->coursesForStudent($userId)) : 0,
            'rank'         => $this->rank($userId),
            'level'        => \HeleXa\Services\Points::summary($userId),
            'shortcuts'    => $this->shortcuts(),
            'securityFlags'=> \HeleXa\Services\IpWatch::recentForUser($userId, 7),
            'typeState'    => \HeleXa\Services\StudentTypes::stateFor($userId),
            'typeOptions'  => Settings::bool('student_type_prompt', true) ? \HeleXa\Services\StudentTypes::all(true) : [],
        ]);
    }

    /** The first few sections that are on, as app icons under the greeting. */
    private function shortcuts(): array
    {
        $out = [];
        foreach (Modules::menu() as $items) {
            foreach ($items as $item) {
                $out[] = $item;
            }
        }
        return array_slice($out, 0, 8);
    }

    private function flashcardsDue(int $userId): int
    {
        try {
            $ids = \HeleXa\Services\Flashcards\FcAccess::allDeckIds($userId);
            return array_sum(array_column((new \HeleXa\Models\Flashcards\FcStudyRepository())->deckStats($userId, $ids), 'due'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array{wrong:int, review:int, collected:int, last:?array} */
    private function qbank(int $userId): array
    {
        $out = ['wrong' => 0, 'review' => 0, 'collected' => 0, 'last' => null];
        try {
            // Questions whose most recent answer was wrong — what is left to fix.
            $out['wrong'] = (int) (Database::selectOne(
                'SELECT COUNT(*) AS c FROM qb_attempts a
                 JOIN (SELECT question_id, MAX(id) AS mid FROM qb_attempts WHERE user_id = :u1 GROUP BY question_id) x
                   ON x.mid = a.id
                 WHERE a.is_correct = 0',
                ['u1' => $userId]
            )['c'] ?? 0);
            foreach (Database::select('SELECT mark, COUNT(*) AS c FROM qb_marks WHERE user_id = :u GROUP BY mark', ['u' => $userId]) as $row) {
                if ($row['mark'] === 'review') {
                    $out['review'] = (int) $row['c'];
                } elseif ($row['mark'] === 'exam') {
                    $out['collected'] = (int) $row['c'];
                }
            }
            $out['last'] = Database::selectOne(
                "SELECT uuid, title, score_percent, finished_at FROM qb_my_exams
                 WHERE user_id = :u AND status = 'finished' ORDER BY finished_at DESC LIMIT 1",
                ['u' => $userId]
            );
        } catch (\PDOException) {
            // A module not installed yet leaves its numbers at zero.
        }
        return $out;
    }

    /** @return array{items:array, counts:array} */
    private function marks(int $userId): array
    {
        try {
            $repo = new StudyMarkRepository();
            return ['items' => $repo->forUser($userId), 'counts' => $repo->counts($userId)];
        } catch (\PDOException) {
            return ['items' => [], 'counts' => ['open' => 0, 'due' => 0]];
        }
    }

    private function rank(int $userId): ?array
    {
        try {
            return \HeleXa\Services\Balin\MyRank::mode() === 'off' ? null : \HeleXa\Services\Balin\MyRank::forStudent($userId);
        } catch (\Throwable) {
            return null;
        }
    }
}
