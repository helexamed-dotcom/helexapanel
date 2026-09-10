<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Core\Validator;
use HeleXa\Models\ContentRepository;
use HeleXa\Models\CourseRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\SectionRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\ContentStorage;

final class CourseController extends Controller
{
    private CourseRepository $courses;

    public function __construct()
    {
        $this->courses = new CourseRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.courses.index', [
            'title'   => 'دوره‌ها',
            'courses' => $this->courses->all(),
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.courses.form', [
            'title'  => 'افزودن دوره',
            'course' => null,
            'errors' => [],
            'old'    => [],
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $data   = $this->collect($request);
        $errors = $this->validate($data)->errors();

        if ($this->courses->slugExists($data['slug'])) {
            $data['slug'] .= '-' . substr(Str::token(2), 0, 4);
        }

        if ($errors !== []) {
            return $this->page('layouts.app', 'admin.courses.form', [
                'title' => 'افزودن دوره', 'course' => null, 'errors' => $errors, 'old' => $data,
            ], 422);
        }

        $id = $this->courses->create($data + ['uuid' => Str::uuid4(), 'created_by' => Auth::id()]);
        ActivityLogger::log('course.created', Auth::id(), 'course', $id, ['title' => $data['title']], 'notice', $request);
        $this->flash('success', 'دوره ساخته شد. حالا ساختار درختی و محتوا را اضافه کنید.');

        return $this->redirect('/admin/courses');
    }

