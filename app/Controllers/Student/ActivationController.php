<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ActivationCodeRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Services\ActivationCodes;
use HeleXa\Services\Auth;

/**
 * «خرید و فعال‌سازی»: the packages on offer, and the box a code goes into.
 *
 * There is no payment gateway: a student gets a code from the institute
 * (after paying however the institute takes payment) and enters it here.
 */
final class ActivationController extends Controller
{
    public function show(Request $request, array $params = []): Response
    {
        $userId   = (int) Auth::id();
        $packages = new PackageRepository();

        $held = array_map(static fn (array $r): string => (string) $r['uuid'], $packages->forStudent($userId));

        // The catalogue: published packages that are not free (a free one is
        // already everyone's) and not the full-access one, which is sold on
        // request rather than listed.
        $catalogue = array_values(array_filter($packages->all(), static fn (array $p): bool =>
            $p['status'] === 'published'
            && (int) ($p['is_free'] ?? 0) !== 1
            && (int) ($p['is_full_access'] ?? 0) !== 1));

        return $this->page('layouts.app', 'student.activate', [
            'title'     => 'خرید و فعال‌سازی',
            'catalogue' => $catalogue,
            'held'      => $held,
            'mine'      => $packages->forStudent($userId),
            'used'      => (new ActivationCodeRepository())->redeemedBy($userId),
        ]);
    }

    public function redeem(Request $request, array $params = []): Response
    {
        $result = ActivationCodes::redeem((array) Auth::user(), $request->string('code'));

        if ($request->isAjax()) {
            return $this->json($result, $result['ok'] ? 200 : 422);
        }
        $this->flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('/student/activate');
    }
}
