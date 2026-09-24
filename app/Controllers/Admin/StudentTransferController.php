<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\StudentTransfer;

/**
 * Export / import of all students as JSON.
 */
final class StudentTransferController extends Controller
{
    private const MAX_UPLOAD = 30 * 1024 * 1024;

    public function index(Request $request, array $params = []): Response
    {
        $report = $_SESSION['students_import_report'] ?? null;
        unset($_SESSION['students_import_report']);

        return $this->page('layouts.app', 'admin.students.transfer', [
            'title'  => 'ورود و خروج دانشجویان',
            'qbank'  => true, // shared page styles
            'report' => is_array($report) ? $report : null,
        ]);
    }

    public function export(Request $request, array $params = []): Response
    {
        $withHashes = $request->bool('hashes');
        ActivityLogger::log('students.exported', Auth::id(), null, null, ['hashes' => $withHashes],
            $withHashes ? 'warning' : 'notice', $request);

        return Response::streamed(
            static function () use ($withHashes): void {
                @set_time_limit(300);
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                (new StudentTransfer())->exportTo($withHashes);
            },
            200,
            [
                'Content-Type'        => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="helexa-students-' . date('Y-m-d-His') . '.json"',
                'Cache-Control'       => 'no-store',
            ]
        );
    }

    public function import(Request $request, array $params = []): Response
    {
        $json = trim((string) $request->input('json', ''));
        $file = $request->file('file');
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if ((int) $file['size'] > self::MAX_UPLOAD) {
                $this->flash('error', 'حجم فایل بیش از ۳۰ مگابایت است؛ آن را چند بخش کنید.');
                return $this->redirect('/admin/students/transfer');
            }
            $json = (string) file_get_contents((string) $file['tmp_name']);
        }
        if ($json === '') {
            $this->flash('error', 'فایل JSON را انتخاب کنید یا متن آن را بچسبانید.');
            return $this->redirect('/admin/students/transfer');
        }

        $opts = [
            'update_existing' => $request->bool('update_existing'),
            'sync_access'     => $request->bool('sync_access'),
            'restore_hashes'  => $request->bool('restore_hashes'),
            'dry_run'         => $request->bool('dry_run'),
            'author'          => Auth::id(),
        ];

        @set_time_limit(600);
        try {
            $report = (new StudentTransfer())->import($json, $opts);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/students/transfer');
        }

        $report['error_count']   = count($report['errors']);
        $report['warning_count'] = count($report['warnings']);
        $report['errors']        = array_slice($report['errors'], 0, 100, true);
        $report['warnings']      = array_slice($report['warnings'], 0, 100, true);
        $report['dry_run']       = $opts['dry_run'];
        // Temporary passwords are shown once, on the next page, then gone.
        $_SESSION['students_import_report'] = $report;

        if (!$opts['dry_run']) {
            ActivityLogger::log('students.imported', Auth::id(), null, null, [
                'total' => $report['total'], 'created' => $report['created'],
                'updated' => $report['updated'], 'errors' => $report['error_count'],
            ], 'warning', $request);
        }

        return $this->redirect('/admin/students/transfer#report');
    }
}