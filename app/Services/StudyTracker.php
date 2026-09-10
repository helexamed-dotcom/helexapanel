<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Request;
use HeleXa\Models\ContentStatusRepository;
use HeleXa\Models\StudySessionRepository;

/**
 * Server-authoritative study time.
 *
 * The viewer sends a beat every N seconds. The elapsed time is measured from
 * the SERVER's previous beat timestamp, never from a counter the client sends,
 * and each delta is clamped to the expected interval plus a small grace. That
 * makes the two obvious attacks useless:
 *
 *   - Sending "I studied 3600 seconds": the number is ignored entirely.
 *   - Beating in a tight loop: deltas are tiny, and beats arriving faster than
 *     the floor are recorded as rejected and credited zero.
 *
 * A tab left open overnight does not inflate the total either: once beats stop,
 * the session is swept closed and the gap is never credited.
 */
final class StudyTracker
{
    /**
     * @return array{session_id:int, total:int, content_total:int, accepted:int, status:string}
     */
    public static function beat(Request $request, array $user, array $content, int $loginSessionId, array $signals): array
    {
        $repository  = new StudySessionRepository();
        $interval    = Settings::int('heartbeat_interval', 25);
        $grace       = Settings::int('heartbeat_grace', 15);
        $idleCutoff  = Settings::int('study_idle_cutoff', 120);
        $maxDelta    = $interval + $grace;
        $minDelta    = max(2, (int) floor($interval / 5));

        $repository->sweepStale($idleCutoff);

        $session = $repository->findActive((int) $user['id'], (int) $content['id'], $idleCutoff);

        if ($session === null) {
            $id = $repository->open([
                'user_id'    => (int) $user['id'],
                'content_id' => (int) $content['id'],
                'course_id'  => (int) $content['course_id'],
                'session_id' => $loginSessionId,
                'ip_address' => $request->ip(),
            ]);

            // The first beat opens the session and credits nothing: there is no
            // previous server timestamp to measure against yet.
            $repository->recordBeat($id, 0, (bool) $signals['visible'], (bool) $signals['focused'], $signals['scroll'], false);

            return [
                'session_id'    => $id,
                'total'         => 0,
                'content_total' => self::contentTotal((int) $user['id'], (int) $content['id']),
                'accepted'      => 0,
                'status'        => 'started',
            ];
        }

        $sessionId = (int) $session['id'];
        $elapsed   = time() - (int) strtotime((string) $session['last_heartbeat_at']);

        // Too fast: someone is looping the endpoint. Record it and credit nothing.
        if ($elapsed < $minDelta) {
            $repository->recordBeat($sessionId, 0, (bool) $signals['visible'], (bool) $signals['focused'], $signals['scroll'], true);
            $repository->touch($sessionId);

            return [
                'session_id'    => $sessionId,
                'total'         => (int) $session['duration_seconds'],
                'content_total' => self::contentTotal((int) $user['id'], (int) $content['id']),
                'accepted'      => 0,
                'status'        => 'throttled',
            ];
        }

        // A hidden or unfocused tab is open, not studied.
        $accepted = ((bool) $signals['visible']) ? min($elapsed, $maxDelta) : 0;

        $repository->recordBeat($sessionId, $accepted, (bool) $signals['visible'], (bool) $signals['focused'], $signals['scroll'], false);

        if ($accepted > 0) {
            $repository->addSeconds($sessionId, $accepted);
            $repository->addToDailyStats((int) $user['id'], (int) $content['course_id'], $accepted);
            (new ContentStatusRepository())->addSeconds(
                (int) $user['id'], (int) $content['id'], (int) $content['course_id'], $accepted
            );
        } else {
            $repository->touch($sessionId);
        }

        return [
            'session_id'    => $sessionId,
            'total'         => (int) $session['duration_seconds'] + $accepted,
            'content_total' => self::contentTotal((int) $user['id'], (int) $content['id']),
            'accepted'      => $accepted,
            'status'        => $accepted > 0 ? 'counted' : 'idle',
        ];
    }

    public static function stop(int $userId, int $contentId, string $reason = 'closed'): void
    {
        $repository = new StudySessionRepository();
        $session    = $repository->findActive($userId, $contentId, Settings::int('study_idle_cutoff', 120));

        if ($session !== null) {
            $repository->close((int) $session['id'], $reason);
        }
    }

    private static function contentTotal(int $userId, int $contentId): int
    {
        $row = (new ContentStatusRepository())->find($userId, $contentId);
        return (int) ($row['total_seconds'] ?? 0);
    }
}
