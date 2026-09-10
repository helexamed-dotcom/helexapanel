<?php
declare(strict_types=1);

namespace HeleXa\Controllers;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Core\Str;
use HeleXa\Models\ContentStatusRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\ContentAccess;
use HeleXa\Services\ContentStorage;
use HeleXa\Services\Settings;
use HeleXa\Services\ViewerPayload;

/**
 * Serves private lesson HTML.
 *
 * Two routes: the viewer shell (a normal page in the panel) and the stream
 * (the lesson itself, rendered inside a sandboxed iframe). Both re-run the
 * full authorization chain; the shell never hands the stream a capability
 * that the stream does not verify again for itself.
 */
final class ContentViewerController extends Controller
{
    public function show(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        $statuses = new ContentStatusRepository();
        if (Auth::isStudent()) {
            $statuses->registerOpen((int) $user['id'], (int) $content['id'], (int) $content['course_id']);
        }

        $status = $statuses->find((int) $user['id'], (int) $content['id']);
        $alreadyStudied = (int) ($status['total_seconds'] ?? 0);

        ActivityLogger::log('content.opened', (int) $user['id'], 'content', (int) $content['id'],
            ['title' => $content['title']], 'info', $request);

        return $this->view('content.viewer', [
            'title'        => (string) $content['title'],
            'content'      => $content,
            'statusValue'  => $status['status'] ?? 'unread',
            'isStudent'    => Auth::isStudent(),
            'heartbeatInt' => Settings::int('heartbeat_interval', 25),
            'offlineEnabled'   => \HeleXa\Services\OfflineAccess::isEnabled(),
            'highlightEnabled' => Settings::bool('highlight_enabled', true),
            'studiedSecs'  => $alreadyStudied,
            'studiedClock' => \HeleXa\Services\StudyAnalytics::clock($alreadyStudied),
        ]);
    }

    /**
     * The lesson bytes. Everything is checked again here, because this is the
     * only endpoint that can actually leak content.
     */
    public function stream(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        // Browsers that support Fetch Metadata tell us how the request was made.
        // A top-level navigation means someone pasted the raw URL into a tab.
        // "empty" is the offline downloader calling fetch() from the viewer;
        // it is still a same-origin subresource request, never a navigation.
        $dest = strtolower((string) $request->header('Sec-Fetch-Dest'));
        $site = strtolower((string) $request->header('Sec-Fetch-Site'));
        $allowedDestinations = ['', 'iframe', 'frame', 'empty'];
        if (!in_array($dest, $allowedDestinations, true)) {
            throw HttpException::forbidden('این آدرس فقط از داخل نمایشگر قابل استفاده است.');
        }
        if ($dest === 'empty' && !$request->isAjax()) {
            // A bare cross-tool fetch without our own client headers.
            throw HttpException::forbidden();
        }
        if ($site !== '' && $site !== 'same-origin') {
            throw HttpException::forbidden();
        }

        if ($content['storage_kind'] !== 'file' || $content['storage_path'] === null) {
            throw HttpException::notFound('فایل محتوا ثبت نشده است.');
        }

        $storage = new ContentStorage();
        try {
            $absolute = $storage->resolve((string) $content['storage_path']);
        } catch (\RuntimeException $e) {
            \HeleXa\Core\Logger::error('Lesson file missing', ['content' => $content['uuid']]);
            throw HttpException::notFound('فایل محتوا در دسترس نیست.');
        }

        $state = Auth::isStudent()
            ? (new ContentStatusRepository())->clientState((int) $user['id'], (int) $content['id'])
            : [];

        // Seeded into the document rather than fetched by the frame: the frame
        // has an opaque origin and cannot call the API itself, and this way the
        // highlights are already there when the first paint happens.
        $highlights = Auth::isStudent() && \HeleXa\Services\Settings::bool('highlight_enabled', true)
            ? $this->highlightsFor((int) $user['id'], (int) $content['id'])
            : [];

        $payload = ViewerPayload::build($user, $content, $state, $this->origin($request), $highlights);

        $fileSize = (int) filesize($absolute);
        $etag     = '"' . substr(Str::hash(implode('|', [
            (string) $content['checksum'],
            (string) $user['id'],
            (string) $fileSize,
            Str::hash($payload),
        ])), 0, 32) . '"';

        $cacheMode = (string) Settings::get('content_cache_mode', 'revalidate');

        $headers = [
            'Content-Type'           => 'text/html; charset=UTF-8',
            'Content-Security-Policy'=> ViewerPayload::contentSecurityPolicy($this->origin($request)),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'no-referrer',
            'X-Robots-Tag'           => 'noindex, nofollow',
            'Content-Disposition'    => 'inline',
        ];

        if ($cacheMode === 'strict') {
            $headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, private';
            $headers['Pragma']        = 'no-cache';
        } else {
            // The browser still asks us every time; we answer 304 when nothing
            // changed. The bytes never become publicly cacheable.
            $headers['Cache-Control'] = 'private, no-cache, max-age=0, must-revalidate';
            $headers['ETag']          = $etag;

            if (trim((string) $request->header('If-None-Match')) === $etag) {
                return Response::make('', 304, $headers);
            }
        }

        $this->streamFile($absolute, $payload, $headers, $fileSize);
        exit; // the body has already been written to the output stream
    }

