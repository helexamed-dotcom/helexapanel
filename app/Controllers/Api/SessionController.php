<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Api;

use HeleXa\Core\Controller;
use HeleXa\Core\Csrf;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Services\Auth;
use HeleXa\Core\Str;

/**
 * The client's single source of truth about "who am I, right now".
 *
 * The PWA calls this on boot and after every reconnect. It is deliberately
 * the only place a fresh CSRF token is handed out, and it never leaves the
 * network: a cached answer to "am I still logged in" would be a lie.
 */
final class SessionController extends Controller
{
    public function state(Request $request, array $params = []): Response
    {
        $user = Auth::user();

        return $this->json([
            'ok'          => true,
            'server_time' => date('c'),
            'csrf'        => Csrf::token(),
            'user'        => [
                'uuid'  => (string) $user['uuid'],
                'name'  => (string) $user['full_name'],
                'role'  => (string) $user['role_slug'],
                // Opaque handle used to scope the local sync queue to this
                // account. Derived, carries no secret, and changes if the app
                // key is rotated. The prefix is kept so existing devices keep
                // their queue across the update.
                'scope' => substr(Str::hmac('offline-scope:' . $user['uuid']), 0, 32),
            ],
        ])->withHeader('Cache-Control', 'no-store, private');
    }
}
