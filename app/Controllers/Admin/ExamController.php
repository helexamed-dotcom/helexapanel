<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\CourseRepository;
use HeleXa\Models\ExamRepository;
use HeleXa\Models\SubjectRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;

/**
 * Finals and midterms share this controller; the route decides which kind is
 * listed and pre-selected, so the two pages stay separate for the admin while
 * the storage and validation stay single-sourced.
 */
final class ExamController extends Controller
{
    private const KINDS = ['final', 'midterm', 'quiz', 'practical', 'other'];

    private ExamRepository $exams;
    private AcademicRepository $academic;

    public function __construct()
    {
        $this->exams    = new ExamRepository();
        $this->academic = new AcademicRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->render('final', 'امتحانات پایان‌ترم');
    }

    public function midterms(Request $request, array $params = []): Response
    {
        return $this->render('midterm', 'میان‌ترم‌ها');
    }

    public function store(Request $request, array $params = []): Response
    {
        $data = $this->collect($request);
        $back = $data['exam_kind'] === 'midterm' ? '/admin/midterms' : '/admin/exams';

        $error = $this->validate($data);
        if ($error !== null) {
            $this->flash('error', $error);
            return $this->redirect($back);
        }

        $id = $this->exams->create($data, Auth::id());
        ActivityLogger::log('exam.created', Auth::id(), 'exam', $id,
            ['title' => $data['title'], 'kind' => $data['exam_kind']], 'notice', $request);
        $this->flash('success', 'امتحان ثبت شد.');

        return $this->redirect($back);
    }

    public function update(Request $request, array $params = []): Response
    {
        $exam = $this->find((int) ($params['id'] ?? 0));
        $data = $this->collect($request);
        $back = $data['exam_kind'] === 'midterm' ? '/admin/midterms' : '/admin/exams';

        $error = $this->validate($data);
        if ($error !== null) {
            $this->flash('error', $error);
            return $this->redirect($back);
        }

        $this->exams->update((int) $exam['id'], $data);
        ActivityLogger::log('exam.updated', Auth::id(), 'exam', (int) $exam['id'], [], 'notice', $request);
        $this->flash('success', 'امتحان به‌روزرسانی شد.');

        return $this->redirect($back);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $exam = $this->find((int) ($params['id'] ?? 0));
        $back = $exam['exam_kind'] === 'midterm' ? '/admin/midterms' : '/admin/exams';

        $this->exams->delete((int) $exam['id']);
        ActivityLogger::log('exam.deleted', Auth::id(), 'exam', (int) $exam['id'],
            ['title' => $exam['title']], 'warning', $request);
        $this->flash('success', 'امتحان حذف شد.');

        return $this->redirect($back);
    }

    /* ---------------------------------------------------------- helpers */

    private function render(string $kind, string $title): Response
    {
        return $this->page('layouts.app', 'admin.exams', [
            'title'    => $title,
            'kind'     => $kind,
            'exams'    => $this->exams->all($kind),
            'terms'    => $this->academic->terms(),
            'groups'   => $this->academic->groups(),
            'courses'  => (new CourseRepository())->all(),
            // Every subject is shown here rather than scoped to one term/major:
            // this single form serves every term at once, unlike the schedule
            // builder which already belongs to one schedule.
            'subjects' => (new SubjectRepository())->all(null, true),
        ]);
    }

    private function find(int $id): array
    {
        $exam = $this->exams->find($id);
        if ($exam === null) {
            throw HttpException::notFound('امتحان یافت نشد.');
        }
        return $exam;
    }

    private function collect(Request $request): array
    {
        $kind = $request->string('exam_kind', 'final');
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'final';
        }

        $termId  = $request->int('term_id') ?: null;
        $groupId = $request->int('group_id') ?: null;
        if ($groupId !== null && ($termId === null || !$this->academic->groupBelongsToTerm($groupId, $termId))) {
            $groupId = null;
        }

        $courseId = $request->int('course_id') ?: null;
        if ($courseId !== null && (new CourseRepository())->findById($courseId) === null) {
            $courseId = null;
        }
        // Independent of the course link: a subject is just a label, and
        // choosing one never requires or implies a content course.
        $subjectId = $request->int('subject_id') ?: null;
        if ($subjectId !== null && (new SubjectRepository())->find($subjectId) === null) {
            $subjectId = null;
        }

        return [
            'title'        => $request->string('title'),
            'exam_kind'    => $kind,
            'subject_id'   => $subjectId,
            'course_id'    => $courseId,
            'term_id'      => $termId,
            'group_id'     => $groupId,
            'exam_date'    => $this->date($request->string('exam_date')),
            'start_time'   => $this->time($request->string('start_time')),
            'end_time'     => $this->time($request->string('end_time')),
            'location'     => $request->string('location'),
            'description'  => $request->string('description'),
            'is_published' => $request->bool('is_published') ? 1 : 0,
        ];
    }

    private function validate(array $data): ?string
    {
        if ($data['title'] === '') {
            return 'عنوان امتحان الزامی است.';
        }
        if ($data['term_id'] === null) {
            return 'ترم را انتخاب کنید، وگرنه هیچ دانشجویی این امتحان را نمی‌بیند.';
        }
        if ($data['exam_date'] === null) {
            return 'تاریخ امتحان معتبر نیست.';
        }
        if ($data['start_time'] !== null && $data['end_time'] !== null && $data['start_time'] >= $data['end_time']) {
            return 'ساعت پایان باید بعد از ساعت شروع باشد.';
        }
        return null;
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
