<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\MessageRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

final class MessageController extends Controller
{
    private MessageRepository $messages;
    private UserRepository $users;

    public function __construct()
    {
        $this->messages = new MessageRepository();
        $this->users    = new UserRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $academic = new AcademicRepository();

        return $this->page('layouts.app', 'admin.messages', [
            'title'    => 'پیام‌ها',
            'messages' => $this->messages->sent(),
            'students' => $this->users->paginate(['role' => 'student', 'status' => 'active'], 500, 0),
            'terms'    => $academic->terms(),
            'groups'   => $academic->groups(),
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $subject = $request->string('subject');
        $body    = $request->string('body');
        $target  = $request->string('target', 'user');

        if ($subject === '' || $body === '') {
            $this->flash('error', 'موضوع و متن پیام الزامی هستند.');
            return $this->redirect('/admin/messages');
        }
        if (mb_strlen($body) > 5000) {
            $this->flash('error', 'متن پیام نباید بیشتر از ۵۰۰۰ کاراکتر باشد.');
            return $this->redirect('/admin/messages');
        }

        $recipients = [];
        $broadcast  = false;

        switch ($target) {
            case 'all':
                $recipients = $this->users->activeStudentIds();
                $broadcast  = true;
                break;
            case 'term':
                $termId = $request->int('term_id') ?: null;
                if ($termId === null) {
                    $this->flash('error', 'ترم را انتخاب کنید.');
                    return $this->redirect('/admin/messages');
                }
                $recipients = $this->users->activeStudentIds($termId);
                $broadcast  = true;
                break;
            case 'group':
                $groupId = $request->int('group_id') ?: null;
                if ($groupId === null) {
                    $this->flash('error', 'گروه را انتخاب کنید.');
                    return $this->redirect('/admin/messages');
                }
                $recipients = $this->users->activeStudentIds(null, $groupId);
                $broadcast  = true;
                break;
            default:
                $student = $this->users->findByUuid($request->string('student_uuid'));
                if ($student === null || $student['role_slug'] !== 'student') {
                    $this->flash('error', 'دانشجوی انتخاب‌شده معتبر نیست.');
                    return $this->redirect('/admin/messages');
                }
                $recipients = [(int) $student['id']];
        }

        if ($recipients === []) {
            $this->flash('error', 'هیچ گیرنده‌ای برای این پیام پیدا نشد.');
            return $this->redirect('/admin/messages');
        }

        $id = $this->messages->send((int) Auth::id(), $subject, $body, $recipients, $broadcast);

        ActivityLogger::log('message.sent', Auth::id(), 'message', $id,
            ['recipients' => count($recipients), 'target' => $target], 'notice', $request);
        $this->flash('success', sprintf('پیام برای %d دانشجو ارسال شد.', count($recipients)));

        return $this->redirect('/admin/messages');
    }

    public function show(Request $request, array $params = []): Response
    {
        $id         = (int) ($params['id'] ?? 0);
        $recipients = $this->messages->recipientsOf($id);

        if ($recipients === []) {
            throw HttpException::notFound('پیام یافت نشد.');
        }

        return $this->page('layouts.app', 'admin.message_recipients', [
            'title'      => 'گیرندگان پیام',
            'recipients' => $recipients,
        ]);
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $this->messages->delete($id);

        ActivityLogger::log('message.deleted', Auth::id(), 'message', $id, [], 'notice', $request);
        $this->flash('success', 'پیام حذف شد.');

        return $this->redirect('/admin/messages');
    }
}
