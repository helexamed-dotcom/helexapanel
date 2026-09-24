<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\StudyMarkRepository;
use HeleXa\Services\Auth;

/**
 * «درس‌های من»: the student's own reading list.
 *
 * Marks arrive from buttons around the site («📌 باید بخونم») and from the
 * weak-spots analysis of an exam; the student can also write one by hand.
 * Every button posts here and comes back to where it was pressed.
 */
final class StudyMarkController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();

        return $this->page('layouts.app', 'student.study.index', [
            'title' => 'درس‌های من',
            'marks' => (new StudyMarkRepository())->forUser($userId),
            'kinds' => StudyMarkRepository::KINDS,
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $title  = trim($request->string('title'));

        if ($title === '') {
            return $this->reply($request, false, 'عنوان را بنویس.');
        }

        (new StudyMarkRepository())->add(
            $userId,
            $request->string('kind', 'custom'),
            $request->int('ref_id') ?: null,
            $title,
            $request->string('url') ?: null,
            $request->string('note') ?: null,
            $request->string('due_date') ?: null
        );

        return $this->reply($request, true, '📌 به «درس‌های من» اضافه شد.');
    }

    public function toggle(Request $request, array $params = []): Response
    {
        (new StudyMarkRepository())->toggleDone((int) ($params['id'] ?? 0), (int) Auth::id());
        return $this->reply($request, true, '');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        (new StudyMarkRepository())->delete((int) ($params['id'] ?? 0), (int) Auth::id());
        return $this->reply($request, true, 'حذف شد.');
    }

    public function clearDone(Request $request, array $params = []): Response
    {
        $removed = (new StudyMarkRepository())->clearDone((int) Auth::id());
        return $this->reply($request, true, sprintf('%s مورد خوانده‌شده پاک شد.', fa((string) $removed)));
    }

    /** JSON for the buttons that post in place; a redirect back for forms. */
    private function reply(Request $request, bool $ok, string $message): Response
    {
        if ($request->isAjax()) {
            return $this->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
        }
        if ($message !== '') {
            $this->flash($ok ? 'success' : 'error', $message);
        }

        $back = $request->string('back');
        return $this->redirect(preg_match('~^/student/[A-Za-z0-9/_\-?=&%.]*$~', $back) === 1 ? $back : '/student/study');
    }
}
