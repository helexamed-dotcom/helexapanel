<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\Database;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\MessageRepository;
use HeleXa\Models\NotificationRepository;
use HeleXa\Models\PackageRepository;
use HeleXa\Models\SupportTicketRepository;
use HeleXa\Services\Auth;

/**
 * The header's pop-up panels.
 *
 * Each panel is fetched when it is opened, not rendered into every page: the
 * bell alone is three queries and most page views never open it. The answer
 * is an HTML fragment built from the same escaped templates as the pages.
 */
final class HubController extends Controller
{
    /** 🔔 — notifications, messages and the support conversation. */
    public function bell(Request $request, array $params = []): Response
    {
        $user = (array) Auth::user();
        if (!Auth::isStudent()) {
            return $this->fragment($this->view('hub.bell_admin', ['inbox' => \HeleXa\Services\AdminInbox::rows()]));
        }

        $userId  = (int) $user['id'];
        $tickets = new SupportTicketRepository();
        $ticket  = $tickets->openFor($userId);
        $thread  = $ticket === null ? [] : array_slice($tickets->messagesFor((int) $ticket['id']), -30);

        return $this->fragment($this->view('hub.bell_student', [
            'tab'           => in_array($request->string('tab'), ['notifications', 'messages', 'support'], true)
                                ? $request->string('tab') : 'notifications',
            'notifications' => (new NotificationRepository())->forUser($userId, 8),
            'messages'      => (new MessageRepository())->inbox($userId, 6),
            'ticket'        => $ticket,
            'thread'        => $thread,
        ]));
    }

    /** 🔑 — the activation code box, what the student already holds, and «خرید». */
    public function activate(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $held = [];
        try {
            $held = (new PackageRepository())->forStudent($userId);
        } catch (\PDOException) {
            // packages not installed
        }

        return $this->fragment($this->view('hub.activate', [
            'held'     => array_slice($held, 0, 5),
            'heldAll'  => count($held),
            'shopOn'   => \HeleXa\Services\Modules::enabled('shop'),
        ]));
    }

    private function fragment(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex');
    }
}
