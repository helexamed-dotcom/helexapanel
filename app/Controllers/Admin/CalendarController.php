<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\CalendarRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

final class CalendarController extends Controller
{
    private const TYPES = ['holiday', 'event', 'reminder', 'deadline', 'custom'];

    private CalendarRepository $events;
    private AcademicRepository $academic;

    public function __construct()
    {
        $this->events   = new CalendarRepository();
        $this->academic = new AcademicRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.calendar', [
            'title'  => 'تقویم',
            'events' => $this->events->all(),
            'terms'  => $this->academic->terms(),
            'groups' => $this->academic->groups(),
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $termId  = $request->int('term_id') ?: null;
        $groupId = $request->int('group_id') ?: null;
        if ($groupId !== null && ($termId === null || !$this->academic->groupBelongsToTerm($groupId, $termId))) {
            $groupId = null;
        }

        $type = $request->string('event_type', 'event');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'event';
        }

        $date = $request->string('event_date');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $this->flash('error', 'تاریخ رویداد معتبر نیست.');
            return $this->redirect('/admin/calendar');
        }
        if ($request->string('title') === '') {
            $this->flash('error', 'عنوان رویداد الزامی است.');
            return $this->redirect('/admin/calendar');
        }

        $id = $this->events->create([
            'title'       => $request->string('title'),
            'description' => $request->string('description'),
            'event_type'  => $type,
            'event_date'  => $date,
            'start_time'  => null,
            'end_time'    => null,
            'term_id'     => $termId,
            'group_id'    => $groupId,
            'color'       => preg_match('/^#[0-9a-fA-F]{6}$/', $request->string('color')) === 1 ? $request->string('color') : null,
        ], Auth::id());

        ActivityLogger::log('calendar.created', Auth::id(), 'event', $id, ['type' => $type], 'info', $request);
        $this->flash('success', 'رویداد به تقویم اضافه شد.');

        return $this->redirect('/admin/calendar');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if ($this->events->find($id) === null) {
            throw HttpException::notFound();
        }

        $this->events->delete($id);
        ActivityLogger::log('calendar.deleted', Auth::id(), 'event', $id, [], 'info', $request);
        $this->flash('success', 'رویداد حذف شد.');

        return $this->redirect('/admin/calendar');
    }
}
