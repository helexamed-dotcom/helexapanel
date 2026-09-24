<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Modules;
use HeleXa\Services\StudentTypes;

/**
 * «انواع دانشجو»: the admin defines the kinds of student (ترمی، علوم پایه،
 * دستیاری …) and, for each, which sections of the site are on; then
 * approves or rejects what students ask to be.
 */
final class StudentTypeController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        if (!StudentTypes::ready()) {
            $this->flash('error', 'ابتدا مهاجرت 2026_09_26_student_types_and_home.sql را اجرا کنید.');
        }
        $editId = $request->int('edit');

        return $this->page('layouts.app', 'admin.student_types.index', [
            'title'   => 'انواع دانشجو',
            'types'   => StudentTypes::all(),
            'editing' => $editId > 0 ? StudentTypes::find($editId) : null,
            'catalog' => Modules::CATALOG,
            'colors'  => StudentTypes::COLORS,
            'pending' => StudentTypes::pendingCount(),
        ]);
    }

    public function save(Request $request, array $params = []): Response
    {
        $id    = $request->int('id');
        $title = trim(mb_substr($request->string('title'), 0, 128));
        if ($title === '') {
            $this->flash('error', 'عنوان را بنویسید.');
            return $this->redirect('/admin/student-types' . ($id ? '?edit=' . $id : ''));
        }
        $modules = array_values(array_intersect(array_keys(Modules::CATALOG), array_map('strval', (array) $request->input('modules', []))));
        $color   = in_array($request->string('color'), StudentTypes::COLORS, true) ? $request->string('color') : 'blue';
        $icon    = preg_match('/^[a-z\-]{2,24}$/', $request->string('icon')) === 1 ? $request->string('icon') : 'school';

        $values = [
            'title'    => $title,
            'desc'     => trim(mb_substr($request->string('description'), 0, 500)) ?: null,
            'color'    => $color,
            'icon'     => $icon,
            'modules'  => json_encode($modules),
            'approval' => $request->bool('requires_approval') ? 1 : 0,
            'active'   => $request->bool('is_active') ? 1 : 0,
            'sort'     => max(0, min(999, $request->int('sort_order'))),
        ];

        if ($id > 0) {
            Database::execute(
                'UPDATE student_types SET title = :title, description = :desc, color = :color, icon = :icon, modules = :modules,
                        requires_approval = :approval, is_active = :active, sort_order = :sort, updated_at = NOW() WHERE id = :id',
                $values + ['id' => $id]
            );
        } else {
            Database::insert(
                'INSERT INTO student_types (slug, title, description, color, icon, modules, requires_approval, is_active, sort_order, created_at)
                 VALUES (:slug, :title, :desc, :color, :icon, :modules, :approval, :active, :sort, NOW())',
                $values + ['slug' => 't-' . strtolower(Str::token(4))]
            );
        }
        ActivityLogger::log('student_type.saved', Auth::id(), 'student_type', $id ?: null, ['title' => $title], 'info', $request);
        $this->flash('success', 'نوع دانشجو ذخیره شد.');

        return $this->redirect('/admin/student-types');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        Database::execute('UPDATE users SET student_type_id = NULL WHERE student_type_id = :id', ['id' => $id]);
        Database::execute('DELETE FROM student_types WHERE id = :id', ['id' => $id]);
        ActivityLogger::log('student_type.deleted', Auth::id(), 'student_type', $id, [], 'warning', $request);
        $this->flash('success', 'حذف شد؛ دانشجویان این نوع به «بدون نوع» برگشتند.');

        return $this->redirect('/admin/student-types');
    }

    public function requests(Request $request, array $params = []): Response
    {
        $status = in_array($request->string('status'), ['pending', 'approved', 'rejected'], true) ? $request->string('status') : 'pending';
        $rows = [];
        try {
            $rows = Database::select(
                "SELECT r.*, t.title AS type_title, t.color, t.icon, u.full_name, u.username, u.uuid AS user_uuid,
                        u.avatar_path, u.gender, cur.title AS current_title, h.full_name AS handler_name
                 FROM student_type_requests r
                 JOIN student_types t ON t.id = r.type_id
                 JOIN users u ON u.id = r.user_id AND u.deleted_at IS NULL
                 LEFT JOIN student_types cur ON cur.id = u.student_type_id
                 LEFT JOIN users h ON h.id = r.handled_by
                 WHERE r.status = :s ORDER BY r.id DESC LIMIT 300",
                ['s' => $status]
            );
        } catch (\PDOException) {
            // not migrated
        }

        return $this->page('layouts.app', 'admin.student_types.requests', [
            'title'   => 'درخواست‌های نوع دانشجو',
            'rows'    => $rows,
            'status'  => $status,
            'pending' => StudentTypes::pendingCount(),
        ]);
    }

    public function decide(Request $request, array $params = []): Response
    {
        $row = Database::selectOne("SELECT * FROM student_type_requests WHERE id = :id AND status = 'pending'", ['id' => (int) ($params['id'] ?? 0)]);
        if ($row === null) {
            throw HttpException::notFound();
        }
        $approve = $request->string('decision') === 'approve';
        Database::execute(
            'UPDATE student_type_requests SET status = :s, admin_note = :n, handled_by = :u, handled_at = NOW() WHERE id = :id',
            ['s' => $approve ? 'approved' : 'rejected', 'n' => trim(mb_substr($request->string('admin_note'), 0, 500)) ?: null,
             'u' => Auth::id(), 'id' => (int) $row['id']]
        );
        if ($approve) {
            StudentTypes::assign((int) $row['user_id'], (int) $row['type_id']);
        }
        $type = StudentTypes::find((int) $row['type_id']);
        $this->notify((int) $row['user_id'], $approve, (string) ($type['title'] ?? ''));
        ActivityLogger::log('student_type.' . ($approve ? 'approved' : 'rejected'), Auth::id(), 'user', (int) $row['user_id'],
            ['type_id' => (int) $row['type_id']], 'info', $request);
        $this->flash('success', $approve ? 'تأیید شد و بخش‌های این نوع برای دانشجو فعال شد.' : 'درخواست رد شد.');

        return $this->redirect('/admin/student-types/requests');
    }

    /** Set a student's type directly from their page. */
    public function assign(Request $request, array $params = []): Response
    {
        $user = Database::selectOne("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.uuid = :u AND r.slug = 'student'",
            ['u' => (string) ($params['uuid'] ?? '')]);
        if ($user === null) {
            throw HttpException::notFound();
        }
        $typeId = $request->int('type_id');
        StudentTypes::assign((int) $user['id'], $typeId > 0 ? $typeId : null);
        ActivityLogger::log('student_type.assigned', Auth::id(), 'user', (int) $user['id'], ['type_id' => $typeId], 'info', $request);
        $this->flash('success', 'نوع دانشجو ذخیره شد.');

        return $this->redirect('/admin/students/' . $params['uuid'] . '/edit');
    }

    private function notify(int $userId, bool $approved, string $typeTitle): void
    {
        \HeleXa\Services\Notify::user(
            $userId,
            $approved ? 'نوع دانشجوی شما تأیید شد 🎉' : 'درخواست نوع دانشجو',
            $approved
                ? '«' . $typeTitle . '» برای شما فعال شد و بخش‌های مخصوص آن در منو آمده است.'
                : 'درخواست «' . $typeTitle . '» تأیید نشد. در صورت نیاز با پشتیبانی در تماس باشید.',
            '/student'
        );
    }
}
