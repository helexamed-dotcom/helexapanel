<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ContentStatusRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\ExamRepository;
use HeleXa\Services\AcademicScope;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;
use HeleXa\Services\StudyAnalytics;

final class DashboardController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $user     = Auth::user();
        $userId   = (int) $user['id'];
        $statuses = new ContentStatusRepository();

        $week     = StudyAnalytics::week($userId, 0);
        $lastWeek = StudyAnalytics::week($userId, 1);

        $termIds = AcademicScope::termIds($user);
        $groupId = AcademicScope::groupId($user);

        // The classes this student attends today, in every term, by clock.
        $todayClasses = \HeleXa\Services\StudentSchedule::forWeekday($user, Jalali::weekdayIndex(time()));

        $upcomingExams = (new ExamRepository())->forStudentTerms($termIds, $groupId, null, true);

        return $this->page('layouts.app', 'student.dashboard', [
            'title'         => 'داشبورد',
            'todayText'     => Jalali::longDate(time()),
            'user'          => $user,
            'courses'       => (new EnrollmentRepository())->coursesForStudent($userId),
            'continue'      => $statuses->continueStudying($userId, 4),
            'todaySeconds'  => StudyAnalytics::todaySeconds($userId),
            'week'          => $week,
            'lastWeekTotal' => $lastWeek['total'],
            'todayClasses'  => $todayClasses,
            'upcomingExams' => array_slice($upcomingExams, 0, 4),
            'securityFlags' => \HeleXa\Services\IpWatch::recentForUser($userId, 7),
            'tier'          => \HeleXa\Services\StudentTier::forStudent($userId),
            'fcDue'         => $this->flashcardsDue($userId),
            'showBalin'     => \HeleXa\Services\Balin\Access::menuVisible(),
            'showQbank'     => \HeleXa\Services\QuestionBank\QbAccess::menuVisible(),
        ]);
    }

    /** Cards due now, or 0 if the flashcards module is not installed yet. */
    private function flashcardsDue(int $userId): int
    {
        try {
            $ids = \HeleXa\Services\Flashcards\FcAccess::allDeckIds($userId);
            return array_sum(array_column((new \HeleXa\Models\Flashcards\FcStudyRepository())->deckStats($userId, $ids), 'due'));
        } catch (\PDOException) {
            return 0;
        }
    }
}
