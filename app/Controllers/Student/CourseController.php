<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ContentRepository;
use HeleXa\Models\ContentStatusRepository;
use HeleXa\Models\EnrollmentRepository;
use HeleXa\Models\SectionRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\ContentAccess;

final class CourseController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'student.courses', [
            'title'    => 'دوره‌های من',
            'courses'  => (new EnrollmentRepository())->coursesForStudent((int) Auth::id()),
            'packages' => (new \HeleXa\Models\PackageRepository())->forStudent((int) Auth::id()),
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $user   = Auth::user();
        $course = ContentAccess::authorizeCourse($user, (string) ($params['uuid'] ?? ''));

        $sections = (new SectionRepository())->forCourse((int) $course['id']);
        $contents = (new ContentRepository())->forCourse((int) $course['id']);
        $statuses = (new ContentStatusRepository())->forCourse((int) $user['id'], (int) $course['id']);

        // Students only ever see published nodes; drafts stay invisible.
        $isStudent = Auth::isStudent();
        if ($isStudent) {
            $sections = array_values(array_filter($sections, static fn (array $s): bool => $s['status'] === 'published'));
            $contents = array_values(array_filter($contents, static fn (array $c): bool => $c['status'] === 'published'));
        }

        $visibleSectionIds = array_column($sections, 'id');
        $bySection         = ['root' => []];
        foreach ($contents as $content) {
            if ($content['section_id'] !== null && !in_array((int) $content['section_id'], array_map('intval', $visibleSectionIds), true)) {
                continue; // parent section is hidden, so the leaf is hidden too
            }
            $key               = $content['section_id'] === null ? 'root' : (string) $content['section_id'];
            $bySection[$key][] = $content;
        }

        return $this->page('layouts.app', 'student.course_tree', [
            'title'     => (string) $course['title'],
            'course'    => $course,
            'sections'  => $sections,
            'bySection' => $bySection,
            'statuses'  => $statuses,
            'offlineEnabled' => \HeleXa\Services\OfflineAccess::isEnabled() && $isStudent,
        ]);
    }
}
