<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\SessionRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\IpWatch;

/**
 * The student's own sign-in history: when, from where, on what, and how each
 * session ended — plus a warning when their account was used from several
 * networks on one day.
 *
 * Showing a person their own IP addresses discloses nothing new to them, and
 * it is the most useful thing they can see if someone else has their
 * password.
 */
final class SecurityController extends Controller
{
    public function sessions(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();

        return $this->page('layouts.app', 'student.sessions', [
            'title'      => 'نشست‌های من',
            'sessions'   => (new SessionRepository())->historyForUser($userId, 100),
            'flags'      => IpWatch::recentForUser($userId, 14),
            'currentId'  => Auth::currentSessionId(),
        ]);
    }
}
