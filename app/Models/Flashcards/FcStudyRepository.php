<?php
declare(strict_types=1);

namespace HeleXa\Models\Flashcards;

use HeleXa\Core\Database;
use HeleXa\Models\BaseRepository;
use HeleXa\Services\Flashcards\Scheduler;

/**
 * One student's relationship with cards: grants, schedule, stars, history.
 */
final class FcStudyRepository extends BaseRepository
{
    /* ============================================================ access */

    /** @return array<int,int> */
    public function grantedCourseIds(int $userId): array
    {
        $rows = $this->select('SELECT course_id FROM fc_access WHERE user_id = :u', ['u' => $userId]);
        return array_map(static fn (array $r): int => (int) $r['course_id'], $rows);
    }

    public function hasCourse(int $userId, int $courseId): bool
    {
        return $this->selectOne(
            'SELECT 1 FROM fc_access WHERE user_id = :u AND course_id = :c LIMIT 1',
            ['u' => $userId, 'c' => $courseId]
        ) !== null;
    }

    /**
     * Replaces a student's grants with the submitted set. Surviving grants are
     * left untouched so granted_at keeps meaning "first given".
     *
     * @param array<int,int> $courseIds
     */
    public function syncAccess(int $userId, array $courseIds, ?int $adminId): void
    {
        $wanted  = array_values(array_unique(array_map('intval', $courseIds)));
        $current = $this->grantedCourseIds($userId);

        foreach (array_diff($wanted, $current) as $courseId) {
            $this->execute(
                'INSERT IGNORE INTO fc_access (user_id, course_id, granted_by, granted_at) VALUES (:u, :c, :a, :now)',
                ['u' => $userId, 'c' => $courseId, 'a' => $adminId, 'now' => $this->now()]
            );
        }
        foreach (array_diff($current, $wanted) as $courseId) {
            // Progress on the course's cards is kept: re-granting later puts
            // the student back exactly where they were.
            $this->execute('DELETE FROM fc_access WHERE user_id = :u AND course_id = :c', ['u' => $userId, 'c' => $courseId]);
        }
    }

