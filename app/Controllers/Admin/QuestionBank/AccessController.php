<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\QuestionBank;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\QuestionBank\QbAccessRepository;
use HeleXa\Models\QuestionBank\QbSubjectRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * Which students hold which درس.
 *
 * Two views of the same table: a searchable list of students, and one
 * student's page with a checkbox per درس. The second is the one the student
 * management page links to, so an admin editing a student can open their
 * question-bank access without hunting for it.
 */
final class AccessController extends Controller
{
    private const PER_PAGE = 40;

    private QbAccessRepository $access;
    private QbSubjectRepository $subjects;
    private UserRepository $users;

    public function __construct()
    {
        $this->access   = new QbAccessRepository();
        $this->subjects = new QbSubjectRepository();
        $this->users    = new UserRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $search  = trim($request->string('q'));
        $page    = max(1, $request->int('page', 1));
        $filters = ['role' => 'student', 'search' => $search];
        $total   = $this->users->countFiltered($filters);
        $rows    = $this->users->paginate($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        // One query for every listed student's grants, not one per row.
        $grants = [];
        foreach ($rows as $row) {
            $grants[(int) $row['id']] = [];
        }
        if ($grants !== []) {
            foreach ($this->grantsFor(array_keys($grants)) as $grant) {
                $grants[(int) $grant['user_id']][] = $grant['title'];
            }
        }

        return $this->page('layouts.app', 'admin.qbank.access', [
            'title'    => 'دسترسی بانک سوال',
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

        return $this->page('layouts.app', 'admin.qbank.access_student', [
            'title'    => 'دسترسی بانک سوال — ' . $student['full_name'],
            'student'  => $student,
            'subjects' => $this->subjects->roots(false),
            'granted'  => $this->grantedIdsIncludingInactive((int) $student['id']),
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $student = $this->studentOr404((string) ($params['uuid'] ?? ''));

        // Only depth-1 rows are grantable. Anything else in the submission —
        // a زیردرس id typed into the form by hand — is dropped here.
        $valid = [];
        foreach ($this->subjects->roots(false) as $root) {
            $valid[(int) $root['id']] = true;
        }

        $submitted = $request->input('subjects');
        $submitted = is_array($submitted) ? array_map('intval', $submitted) : [];
        $wanted    = array_values(array_filter($submitted, static fn (int $id): bool => isset($valid[$id])));

        $before = $this->grantedIdsIncludingInactive((int) $student['id']);
        $this->access->sync((int) $student['id'], $wanted, Auth::id());

        ActivityLogger::log('qbank.access.updated', Auth::id(), 'user', (int) $student['id'], [
            'granted' => array_values(array_diff($wanted, $before)),
            'revoked' => array_values(array_diff($before, $wanted)),
        ], 'notice', $request);

        $this->flash('success', 'دسترسی بانک سوال «' . $student['full_name'] . '» ذخیره شد.');

        $back = $request->string('back');
        return $this->redirect(str_starts_with($back, '/admin/') && !str_contains($back, '//')
            ? $back
            : '/admin/qbank/access/' . $student['uuid']);
    }

    /* ----------------------------------------------------------- helpers */

    private function studentOr404(string $uuid): array
    {
        $student = $this->users->findByUuid($uuid);
        if ($student === null || ($student['role_slug'] ?? '') !== 'student') {
            throw HttpException::notFound();
        }
        return $student;
    }

    /** @return array<int,int> */
    private function grantedIdsIncludingInactive(int $userId): array
    {
        $rows = \HeleXa\Core\Database::select(
            'SELECT subject_id FROM qb_student_access WHERE user_id = :u',
            ['u' => $userId]
        );

        return array_map(static fn (array $r): int => (int) $r['subject_id'], $rows);
    }

    /**
     * @param  array<int,int> $userIds
     * @return array<int,array{user_id:int, title:string}>
     */
    private function grantsFor(array $userIds): array
    {
        $ids = implode(',', array_map('intval', $userIds));

        return \HeleXa\Core\Database::select(
            'SELECT a.user_id, s.title
             FROM qb_student_access a
             JOIN qb_subjects s ON s.id = a.subject_id
             WHERE a.user_id IN (' . $ids . ')
             ORDER BY s.sort_order, s.title'
        );
    }

    /** Every page of the bank carries its own stylesheet and script. */
    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['qbank' => true], $status);
    }
}
