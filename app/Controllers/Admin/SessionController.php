<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\SessionRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Settings;

/**
 * Session monitor. The "terminate all" action is the safety valve: if the
 * single-device policy ever traps a student, an admin can clear every row and
 * the student can log in again immediately.
 */
final class SessionController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $sessions = new SessionRepository();
        $sessions->sweepStale(
            Settings::int('session_idle_timeout', 1800),
            Settings::int('session_absolute_timeout', 7200)
        );

        return $this->page('layouts.app', 'admin.sessions', [
            'title'    => 'نشست‌های فعال',
            'sessions' => $sessions->recentLogins(50),
        ]);
    }

    public function forUser(Request $request, array $params = []): Response
    {
        $user = (new UserRepository())->findByUuid((string) ($params['uuid'] ?? ''));
        if ($user === null) {
            throw HttpException::notFound();
        }

        $sessions = new SessionRepository();

        return $this->page('layouts.app', 'admin.user_sessions', [
            'title'    => 'نشست‌های ' . $user['full_name'],
            'student'  => $user,
            'active'   => $sessions->activeForUser((int) $user['id']),
            'history'  => $sessions->historyForUser((int) $user['id'], 50),
        ]);
    }

    public function terminate(Request $request, array $params = []): Response
    {
        $sessionId = (int) ($params['id'] ?? 0);
        $sessions  = new SessionRepository();
        $row       = $sessions->findById($sessionId);

        if ($row === null) {
            throw HttpException::notFound();
        }

        $sessions->terminate($sessionId, 'admin_force', Auth::id());
        ActivityLogger::log('session.terminated', Auth::id(), 'session', $sessionId,
            ['target_user' => (int) $row['user_id']], 'notice', $request);

        if ($request->isAjax()) {
            return $this->json(['ok' => true]);
        }
        $this->flash('success', 'نشست انتخاب‌شده بسته شد.');
        return $this->redirect('/admin/sessions');
    }

    public function terminateAllForUser(Request $request, array $params = []): Response
    {
        $user = (new UserRepository())->findByUuid((string) ($params['uuid'] ?? ''));
        if ($user === null) {
            throw HttpException::notFound();
        }

        $count = (new SessionRepository())->terminateAllForUser((int) $user['id'], 'admin_force', Auth::id());
        // Otherwise the next page load would silently restore the session from
        // the remember cookie and the safety valve would do nothing.
        $revoked = Auth::revokeRememberTokens((int) $user['id'], 'admin');
        ActivityLogger::log('session.terminated_all', Auth::id(), 'user', (int) $user['id'],
            ['count' => $count, 'remember_revoked' => $revoked], 'notice', $request);

        if ($request->isAjax()) {
            return $this->json(['ok' => true, 'terminated' => $count]);
        }
        $this->flash('success', sprintf('%d نشست بسته شد. کاربر اکنون می‌تواند دوباره وارد شود.', $count));
        return $this->redirect('/admin/sessions/user/' . $user['uuid']);
    }
}
