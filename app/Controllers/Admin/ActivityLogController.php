<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Paginator;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ActivityLogRepository;

final class ActivityLogController extends Controller
{
    private const PER_PAGE = 40;

    public function index(Request $request, array $params = []): Response
    {
        $logs = new ActivityLogRepository();

        $severity = $request->string('severity');
        $filters  = [
            'action'   => $request->string('action'),
            'severity' => in_array($severity, ['info', 'notice', 'warning', 'critical'], true) ? $severity : '',
            'user'     => $request->string('user'),
            'from'     => $this->normalizeDate($request->string('from')),
            'to'       => $this->normalizeDate($request->string('to'), true),
        ];

        $total     = $logs->countFiltered($filters);
        $paginator = new Paginator($total, self::PER_PAGE, $request->int('page', 1), '/admin/logs', [
            'action' => $filters['action'], 'severity' => $filters['severity'], 'user' => $filters['user'],
        ]);

        return $this->page('layouts.app', 'admin.logs', [
            'title'     => 'گزارش فعالیت',
            'logs'      => $logs->paginate($filters, self::PER_PAGE, $paginator->offset()),
            'paginator' => $paginator,
            'filters'   => $filters,
            'actions'   => $logs->distinctActions(),
            'total'     => $total,
        ]);
    }

    /** Accepts YYYY-MM-DD only; anything else is discarded rather than passed on. */
    private function normalizeDate(string $value, bool $endOfDay = false): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return '';
        }
        return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }
}
