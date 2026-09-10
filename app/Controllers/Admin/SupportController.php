<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Admin;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\SupportTicketRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\NotificationService;
use HeleXa\Services\SupportAttachmentStorage;

final class SupportController extends Controller
{
    private SupportTicketRepository $tickets;

    public function __construct()
    {
        $this->tickets = new SupportTicketRepository();
    }

    public function index(Request $request, array $params = []): Response
    {
        $status = $request->string('status', 'open');
        if (!in_array($status, ['open', 'answered', 'closed'], true)) {
            $status = 'open';
        }

        return $this->page('layouts.app', 'admin.support.index', [
            'title'   => 'پشتیبانی',
            'status'  => $status,
            'tickets' => $this->tickets->forAdmin($status),
            'counts'  => $this->tickets->countByStatus(),
        ]);
    }

    public function show(Request $request, array $params = []): Response
    {
        $ticket = $this->find((string) ($params['uuid'] ?? ''));

        return $this->page('layouts.app', 'admin.support.show', [
            'title'    => 'تیکت پشتیبانی',
            'ticket'   => $ticket,
            'messages' => $this->tickets->messagesFor((int) $ticket['id']),
        ]);
    }

    public function reply(Request $request, array $params = []): Response
    {
        $ticket = $this->find((string) ($params['uuid'] ?? ''));
        $body   = trim($request->string('body'));

        if ($body === '') {
            $this->flash('error', 'متن پاسخ نمی‌تواند خالی باشد.');
            return $this->redirect('/admin/support/' . $ticket['uuid']);
        }
        if (mb_strlen($body) > 4000) {
            $body = mb_substr($body, 0, 4000);
        }

        $adminId   = (int) Auth::id();
        $messageId = $this->tickets->addMessage((int) $ticket['id'], 'admin', $adminId, $body, null);

        // Reaches the student on the site's own notification bell, through the
        // same queue every other notification goes through rather than a
        // separate direct call that could fail silently.
        NotificationService::publish([
            'title'           => 'پاسخ پشتیبانی',
            'body'            => $body,
            'notif_type'      => 'support',
            'idempotency_key' => "support_reply:{$messageId}",
            'audience'        => 'user',
            'user_id'         => (int) $ticket['user_id'],
            'link_url'        => null,
            'expires_at'      => null,
            'created_by'      => $adminId,
        ]);

        ActivityLogger::log('support.replied', $adminId, 'support_ticket', (int) $ticket['id'], [], 'notice', $request);
        $this->flash('success', 'پاسخ ارسال شد.');

        return $this->redirect('/admin/support/' . $ticket['uuid']);
    }

    public function close(Request $request, array $params = []): Response
    {
        $ticket = $this->find((string) ($params['uuid'] ?? ''));
        $this->tickets->close((int) $ticket['id'], (int) Auth::id());

        ActivityLogger::log('support.closed', Auth::id(), 'support_ticket', (int) $ticket['id'], [], 'notice', $request);
        $this->flash('success', 'تیکت بسته شد.');

        return $this->redirect('/admin/support?status=closed');
    }

    /** Streams a photo a student attached — never a public URL, checked on every request. */
    public function attachment(Request $request, array $params = []): Response
    {
        $ticket = $this->find((string) ($params['uuid'] ?? ''));
        $target = null;

        foreach ($this->tickets->messagesFor((int) $ticket['id']) as $message) {
            if ($message['uuid'] === (string) ($params['message'] ?? '')) {
                $target = $message;
                break;
            }
        }
        if ($target === null || empty($target['attachment_path'])) {
            throw HttpException::notFound();
        }

        $resolved = (new SupportAttachmentStorage())->resolve($target['attachment_path']);
        if ($resolved === null) {
            throw HttpException::notFound();
        }

        $body = (string) file_get_contents($resolved['path']);

        return Response::make($body, 200, [
            'Content-Type'           => $resolved['mime'],
            'Content-Length'         => (string) strlen($body),
            'Cache-Control'          => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline',
        ]);
    }

    private function find(string $uuid): array
    {
        $ticket = $this->tickets->findByUuid($uuid);
        if ($ticket === null) {
            throw HttpException::notFound();
        }
        return $ticket;
    }
}
