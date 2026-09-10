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
            'recentActivity' => Auth::can('view_logs') ? (new ActivityLogRepository())->recent(10) : [],
        ]);
    }
}
