<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ActivityLogRepository;
use HeleXa\Models\SessionRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\Jalali;

final class DashboardController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $users    = new UserRepository();
        $sessions = new SessionRepository();

        return $this->page('layouts.app', 'admin.dashboard', [
            'title'          => 'داشبورد مدیریت',
            'todayText'      => Jalali::longDate(time()),
            'totalStudents'  => $users->countByRole('student'),
            'activeStudents' => $users->countActiveStudents(),
            'onlineNow'      => $sessions->countOnline(),
            'recentLogins'   => $sessions->recentLogins(8),
            'recentActivity' => Auth::can('view_logs') ? (new ActivityLogRepository())->recent(8) : [],
            'inbox'          => \HeleXa\Services\AdminInbox::rows(),
            'newToday'       => $this->count("SELECT COUNT(*) AS c FROM users u JOIN roles r ON r.id = u.role_id
                                              WHERE r.slug = 'student' AND u.deleted_at IS NULL AND u.created_at >= CURDATE()"),
            'salesMonth'     => Auth::can('shop.orders') ? $this->count("SELECT COALESCE(SUM(total), 0) AS c FROM shop_orders
                                              WHERE status = 'paid' AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')") : null,
            'studyToday'     => $this->count('SELECT COALESCE(SUM(duration_seconds), 0) AS c FROM study_sessions WHERE started_at >= CURDATE()'),
        ]);
    }

    private function count(string $sql): int
    {
        try {
            return (int) (\HeleXa\Core\Database::selectOne($sql)['c'] ?? 0);
        } catch (\PDOException) {
            return 0;
        }
    }
}
