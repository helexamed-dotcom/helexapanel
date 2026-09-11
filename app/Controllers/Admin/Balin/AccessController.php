<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Balin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\Balin\BalinAccessRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * Who gets into the island.
 *
 * A student with no row here has no access — the default is deny, and
 * granting is always an explicit act. Revoking closes the door but keeps
 * everything behind it: progress, XP, mastery and achievements all survive,
 * so restoring access restores the student exactly where they were.
 */
final class AccessController extends Controller
{
    private const PER_PAGE = 40;

    public function __construct(
        private readonly BalinAccessRepository $access = new BalinAccessRepository(),
    ) {
    }

    public function index(Request $request, array $params = []): Response
    {
        $search = trim($request->string('q'));
        $page   = max(1, $request->int('page', 1));
        $total  = $this->access->countStudents($search);

        return $this->page('layouts.app', 'admin.balin.access', [
            'title'    => 'دسترسی دانشجویان',
            'students' => $this->access->paginateStudents($search, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'search'   => $search,
            'page'     => $page,
            'pages'    => max(1, (int) ceil($total / self::PER_PAGE)),
            'total'    => $total,
            'enabled'  => $this->access->countEnabled(),
        ]);
    }

    public function toggle(Request $request, array $params = []): Response
    {
        $student = (new UserRepository())->findByUuid((string) ($params['uuid'] ?? ''));

        if ($student === null) {
            throw HttpException::notFound();
        }

        $enable = $request->bool('enable');
        $note   = trim($request->string('note')) ?: null;

        if ($enable) {
            $this->access->grant((int) $student['id'], Auth::id(), $note);
        } else {
            $this->access->revoke((int) $student['id'], Auth::id(), $note);
        }

        ActivityLogger::log(
            $enable ? 'balin.access.granted' : 'balin.access.revoked',
            Auth::id(),
            'user',
            (int) $student['id'],
            ['note' => $note],
            'notice',
            $request
        );

        $this->flash('success', $enable
            ? 'دسترسی «' . $student['full_name'] . '» به جزیره بالین فعال شد.'
            : 'دسترسی «' . $student['full_name'] . '» بسته شد. پیشرفت و امتیاز او حفظ شده است.');

        return $this->redirect('/admin/balin/access');
    }

    /** Opens the island to every student at once. */
    public function grantAll(Request $request, array $params = []): Response
    {
        $affected = $this->access->grantAllStudents(Auth::id());

        ActivityLogger::log('balin.access.granted_all', Auth::id(), 'balin', null,
            ['affected' => $affected], 'warning', $request);
        $this->flash('success', 'دسترسی همه دانشجویان فعال شد.');

        return $this->redirect('/admin/balin/access');
    }
}