    /** Persists the lesson's client state, the server-side stand-in for localStorage. */
    public function saveState(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        if (!Auth::isStudent()) {
            return $this->json(['ok' => true, 'stored' => false]);
        }

        $state = $request->input('state', []);
        if (!is_array($state)) {
            return $this->json(['ok' => false, 'error' => 'INVALID_STATE'], 422);
        }

        // A lesson script must not be able to fill the database.
        $clean = [];
        foreach ($state as $key => $value) {
            if (count($clean) >= 40 || !is_scalar($value)) {
                continue;
            }
            $clean[mb_substr((string) $key, 0, 100)] = mb_substr((string) $value, 0, 1000);
        }

        (new ContentStatusRepository())->saveClientState(
            (int) $user['id'], (int) $content['id'], (int) $content['course_id'], $clean
        );

        return $this->json(['ok' => true, 'keys' => count($clean)]);
    }

    /**
     * Study heartbeat. The client tells us only whether its tab is visible;
     * how much time that is worth is decided here against the server clock.
     */
    public function heartbeat(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        if (!Auth::isStudent()) {
            // Admin previews are not study time.
            return $this->json(['ok' => true, 'tracked' => false, 'total' => 0]);
        }

        $sessionId = Auth::currentSessionId();
        if ($sessionId === null) {
            return $this->json(['ok' => false, 'error' => 'NO_SESSION'], 401);
        }

        $result = \HeleXa\Services\StudyTracker::beat($request, $user, $content, $sessionId, [
            'visible' => $request->bool('visible'),
            'focused' => $request->bool('focused'),
            'scroll'  => max(0, min(100, $request->int('scroll'))),
        ]);

        return $this->json([
            'ok'       => true,
            'tracked'  => true,
            'status'   => $result['status'],
            'accepted' => $result['accepted'],
            'total'    => $result['content_total'],
            'clock'    => \HeleXa\Services\StudyAnalytics::clock($result['content_total']),
            'interval' => \HeleXa\Services\Settings::int('heartbeat_interval', 25),
        ]);
    }

    public function endStudy(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));

        if (Auth::isStudent()) {
            \HeleXa\Services\StudyTracker::stop((int) $user['id'], (int) $content['id'], 'closed');
        }

        return $this->json(['ok' => true]);
    }

    public function setStatus(Request $request, array $params = []): Response
    {
        $user    = Auth::user();
        $content = ContentAccess::authorize($user, (string) ($params['uuid'] ?? ''));
        $status  = $request->string('status');

        if (!in_array($status, ['unread', 'studying', 'completed', 'review_later'], true)) {
            return $this->json(['ok' => false, 'error' => 'INVALID_STATUS'], 422);
        }
        if (!Auth::isStudent()) {
            return $this->json(['ok' => true, 'stored' => false]);
        }

        (new ContentStatusRepository())->setStatus(
            (int) $user['id'], (int) $content['id'], (int) $content['course_id'], $status
        );

        return $this->json(['ok' => true, 'status' => $status]);
    }

    /** @return array<int, array<string,mixed>> highlights with decoded anchors */
    private function highlightsFor(int $userId, int $contentId): array
    {
        $rows = (new \HeleXa\Models\HighlightRepository())->forContent($userId, $contentId);

        foreach ($rows as $index => $row) {
            $rows[$index]['anchor'] = json_decode((string) $row['anchor'], true) ?: [];
        }

        return $rows;
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Writes the file to the client with the guard payload spliced in after
     * <head>. Only the first chunk is held in memory, so a 3 MB lesson costs
     * about 64 KB of RAM instead of 3 MB.
     */
    private function streamFile(string $absolute, string $payload, array $headers, int $fileSize): void
    {
        $handle = fopen($absolute, 'rb');
        if ($handle === false) {
            throw HttpException::notFound();
        }

        $head     = (string) fread($handle, 65536);
        $inserted = false;
        $prefix   = '';

        if (preg_match('#<head[^>]*>#i', $head, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $offset   = (int) $matches[0][1] + strlen((string) $matches[0][0]);
            $prefix   = substr($head, 0, $offset) . $payload . substr($head, $offset);
            $inserted = true;
        } elseif (preg_match('#<html[^>]*>#i', $head, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) $matches[0][1] + strlen((string) $matches[0][0]);
            $prefix = substr($head, 0, $offset) . '<head>' . $payload . '</head>' . substr($head, $offset);
            $inserted = true;
        } else {
            // Fragment without a document shell: wrap it so our guards still apply.
            $prefix = '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
                    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                    . $payload . '</head><body>' . $head;
        }

        $headers['Content-Length'] = (string) ($fileSize + strlen($prefix) - strlen($head)
            + ($inserted ? 0 : strlen('</body></html>')));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(200);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        echo $prefix;
        fpassthru($handle);
        if (!$inserted) {
            echo '</body></html>';
        }
        fclose($handle);
    }

    private function origin(Request $request): string
    {
        return ($request->isSecure() ? 'https://' : 'http://') . $request->host();
    }
}
