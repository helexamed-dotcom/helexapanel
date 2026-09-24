<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ExamRepository;
use HeleXa\Models\StudyMarkRepository;
use HeleXa\Services\AcademicScope;
use HeleXa\Services\Auth;
use HeleXa\Services\Balin\MyRank;
use HeleXa\Services\Jalali;
use HeleXa\Services\StudentSchedule;
use HeleXa\Services\StudyAnalytics;

/**
 * «امروز من»: one page that answers "what do I do today?".
 *
 * Today's classes, what is due for review (flashcards, questions answered
 * wrong, the reading list), the nearest exams, and how today's studying
 * compares — each block links straight to where the work is done. Every
 * module is optional: a block whose module is not installed is left out.
 */
final class TodayController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $user   = (array) Auth::user();
        $userId = (int) $user['id'];

        $termIds = AcademicScope::termIds($user);
        $groupId = AcademicScope::groupId($user);

        $seconds = StudyAnalytics::todaySeconds($userId);
        $goal    = max(10, \HeleXa\Services\Settings::int('daily_goal_minutes', 60)) * 60;

        return $this->page('layouts.app', 'student.today', [
            'title'      => 'امروز من',
            'user'       => $user,
            'todayText'  => Jalali::longDate(time()),
            'classes'    => StudentSchedule::forWeekday($user, Jalali::weekdayIndex(time())),
            'exams'      => array_slice((new ExamRepository())->forStudentTerms($termIds, $groupId, null, true), 0, 3),
            'seconds'    => $seconds,
            'goal'       => $goal,
            'fcDue'      => $this->flashcardsDue($userId),
            'qb'         => $this->qbank($userId),
            'marks'      => $this->marks($userId),
            // null when the ranking section is switched off on «مرور جزیره».
            'rank'       => MyRank::mode() === 'off' ? null : MyRank::forStudent($userId),
            'showBalin'  => \HeleXa\Services\Balin\Access::menuVisible(),
            'showQbank'  => \HeleXa\Services\QuestionBank\QbAccess::menuVisible(),
        ]);
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
            $marks = Database::select(
                'SELECT mark, COUNT(*) AS c FROM qb_marks WHERE user_id = :u GROUP BY mark',
                ['u' => $userId]
            );
            foreach ($marks as $row) {
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
            return ['items' => array_slice($repo->forUser($userId, false), 0, 5), 'counts' => $repo->counts($userId)];
        } catch (\PDOException) {
            return ['items' => [], 'counts' => ['open' => 0, 'due' => 0]];
        }
    }
}
