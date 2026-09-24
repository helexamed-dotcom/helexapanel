<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\SessionRepository;
use HeleXa\Models\UserRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\IpWatch;
use HeleXa\Services\NotificationService;

/**
 * Students whose account was used from several networks in one day.
 *
 * Three decisions per flag, all made by a person:
 *   warn     the student gets a notification and sees the warning on their
 *            sessions page
 *   suspend  the account is suspended and every session and remember-me
 *            token is revoked — the same thing the student list's suspend
 *            button does
 *   dismiss  a false alarm (a trip, a new internet plan); it reopens only if
 *            that day gets worse
 */
final class SecurityFlagController extends Controller
{
    private const STATUSES = ['open' => 'بررسی‌نشده', 'warned' => 'اخطار داده شده', 'suspended' => 'تعلیق شده', 'dismissed' => 'رد شده'];

    public function index(Request $request, array $params = []): Response
    {
        $status = $request->string('status', 'open');
        if ($status !== 'all' && !isset(self::STATUSES[$status])) {
            $status = 'open';
        }

        $rows = [];
        if (IpWatch::available()) {
            $rows = Database::select(
                'SELECT f.*, u.uuid AS user_uuid, u.full_name, u.username, u.status AS user_status,
                        (SELECT COUNT(*) FROM security_flags f2 WHERE f2.user_id = f.user_id) AS flag_total,
                        r.full_name AS reviewer_name
                 FROM security_flags f
                 JOIN users u ON u.id = f.user_id AND u.deleted_at IS NULL
                 LEFT JOIN users r ON r.id = f.reviewed_by'
                . ($status === 'all' ? '' : ' WHERE f.status = :status')
                . ' ORDER BY f.flag_day DESC, f.networks DESC LIMIT 200',
                $status === 'all' ? [] : ['status' => $status]
            );
        }

        $counts = ['open' => 0, 'warned' => 0, 'suspended' => 0, 'dismissed' => 0];
        if (IpWatch::available()) {
            foreach (Database::select('SELECT status, COUNT(*) AS c FROM security_flags GROUP BY status') as $row) {
                $counts[(string) $row['status']] = (int) $row['c'];
            }
        }

        return $this->page('layouts.app', 'admin.security_flags', [
            'title'     => 'کاربران مشکوک',
            'rows'      => $rows,
            'status'    => $status,
            'statuses'  => self::STATUSES,
            'counts'    => $counts,
            'available' => IpWatch::available(),
            'enabled'   => IpWatch::enabled(),
            'threshold' => IpWatch::threshold(),
        ]);
    }

    public function warn(Request $request, array $params = []): Response
    {
        [$flag, $student] = $this->load($params);
        $note = mb_substr(trim($request->string('note')), 0, 255);

        $this->review($flag, 'warned', $note);

        NotificationService::publish([
            'title'           => '⚠ اخطار امنیتی حساب کاربری',
            'body'            => 'در تاریخ ' . jdate($flag['flag_day'] . ' 00:00:00') . ' به حساب شما از '
                               . fa((string) $flag['networks']) . ' شبکه‌ی مختلف وارد شده‌اند. '
                               . 'حساب کاربری شخصی است و استفاده‌ی مشترک از آن به تعلیق حساب منجر می‌شود.'
                               . ($note !== '' ? "\n" . $note : ''),
            'notif_type'      => 'system',
            'related_type'    => 'security_flag',
            'related_id'      => (int) $flag['id'],
            'idempotency_key' => 'security_warn:' . (int) $flag['id'],
            'audience'        => 'user',
            'user_id'         => (int) $student['id'],
            'link_url'        => '/student/sessions',
            'created_by'      => Auth::id(),
        ]);

        ActivityLogger::log('security.flag_warned', Auth::id(), 'user', (int) $student['id'], ['flag' => (int) $flag['id']], 'warning', $request);
        $this->flash('success', 'به «' . $student['full_name'] . '» اخطار داده شد.');

        return $this->redirect('/admin/security/flags');
    }

    public function suspend(Request $request, array $params = []): Response
    {
        [$flag, $student] = $this->load($params);

        (new UserRepository())->setStatus((int) $student['id'], 'suspended');
        (new SessionRepository())->terminateAllForUser((int) $student['id'], 'admin_force', Auth::id());
        Auth::revokeRememberTokens((int) $student['id'], 'admin');

        $this->review($flag, 'suspended', mb_substr(trim($request->string('note')), 0, 255));

        ActivityLogger::log('security.flag_suspended', Auth::id(), 'user', (int) $student['id'], ['flag' => (int) $flag['id']], 'critical', $request);
        $this->flash('success', 'حساب «' . $student['full_name'] . '» تعلیق شد و همه نشست‌هایش بسته شد.');

        return $this->redirect('/admin/security/flags');
    }

    public function dismiss(Request $request, array $params = []): Response
    {
        [$flag, $student] = $this->load($params);

        $this->review($flag, 'dismissed', mb_substr(trim($request->string('note')), 0, 255));

        ActivityLogger::log('security.flag_dismissed', Auth::id(), 'user', (int) $student['id'], ['flag' => (int) $flag['id']], 'notice', $request);
        $this->flash('success', 'این مورد رد شد.');

        return $this->redirect('/admin/security/flags');
    }

    /** @return array{0:array,1:array} */
    private function load(array $params): array
    {
        if (!IpWatch::available()) {
            throw HttpException::notFound();
        }

        $flag = Database::selectOne('SELECT * FROM security_flags WHERE id = :id LIMIT 1', ['id' => (int) ($params['id'] ?? 0)]);
        if ($flag === null) {
            throw HttpException::notFound();
        }

        $student = (new UserRepository())->findById((int) $flag['user_id']);
        if ($student === null || ($student['role_slug'] ?? '') !== 'student') {
            throw HttpException::notFound();
        }

        return [$flag, $student];
    }

    private function review(array $flag, string $status, string $note): void
    {
        Database::execute(
            'UPDATE security_flags
                SET status = :status, reviewed_by = :by, reviewed_at = :now1, note = :note, updated_at = :now2
              WHERE id = :id',
            [
                'status' => $status,
                'by'     => Auth::id(),
                'now1'   => date('Y-m-d H:i:s'),
                'note'   => $note !== '' ? $note : null,
                'now2'   => date('Y-m-d H:i:s'),
                'id'     => (int) $flag['id'],
            ]
        );
    }
}
