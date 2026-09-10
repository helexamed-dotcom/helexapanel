<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\AcademicRepository;
use HeleXa\Models\CourseRepository;
use HeleXa\Models\NotificationRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

final class NotificationController extends Controller
{
    private const TYPES     = ['content', 'schedule', 'exam', 'course', 'package', 'message', 'system'];
    private const AUDIENCES = ['all', 'term', 'group', 'university', 'major', 'course', 'package', 'user'];

    private NotificationRepository $notifications;
    private AcademicRepository $academic;

    public function __construct()
    {
        $this->notifications = new NotificationRepository();
        $this->academic      = new AcademicRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->page('layouts.app', 'admin.notifications', [
            'title'         => 'اطلاعیه‌ها',
            'notifications' => $this->notifications->all(),
            'terms'         => $this->academic->terms(),
            'groups'        => $this->academic->groups(),
            'universities'  => $this->academic->universities(),
            'majors'        => $this->academic->majors(),
            'courses'       => (new CourseRepository())->all(),
            'packages'      => (new PackageRepository())->all(),
            'students'      => (new UserRepository())->paginate(['role' => 'student', 'status' => 'active'], 500, 0),
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $audience = $request->string('audience', 'all');
        if (!in_array($audience, self::AUDIENCES, true)) {
            $audience = 'all';
        }

        $type = $request->string('notif_type', 'system');
        if (!in_array($type, self::TYPES, true)) {
            $type = 'system';
        }

        $title = $request->string('title');
        if ($title === '') {
            $this->flash('error', 'عنوان اطلاعیه الزامی است.');
            return $this->redirect('/admin/notifications');
        }

        $termId       = $request->int('term_id') ?: null;
        $groupId      = $request->int('group_id') ?: null;
        $universityId = $request->int('university_id') ?: null;
        $majorId      = $request->int('major_id') ?: null;
        $courseId     = $request->int('course_id') ?: null;
        $packageId    = $request->int('package_id') ?: null;
        $userId       = null;

        // Only the field the chosen audience actually needs is kept; the
        // others are dropped so a stale value from the form cannot leak into
        // a different audience type.
        [$termId, $groupId, $universityId, $majorId, $courseId, $packageId] = match ($audience) {
            'term'       => [$termId, null, null, null, null, null],
            'group'      => [null, $groupId, null, null, null, null],
            'university' => [null, null, $universityId, null, null, null],
            'major'      => [null, null, null, $majorId, null, null],
            'course'     => [null, null, null, null, $courseId, null],
            'package'    => [null, null, null, null, null, $packageId],
            default      => [null, null, null, null, null, null],
        };

        $requiredMessages = [
            'term'       => ['value' => $termId,       'error' => 'برای اطلاعیه ترمی باید ترم را انتخاب کنید.'],
            'group'      => ['value' => $groupId,      'error' => 'برای اطلاعیه گروهی باید گروه را انتخاب کنید.'],
            'university' => ['value' => $universityId, 'error' => 'برای اطلاعیه دانشگاهی باید دانشگاه را انتخاب کنید.'],
            'major'      => ['value' => $majorId,      'error' => 'برای اطلاعیه رشته‌ای باید رشته را انتخاب کنید.'],
            'course'     => ['value' => $courseId,     'error' => 'برای اطلاعیه دوره‌ای باید دوره را انتخاب کنید.'],
            'package'    => ['value' => $packageId,    'error' => 'برای اطلاعیه پکیجی باید پکیج را انتخاب کنید.'],
        ];
        if (isset($requiredMessages[$audience]) && $requiredMessages[$audience]['value'] === null) {
            $this->flash('error', $requiredMessages[$audience]['error']);
            return $this->redirect('/admin/notifications');
        }

        if ($audience === 'user') {
            $student = (new UserRepository())->findByUuid($request->string('student_uuid'));
            if ($student === null || $student['role_slug'] !== 'student') {
                $this->flash('error', 'دانشجوی انتخاب‌شده معتبر نیست.');
                return $this->redirect('/admin/notifications');
            }
            $userId = (int) $student['id'];
        }

        // Internal routes only: an announcement must never become a phishing link.
        $link = $request->string('link_url');
        if ($link !== '' && !str_starts_with($link, '/')) {
            $link = '';
        }

        $expires = $request->string('expires_at');
        $expires = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) === 1 ? $expires . ' 23:59:59' : null;

        $id = \HeleXa\Services\NotificationService::publish([
            'title'         => $title,
            'body'          => $request->string('body'),
            'notif_type'    => $type,
            'audience'      => $audience,
            'term_id'       => $termId,
            'group_id'      => $groupId,
            'university_id' => $universityId,
            'major_id'      => $majorId,
            'course_id'     => $courseId,
            'package_id'    => $packageId,
            'user_id'       => $userId,
            'link_url'      => $link ?: null,
            'expires_at'    => $expires,
            'created_by'    => Auth::id(),
        ])['id'];

        ActivityLogger::log('notification.created', Auth::id(), 'notification', $id,
            ['audience' => $audience, 'title' => $title], 'notice', $request);
        $this->flash('success', 'اطلاعیه منتشر شد.');

        return $this->redirect('/admin/notifications');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if ($this->notifications->find($id) === null) {
            throw HttpException::notFound();
        }

        // The user_notifications rows go with it through the foreign key.
        $this->notifications->delete($id);
        ActivityLogger::log('notification.deleted', Auth::id(), 'notification', $id, [], 'notice', $request);
        $this->flash('success', 'اطلاعیه حذف شد.');

        return $this->redirect('/admin/notifications');
    }
}
