<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Paginator;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\AccessOverviewRepository;
use HeleXa\Models\Balin\BalinAccessRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\CourseRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\Flashcards\FcCatalogRepository;
use HeleXa\Models\Flashcards\FcStudyRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Models\QuestionBank\QbAccessRepository;
use HeleXa\Models\QuestionBank\QbSubjectRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivationService;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\StudentTier;

/**
 * Everything one student can open, on one page.
 *
 * Courses, packages, Balin (the island and each lesson in it), the question
 * bank and flashcards were each granted from their own module's screen. This
 * page gathers them for one person and writes through the same repositories
 * and services those screens use — it adds no second set of rules.
 *
 * The page needs manage_students; each section additionally needs its own
 * module's permission, checked here on every write, not only by hiding the
 * form. An admin who may manage students but not packages sees the package
 * list and cannot change it.
 */
final class StudentAccessController extends Controller
{
    private const STATUSES = ['active', 'suspended', 'expired', 'cancelled'];
    private const PER_PAGE = 20;

    /**
     * The access desk: every student, with what they hold, and a way in.
     *
     * The counts come from one grouped query per module rather than from the
     * per-student page's repositories, which would be five queries a row.
     */
    public function index(Request $request, array $params = []): Response
    {
        $filters = [
            'role'     => 'student',
            'search'   => $request->string('q'),
            'term_id'  => $request->int('term_id') ?: null,
            'group_id' => $request->int('group_id') ?: null,
            'sort'     => 'name',
            'direction' => 'asc',
        ];

        $users     = new UserRepository();
        $total     = $users->countFiltered($filters);
        $paginator = new Paginator($total, self::PER_PAGE, $request->int('page', 1), '/admin/access', [
            'q' => $filters['search'], 'term_id' => $filters['term_id'], 'group_id' => $filters['group_id'],
        ]);
        $students  = $users->paginate($filters, self::PER_PAGE, $paginator->offset());

        // The counts are a convenience: if one of them cannot be read, the
        // list is still shown, with empty counts, and the cause is logged.
        try {
            $summaries = (new AccessOverviewRepository())->summaries(
                array_map(static fn (array $s): int => (int) $s['id'], $students)
            );
        } catch (\Throwable $e) {
            \HeleXa\Core\Logger::error('access overview failed', ['error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
            $summaries = [];
        }

        return $this->page('layouts.app', 'admin.access.index', [
            'title'     => 'دسترسی‌ها',
            'students'  => $students,
            'summaries' => $summaries,
            'paginator' => $paginator,
            'filters'   => $filters,
            'total'     => $total,
            'terms'     => (new AcademicRepository())->terms(),
            'groups'    => (new AcademicRepository())->groups(),
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $student = $this->studentOr404((string) ($params['uuid'] ?? ''));
        $userId  = (int) $student['id'];

        $balinAccess = new BalinAccessRepository();
        $lessons     = new BalinLessonRepository();

        return $this->page('layouts.app', 'admin.students.access', [
            'title'       => 'دسترسی‌های ' . $student['full_name'],
            'student'     => $student,
            'tier'        => StudentTier::forStudent($userId),

            'enrollments' => (new EnrollmentRepository())->allForStudent($userId),
            'courses'     => (new CourseRepository())->all(),

            'activations' => (new PackageRepository())->activationsForStudent($userId),
            'packages'    => (new PackageRepository())->all(),

            'balinOn'     => $balinAccess->isEnabled($userId),
            'lessons'     => $this->optional(static fn () => $lessons->all()),
            // Which lessons this student may open, whichever rule each one
            // follows — the form shows one tick box either way.
            'openLessons' => $this->optional(static fn () => $lessons->openIdsFor($userId)),

            'qbSubjects'  => $this->optional(static fn () => (new QbSubjectRepository())->roots(false)),
            'qbGranted'   => $this->optional(static fn () => (new QbAccessRepository())->subjectIdsFor($userId)),

            'fcCourses'   => $this->optional(static fn () => (new FcCatalogRepository())->courses()),
            'fcGranted'   => $this->optional(static fn () => (new FcStudyRepository())->grantedCourseIds($userId)),
        ]);
    }

    /* ============================================================ courses */

    public function addCourse(Request $request, array $params = []): Response
    {
        $student = $this->guard($params, 'manage_courses');
        $course  = (new CourseRepository())->findByUuid($request->string('course'));
        if ($course === null) {
            return $this->returnTo($student, 'دوره انتخاب‌شده پیدا نشد.', 'courses');
        }

        $result = ActivationService::activateCourse($student, $course, [
            'status'    => 'active',
            'starts_at' => self::date($request->string('starts_at')),
            'ends_at'   => self::date($request->string('ends_at'), true),
        ], Auth::id());

        return $this->returnTo($student, null, 'courses', 'دوره «' . $course['title'] . '» فعال شد.'
            . ($result['notified'] ? ' اطلاعیه برای دانشجو ثبت شد.' : ''));
    }

    public function courseStatus(Request $request, array $params = []): Response
    {
        $student = $this->guard($params, 'manage_courses');
        $course  = (new CourseRepository())->findByUuid((string) ($params['course'] ?? ''));
        $status  = $request->string('status');

        if ($course === null || !in_array($status, self::STATUSES, true)) {
            throw HttpException::notFound();
        }

        (new EnrollmentRepository())->setStatus((int) $student['id'], (int) $course['id'], $status);
        ActivityLogger::log('course.enrollment_status', Auth::id(), 'course', (int) $course['id'],
            ['student' => (int) $student['id'], 'status' => $status], 'notice', $request);

        return $this->returnTo($student, null, 'courses', 'وضعیت دوره «' . $course['title'] . '» تغییر کرد.');
    }

    public function removeCourse(Request $request, array $params = []): Response
    {
        $student = $this->guard($params, 'manage_courses');
        $course  = (new CourseRepository())->findByUuid((string) ($params['course'] ?? ''));
        if ($course === null) {
            throw HttpException::notFound();
        }

        (new EnrollmentRepository())->remove((int) $student['id'], (int) $course['id']);
        ActivityLogger::log('course.unassigned', Auth::id(), 'course', (int) $course['id'],
            ['student' => $student['username']], 'warning', $request);

        return $this->returnTo($student, null, 'courses', 'دوره «' . $course['title'] . '» از دسترسی دانشجو حذف شد.');
    }

    /* =========================================================== packages */

    public function addPackage(Request $request, array $params = []): Response
    {
        $student  = $this->guard($params, 'manage_packages');
        $packages = new PackageRepository();
        $package  = $packages->findByUuid($request->string('package'));

        if ($package === null) {
            return $this->returnTo($student, 'پکیج انتخاب‌شده پیدا نشد.', 'packages');
        }


        $result = ActivationService::activatePackage($student, $package, [
            'status'    => 'active',
            'starts_at' => self::date($request->string('starts_at')),
            'ends_at'   => self::date($request->string('ends_at'), true),
        ], Auth::id());

        return $this->returnTo($student, null, 'packages', sprintf(
            'پکیج «%s» فعال شد: %s.', $package['title'], \HeleXa\Services\PackageAccess::summarise($result)
        ));
    }

    public function packageStatus(Request $request, array $params = []): Response
    {
        $student    = $this->guard($params, 'manage_packages');
        $packages   = new PackageRepository();
        $activation = $packages->findActivation((int) ($params['activation'] ?? 0));
        $status     = $request->string('status');

        // The activation must belong to this student: an id from someone
        // else's row is a 404, not a way to change their package.
        if ($activation === null || (int) $activation['user_id'] !== (int) $student['id']
            || !in_array($status, self::STATUSES, true)) {
            throw HttpException::notFound();
        }

        $package = $packages->findById((int) $activation['package_id']);
        if ($package === null) {
            throw HttpException::notFound();
        }

        ActivationService::setPackageStatus($activation, $package, $status, Auth::id());

        return $this->returnTo($student, null, 'packages', 'وضعیت پکیج «' . $package['title'] . '» و دوره‌های داخلش تغییر کرد.');
    }

    /* ============================================================== balin */

    public function balin(Request $request, array $params = []): Response
    {
        $student = $this->guard($params, 'balin.manage_students');
        $userId  = (int) $student['id'];
        $access  = new BalinAccessRepository();
        $lessons = new BalinLessonRepository();

        $enable = $request->bool('enabled');
        if ($enable && !$access->isEnabled($userId)) {
            $access->grant($userId, Auth::id(), 'از صفحه دسترسی دانشجو');
        } elseif (!$enable && $access->isEnabled($userId)) {
            $access->revoke($userId, Auth::id(), 'از صفحه دسترسی دانشجو');
        }

        // The form lists every lesson with a checkbox meaning "open". A lesson
        // everyone may see is closed by adding a block; a lesson given out one
        // by one is opened by adding a grant — setOpenLessons does both.
        $valid = array_map(static fn (array $l): int => (int) $l['id'], $lessons->all());
        $open  = $request->input('lessons');
        $open  = is_array($open) ? array_values(array_intersect($valid, array_map('intval', $open))) : [];

        $lessons->setOpenLessons($userId, $open, Auth::id());

        ActivityLogger::log('balin.student_access', Auth::id(), 'user', $userId,
            ['enabled' => $enable, 'open_lessons' => $open], 'notice', $request);

        return $this->returnTo($student, null, 'balin', 'دسترسی جزیره بالین ذخیره شد.');
    }

    /* ============================================== question bank / cards */

    public function qbank(Request $request, array $params = []): Response
    {
        $student = $this->guard($params, 'qbank.manage_students');
        $valid   = array_map(static fn (array $s): int => (int) $s['id'], (new QbSubjectRepository())->roots(false));
        $wanted  = array_values(array_intersect($valid, $this->ids($request, 'subjects')));

        (new QbAccessRepository())->sync((int) $student['id'], $wanted, Auth::id());
        ActivityLogger::log('qbank.access.updated', Auth::id(), 'user', (int) $student['id'], ['subjects' => $wanted], 'notice', $request);

        return $this->returnTo($student, null, 'qbank', 'دسترسی بانک سوال ذخیره شد.');
    }

    public function flashcards(Request $request, array $params = []): Response
    {
        $student = $this->guard($params, 'flashcards.manage_students');
        $valid   = array_map(static fn (array $c): int => (int) $c['id'], (new FcCatalogRepository())->courses());
        $wanted  = array_values(array_intersect($valid, $this->ids($request, 'courses')));

        (new FcStudyRepository())->syncAccess((int) $student['id'], $wanted, Auth::id());
        ActivityLogger::log('flashcards.access.updated', Auth::id(), 'user', (int) $student['id'], ['courses' => $wanted], 'notice', $request);

        return $this->returnTo($student, null, 'flashcards', 'دسترسی فلش‌کارت ذخیره شد.');
    }

    /* ============================================================ helpers */

    /** Loads the student and checks the section's own permission. */
    private function guard(array $params, string $permission): array
    {
        if (!Auth::can($permission)) {
            throw HttpException::forbidden('برای این بخش دسترسی ندارید.');
        }
        return $this->studentOr404((string) ($params['uuid'] ?? ''));
    }

    private function studentOr404(string $uuid): array
    {
        $student = (new UserRepository())->findByUuid($uuid);
        if ($student === null || ($student['role_slug'] ?? '') !== 'student') {
            throw HttpException::notFound();
        }
        return $student;
    }

    private function returnTo(array $student, ?string $error, string $anchor, string $success = ''): Response
    {
        if ($error !== null) {
            $this->flash('error', $error);
        } elseif ($success !== '') {
            $this->flash('success', $success);
        }
        return $this->redirect('/admin/students/' . $student['uuid'] . '/access#' . $anchor);
    }

    /** @return array<int,int> */
    private function ids(Request $request, string $field): array
    {
        $raw = $request->input($field);
        return is_array($raw) ? array_map('intval', $raw) : [];
    }

    /**
     * Reads data for a module that may not be installed yet. A missing table
     * shows that section as empty rather than failing the whole page.
     */
    private function optional(callable $read): array
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            \HeleXa\Core\Logger::error('access page section failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private static function date(string $value, bool $endOfDay = false): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00')
            : null;
    }

    /** Reuses the question bank's section, list and checklist styles. */
    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['qbank' => true], $status);
    }
}
