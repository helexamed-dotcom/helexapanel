<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\QuestionBank;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\QuestionBank\QbQuestionRepository;
use HeleXa\Models\QuestionBank\QbSubjectRepository;
use HeleXa\Models\QuestionBank\QbTagRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\QuestionBank\QbTransfer;

/**
 * JSON import / export of questions — the path for bulk and AI-written work.
 */
final class TransferController extends Controller
{
    private const MAX_UPLOAD = 25 * 1024 * 1024;

    public function index(Request $request, array $params = []): Response
    {
        $report = $_SESSION['qb_import_report'] ?? null;
        unset($_SESSION['qb_import_report']);

        return $this->page('layouts.app', 'admin.qbank.transfer', [
            'title'        => 'ورود و خروج JSON',
            'qbank'        => true,
            'tree'         => (new QbSubjectRepository())->tree(),
            'tags'         => (new QbTagRepository())->all(),
            'difficulties' => QbQuestionRepository::DIFFICULTY_LABELS,
            'prompt'       => QbTransfer::aiPrompt(),
            'report'       => is_array($report) ? $report : null,
            'canWrite'     => Auth::can('qbank.manage_questions'),
            'canPublish'   => Auth::can('qbank.publish'),
        ]);
    }

    public function export(Request $request, array $params = []): Response
    {
        $filters = [
            'subject_id' => $request->string('subject_id'),
            'difficulty' => $request->string('difficulty'),
            'status'     => $request->string('status'),
            'tag_id'     => $request->int('tag_id'),
        ];
        $withImages = $request->bool('images');

        ActivityLogger::log('qbank.exported', Auth::id(), null, null, $filters + ['images' => $withImages], 'notice', $request);

        return Response::streamed(
            static function () use ($filters, $withImages): void {
                @set_time_limit(300);
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                (new QbTransfer())->exportTo($filters, $withImages);
            },
            200,
            [
                'Content-Type'        => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="helexa-qbank-' . date('Y-m-d-His') . '.json"',
                'Cache-Control'       => 'no-store',
            ]
        );
    }

    public function sample(Request $request, array $params = []): Response
    {
        return Response::make(
            (string) json_encode(QbTransfer::sample(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            200,
            [
                'Content-Type'        => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="helexa-qbank-sample.json"',
            ]
        );
    }

    public function import(Request $request, array $params = []): Response
    {
        $json = trim((string) $request->input('json', ''));
        $file = $request->file('file');
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if ((int) $file['size'] > self::MAX_UPLOAD) {
                $this->flash('error', 'حجم فایل JSON بیش از ۲۵ مگابایت است؛ آن را چند بخش کنید.');
                return $this->redirect('/admin/qbank/transfer');
            }
            $json = (string) file_get_contents((string) $file['tmp_name']);
        }
        if ($json === '') {
            $this->flash('error', 'فایل JSON را انتخاب کنید یا متن آن را بچسبانید.');
            return $this->redirect('/admin/qbank/transfer');
        }

        $status = $request->string('status');
        $opts = [
            'create_missing' => $request->bool('create_missing'),
            'status'         => in_array($status, ['keep', 'draft', 'published'], true) ? $status : 'keep',
            'dry_run'        => $request->bool('dry_run'),
            'can_publish'    => Auth::can('qbank.publish'),
            'author'         => Auth::id(),
        ];

        @set_time_limit(300);
        try {
            $report = (new QbTransfer())->import($json, $opts);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/qbank/transfer');
        }

        // Only the first errors travel in the session; a file of thousands of
        // broken rows must not bloat it.
        $report['error_count']   = count($report['errors']);
        $report['warning_count'] = count($report['warnings']);
        $report['errors']        = array_slice($report['errors'], 0, 100, true);
        $report['warnings']      = array_slice($report['warnings'], 0, 100, true);
        $report['dry_run']       = $opts['dry_run'];
        $_SESSION['qb_import_report'] = $report;

        if (!$opts['dry_run']) {
            ActivityLogger::log('qbank.imported', Auth::id(), null, null, [
                'total' => $report['total'], 'created' => $report['created'],
                'updated' => $report['updated'], 'errors' => $report['error_count'],
            ], 'notice', $request);
        }

        return $this->redirect('/admin/qbank/transfer#report');
    }
}
