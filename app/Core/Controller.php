<?php
declare(strict_types=1);

namespace HeleXa\Core;

use HeleXa\Services\Auth;

abstract class Controller
{
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(View::render($template, $this->withDefaults($data)), $status);
    }

    protected function page(string $layout, string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(View::page($layout, $template, $this->withDefaults($data)), $status);
    }

    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    protected function back(string $fallback = '/'): Response
    {
        return Response::redirect($fallback);
    }

    protected function flash(string $type, string $message): void
    {
        $_SESSION['_flash_' . $type] = $message;
    }

    protected function pullFlash(string $type): ?string
    {
        $key = '_flash_' . $type;
        if (!isset($_SESSION[$key])) {
            return null;
        }
        $value = (string) $_SESSION[$key];
        unset($_SESSION[$key]);
        return $value;
    }

    /**
     * Badge counts for the header. Queried only for the role that can
     * actually act on them, so guest and unrelated pages stay query-free.
     *
     * @return array{notifications:int, messages:int, support_open:int}
     */
    private function unreadCounts(): array
    {
        $zero = ['notifications' => 0, 'messages' => 0, 'support_open' => 0];

        if (!Auth::check()) {
            return $zero;
        }

        try {
            if (Auth::isStudent()) {
                $userId = (int) Auth::id();
                return [
                    'notifications' => (new \HeleXa\Models\NotificationRepository())->unreadCount($userId),
                    'messages'      => (new \HeleXa\Models\MessageRepository())->unreadCount($userId),
                    'support_open'  => 0,
                ];
            }

            if (Auth::can('manage_messages')) {
                $counts = (new \HeleXa\Models\SupportTicketRepository())->countByStatus();
                return ['notifications' => 0, 'messages' => 0, 'support_open' => $counts['open']];
            }
        } catch (\Throwable) {
            // A badge is never worth breaking a page for.
        }

        return $zero;
    }

    private function pullTempPassword(): ?array
    {
        if (!isset($_SESSION['_temp_password']) || !is_array($_SESSION['_temp_password'])) {
            return null;
        }
        $value = $_SESSION['_temp_password'];
        unset($_SESSION['_temp_password']);
        return $value;
    }

    private function withDefaults(array $data): array
    {
        return array_merge([
            'appName'      => (string) Config::get('app.app.name', 'HeleXa Med'),
            'currentUser'  => Auth::user(),
            'permissions'  => Auth::check() ? Auth::permissions() : [],
            'flashSuccess' => $this->pullFlash('success'),
            'flashError'   => $this->pullFlash('error'),
            // Temporary passwords are shown exactly once, then dropped from the session.
            'tempPassword' => $this->pullTempPassword(),
            'unreadCounts' => $this->unreadCounts(),
            'cspNonce'     => SecurityHeaders::nonce(),
            'currentPath'  => Request::capture()->path(),
        ], $data);
    }
}