    /**
     * Published courses this student holds, with their personal numbers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function coursesFor(int $userId): array
    {
        return $this->select(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM fc_decks d WHERE d.course_id = c.id) AS deck_count,
                    (SELECT COUNT(*) FROM fc_cards k JOIN fc_decks d ON d.id = k.deck_id WHERE d.course_id = c.id) AS card_count,
                    (SELECT COUNT(*) FROM fc_progress p JOIN fc_cards k ON k.id = p.card_id JOIN fc_decks d ON d.id = k.deck_id
                      WHERE d.course_id = c.id AND p.user_id = :u1 AND p.last_reviewed_at IS NOT NULL) AS seen_count,
                    (SELECT COUNT(*) FROM fc_progress p JOIN fc_cards k ON k.id = p.card_id JOIN fc_decks d ON d.id = k.deck_id
                      WHERE d.course_id = c.id AND p.user_id = :u2 AND p.interval_days >= " . Scheduler::MASTERED_DAYS . ") AS mastered_count,
                    (SELECT COUNT(*) FROM fc_progress p JOIN fc_cards k ON k.id = p.card_id JOIN fc_decks d ON d.id = k.deck_id
                      WHERE d.course_id = c.id AND p.user_id = :u3 AND p.last_reviewed_at IS NOT NULL AND p.due_at <= :now) AS due_count
             FROM fc_access a
             JOIN fc_courses c ON c.id = a.course_id
             WHERE a.user_id = :u4 AND c.status = 'published'
             ORDER BY c.sort_order, c.title",
            ['u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId, 'now' => $this->now()]
        );
    }

    /**
     * Per-deck numbers for a set of decks, in one query.
     *
     * @param  array<int,int> $deckIds
     * @return array<int,array{seen:int, mastered:int, due:int}>
     */
    public function deckStats(int $userId, array $deckIds): array
    {
        $ids = implode(',', array_map('intval', $deckIds));
        if ($ids === '') {
            return [];
        }

        $rows = $this->select(
            'SELECT k.deck_id,
                    COUNT(*) AS seen,
                    SUM(p.interval_days >= ' . Scheduler::MASTERED_DAYS . ') AS mastered,
                    SUM(p.due_at <= :now) AS due
             FROM fc_progress p JOIN fc_cards k ON k.id = p.card_id
             WHERE p.user_id = :u AND k.deck_id IN (' . $ids . ')
               -- A star on a card never studied creates a progress row; it
               -- is not a review and must not count as seen or due.
               AND p.last_reviewed_at IS NOT NULL
             GROUP BY k.deck_id',
            ['u' => $userId, 'now' => $this->now()]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['deck_id']] = [
                'seen'     => (int) $row['seen'],
                'mastered' => (int) $row['mastered'],
                'due'      => (int) $row['due'],
            ];
        }
        return $out;
    }

    /* ============================================================ queues */

    /**
     * The cards for one study session.
     *
     *   due      due reviews first, then up to $newLimit unseen cards
     *   all      every card in order, seen or not
     *   starred  only starred cards
     *   hard     cards last rated «دوباره» or «سخت», or forgotten at least once
     *
     * Only ids of decks the caller has already authorised are accepted — this
     * method does not decide access, it is handed the answer.
     *
     * @param  array<int,int> $deckIds
     * @return array<int,array<string,mixed>>
     */
    public function queue(int $userId, array $deckIds, string $mode, int $newLimit, int $cap, bool $shuffle): array
    {
        $ids = implode(',', array_map('intval', $deckIds));
        if ($ids === '') {
            return [];
        }
        $cap = max(1, min($cap, 500));

        $select = 'SELECT k.uuid, k.front, k.back, k.hint, k.deck_id,
                          p.reps, p.lapses, p.ease, p.interval_days, p.due_at, p.starred, p.last_rating
                   FROM fc_cards k
                   LEFT JOIN fc_progress p ON p.card_id = k.id AND p.user_id = :u
                   WHERE k.deck_id IN (' . $ids . ')';
        $order  = $shuffle ? 'RAND()' : 'k.deck_id, k.sort_order, k.id';

        switch ($mode) {
            case 'all':
                $rows = $this->select($select . ' ORDER BY ' . $order . ' LIMIT ' . $cap, ['u' => $userId]);
                break;

            case 'starred':
                $rows = $this->select($select . ' AND p.starred = 1 ORDER BY ' . $order . ' LIMIT ' . $cap, ['u' => $userId]);
                break;

            case 'hard':
                $rows = $this->select(
                    $select . ' AND (p.last_rating IN (1, 2) OR p.lapses > 0) ORDER BY '
                    . ($shuffle ? 'RAND()' : 'p.lapses DESC, p.ease ASC') . ' LIMIT ' . $cap,
                    ['u' => $userId]
                );
                break;

            default: // due
                $due = $this->select(
                    $select . ' AND p.last_reviewed_at IS NOT NULL AND p.due_at <= :now ORDER BY ' . ($shuffle ? 'RAND()' : 'p.due_at') . ' LIMIT ' . $cap,
                    ['u' => $userId, 'now' => $this->now()]
                );
                $room  = max(0, min($newLimit, $cap - count($due)));
                $fresh = $room > 0
                    ? $this->select($select . ' AND (p.card_id IS NULL OR p.last_reviewed_at IS NULL) ORDER BY ' . $order . ' LIMIT ' . $room, ['u' => $userId])
                    : [];
                // Reviews before new cards: what is overdue is what is being
                // forgotten right now.
                $rows = array_merge($due, $fresh);
                break;
        }

        return $rows;
    }

    /* ============================================================ writes */

    public function stateFor(int $userId, int $cardId): ?array
    {
        return $this->selectOne(
            'SELECT reps, lapses, ease, interval_days, starred FROM fc_progress WHERE user_id = :u AND card_id = :c',
            ['u' => $userId, 'c' => $cardId]
        );
    }

    /**
     * Records one rating: the schedule and the history row together.
     *
     * The row is locked while the next state is computed, so two ratings sent
     * at once for the same card — a double tap — are applied one after the
     * other rather than both starting from the same old state.
     *
     * @return array<string,mixed> the new state
     */
    public function rate(int $userId, int $cardId, int $rating): array
    {
        return Database::transaction(function () use ($userId, $cardId, $rating): array {
            $state = $this->selectOne(
                'SELECT reps, lapses, ease, interval_days, starred FROM fc_progress
                  WHERE user_id = :u AND card_id = :c FOR UPDATE',
                ['u' => $userId, 'c' => $cardId]
            );

            $next = Scheduler::next($state, $rating, new \DateTimeImmutable());
            $now  = $this->now();

            $this->execute(
                'INSERT INTO fc_progress
                    (user_id, card_id, reps, lapses, ease, interval_days, due_at, last_rating, last_reviewed_at, starred)
                 VALUES (:u, :c, :reps, :lapses, :ease, :interval, :due, :rating, :now, 0)
                 ON DUPLICATE KEY UPDATE
                    reps = VALUES(reps), lapses = VALUES(lapses), ease = VALUES(ease),
                    interval_days = VALUES(interval_days), due_at = VALUES(due_at),
                    last_rating = VALUES(last_rating), last_reviewed_at = VALUES(last_reviewed_at)',
                [
                    'u' => $userId, 'c' => $cardId,
                    'reps' => $next['reps'], 'lapses' => $next['lapses'], 'ease' => $next['ease'],
                    'interval' => $next['interval_days'], 'due' => $next['due_at'],
                    'rating' => $rating, 'now' => $now,
                ]
            );

            $this->insert(
                'INSERT INTO fc_review_log (user_id, card_id, rating, reviewed_at) VALUES (:u, :c, :r, :now)',
                ['u' => $userId, 'c' => $cardId, 'r' => $rating, 'now' => $now]
            );

            return $next;
        });
    }

    /** Flips the star; a card never studied gets a progress row that is due now. */
    public function toggleStar(int $userId, int $cardId): bool
    {
        $this->execute(
            'INSERT INTO fc_progress (user_id, card_id, due_at, starred) VALUES (:u, :c, :now, 1)
             ON DUPLICATE KEY UPDATE starred = 1 - starred',
            ['u' => $userId, 'c' => $cardId, 'now' => $this->now()]
        );

        $row = $this->stateFor($userId, $cardId);
        return (int) ($row['starred'] ?? 0) === 1;
    }

    /** Forgets this student's schedule for a deck, so it can be studied from zero. */
    public function resetDeck(int $userId, int $deckId): void
    {
        $this->execute(
            'DELETE p FROM fc_progress p JOIN fc_cards k ON k.id = p.card_id
              WHERE p.user_id = :u AND k.deck_id = :d',
            ['u' => $userId, 'd' => $deckId]
        );
    }

    /* ============================================================= stats */

    /** @return array{today:int, streak:int, due:int, mastered:int} */
    public function overview(int $userId, array $deckIds): array
    {
        $today = date('Y-m-d');

        $reviewedToday = (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM fc_review_log WHERE user_id = :u AND reviewed_at >= :d',
            ['u' => $userId, 'd' => $today . ' 00:00:00']
        )['c'] ?? 0);

        // The streak counts back from today (or from yesterday, if nothing
        // has been reviewed yet today — the day is not over).
        $days = $this->select(
            'SELECT DISTINCT DATE(reviewed_at) AS d FROM fc_review_log
              WHERE user_id = :u AND reviewed_at >= :since ORDER BY d DESC',
            ['u' => $userId, 'since' => date('Y-m-d', strtotime('-400 days')) . ' 00:00:00']
        );
        $set    = array_flip(array_map(static fn (array $r): string => (string) $r['d'], $days));
        $cursor = new \DateTimeImmutable($today);
        if (!isset($set[$today])) {
            $cursor = $cursor->modify('-1 day');
        }
        $streak = 0;
        while (isset($set[$cursor->format('Y-m-d')])) {
            $streak++;
            $cursor = $cursor->modify('-1 day');
        }

        $due = 0;
        $mastered = 0;
        foreach ($this->deckStats($userId, $deckIds) as $stat) {
            $due      += $stat['due'];
            $mastered += $stat['mastered'];
        }

        return ['today' => $reviewedToday, 'streak' => $streak, 'due' => $due, 'mastered' => $mastered];
    }

    /** @return array<string,array{stage:string,starred:bool}> card uuid →, for a deck's card list */
    public function stagesForDeck(int $userId, int $deckId): array
    {
        $rows = $this->select(
            'SELECT k.uuid, p.reps, p.interval_days, p.starred, p.last_reviewed_at
             FROM fc_cards k JOIN fc_progress p ON p.card_id = k.id AND p.user_id = :u
             WHERE k.deck_id = :d',
            ['u' => $userId, 'd' => $deckId]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['uuid']] = [
                'stage'   => $row['last_reviewed_at'] === null ? 'new' : Scheduler::stage($row),
                'starred' => (int) $row['starred'] === 1,
            ];
        }
        return $out;
    }
}
