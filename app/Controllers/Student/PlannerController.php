<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\CalendarRepository;
use HeleXa\Models\ExamRepository;
use HeleXa\Models\ScheduleRepository;
use HeleXa\Services\AcademicScope;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;

/**
 * Weekly schedule, exams and the Jalali calendar for one student.
 *
 * Everything here is scoped by the student's own term and group, read from
 * their user row on the server. There is no term or group parameter in any of
 * these URLs, so another term's plan cannot be requested at all.
 */
final class PlannerController extends Controller
{
    public function schedule(Request $request, array $params = []): Response
    {
        $user        = Auth::user();
        $repository  = new ScheduleRepository();
        $termIds     = AcademicScope::termIds($user);
        $groupId     = AcademicScope::groupId($user);

        // One block per term, because a student carrying units from two terms
        // needs to see both timetables, not a merged one they cannot read.
        $blocks = [];
        foreach ($repository->forStudentTerms($termIds, $groupId) as $entry) {
            $byDay = [];
            if ($entry['schedule'] !== null) {
                foreach ($repository->items((int) $entry['schedule']['id']) as $item) {
                    $byDay[(int) $item['weekday']][] = $item;
                }
            }
            $blocks[] = [
                'term_title' => $entry['term_title'],
                'schedule'   => $entry['schedule'],
                'byDay'      => $byDay,
            ];
        }

        return $this->page('layouts.app', 'student.schedule', [
            'title'      => 'برنامه هفتگی',
            'blocks'     => $blocks,
            'weekdays'   => Jalali::WEEKDAYS,
            'todayIndex' => Jalali::weekdayIndex(time()),
            'hasTerms'   => $termIds !== [],
        ]);
    }

    public function exams(Request $request, array $params = []): Response
    {
        return $this->renderExams('final', 'برنامه امتحانات');
    }

    public function midterms(Request $request, array $params = []): Response
    {
        return $this->renderExams('midterm', 'میان‌ترم‌ها');
    }

    public function calendar(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $termIds = AcademicScope::termIds($user);
        $group   = AcademicScope::groupId($user);

        [$currentYear, $currentMonth] = Jalali::currentMonth();
        $year  = $request->int('year', $currentYear);
        $month = $request->int('month', $currentMonth);

        // Clamp rather than trust: a crafted ?month=99 would break the grid.
        if ($month < 1 || $month > 12 || $year < 1300 || $year > 1500) {
            [$year, $month] = [$currentYear, $currentMonth];
        }

        $grid  = Jalali::monthGrid($year, $month);
        $from  = $grid['days'][0]['date'];
        $to    = $grid['days'][count($grid['days']) - 1]['date'];

        $exams  = (new ExamRepository())->betweenDatesForTerms($termIds, $group, $from, $to);
        $events = (new CalendarRepository())->forStudentTerms((int) $user['id'], $termIds, $group, $from, $to);

        $byDate = [];
        foreach ($exams as $exam) {
            $byDate[(string) $exam['exam_date']][] = [
                'type'  => $exam['exam_kind'] === 'midterm' ? 'midterm' : 'exam',
                'title' => (string) $exam['title'],
                'time'  => $exam['start_time'] !== null ? substr((string) $exam['start_time'], 0, 5) : null,
                'note'  => $exam['location'],
            ];
        }
        foreach ($events as $event) {
            $byDate[(string) $event['event_date']][] = [
                'type'  => (string) $event['event_type'],
                'title' => (string) $event['title'],
                'time'  => $event['start_time'] !== null ? substr((string) $event['start_time'], 0, 5) : null,
                'note'  => $event['description'],
            ];
        }

        $selected = $request->string('day');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected) !== 1) {
            $selected = date('Y-m-d');
        }

        // Classes are weekly, so the selected day's lessons come from the
        // schedules of every term the student is in.
        $schedules  = new ScheduleRepository();
        $selectedTs = (int) strtotime($selected);
        $weekday    = Jalali::weekdayIndex($selectedTs);
        $dayClasses = [];

        foreach ($schedules->forStudentTerms($termIds, $group) as $entry) {
            if ($entry['schedule'] === null) {
                continue;
            }
            foreach ($schedules->itemsForWeekday((int) $entry['schedule']['id'], $weekday) as $item) {
                $item['term_title'] = $entry['term_title'];
                $dayClasses[]       = $item;
            }
        }

        [$prevYear, $prevMonth] = Jalali::shiftMonth($year, $month, -1);
        [$nextYear, $nextMonth] = Jalali::shiftMonth($year, $month, 1);

        return $this->page('layouts.app', 'student.calendar', [
            'title'       => 'تقویم درسی',
            'grid'        => $grid,
            'byDate'      => $byDate,
            'selected'    => $selected,
            'selectedFa'  => Jalali::longDate($selectedTs),
            'dayItems'    => $byDate[$selected] ?? [],
            'dayClasses'  => $dayClasses,
            'prev'        => ['year' => $prevYear, 'month' => $prevMonth],
            'next'        => ['year' => $nextYear, 'month' => $nextMonth],
            'weekdays'    => Jalali::WEEKDAYS,
        ]);
    }

    private function renderExams(string $kind, string $title): Response
    {
        $user    = Auth::user();
        $termIds = AcademicScope::termIds($user);
        $group   = AcademicScope::groupId($user);

        $repository = new ExamRepository();
        $upcoming   = $repository->forStudentTerms($termIds, $group, $kind, true);
        $past       = array_values(array_filter(
            $repository->forStudentTerms($termIds, $group, $kind, false),
            static fn (array $e): bool => $e['exam_date'] < date('Y-m-d')
        ));

        return $this->page('layouts.app', 'student.exams', [
            'title'    => $title,
            'kind'     => $kind,
            'upcoming' => $upcoming,
            'past'     => array_slice(array_reverse($past), 0, 10),
            'hasTerm'  => $termIds !== [],
        ]);
    }
}
