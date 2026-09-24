<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Controllers\Admin\LibraryController as AdminLibrary;
use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\LibraryRepository;
use HeleXa\Services\Auth;

/**
 * The student's content library: search, filter by kind, open an item.
 * Every item and every file is re-checked against the student on each request.
 */
final class LibraryController extends Controller
{
    private const PER_PAGE = 24;

    public function index(Request $request, array $params = []): Response
    {
        $userId  = (int) Auth::id();
        $repo    = new LibraryRepository();
        $filters = ['q' => mb_substr(trim($request->string('q')), 0, 100), 'kind' => $request->string('kind')];
        $page    = max(1, $request->int('page', 1));
        $total   = $repo->countForStudent($userId, $filters);

        return $this->page('layouts.app', 'student.library.index', [
            'title'   => 'کتابخانه',
            'items'   => $repo->forStudent($userId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'filters' => $filters,
            'kinds'   => LibraryRepository::KINDS,
            'page'    => $page,
            'pages'   => max(1, (int) ceil($total / self::PER_PAGE)),
            'total'   => $total,
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $repo = new LibraryRepository();
        $item = $this->itemOr404((string) ($params['uuid'] ?? ''));
        $repo->countView((int) $item['id']);

        return $this->page('layouts.app', 'student.library.show', [
            'title' => $item['title'],
            'item'  => $item,
            'kinds' => LibraryRepository::KINDS,
        ]);
    }

    public function media(Request $request, array $params = []): Response
    {
        return AdminLibrary::serve($this->itemOr404((string) ($params['uuid'] ?? '')), (string) ($params['which'] ?? ''), $request);
    }

    private function itemOr404(string $uuid): array
    {
        $item = (new LibraryRepository())->findForStudent((int) Auth::id(), $uuid);
        if ($item === null) {
            throw HttpException::notFound();
        }
        return $item;
    }
}
