<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\Backup;

/**
 * «پشتیبان‌گیری»: download the whole database, or the configuration as JSON,
 * and read a configuration file back.
 *
 * Only for admins who may change settings: a full dump carries every
 * student's data, and an import changes access for everyone.
 */
final class BackupController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        $report = $_SESSION['_backup_report'] ?? null;
        unset($_SESSION['_backup_report']);

        return $this->page('layouts.app', 'admin.backup', [
            'title'  => 'پشتیبان‌گیری',
            'report' => is_array($report) ? $report : null,
        ]);
    }

    /** The full SQL dump, streamed (optionally gzip-compressed). */
    public function sql(Request $request, array $params = []): Response
    {
        @set_time_limit(0);
        ActivityLogger::log('backup.sql_downloaded', Auth::id(), 'backup', null, [], 'critical', $request);

        $gzip = $request->bool('gzip') && function_exists('gzencode');
        $name = 'helexa-backup-' . date('Ymd-His') . ($gzip ? '.sql.gz' : '.sql');

        return Response::streamed(static function () use ($gzip): void {
            if (!$gzip) {
                Backup::streamSql();
                return;
            }
            // Buffered then compressed: gzip needs the whole stream, and a
            // typical site database compresses to a small fraction.
            ob_start();
            Backup::streamSql();
            echo gzencode((string) ob_get_clean(), 6);
        }, 200, [
            'Content-Type'        => $gzip ? 'application/gzip' : 'application/sql; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function exportConfig(Request $request, array $params = []): Response
    {
        ActivityLogger::log('backup.config_exported', Auth::id(), 'backup', null, [], 'notice', $request);

        $json = (string) json_encode(Backup::exportConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return Response::make($json, 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="helexa-config-' . date('Ymd-His') . '.json"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function importConfig(Request $request, array $params = []): Response
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || (int) ($file['size'] ?? 0) > 20 * 1024 * 1024) {
            $this->flash('error', 'فایل پشتیبان (JSON، حداکثر ۲۰ مگابایت) را انتخاب کنید.');
            return $this->redirect('/admin/backup');
        }

        $data = json_decode((string) file_get_contents((string) $file['tmp_name']), true);
        if (!is_array($data)) {
            $this->flash('error', 'فایل JSON خوانده نشد.');
            return $this->redirect('/admin/backup');
        }

        $parts = [
            'settings' => $request->bool('part_settings'),
            'packages' => $request->bool('part_packages'),
            'codes'    => $request->bool('part_codes'),
            'access'   => $request->bool('part_access'),
        ];
        if (!in_array(true, $parts, true)) {
            $this->flash('error', 'دست‌کم یک بخش را برای بازگردانی انتخاب کنید.');
            return $this->redirect('/admin/backup');
        }

        try {
            $result = Backup::importConfig($data, $parts, Auth::id());
        } catch (\Throwable $e) {
            $this->flash('error', 'بازگردانی انجام نشد و هیچ تغییری ذخیره نشد: ' . $e->getMessage());
            return $this->redirect('/admin/backup');
        }

        ActivityLogger::log('backup.config_imported', Auth::id(), 'backup', null, $result['counts'], 'critical', $request);

        if (!$result['ok']) {
            $this->flash('error', implode(' ', $result['warnings']));
        } else {
            $_SESSION['_backup_report'] = $result;
            $this->flash('success', 'بازگردانی انجام شد.');
        }

        return $this->redirect('/admin/backup');
    }

    /** Borrows the question bank's page styles (qb-page, qb-section, …). */
    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['qbank' => true], $status);
    }
}