    public function edit(Request $request, array $params = []): Response
    {
        $course = $this->find((string) ($params['uuid'] ?? ''));
        return $this->page('layouts.app', 'admin.courses.form', [
            'title' => 'ویرایش ' . $course['title'], 'course' => $course, 'errors' => [], 'old' => $course,
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $course = $this->find((string) ($params['uuid'] ?? ''));
        $data   = $this->collect($request);
        $errors = $this->validate($data)->errors();

        if ($this->courses->slugExists($data['slug'], (int) $course['id'])) {
            $errors['slug'] = 'این نامک قبلاً استفاده شده است.';
        }
        if ($errors !== []) {
            return $this->page('layouts.app', 'admin.courses.form', [
                'title' => 'ویرایش ' . $course['title'], 'course' => $course, 'errors' => $errors, 'old' => $data,
            ], 422);
        }

        $this->courses->update((int) $course['id'], $data);
        ActivityLogger::log('course.updated', Auth::id(), 'course', (int) $course['id'], [], 'notice', $request);
        $this->flash('success', 'دوره به‌روزرسانی شد.');

        return $this->redirect('/admin/courses');
    }

    /**
     * The course icon has its own small upload form, separate from the rest
     * of the fields: uploading an image has no bearing on the title/slug
     * validation the general update() path runs.
     */
    public function updateIcon(Request $request, array $params = []): Response
    {
        $course = $this->find((string) ($params['uuid'] ?? ''));

        $file     = $request->file('icon');
        $existing = $request->string('existing_path');

        try {
            if ($file !== null) {
                $path = \HeleXa\Services\ImageAssetStorage::storeUploaded($file, 'courses');
            } elseif ($existing !== '') {
                $offered = array_column(\HeleXa\Services\ImageAssetStorage::listExisting('courses'), 'path');
                if (!in_array($existing, $offered, true)) {
                    $this->flash('error', 'فایل انتخاب‌شده معتبر نیست.');
                    return $this->redirect('/admin/courses/' . $course['uuid'] . '/edit');
                }
                $path = $existing;
            } else {
                $this->flash('error', 'فایلی برای ذخیره انتخاب نشده است.');
                return $this->redirect('/admin/courses/' . $course['uuid'] . '/edit');
            }
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/courses/' . $course['uuid'] . '/edit');
        }

        $this->courses->setThumbnail((int) $course['id'], $path);
        ActivityLogger::log('course.icon_updated', Auth::id(), 'course', (int) $course['id'], [], 'notice', $request);
        $this->flash('success', 'آیکون دوره به‌روزرسانی شد.');

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/edit');
    }

    public function resetIcon(Request $request, array $params = []): Response
    {
        $course = $this->find((string) ($params['uuid'] ?? ''));
        $this->courses->setThumbnail((int) $course['id'], null);
        $this->flash('success', 'آیکون دوره حذف شد.');

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/edit');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $course = $this->find((string) ($params['uuid'] ?? ''));
        $this->courses->softDelete((int) $course['id']);

        ActivityLogger::log('course.deleted', Auth::id(), 'course', (int) $course['id'],
            ['title' => $course['title']], 'warning', $request);
        $this->flash('success', 'دوره حذف شد. فایل‌های محتوا روی سرور باقی می‌مانند تا در صورت نیاز بازیابی شوند.');

        return $this->redirect('/admin/courses');
    }

    /** The tree builder: sections and their contents for one course. */
    public function builder(Request $request, array $params = []): Response
    {
        $course   = $this->find((string) ($params['uuid'] ?? ''));
        $sections = (new SectionRepository())->forCourse((int) $course['id']);
        $contents = (new ContentRepository())->forCourse((int) $course['id']);

        $bySection = ['root' => []];
        foreach ($contents as $content) {
            $key               = $content['section_id'] === null ? 'root' : (string) $content['section_id'];
            $bySection[$key][] = $content;
        }

        return $this->page('layouts.app', 'admin.courses.builder', [
            'title'     => 'ساختار ' . $course['title'],
            'course'    => $course,
            'sections'  => $sections,
            'bySection' => $bySection,
            'maxUpload' => $this->uploadLimitLabel(),
        ]);
    }

    /* ------------------------------------------------------- enrollment */

    public function students(Request $request, array $params = []): Response
    {
        $course      = $this->find((string) ($params['uuid'] ?? ''));
        $enrollments = (new EnrollmentRepository())->forCourse((int) $course['id']);
        $enrolledIds = array_column($enrollments, 'user_id');

        $all = (new UserRepository())->paginate(['role' => 'student', 'status' => 'active'], 500, 0);

        return $this->page('layouts.app', 'admin.courses.students', [
            'title'       => 'دانشجویان ' . $course['title'],
            'course'      => $course,
            'enrollments' => $enrollments,
            'candidates'  => array_values(array_filter(
                $all,
                static fn (array $u): bool => !in_array((int) $u['id'], array_map('intval', $enrolledIds), true)
            )),
        ]);
    }

    public function assignStudent(Request $request, array $params = []): Response
    {
        $course  = $this->find((string) ($params['uuid'] ?? ''));
        $student = (new UserRepository())->findByUuid($request->string('student_uuid'));

        if ($student === null || $student['role_slug'] !== 'student') {
            throw HttpException::notFound('دانشجو یافت نشد.');
        }

        $status = $request->string('status', 'active');
        if (!in_array($status, ['active', 'suspended', 'expired', 'cancelled'], true)) {
            $status = 'active';
        }

        // Routed through the activation service so the enrolment, the audit
        // entry and the student's announcement all come from one place.
        $result = \HeleXa\Services\ActivationService::activateCourse($student, $course, [
            'status'    => $status,
            'starts_at' => $this->normalizeDate($request->string('starts_at')),
            'ends_at'   => $this->normalizeDate($request->string('ends_at'), true),
        ], Auth::id());

        $this->flash('success', $result['notified']
            ? 'دسترسی دانشجو ثبت شد و یک اطلاعیه برای او ساخته شد.'
            : 'دسترسی دانشجو ثبت شد.');

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/students');
    }

    public function removeStudent(Request $request, array $params = []): Response
    {
        $course  = $this->find((string) ($params['uuid'] ?? ''));
        $student = (new UserRepository())->findByUuid((string) ($params['student'] ?? ''));

        if ($student === null) {
            throw HttpException::notFound();
        }

        (new EnrollmentRepository())->remove((int) $student['id'], (int) $course['id']);
        ActivityLogger::log('course.unassigned', Auth::id(), 'course', (int) $course['id'],
            ['student' => $student['username']], 'warning', $request);
        $this->flash('success', 'دسترسی دانشجو حذف شد و از همین لحظه محتوا برای او باز نمی‌شود.');

        return $this->redirect('/admin/courses/' . $course['uuid'] . '/students');
    }

    /* ---------------------------------------------------------- helpers */

    private function find(string $uuid): array
    {
        $course = $this->courses->findByUuid($uuid);
        if ($course === null) {
            throw HttpException::notFound('دوره یافت نشد.');
        }
        return $course;
    }

    private function collect(Request $request): array
    {
        $status = $request->string('status', 'draft');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            $status = 'draft';
        }
        $title = $request->string('title');

        return [
            'title'       => $title,
            'slug'        => Str::slug($request->string('slug') !== '' ? $request->string('slug') : $title),
            'description' => $request->string('description'),
            'color'       => preg_match('/^#[0-9a-fA-F]{6}$/', $request->string('color')) === 1 ? $request->string('color') : null,
            'status'      => $status,
            'sort_order'  => $request->int('sort_order'),
        ];
    }

    private function validate(array $data): Validator
    {
        return (new Validator($data))
            ->required('title', 'عنوان دوره')
            ->length('title', 'عنوان دوره', 2, 191);
    }

    private function normalizeDate(string $value, bool $endOfDay = false): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }

    private function uploadLimitLabel(): string
    {
        return (string) ini_get('upload_max_filesize') . ' / ' . (string) ini_get('post_max_size');
    }
}
