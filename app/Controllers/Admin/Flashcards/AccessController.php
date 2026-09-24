<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Flashcards;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\Flashcards\FcCatalogRepository;
use HeleXa\Models\Flashcards\FcStudyRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * Which students hold which flashcard course.
 *
 * Granted per course — «زبان», never «زبان · جلسه ۷» — so a session added to
 * the course later reaches every holder without a second grant.
 */
final class AccessController extends Controller
{
    private const PER_PAGE = 40;

    public function index(Request $request, array $params = []): Response
    {
        $users   = new UserRepository();
        $search  = trim($request->string('q'));
        $page    = max(1, $request->int('page', 1));
        $filters = ['role' => 'student', 'search' => $search];
        $total   = $users->countFiltered($filters);
        $rows    = $users->paginate($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        $grants = [];
        foreach ($rows as $row) {
            $grants[(int) $row['id']] = [];
        }
        if ($grants !== []) {
            $found = Database::select(
                'SELECT a.user_id, c.title FROM fc_access a JOIN fc_courses c ON c.id = a.course_id
                 WHERE a.user_id IN (' . implode(',', array_map('intval', array_keys($grants))) . ')
                 ORDER BY c.sort_order, c.title'
            );
            foreach ($found as $grant) {
                $grants[(int) $grant['user_id']][] = $grant['title'];
            }
        }

        return $this->page('layouts.app', 'admin.flashcards.access', [
            'title'    => 'دسترسی فلش‌کارت',
            'students' => $rows,
            'grants'   => $grants,
            'search'   => $search,
            'page'     => $page,
            'pages'    => max(1, (int) ceil($total / self::PER_PAGE)),
            'total'    => $total,
        ]);
    }

    public function edit(Request $request, array $params = []): Response
    {
        $student = $this->studentOr404((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.flashcards.access_student', [
            'title'   => 'دسترسی فلش‌کارت — ' . $student['full_name'],
            'student' => $student,
            'courses' => (new FcCatalogRepository())->courses(),
            'granted' => (new FcStudyRepository())->grantedCourseIds((int) $student['id']),
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $student = $this->studentOr404((string) ($params['uuid'] ?? ''));
        $study   = new FcStudyRepository();

        $valid = [];
        foreach ((new FcCatalogRepository())->courses() as $course) {
            $valid[(int) $course['id']] = true;
        }

        $submitted = $request->input('courses');
        $submitted = is_array($submitted) ? array_map('intval', $submitted) : [];
        $wanted    = array_values(array_filter($submitted, static fn (int $id): bool => isset($valid[$id])));
        $before    = $study->grantedCourseIds((int) $student['id']);

        $study->syncAccess((int) $student['id'], $wanted, Auth::id());

        ActivityLogger::log('flashcards.access.updated', Auth::id(), 'user', (int) $student['id'], [
            'granted' => array_values(array_diff($wanted, $before)),
            'revoked' => array_values(array_diff($before, $wanted)),
        ], 'notice', $request);

        $this->flash('success', 'دسترسی فلش‌کارت «' . $student['full_name'] . '» ذخیره شد.');

        return $this->redirect('/admin/flashcards/access/' . $student['uuid']);
    }

    private function studentOr404(string $uuid): array
    {
        $student = (new UserRepository())->findByUuid($uuid);
        if ($student === null || ($student['role_slug'] ?? '') !== 'student') {
            throw HttpException::notFound();
        }
        return $student;
    }

    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        // The two access pages share their list and checklist layout with the
        // question bank's, so they load its stylesheet as well. qbank.js does
        // nothing on a page without its editor or player.
        return parent::page($layout, $template, $data + ['flashcards' => true, 'qbank' => true], $status);
    }
}
