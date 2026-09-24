<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\QuestionBank;

use HeleXa\Core\Controller;
use HeleXa\Core\Paginator;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\QuestionBank\QbReportRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * «گزارشات»: the question-error reports students sent.
 *
 * Open reports come first. Each links to the question's editor; closing a
 * report can close every other open report on the same question at once,
 * since fixing the question answers all of them.
 */
final class ReportController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request, array $params = []): Response
    {
        $reports = new QbReportRepository();
        $status  = $request->string('status', 'open');
        if ($status !== 'all' && !array_key_exists($status, QbReportRepository::STATUSES)) {
            $status = 'open';
        }

        $total     = $reports->count($status);
        $paginator = new Paginator($total, self::PER_PAGE, $request->int('page', 1), '/admin/qbank/reports', ['status' => $status]);

        return $this->page('layouts.app', 'admin.qbank.reports', [
            'title'     => 'گزارشات اشکال سوال',
            'reports'   => $reports->page($status, self::PER_PAGE, $paginator->offset()),
            'status'    => $status,
            'counts'    => [
                'open'      => $reports->count('open'),
                'resolved'  => $reports->count('resolved'),
                'dismissed' => $reports->count('dismissed'),
                'all'       => $reports->count('all'),
            ],
            'reasons'   => QbReportRepository::REASONS,
            'statuses'  => QbReportRepository::STATUSES,
            'paginator' => $paginator,
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $reports = new QbReportRepository();
        $report  = $reports->find((int) ($params['id'] ?? 0));
        $status  = $request->string('status');
        $note    = $request->string('admin_note');

        if ($report !== null && array_key_exists($status, QbReportRepository::STATUSES)) {
            if ($status === 'resolved' && $request->bool('all_on_question')) {
                $closed = $reports->resolveQuestion((int) $report['question_id'], $note, Auth::id());
                $this->flash('success', sprintf('%s گزارش این سوال بسته شد.', fa((string) $closed)));
            } else {
                $reports->setStatus((int) $report['id'], $status, $note, Auth::id());
                $this->flash('success', 'وضعیت گزارش به «' . QbReportRepository::STATUSES[$status] . '» تغییر کرد.');
            }
            ActivityLogger::log('qbank.report.' . $status, Auth::id(), 'qb_report', (int) $report['id'], [], 'info', $request);
        }

        $back = $request->string('back');
        return $this->redirect(str_starts_with($back, '/admin/qbank/reports') ? $back : '/admin/qbank/reports');
    }

    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['qbank' => true], $status);
    }
}
