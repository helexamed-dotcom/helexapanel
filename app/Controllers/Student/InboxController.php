<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\MessageRepository;
use HeleXa\Models\NotificationRepository;
use HeleXa\Services\Auth;

/**
 * Announcements and private messages for one student.
 * Every lookup is scoped by user_id, so an id from another inbox simply
 * matches no row rather than being checked and rejected afterwards.
 */
final class InboxController extends Controller
{
    public function notifications(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();

        return $this->page('layouts.app', 'student.notifications', [
            'title'         => 'اطلاعیه‌ها',
            'notifications' => (new NotificationRepository())->forUser($userId),
        ]);
    }

    public function readNotification(Request $request, array $params = []): Response
    {
        (new NotificationRepository())->markRead((int) Auth::id(), (int) ($params['id'] ?? 0));
        return $request->isAjax()
            ? $this->json(['ok' => true])
            : $this->redirect('/student/notifications');
    }

    public function readAllNotifications(Request $request, array $params = []): Response
    {
        $count = (new NotificationRepository())->markAllRead((int) Auth::id());
        if ($request->isAjax()) {
            return $this->json(['ok' => true, 'count' => $count]);
        }
        $this->flash('success', sprintf('%d اطلاعیه خوانده‌شده علامت خورد.', $count));

        return $this->redirect('/student/notifications');
    }

    public function messages(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'student.messages', [
            'title'    => 'پیام‌ها',
            'messages' => (new MessageRepository())->inbox((int) Auth::id()),
        ]);
    }

    public function showMessage(Request $request, array $params = []): Response
    {
        $userId     = (int) Auth::id();
        $repository = new MessageRepository();
        $message    = $repository->findForUser($userId, (int) ($params['id'] ?? 0));

        if ($message === null) {
            throw HttpException::notFound('پیام یافت نشد.');
        }

        $repository->markRead($userId, (int) $message['id']);

        return $this->page('layouts.app', 'student.message', [
            'title'   => (string) $message['subject'],
            'message' => $message,
        ]);
    }
}
