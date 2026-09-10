<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\CourseRepository;
use HeleXa\Models\SubjectRepository;
use HeleXa\Models\ScheduleRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;

final class ScheduleController extends Controller
{
    private ScheduleRepository $schedules;
    private AcademicRepository $academic;

    public function __construct()
    {
        $this->schedules = new ScheduleRepository();
        $this->academic  = new AcademicRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.schedule.index', [
            'title'     => 'برنامه هفتگی',
            'schedules' => $this->schedules->all(),
            'terms'     => $this->academic->terms(),
            'groups'    => $this->academic->groups(),
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $data = $this->collect($request);

        if ($data['title'] === '' || $data['term_id'] === null) {
            $this->flash('error', 'عنوان و ترم الزامی هستند.');
            return $this->redirect('/admin/schedule');
        }

        $id = $this->schedules->create($data, Auth::id());
        ActivityLogger::log('schedule.created', Auth::id(), 'schedule', $id, ['title' => $data['title']], 'notice', $request);
        $this->flash('success', 'برنامه ساخته شد. حالا جلسات هر روز را اضافه کنید.');

        return $this->redirect('/admin/schedule/' . $id);
    }

    public function show(Request $request, array $params = []): Response
    {
        $schedule = $this->find((int) ($params['id'] ?? 0));
        $items    = $this->schedules->items((int) $schedule['id']);

        $byDay = [];
        foreach ($items as $item) {
            $byDay[(int) $item['weekday']][] = $item;
        }

        // Subjects are scoped to the schedule's major (plus general ones),
        // the same rule terms already follow.
        $termRow = \HeleXa\Core\Database::selectOne(
            'SELECT major_id FROM terms WHERE id = :id LIMIT 1', ['id' => (int) $schedule['term_id']]
        );
        $subjects = (new SubjectRepository())->all($termRow['major_id'] ?? null, true);

        return $this->page('layouts.app', 'admin.schedule.builder', [
            'title'    => 'جلسات ' . $schedule['title'],
            'schedule' => $schedule,
            'byDay'    => $byDay,
            'weekdays' => Jalali::WEEKDAYS,
            'courses'  => (new CourseRepository())->all(),
            'subjects' => $subjects,
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $schedule = $this->find((int) ($params['id'] ?? 0));
        $data     = $this->collect($request);

        if ($data['title'] === '' || $data['term_id'] === null) {
            $this->flash('error', 'عنوان و ترم الزامی هستند.');
            return $this->redirect('/admin/schedule/' . $schedule['id']);
        }

        $this->schedules->update((int) $schedule['id'], $data);
        ActivityLogger::log('schedule.updated', Auth::id(), 'schedule', (int) $schedule['id'], [], 'notice', $request);
        $this->flash('success', 'برنامه به‌روزرسانی شد.');

        return $this->redirect('/admin/schedule/' . $schedule['id']);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $schedule = $this->find((int) ($params['id'] ?? 0));

        $this->schedules->delete((int) $schedule['id']);
        ActivityLogger::log('schedule.deleted', Auth::id(), 'schedule', (int) $schedule['id'], [], 'warning', $request);
        $this->flash('success', 'برنامه و همه جلسات آن حذف شد.');

        return $this->redirect('/admin/schedule');
    }

    public function storeItem(Request $request, array $params = []): Response
    {
        $schedule = $this->find((int) ($params['id'] ?? 0));

        $weekday = $request->int('weekday');
        $start   = $this->time($request->string('start_time'));
        $end     = $this->time($request->string('end_time'));
        $title   = $request->string('title');

        if ($weekday < 0 || $weekday > 6 || $start === null || $end === null || $title === '') {
            $this->flash('error', 'روز، ساعت شروع، ساعت پایان و عنوان الزامی هستند.');
            return $this->redirect('/admin/schedule/' . $schedule['id']);
        }
        if ($start >= $end) {
            $this->flash('error', 'ساعت پایان باید بعد از ساعت شروع باشد.');
            return $this->redirect('/admin/schedule/' . $schedule['id']);
        }

        // Independent of each other by design: a class period may name a
        // subject, link to LMS content, both, or neither.
        $courseId = $request->int('course_id') ?: null;
        if ($courseId !== null && (new CourseRepository())->findById($courseId) === null) {
            $courseId = null;
        }
        $subjectId = $request->int('subject_id') ?: null;
        if ($subjectId !== null && (new SubjectRepository())->find($subjectId) === null) {
            $subjectId = null;
        }

        $this->schedules->addItem((int) $schedule['id'], [
            'weekday'    => $weekday,
            'start_time' => $start,
            'end_time'   => $end,
            'title'      => $title,
            'subject_id' => $subjectId,
            'course_id'  => $courseId,
            'teacher'    => $request->string('teacher'),
            'location'   => $request->string('location'),
            'color'      => preg_match('/^#[0-9a-fA-F]{6}$/', $request->string('color')) === 1 ? $request->string('color') : null,
        ]);

        $this->flash('success', 'جلسه اضافه شد.');
        return $this->redirect('/admin/schedule/' . $schedule['id']);
    }

    public function destroyItem(Request $request, array $params = []): Response
    {
        $schedule = $this->find((int) ($params['id'] ?? 0));
        $itemId   = (int) ($params['item'] ?? 0);

        // Scoped delete: an item id from another schedule matches nothing.
        $this->schedules->deleteItem($itemId, (int) $schedule['id']);
        $this->flash('success', 'جلسه حذف شد.');

        return $this->redirect('/admin/schedule/' . $schedule['id']);
    }

    /* ---------------------------------------------------------- helpers */

    private function find(int $id): array
    {
        $schedule = $this->schedules->find($id);
        if ($schedule === null) {
            throw HttpException::notFound('برنامه یافت نشد.');
        }
        return $schedule;
    }

    private function collect(Request $request): array
    {
        $termId  = $request->int('term_id') ?: null;
        $groupId = $request->int('group_id') ?: null;

        if ($groupId !== null && ($termId === null || !$this->academic->groupBelongsToTerm($groupId, $termId))) {
            $groupId = null;
        }

        return [
            'title'          => $request->string('title'),
            'term_id'        => $termId,
            'group_id'       => $groupId,
            'academic_year'  => $request->string('academic_year'),
            'effective_from' => $this->date($request->string('effective_from')),
            'effective_to'   => $this->date($request->string('effective_to')),
            'is_active'      => $request->bool('is_active') ? 1 : 0,
        ];
    }

    private function date(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function time(string $value): ?string
    {
        $value = Jalali::toLatinDigits($value);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value . ':00' : null;
    }
}
