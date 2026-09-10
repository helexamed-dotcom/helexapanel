<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ContentStatusRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\StudyAnalytics;

final class AnalyticsController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $userId   = (int) Auth::id();
        $weeksAgo = max(0, min(3, $request->int('week', 0)));

        $week      = StudyAnalytics::week($userId, $weeksAgo);
        $previous  = StudyAnalytics::week($userId, $weeksAgo + 1);
        $breakdown = (new ContentStatusRepository())->breakdownByCourse($userId);

        return $this->page('layouts.app', 'student.analytics', [
            'title'         => 'تحلیل عملکرد',
            'week'          => $week,
            'weeksAgo'      => $weeksAgo,
            'previousTotal' => $previous['total'],
            'courses'       => StudyAnalytics::coursesThisWeek($userId, $weeksAgo),
            'breakdown'     => $breakdown,
        ]);
    }
}
