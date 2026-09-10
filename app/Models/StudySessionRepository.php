<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * Study sessions and their heartbeats.
 *
 * Duration is never taken from the client. Each heartbeat is turned into a
 * delta measured against the server's own clock and stored separately, so the
 * total can always be recomputed and audited from the raw beats.
 */
final class StudySessionRepository extends BaseRepository
{
    public function findActive(int $userId, int $contentId, int $idleCutoff): ?array
    {
        return $this->selectOne(
            'SELECT * FROM study_sessions
             WHERE user_id = :user AND content_id = :content AND is_active = 1
               AND last_heartbeat_at >= :cutoff
             ORDER BY id DESC LIMIT 1',
            [
                'user'    => $userId,
                'content' => $contentId,
                'cutoff'  => date('Y-m-d H:i:s', time() - $idleCutoff),
            ]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM study_sessions WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function open(array $data): int
    {
        return $this->insert(
            'INSERT INTO study_sessions
                (user_id, content_id, course_id, session_id, started_at, last_heartbeat_at,
                 duration_seconds, ip_address, is_active)
             VALUES (:user, :content, :course, :session, :started, :beat, 0, :ip, 1)',
            [
                'user'    => $data['user_id'],
                'content' => $data['content_id'],
                'course'  => $data['course_id'],
                'session' => $data['session_id'],
                'started' => $this->now(),
                'beat'    => $this->now(),
                'ip'      => $data['ip_address'] ?? null,
            ]
        );
    }

    public function recordBeat(int $studySessionId, int $acceptedSeconds, bool $visible, bool $focused, ?int $scroll, bool $rejected): void
    {
        $this->insert(
            'INSERT INTO study_heartbeats
                (study_session_id, beat_at, accepted_seconds, is_visible, is_focused, scroll_percent, rejected)
             VALUES (:session, :beat, :seconds, :visible, :focused, :scroll, :rejected)',
            [
                'session'  => $studySessionId,
                'beat'     => $this->now(),
                'seconds'  => $acceptedSeconds,
                'visible'  => $visible ? 1 : 0,
                'focused'  => $focused ? 1 : 0,
                'scroll'   => $scroll,
                'rejected' => $rejected ? 1 : 0,
            ]
        );
    }

    public function addSeconds(int $studySessionId, int $seconds): void
    {
        $this->execute(
            'UPDATE study_sessions
             SET duration_seconds = duration_seconds + :seconds, last_heartbeat_at = :beat
             WHERE id = :id AND is_active = 1',
            ['seconds' => $seconds, 'beat' => $this->now(), 'id' => $studySessionId]
        );
    }

    public function touch(int $studySessionId): void
    {
        $this->execute(
            'UPDATE study_sessions SET last_heartbeat_at = :beat WHERE id = :id AND is_active = 1',
            ['beat' => $this->now(), 'id' => $studySessionId]
        );
    }

    public function close(int $studySessionId, string $reason): void
    {
        $this->execute(
            'UPDATE study_sessions SET is_active = 0, ended_at = :now, end_reason = :reason
             WHERE id = :id AND is_active = 1',
            ['now' => $this->now(), 'reason' => $reason, 'id' => $studySessionId]
        );
    }

    /** Closes sessions whose viewer stopped sending beats (tab closed, crash, sleep). */
    public function sweepStale(int $idleCutoff): int
    {
        return $this->execute(
            "UPDATE study_sessions
             SET is_active = 0, ended_at = last_heartbeat_at, end_reason = 'sweep'
             WHERE is_active = 1 AND last_heartbeat_at < :cutoff",
            ['cutoff' => date('Y-m-d H:i:s', time() - $idleCutoff)]
        );
    }

    /* --------------------------------------------------------- analytics */

    public function addToDailyStats(int $userId, int $courseId, int $seconds): void
    {
        $this->execute(
            'INSERT INTO study_daily_stats (user_id, stat_date, course_id, total_seconds, contents_opened, updated_at)
             VALUES (:user, :day, :course, :seconds, 0, :now)
             ON DUPLICATE KEY UPDATE total_seconds = total_seconds + VALUES(total_seconds),
                                     updated_at    = VALUES(updated_at)',
            [
                'user'    => $userId,
                'day'     => date('Y-m-d'),
                'course'  => $courseId,
                'seconds' => $seconds,
                'now'     => $this->now(),
            ]
        );
    }

    /** @return array<string,int> date (Y-m-d) => seconds */
    public function dailyTotals(int $userId, string $from, string $to): array
    {
        $rows = $this->select(
            'SELECT stat_date, SUM(total_seconds) AS seconds
             FROM study_daily_stats
             WHERE user_id = :user AND stat_date BETWEEN :from AND :to
             GROUP BY stat_date',
            ['user' => $userId, 'from' => $from, 'to' => $to]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['stat_date']] = (int) $row['seconds'];
        }
        return $map;
    }

    public function totalBetween(int $userId, string $from, string $to): int
    {
        return (int) ($this->selectOne(
            'SELECT COALESCE(SUM(total_seconds), 0) AS seconds FROM study_daily_stats
             WHERE user_id = :user AND stat_date BETWEEN :from AND :to',
            ['user' => $userId, 'from' => $from, 'to' => $to]
        )['seconds'] ?? 0);
    }

    /** Per-course totals for the performance page. */
    public function totalsByCourse(int $userId, string $from, string $to): array
    {
        return $this->select(
            'SELECT c.id, c.title, COALESCE(SUM(s.total_seconds), 0) AS seconds
             FROM study_daily_stats s JOIN courses c ON c.id = s.course_id
             WHERE s.user_id = :user AND s.stat_date BETWEEN :from AND :to
             GROUP BY c.id, c.title ORDER BY seconds DESC',
            ['user' => $userId, 'from' => $from, 'to' => $to]
        );
    }
}
