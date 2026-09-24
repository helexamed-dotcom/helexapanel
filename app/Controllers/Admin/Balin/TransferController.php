<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin\Balin;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Balin\BalinTransfer;
use HeleXa\Services\Balin\BalinTransferGuide;

/**
 * Balin island: whole lessons in and out as JSON.
 */
final class TransferController extends Controller
{
    private const MAX_UPLOAD = 30 * 1024 * 1024;

    public function index(Request $request, array $params = []): Response
    {
        $report = $_SESSION['balin_import_report'] ?? null;
        unset($_SESSION['balin_import_report']);

        return $this->page('layouts.app', 'admin.balin.transfer', [
            'title'         => 'ورود و خروج JSON جزیره',
            'qbank'         => true, // shared page styles
            'lessons'       => (new BalinLessonRepository())->all(),
            'selected'      => $request->string('lesson'),
            'prompt'        => BalinTransferGuide::prompt(),
            'report'        => is_array($report) ? $report : null,
            'canImport'     => Auth::can('balin.create') && Auth::can('balin.manage_questions'),
            'canPublish'    => Auth::can('balin.publish'),
            'clinicalReady' => (new BalinTransfer())->clinicalReady(),
        ]);
    }

    public function export(Request $request, array $params = []): Response
    {
        $repo = new BalinLessonRepository();
        $uuid = $request->string('lesson');

        if ($uuid !== '') {
            $lesson = $repo->findByUuid($uuid);
            $ids    = $lesson !== null ? [(int) $lesson['id']] : [];
            $name   = $lesson !== null ? 'helexa-balin-' . $lesson['slug'] : 'helexa-balin';
        } else {
            $ids  = array_map(static fn (array $l): int => (int) $l['id'], $repo->all());
            $name = 'helexa-balin-all-' . date('Y-m-d');
        }

        if ($ids === []) {
            $this->flash('error', 'درسی برای خروجی پیدا نشد.');
            return $this->redirect('/admin/balin/transfer');
        }

        @set_time_limit(300);
        $data = (new BalinTransfer())->export($ids);
        ActivityLogger::log('balin.exported', Auth::id(), null, null, ['lessons' => count($ids)], 'notice', $request);

        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $name) ?: 'helexa-balin';

        return Response::make(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            200,
            [
                'Content-Type'        => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $safe . '.json"; filename*=UTF-8\'\'' . rawurlencode($name . '.json'),
                'Cache-Control'       => 'no-store',
            ]
        );
    }

    public function sample(Request $request, array $params = []): Response
    {
        return Response::make(
            (string) json_encode(BalinTransferGuide::sample(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            200,
            [
                'Content-Type'        => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="helexa-balin-sample.json"',
            ]
        );
    }

    public function import(Request $request, array $params = []): Response
    {
        if (!Auth::can('balin.create') || !Auth::can('balin.manage_questions')) {
            $this->flash('error', 'برای ورود درس، دسترسی «ساخت محتوا» و «مدیریت سؤال‌ها» لازم است.');
            return $this->redirect('/admin/balin/transfer');
        }

        $json = trim((string) $request->input('json', ''));
        $file = $request->file('file');
        if ($file !== null && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if ((int) $file['size'] > self::MAX_UPLOAD) {
                $this->flash('error', 'حجم فایل بیش از ۳۰ مگابایت است؛ آن را چند بخش کنید.');
                return $this->redirect('/admin/balin/transfer');
            }
            $json = (string) file_get_contents((string) $file['tmp_name']);
        }
        if ($json === '') {
            $this->flash('error', 'فایل JSON را انتخاب کنید یا متن آن را بچسبانید.');
            return $this->redirect('/admin/balin/transfer');
        }

        $status = $request->string('status');
        $opts = [
            'dry_run'        => $request->bool('dry_run'),
            'status'         => in_array($status, ['keep', 'draft', 'published'], true) ? $status : 'keep',
            'can_publish'    => Auth::can('balin.publish'),
            'create_missing' => $request->bool('create_missing'),
            'author'         => Auth::id(),
        ];

        @set_time_limit(600);
        try {
            $report = (new BalinTransfer())->import($json, $opts);
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/balin/transfer');
        }

        $report['warning_count'] = count($report['warnings']);
        $report['warnings']      = array_slice($report['warnings'], 0, 150);
        $_SESSION['balin_import_report'] = $report;

        if (!$opts['dry_run']) {
            ActivityLogger::log('balin.imported', Auth::id(), null, null, $report['counts'] + [
                'errors' => count($report['errors']),
            ], 'notice', $request);
        }

        return $this->redirect('/admin/balin/transfer#report');
    }
}