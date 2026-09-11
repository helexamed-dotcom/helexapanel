<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Core\Database;
use HeleXa\Models\BaseRepository;

/**
 * Leaderboard snapshots.
 *
 * Boards are not computed per page view — that would be one aggregate per
 * visitor over the whole ledger. They are rebuilt on a schedule into this
 * table and read from it with an index and a LIMIT.
 *
 * Because the rebuild reads the ledger, editing a row here by hand changes
 * nothing durable: the next rebuild overwrites it. That is the intended
 * defence against someone with database access inflating a rank.
 */
final class BalinLeaderboardRepository extends BaseRepository
{
    public const TYPES = [
        'overall_xp' => 'مجموع امتیاز',
        'weekly_xp'  => 'امتیاز هفتگی',
        'lesson'     => 'رتبه درس',
        'mastery'    => 'رتبه تسلط',
        'streak'     => 'رتبه استمرار',
        'skill'      => 'رتبه مهارت',
    ];

    /**
     * Replaces one board atomically: the old rows and the new ones never
     * coexist, so a page load during a rebuild sees one complete board
     * rather than half of each.
     *
     * @param array<int, array{user_id:int|string, score:float|string}> $rows already sorted, best first
     */
    public function replaceBoard(string $boardType, ?int $scopeId, array $rows): void
    {
        Database::transaction(function () use ($boardType, $scopeId, $rows): void {
            $this->execute(
                'DELETE FROM balin_leaderboard_snapshot
                 WHERE board_type = :type AND ' . ($scopeId === null ? 'scope_id IS NULL' : 'scope_id = :scope'),
                $scopeId === null ? ['type' => $boardType] : ['type' => $boardType, 'scope' => $scopeId]
            );

            $now  = $this->now();
            $rank = 1;
            foreach ($rows as $row) {
                $this->insert(
                    'INSERT INTO balin_leaderboard_snapshot
                        (board_type, scope_id, user_id, score, rank_position, generated_at)
                     VALUES (:type, :scope, :user, :score, :rank, :now)',
                    [
                        'type'  => $boardType,
                        'scope' => $scopeId,
                        'user'  => (int) $row['user_id'],
                        'score' => (float) $row['score'],
                        'rank'  => $rank,
                        'now'   => $now,
                    ]
                );
                $rank++;
            }
        });
    }

    /**
     * A page of a board, joined to the names and levels needed to render it.
     * Students whose access has been revoked are left out, per the rule that
     * a blocked student keeps their progress but leaves the public board.
     */
    public function page(string $boardType, ?int $scopeId, int $limit, int $offset): array
    {
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $scopeClause = $scopeId === null ? 'b.scope_id IS NULL' : 'b.scope_id = :scope';
        $params = ['type' => $boardType];
        if ($scopeId !== null) {
            $params['scope'] = $scopeId;
        }

        return $this->select(
            "SELECT b.rank_position, b.score, b.user_id,
                    u.full_name, u.uuid AS user_uuid, u.avatar_path, u.gender,
                    COALESCE(s.cached_level, 0) AS level
             FROM balin_leaderboard_snapshot b
             JOIN users u ON u.id = b.user_id
             JOIN balin_student_access a ON a.user_id = b.user_id AND a.is_enabled = 1
             LEFT JOIN balin_user_stats s ON s.user_id = b.user_id
             WHERE b.board_type = :type AND {$scopeClause}
             ORDER BY b.rank_position
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    public function countBoard(string $boardType, ?int $scopeId): int
    {
        $scopeClause = $scopeId === null ? 'b.scope_id IS NULL' : 'b.scope_id = :scope';
        $params = ['type' => $boardType];
        if ($scopeId !== null) {
            $params['scope'] = $scopeId;
        }

        return (int) ($this->selectOne(
            "SELECT COUNT(*) AS c
             FROM balin_leaderboard_snapshot b
             JOIN balin_student_access a ON a.user_id = b.user_id AND a.is_enabled = 1
             WHERE b.board_type = :type AND {$scopeClause}",
            $params
        )['c'] ?? 0);
    }

    /** One student's own position, so the profile can show it without paging. */
    public function rankFor(int $userId, string $boardType, ?int $scopeId): ?array
    {
        $scopeClause = $scopeId === null ? 'scope_id IS NULL' : 'scope_id = :scope';
        $params = ['type' => $boardType, 'user' => $userId];
        if ($scopeId !== null) {
            $params['scope'] = $scopeId;
        }

        return $this->selectOne(
            "SELECT rank_position, score, generated_at
             FROM balin_leaderboard_snapshot
             WHERE board_type = :type AND {$scopeClause} AND user_id = :user
             LIMIT 1",
            $params
        );
    }

    public function generatedAt(string $boardType, ?int $scopeId): ?string
    {
        $scopeClause = $scopeId === null ? 'scope_id IS NULL' : 'scope_id = :scope';
        $params = ['type' => $boardType];
        if ($scopeId !== null) {
            $params['scope'] = $scopeId;
        }

        $row = $this->selectOne(
            "SELECT MAX(generated_at) AS t FROM balin_leaderboard_snapshot
             WHERE board_type = :type AND {$scopeClause}",
            $params
        );

        return $row === null || $row['t'] === null ? null : (string) $row['t'];
    }

    // --------------------------------------------- source rows for rebuilds

    /** @return array<int, array{user_id:int, score:float}> */
    public function masterySourceRows(int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));

        return $this->select(
            "SELECT user_id, AVG(mastery_percent) AS score
             FROM balin_student_lesson_mastery
             GROUP BY user_id
             HAVING score > 0
             ORDER BY score DESC
             LIMIT {$limit}"
        );
    }

    /** @return array<int, array{user_id:int, score:float}> */
    public function lessonSourceRows(int $lessonId, int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));

        return $this->select(
            "SELECT user_id, mastery_percent AS score
             FROM balin_student_lesson_mastery
             WHERE lesson_id = :lesson AND mastery_percent > 0
             ORDER BY score DESC
             LIMIT {$limit}",
            ['lesson' => $lessonId]
        );
    }

    /**
     * Skill board rows, restricted to students with enough answers behind
     * the figure — ranking on a two-question sample would be noise.
     *
     * @return array<int, array{user_id:int, score:float}>
     */
    public function skillSourceRows(int $trackId, int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));

        return $this->select(
            "SELECT m.user_id, m.mastery_percent AS score
             FROM balin_student_skill_mastery m
             JOIN balin_skill_tracks t ON t.id = m.skill_track_id
             WHERE m.skill_track_id = :track
               AND m.answered_count >= t.min_questions_for_reliable_mastery
               AND m.mastery_percent > 0
             ORDER BY score DESC
             LIMIT {$limit}",
            ['track' => $trackId]
        );
    }
}
