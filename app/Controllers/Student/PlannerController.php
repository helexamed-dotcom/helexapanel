<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\CalendarRepository;
use HeleXa\Models\ExamRepository;
use HeleXa\Services\AcademicScope;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;
use HeleXa\Services\StudentSchedule;

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
        return $this->page('layouts.app', 'student.schedule', ['title' => 'برنامه هفتگی'] + $this->scheduleData());
    }

    /**
     * The weekly timetable data, shared by its own page and the calendar tab:
     * every plan of every term the student is in, own group first, then the
     * other groups of the same term below it.
     */
    private function scheduleData(): array
    {
        $user = Auth::user();

        $custom = false;
        try {
            $custom = StudentSchedule::isCustom($user);
        } catch (\Throwable) {
            $custom = false;
        }

        return [
            'plans'      => StudentSchedule::plans($user),
            'custom'     => $custom,
            'canPick'    => \HeleXa\Models\ScheduleRepository::choiceReady(),
            'weekdays'   => Jalali::WEEKDAYS,
            'todayIndex' => Jalali::weekdayIndex(time()),
            'hasTerms'   => AcademicScope::termIds($user) !== [],
        ];
    }
    public function exams(Request $request, array $params = []): Response
    {
        return $this->renderExams('final', 'برنامه امتحانات');
    }

    public function midterms(Request $request, array $params = []): Response
    {
        return $this->renderExams('midterm', 'میان‌ترم‌ها');
    }

    /**
     * The calendar hub: the month grid, the weekly timetable, finals and
     * midterms, one tab each. Only the open tab's data is loaded; the other
     * three pages keep working at their own addresses.
     */
    public function calendar(Request $request, array $params = []): Response
    {
        $tab = $request->string('tab', 'month');
        $tabs = ['month' => 'تقویم ماهانه', 'classes' => 'برنامه کلاسی', 'finals' => 'امتحانات پایان‌ترم', 'midterms' => 'میان‌ترم‌ها'];
        if (!isset($tabs[$tab])) {
            $tab = 'month';
        }

        if ($tab !== 'month') {
            $data = match ($tab) {
                'classes'  => ['embed' => 'student.schedule'] + $this->scheduleData(),
                'finals'   => ['embed' => 'student.exams'] + $this->examData('final'),
                default    => ['embed' => 'student.exams'] + $this->examData('midterm'),
            };
            return $this->page('layouts.app', 'student.calendar_hub', ['title' => 'تقویم', 'tab' => $tab, 'tabs' => $tabs] + $data);
        }

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
        // classes the student attends in every term they are in.
        $selectedTs = (int) strtotime($selected);
        $dayClasses = StudentSchedule::forWeekday($user, Jalali::weekdayIndex($selectedTs));

        [$prevYear, $prevMonth] = Jalali::shiftMonth($year, $month, -1);
        [$nextYear, $nextMonth] = Jalali::shiftMonth($year, $month, 1);

        return $this->page('layouts.app', 'student.calendar', [
            'title'       => 'تقویم',
            'tab'         => 'month',
            'tabs'        => $tabs,
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

    /**
     * Choosing the classes the student has taken, from the plans of every
     * group of every term they are in.
     */
    public function choose(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $choices = StudentSchedule::choices($user);
        $back    = $this->returnTo($request);

        if ($choices === []) {
            $this->flash('error', \HeleXa\Models\ScheduleRepository::choiceReady()
                ? 'برای ترم‌های شما هنوز برنامه کلاسی ثبت نشده است.'
                : 'انتخاب درس هنوز فعال نشده است (مهاجرت دیتابیس اجرا نشده).');
            return $this->redirect($back);
        }

        return $this->page('layouts.app', 'student.schedule_choose', [
            'title'    => 'درس‌های اخذشده',
            'choices'  => $choices,
            'custom'   => StudentSchedule::isCustom($user),
            'weekdays' => Jalali::WEEKDAYS,
            'back'     => $back,
        ]);
    }

    public function saveChoices(Request $request, array $params = []): Response
    {
        $user = Auth::user();
        $back = $this->returnTo($request);

        $ids = [];
        if (!$request->bool('reset')) {
            $raw = $request->input('units', []);
            foreach (is_array($raw) ? $raw : [] as $value) {
                foreach (explode(',', is_scalar($value) ? (string) $value : '') as $id) {
                    if (ctype_digit(trim($id))) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        $result = StudentSchedule::save($user, array_slice(array_values(array_unique($ids)), 0, 500));

        $this->flash('success', $result['saved'] === 0
            ? 'انتخاب‌ها پاک شد؛ برنامه گروه خودتان نمایش داده می‌شود.'
            : 'درس‌های اخذشده ذخیره شد؛ برنامه هفتگی، تقویم و داشبورد فقط همین کلاس‌ها را نشان می‌دهند.');
        if ($result['clashes'] !== []) {
            $this->flash('error', 'این کلاس‌ها هم‌زمان‌اند: ' . implode(' — ', $result['clashes']));
        }

        return $this->redirect($back);
    }

    /** Where the choice page returns to: the profile or the calendar. */
    private function returnTo(Request $request): string
    {
        return $request->string('from') === 'profile' ? '/account/profile#classes' : '/student/calendar?tab=classes';
    }
    private function renderExams(string $kind, string $title): Response
    {
        return $this->page('layouts.app', 'student.exams', ['title' => $title] + $this->examData($kind));
    }

    private function examData(string $kind): array
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

        return [
            'kind'     => $kind,
            'upcoming' => $upcoming,
            'past'     => array_slice(array_reverse($past), 0, 10),
            'hasTerm'  => $termIds !== [],
        ];
    }
}
