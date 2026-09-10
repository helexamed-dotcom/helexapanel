<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ContentStatusRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\ExamRepository;
use HeleXa\Models\ScheduleRepository;
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

        $schedules    = new ScheduleRepository();
        $todayIndex   = Jalali::weekdayIndex(time());
        $todayClasses = [];

        foreach ($schedules->forStudentTerms($termIds, $groupId) as $entry) {
            if ($entry['schedule'] === null) {
                continue;
            }
            foreach ($schedules->itemsForWeekday((int) $entry['schedule']['id'], $todayIndex) as $item) {
                $item['term_title'] = $entry['term_title'];
                $todayClasses[]     = $item;
            }
        }

        // Across terms the classes arrive grouped by schedule, so sort by clock.
        usort($todayClasses, static fn (array $a, array $b): int => strcmp((string) $a['start_time'], (string) $b['start_time']));

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
        ]);
    }
}
