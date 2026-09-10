<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\Settings;

/**
 * The offline shell.
 *
 * Deliberately contains no user data: no name, no CSRF token, no counts.
 * That is what makes it safe for the service worker to cache one copy and
 * serve it to whoever is holding the device. Everything personal is read
 * from IndexedDB by the page's own script, scoped to the signed-in account.
 */
final class OfflineController extends Controller
{
    public function index(Request $request, array $params = []): Response
    {
        return $this->view('offline.app', [
            'title'           => 'مطالعه آفلاین',
            'heartbeatSecs'   => Settings::int('heartbeat_interval', 25),
            'offlineEnabled'  => Settings::bool('offline_enabled', true),
        ])->withHeader('Cache-Control', 'public, max-age=0, must-revalidate');
    }
}
