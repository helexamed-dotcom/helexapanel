<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\Paginator;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ActivationCodeRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;

/**
 * Making and managing single-use activation codes.
 *
 * A code opens one package for the one student who uses it, for as many
 * days as the admin chose. The freshly made batch is shown once, ready to
 * copy, and can always be downloaded again as a CSV of the unused codes.
 */
final class ActivationCodeController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, array $params = []): Response
    {
        $codes   = new ActivationCodeRepository();
        $filters = [
            'package_id' => $request->int('package'),
            'status'     => $request->string('status'),
            'q'          => mb_substr(trim($request->string('q')), 0, 40),
        ];
        $total     = $codes->count($filters);
        $paginator = new Paginator($total, self::PER_PAGE, $request->int('page', 1), '/admin/activation-codes', [
            'package' => $filters['package_id'] ?: null, 'status' => $filters['status'] ?: null, 'q' => $filters['q'] ?: null,
        ]);

        $fresh = $_SESSION['_fresh_codes'] ?? null;
        unset($_SESSION['_fresh_codes']);

        return $this->page('layouts.app', 'admin.codes.index', [
            'title'     => 'کدهای فعال‌سازی',
            'codes'     => $codes->page($filters, self::PER_PAGE, $paginator->offset()),
            'packages'  => (new PackageRepository())->all(),
            'filters'   => $filters,
            'paginator' => $paginator,
            'total'     => $total,
            'fresh'     => is_array($fresh) ? $fresh : null,
        ]);
    }

    public function generate(Request $request, array $params = []): Response
    {
        $package = (new PackageRepository())->findById($request->int('package_id'));
        if ($package === null) {
            $this->flash('error', 'پکیج را انتخاب کنید.');
            return $this->redirect('/admin/activation-codes');
        }

        $expires = $request->string('expires_at');
        $expires = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) === 1 ? $expires . ' 23:59:59' : null;
        $days    = $request->int('duration_days');

        $codes = (new ActivationCodeRepository())->generate(
            (int) $package['id'],
            $request->int('count', 1),
            $days > 0 ? $days : null,
            $expires,
            $request->string('note'),
            Auth::id()
        );

        ActivityLogger::log('activation_code.generated', Auth::id(), 'package', (int) $package['id'],
            ['count' => count($codes)], 'notice', $request);

        $_SESSION['_fresh_codes'] = ['package' => $package['title'], 'codes' => $codes];
        $this->flash('success', sprintf('%s کد برای پکیج «%s» ساخته شد.', fa((string) count($codes)), $package['title']));

        return $this->redirect('/admin/activation-codes');
    }

    public function revoke(Request $request, array $params = []): Response
    {
        (new ActivationCodeRepository())->revoke((int) ($params['id'] ?? 0));
        ActivityLogger::log('activation_code.revoked', Auth::id(), 'activation_code', (int) ($params['id'] ?? 0), [], 'notice', $request);
        $this->flash('success', 'کد باطل شد.');

        return $this->redirect('/admin/activation-codes');
    }

    /** The unused codes (of one package, if chosen) as a CSV for Excel. */
    public function export(Request $request, array $params = []): Response
    {
        $rows = (new ActivationCodeRepository())->page(
            ['package_id' => $request->int('package'), 'status' => 'unused'], 500, 0
        );

        // The BOM makes Excel open the Persian columns as UTF-8.
        $csv = "\xEF\xBB\xBF" . "code,package,days,expires,note\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(static function ($v): string {
                $v = (string) $v;
                // A leading = + - @ would be a formula in a spreadsheet.
                if ($v !== '' && str_contains('=+-@', $v[0])) {
                    $v = "'" . $v;
                }
                return '"' . str_replace('"', '""', $v) . '"';
            }, [$row['code'], $row['package_title'], $row['duration_days'] ?? '', $row['expires_at'] ?? '', $row['note'] ?? ''])) . "\n";
        }

        return Response::make($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="activation-codes-' . date('Ymd-His') . '.csv"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** Borrows the question bank's page styles (qb-page, qb-section, …). */
    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return parent::page($layout, $template, $data + ['qbank' => true], $status);
    }
}