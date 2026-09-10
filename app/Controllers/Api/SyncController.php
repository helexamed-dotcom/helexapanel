<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Api;

use HeleXa\Core\Controller;
use HeleXa\Core\HttpException;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ContentStatusRepository;
use HeleXa\Models\StudySessionRepository;
use HeleXa\Models\SyncEventRepository;
use HeleXa\Services\ActivityLogger;
use HeleXa\Services\Auth;
use HeleXa\Services\ContentAccess;
use HeleXa\Services\DeviceDetector;
use HeleXa\Services\Settings;

/**
 * Receives activity that happened while the device was offline.
 *
 * The threat here is obvious: the client is now telling the server how long
 * someone studied, and the client is under the student's control. So nothing
 * in the payload is taken at face value.
 *
 *   - user_id is never read from the request. It comes from the session.
 *   - Each event carries a client-generated UUID and is written to a ledger
 *     with a unique key, so replaying a batch changes nothing.
 *   - Durations are bounded by the interval the client itself reports, by a
 *     per-event ceiling, and by a per-day ceiling.
 *   - Timestamps in the future or older than the retention window are refused.
 *   - Every content id is re-authorised against live enrolment, not against
 *     whatever the device believed when it went offline.
 *
 * The result is that offline study can be credited without offline study
 * becoming a way to mint study time.
 */
final class SyncController extends Controller
{
    private const MAX_EVENTS_PER_REQUEST = 100;
    private const MAX_CLOCK_SKEW         = 300;      // seconds a client may run ahead
    private const MAX_EVENT_AGE          = 2592000;  // 30 days

