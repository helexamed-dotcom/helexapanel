<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Api;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\OfflineAccess;

final class OfflineController extends Controller
{
    /** The student's navigable tree, for offline browsing. Metadata only. */
    public function catalogue(Request $request, array $params = []): Response
    {
        if (!Auth::isStudent()) {
            throw HttpException::forbidden();
        }

        return $this->json([
            'ok'         => true,
            'fetched_at' => date('c'),
            'courses'    => OfflineAccess::catalogue(Auth::user()),
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Permission to store one lesson, plus the metadata needed to store it.
     * The bytes themselves still come from the ordinary stream endpoint,
     * so there is no second content-delivery path to secure.
     */
    public function manifest(Request $request, array $params = []): Response
    {
        $package = OfflineAccess::package(Auth::user(), (string) ($params['uuid'] ?? ''));

        ActivityLogger::log('offline.package_issued', Auth::id(), 'content', null,
            ['content' => $package['content_uuid'], 'lease' => $package['lease']['expires_at']], 'info', $request);

        return $this->json(['ok' => true, 'package' => $package])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Re-checks a set of already-stored lessons. The client calls this on
     * every reconnect: it is how revoked access, an expired enrolment or a
     * new content version reaches a device that has been offline.
     */
    public function verify(Request $request, array $params = []): Response
    {
        if (!Auth::isStudent()) {
            throw HttpException::forbidden();
        }

        $uuids = $request->input('contents', []);
        if (!is_array($uuids)) {
            return $this->json(['ok' => false, 'error' => 'INVALID_PAYLOAD'], 422);
        }

        $user   = Auth::user();
        $result = [];

        foreach (array_slice($uuids, 0, 200) as $uuid) {
            if (!is_string($uuid)) {
                continue;
            }
            try {
                $package = OfflineAccess::package($user, $uuid);
                $result[$uuid] = [
                    'status'  => 'authorized',
                    'version' => $package['version'],
                    'lease'   => $package['lease'],
                    'title'   => $package['title'],
                ];
            } catch (HttpException $e) {
                // Anything that is no longer authorised is reported as revoked,
                // and the client deletes its local copy.
                $result[$uuid] = ['status' => 'revoked', 'code' => $e->statusCode()];
            }
        }

        return $this->json(['ok' => true, 'checked_at' => date('c'), 'contents' => $result])
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
