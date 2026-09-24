<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\SupportTicketRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\SupportAttachmentStorage;

/**
 * The student's side of the support desk.
 *
 * The tables, the repository and the whole admin queue were already built —
 * the only way in was the Telegram bot, so when the bot was removed the
 * system was left with an answering end and no asking end. This is that
 * missing half, and it deliberately reuses SupportTicketRepository as-is:
 * openFor() was written for exactly this question.
 *
 * One open conversation per student, which is the rule the repository already
 * enforces. Writing while a ticket is open continues it; writing after it was
 * closed starts a new one. A student never chooses which ticket they are in,
 * so there is no ticket id in any of these URLs to tamper with — the only
 * exception is the attachment route, and that one re-checks ownership against
 * the signed-in user rather than trusting the id it was handed.
 */
final class SupportController extends Controller
{
    private const MAX_BODY = 4000;

    private SupportTicketRepository $tickets;

    public function __construct()
    {
        $this->tickets = new SupportTicketRepository();
    }

    /** The conversation, or an empty state inviting the student to start one. */
    public function index(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $ticket = $this->tickets->openFor($userId);

        return $this->page('layouts.app', 'student.support', [
            'title'    => 'پشتیبانی',
            'ticket'   => $ticket,
            'messages' => $ticket === null ? [] : $this->tickets->messagesFor((int) $ticket['id']),
            'maxBody'  => self::MAX_BODY,
        ]);
    }

    /**
     * Adds a message, opening a ticket first if the student has none.
     *
     * Text, a photo, or both — but not neither, because an empty row would
     * still bump the ticket to the top of the admin queue and say nothing.
     */
    public function send(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $body   = trim($request->string('body'));
        $upload = $request->file('photo');
        $hasUpload = $upload !== null && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($body === '' && !$hasUpload) {
            $this->flash('error', 'پیام خالی است. متنی بنویس یا تصویری انتخاب کن.');
            return $this->redirect('/student/support');
        }

        if (mb_strlen($body) > self::MAX_BODY) {
            $body = mb_substr($body, 0, self::MAX_BODY);
        }

        // Stored before the row is written: if the image is rejected, the
        // student gets the reason with their text still in hand, instead of a
        // saved message that quietly lost its attachment.
        $attachment = null;
        if ($hasUpload) {
            try {
                $attachment = (new SupportAttachmentStorage())->store($upload);
            } catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
                return $this->redirect('/student/support');
            }
        }

        $ticket = $this->tickets->openFor($userId);
        $ticketId = $ticket === null
            ? $this->tickets->create($userId)
            : (int) $ticket['id'];

        $this->tickets->addMessage($ticketId, 'student', $userId, $body === '' ? null : $body, $attachment);

        ActivityLogger::log('support.student_message', $userId, 'support_ticket', $ticketId, [], 'info', $request);
        $this->flash('success', 'پیام شما ثبت شد. پشتیبانی به‌زودی پاسخ می‌دهد.');

        return $this->redirect('/student/support');
    }

    /**
     * Streams an attachment from this student's own conversation.
     *
     * The message uuid is looked up inside the messages of the ticket that
     * belongs to the signed-in user — not searched globally and then checked.
     * A uuid copied from someone else's ticket simply is not in this list, so
     * it 404s the same way a made-up one does.
     */
    public function attachment(Request $request, array $params = []): Response
    {
        $ticket = $this->tickets->openFor((int) Auth::id());
        if ($ticket === null) {
            throw HttpException::notFound();
        }

        $wanted = (string) ($params['message'] ?? '');
        $target = null;
        foreach ($this->tickets->messagesFor((int) $ticket['id']) as $message) {
            if ($message['uuid'] === $wanted) {
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

        $bytes = (string) file_get_contents($resolved['path']);

        return Response::make($bytes, 200, [
            'Content-Type'           => $resolved['mime'],
            'Content-Length'         => (string) strlen($bytes),
            'Cache-Control'          => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline',
        ]);
    }
}