    public function study(Request $request, array $params = []): Response
    {
        if (!Auth::isStudent()) {
            // Admins previewing content offline generate no study time.
            return $this->json(['ok' => true, 'accepted' => 0, 'results' => []]);
        }

        $events = $request->input('events', []);
        if (!is_array($events)) {
            return $this->json(['ok' => false, 'error' => 'INVALID_PAYLOAD'], 422);
        }
        if (count($events) > self::MAX_EVENTS_PER_REQUEST) {
            return $this->json(['ok' => false, 'error' => 'TOO_MANY_EVENTS'], 422);
        }

        $user       = Auth::user();
        $ledger     = new SyncEventRepository();
        $sessions   = new StudySessionRepository();
        $statuses   = new ContentStatusRepository();
        $device     = DeviceDetector::fingerprint($request->userAgent(), $request->acceptLanguage());

        $maxEvent   = max(60, min(28800, Settings::int('offline_max_event_seconds', 7200)));
        $maxDaily   = max(3600, min(86400, Settings::int('offline_max_daily_seconds', 28800)));

        $results    = [];
        $accepted   = 0;
        $dailyCache = [];

        foreach ($events as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $eventId = $this->uuid($raw['event_id'] ?? null);
            if ($eventId === null) {
                $results[] = ['event_id' => null, 'outcome' => 'rejected', 'reason' => 'BAD_EVENT_ID'];
                continue;
            }

            // Claim the id first. If this is a replay the insert fails and we
            // stop before any credit is applied.
            $claimed = $ledger->claim([
                'user_id'           => (int) $user['id'],
                'event_id'          => $eventId,
                'event_type'        => 'study',
                'content_id'        => null,
                'accepted_seconds'  => 0,
                'client_started_at' => null,
                'client_ended_at'   => null,
                'outcome'           => 'rejected',
                'reason'            => 'PENDING',
                'device_hash'       => $device,
            ]);

            if (!$claimed) {
                $results[] = ['event_id' => $eventId, 'outcome' => 'duplicate'];
                continue;
            }

            $verdict = $this->evaluate($user, $raw, $maxEvent, $maxDaily, $ledger, $dailyCache);

            if ($verdict['outcome'] !== 'accepted') {
                $ledger->markOutcome(
                    (int) $user['id'], $eventId, 'rejected', $verdict['reason'], 0,
                    $verdict['started'] > 0 ? date('Y-m-d H:i:s', $verdict['started']) : null,
                    null
                );
                $results[] = ['event_id' => $eventId, 'outcome' => 'rejected', 'reason' => $verdict['reason']];
                continue;
            }

            $content = $verdict['content'];
            $seconds = $verdict['seconds'];

            // Credited through the same tables as online study, tagged so an
            // audit can separate measured time from replayed offline time.
            $studyId = $sessions->open([
                'user_id'    => (int) $user['id'],
                'content_id' => (int) $content['id'],
                'course_id'  => (int) $content['course_id'],
                'session_id' => Auth::currentSessionId(),
                'ip_address' => $request->ip(),
            ]);
            $sessions->addSeconds($studyId, $seconds);
            $sessions->close($studyId, 'offline');
            $sessions->addToDailyStats((int) $user['id'], (int) $content['course_id'], $seconds);
            $statuses->addSeconds((int) $user['id'], (int) $content['id'], (int) $content['course_id'], $seconds);

            // The day these seconds belong to is what the daily ceiling counts.
            $ledger->markOutcome(
                (int) $user['id'], $eventId, 'accepted', null, $seconds,
                date('Y-m-d H:i:s', $verdict['started']),
                date('Y-m-d H:i:s', $verdict['ended'])
            );

            $day = date('Y-m-d', $verdict['started']);
            $dailyCache[$day] = ($dailyCache[$day] ?? 0) + $seconds;

            $accepted += $seconds;
            $results[] = ['event_id' => $eventId, 'outcome' => 'accepted', 'seconds' => $seconds];
        }

        if ($accepted > 0) {
            ActivityLogger::log('offline.study_synced', (int) $user['id'], 'user', (int) $user['id'],
                ['events' => count($results), 'seconds' => $accepted], 'info', $request);
        }

        return $this->json([
            'ok'              => true,
            'accepted_seconds'=> $accepted,
            'results'         => $results,
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    /** Study status changes made offline (completed / studying / review later). */
    public function status(Request $request, array $params = []): Response
    {
        if (!Auth::isStudent()) {
            return $this->json(['ok' => true, 'results' => []]);
        }

        $events = $request->input('events', []);
        if (!is_array($events) || count($events) > self::MAX_EVENTS_PER_REQUEST) {
            return $this->json(['ok' => false, 'error' => 'INVALID_PAYLOAD'], 422);
        }

        $user     = Auth::user();
        $ledger   = new SyncEventRepository();
        $statuses = new ContentStatusRepository();
        $device   = DeviceDetector::fingerprint($request->userAgent(), $request->acceptLanguage());
        $results  = [];

        foreach ($events as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $eventId = $this->uuid($raw['event_id'] ?? null);
            $status  = is_string($raw['status'] ?? null) ? $raw['status'] : '';

            if ($eventId === null || !in_array($status, ['unread', 'studying', 'completed', 'review_later'], true)) {
                $results[] = ['event_id' => $eventId, 'outcome' => 'rejected', 'reason' => 'INVALID_STATUS'];
                continue;
            }

            $claimed = $ledger->claim([
                'user_id'           => (int) $user['id'],
                'event_id'          => $eventId,
                'event_type'        => 'status',
                'content_id'        => null,
                'accepted_seconds'  => 0,
                'client_started_at' => null,
                'client_ended_at'   => null,
                'outcome'           => 'rejected',
                'reason'            => 'PENDING',
                'device_hash'       => $device,
            ]);

            if (!$claimed) {
                $results[] = ['event_id' => $eventId, 'outcome' => 'duplicate'];
                continue;
            }

            try {
                $content = ContentAccess::authorize($user, (string) ($raw['content_uuid'] ?? ''));
            } catch (HttpException) {
                $ledger->markOutcome((int) $user['id'], $eventId, 'rejected', 'UNAUTHORIZED_CONTENT', 0);
                $results[] = ['event_id' => $eventId, 'outcome' => 'rejected', 'reason' => 'UNAUTHORIZED_CONTENT'];
                continue;
            }

            $statuses->setStatus((int) $user['id'], (int) $content['id'], (int) $content['course_id'], $status);
            $ledger->markOutcome((int) $user['id'], $eventId, 'accepted', null, 0);
            $results[] = ['event_id' => $eventId, 'outcome' => 'accepted'];
        }

        return $this->json(['ok' => true, 'results' => $results])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Highlights created or removed while the device was offline.
     *
     * Same shape as the other sync endpoints: each event carries the id it was
     * born with, the ledger refuses a repeat, and every content id is
     * re-authorised against live enrolment before anything is written.
     */
    public function highlights(Request $request, array $params = []): Response
    {
        if (!Auth::isStudent()) {
            return $this->json(['ok' => true, 'results' => []]);
        }

        $events = $request->input('events', []);
        if (!is_array($events) || count($events) > self::MAX_EVENTS_PER_REQUEST) {
            return $this->json(['ok' => false, 'error' => 'INVALID_PAYLOAD'], 422);
        }

        $user       = Auth::user();
        $ledger     = new SyncEventRepository();
        $highlights = new \HeleXa\Models\HighlightRepository();
        $device     = DeviceDetector::fingerprint($request->userAgent(), $request->acceptLanguage());
        $results    = [];

        foreach ($events as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $eventId = $this->uuid($raw['event_id'] ?? null);
            $action  = is_string($raw['action'] ?? null) ? $raw['action'] : '';

            if ($eventId === null || !in_array($action, ['create', 'delete', 'recolor'], true)) {
                $results[] = ['event_id' => $eventId, 'outcome' => 'rejected', 'reason' => 'INVALID_ACTION'];
                continue;
            }

            $claimed = $ledger->claim([
                'user_id'           => (int) $user['id'],
                'event_id'          => $eventId,
                'event_type'        => 'highlight',
                'content_id'        => null,
                'accepted_seconds'  => 0,
                'client_started_at' => null,
                'client_ended_at'   => null,
                'outcome'           => 'rejected',
                'reason'            => 'PENDING',
                'device_hash'       => $device,
            ]);

            if (!$claimed) {
                $results[] = ['event_id' => $eventId, 'outcome' => 'duplicate'];
                continue;
            }

            try {
                $content = ContentAccess::authorize($user, (string) ($raw['content_uuid'] ?? ''));
            } catch (HttpException) {
                $ledger->markOutcome((int) $user['id'], $eventId, 'rejected', 'UNAUTHORIZED_CONTENT', 0);
                $results[] = ['event_id' => $eventId, 'outcome' => 'rejected', 'reason' => 'UNAUTHORIZED_CONTENT'];
                continue;
            }

            $outcome = $this->applyHighlight($user, $content, $action, $raw['highlight'] ?? [], $highlights);

            $ledger->markOutcome((int) $user['id'], $eventId, $outcome === null ? 'accepted' : 'rejected', $outcome, 0);
            $results[] = $outcome === null
                ? ['event_id' => $eventId, 'outcome' => 'accepted']
                : ['event_id' => $eventId, 'outcome' => 'rejected', 'reason' => $outcome];
        }

        return $this->json(['ok' => true, 'results' => $results])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /** @return string|null null on success, otherwise a rejection reason */
    private function applyHighlight(
        array $user,
        array $content,
        string $action,
        mixed $payload,
        \HeleXa\Models\HighlightRepository $highlights
    ): ?string {
        if (!is_array($payload)) {
            return 'INVALID_HIGHLIGHT';
        }

        $uuid = $this->uuid($payload['uuid'] ?? null);
        if ($uuid === null) {
            return 'INVALID_HIGHLIGHT';
        }

        if ($action === 'delete') {
            $highlights->delete((int) $user['id'], $uuid);
            return null;
        }

        $color = is_string($payload['color'] ?? null) ? $payload['color'] : '';
        if (!in_array($color, \HeleXa\Controllers\HighlightController::COLORS, true)) {
            return 'INVALID_COLOR';
        }

        if ($action === 'recolor') {
            $highlights->updateColor((int) $user['id'], $uuid, $color);
            return null;
        }

        // create
        if ($highlights->exists((int) $user['id'], $uuid)) {
            return null;   // already synced from another device
        }

        $anchor = $payload['anchor'] ?? null;
        $kind   = is_string($payload['kind'] ?? null) ? $payload['kind'] : 'text';
        if (!is_array($anchor) || !in_array($kind, ['text', 'area'], true)) {
            return 'INVALID_ANCHOR';
        }

        // The offline path reuses the same validation as the online one, so a
        // crafted payload cannot take a shortcut by arriving through sync.
        $checked = $kind === 'text'
            ? $this->checkTextAnchor($anchor)
            : $this->checkAreaAnchor($anchor);

        if ($checked === null) {
            return 'INVALID_ANCHOR';
        }

        $highlights->create([
            'uuid'            => $uuid,
            'user_id'         => (int) $user['id'],
            'content_id'      => (int) $content['id'],
            'course_id'       => (int) $content['course_id'],
            'kind'            => $kind,
            'color'           => $color,
            'anchor'          => $checked,
            'quote'           => isset($payload['quote']) && is_string($payload['quote'])
                ? mb_substr($payload['quote'], 0, 500) : null,
            'note'            => null,
            'content_version' => (string) ($content['checksum'] ?? ''),
        ]);

        return null;
    }

    private function checkTextAnchor(array $anchor): ?array
    {
        $start = $anchor['start'] ?? null;
        $end   = $anchor['end'] ?? null;

        if (!is_numeric($start) || !is_numeric($end)) {
            return null;
        }
        $start = (int) $start;
        $end   = (int) $end;
        if ($start < 0 || $end <= $start || $end > 10000000 || ($end - $start) > 20000) {
            return null;
        }

        return [
            'start'  => $start,
            'end'    => $end,
            'prefix' => mb_substr((string) ($anchor['prefix'] ?? ''), 0, 60),
            'suffix' => mb_substr((string) ($anchor['suffix'] ?? ''), 0, 60),
        ];
    }

    private function checkAreaAnchor(array $anchor): ?array
    {
        $index = $anchor['imageIndex'] ?? null;
        if (!is_numeric($index) || (int) $index < 0 || (int) $index > 5000) {
            return null;
        }

        $rect = [];
        foreach (['x', 'y', 'w', 'h'] as $key) {
            if (!is_numeric($anchor[$key] ?? null)) {
                return null;
            }
            $rect[$key] = max(0.0, min(100.0, round((float) $anchor[$key], 3)));
        }
        if ($rect['w'] <= 0.2 || $rect['h'] <= 0.2) {
            return null;
        }

        return [
            'imageIndex' => (int) $index,
            'srcKey'     => mb_substr((string) ($anchor['srcKey'] ?? ''), 0, 64),
        ] + $rect;
    }

    /* ---------------------------------------------------------- helpers */

    /**
     * @return array{outcome:string, reason:?string, seconds:int, started:int, ended:int, content:?array}
     */
    private function evaluate(
        array $user,
        array $raw,
        int $maxEvent,
        int $maxDaily,
        SyncEventRepository $ledger,
        array $dailyCache
    ): array {
        $reject = static fn (string $reason): array =>
            ['outcome' => 'rejected', 'reason' => $reason, 'seconds' => 0,
             'started' => 0, 'ended' => 0, 'content' => null];

        $started = $this->timestamp($raw['started_at'] ?? null);
        $ended   = $this->timestamp($raw['ended_at'] ?? null);
        $claimed = isset($raw['duration']) && is_numeric($raw['duration']) ? (int) $raw['duration'] : -1;

        if ($started === null || $ended === null) {
            return $reject('BAD_TIMESTAMP');
        }
        if ($claimed <= 0) {
            return $reject('NON_POSITIVE_DURATION');
        }

        $now = time();
        if ($started > $now + self::MAX_CLOCK_SKEW || $ended > $now + self::MAX_CLOCK_SKEW) {
            return $reject('FUTURE_TIMESTAMP');
        }
        if ($started < $now - self::MAX_EVENT_AGE) {
            return $reject('EVENT_TOO_OLD');
        }
        if ($ended < $started) {
            return $reject('NEGATIVE_INTERVAL');
        }

        // The credited amount can never exceed the wall-clock interval the
        // client itself reported, nor the per-event ceiling.
        $seconds = min($claimed, $ended - $started, $maxEvent);
        if ($seconds <= 0) {
            return $reject('NON_POSITIVE_DURATION');
        }

        try {
            $content = ContentAccess::authorize($user, (string) ($raw['content_uuid'] ?? ''));
        } catch (HttpException) {
            return $reject('UNAUTHORIZED_CONTENT');
        }

        // Daily ceiling, counting what earlier batches already credited.
        $day        = date('Y-m-d', $started);
        $alreadyGot = $ledger->acceptedSecondsOn((int) $user['id'], $day) + ($dailyCache[$day] ?? 0);
        $remaining  = $maxDaily - $alreadyGot;

        if ($remaining <= 0) {
            return $reject('DAILY_LIMIT_REACHED');
        }
        $seconds = min($seconds, $remaining);

        return [
            'outcome' => 'accepted',
            'reason'  => null,
            'seconds' => $seconds,
            'started' => $started,
            'ended'   => $ended,
            'content' => $content,
        ];
    }

    private function uuid(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1
            ? strtolower($value)
            : null;
    }

    /** Accepts an ISO-8601 string or an epoch value; refuses anything else. */
    private function timestamp(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $seconds = (int) $value;
            return $seconds > 1600000000 && $seconds < 4102444800 ? $seconds : null;
        }
        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);
            return $parsed === false ? null : $parsed;
        }
        return null;
    }
}
