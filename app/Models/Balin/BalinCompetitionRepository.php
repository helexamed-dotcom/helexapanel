<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Weekly competitions and their rewards.
 *
 * Only one competition may be active at a time. That is enforced here rather
 * than by a database constraint, because "active" depends on the clock as
 * well as the status column, and a constraint cannot see the clock.
 */
final class BalinCompetitionRepository extends BaseRepository
{
    public function all(): array
    {
        return $this->select(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM balin_competition_xp x WHERE x.competition_id = c.id) AS participant_count,
                    (SELECT COUNT(*) FROM balin_rewards r WHERE r.competition_id = c.id) AS reward_count
             FROM balin_competitions c
             ORDER BY c.start_date DESC'
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_competitions WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM balin_competitions WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    /** The competition running right now: marked active and inside its window. */
    public function current(): ?array
    {
        return $this->selectOne(
            "SELECT * FROM balin_competitions
             WHERE status = 'active' AND start_date <= :started AND end_date >= :ends
             ORDER BY start_date DESC LIMIT 1",
            ['started' => $this->now(), 'ends' => $this->now()]
        );
    }

    public function lastEnded(): ?array
    {
        return $this->selectOne(
            "SELECT * FROM balin_competitions
             WHERE status = 'ended' OR end_date < :now
             ORDER BY end_date DESC LIMIT 1",
            ['now' => $this->now()]
        );
    }

    /** Whether making this competition active would collide with another. */
    public function activeConflict(string $start, string $end, ?int $exceptId = null): ?array
    {
        $sql = "SELECT * FROM balin_competitions
                WHERE status = 'active' AND start_date <= :end AND end_date >= :start";
        $params = ['start' => $start, 'end' => $end];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return $this->selectOne($sql . ' LIMIT 1', $params);
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO balin_competitions
                (uuid, title, description, start_date, end_date, status, rules, leaderboard_visibility, created_by, created_at)
             VALUES (:uuid, :title, :description, :start, :end, :status, :rules, :visibility, :by, :now)',
            [
                'uuid'        => $data['uuid'],
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'start'       => $data['start_date'],
                'end'         => $data['end_date'],
                'status'      => $data['status'] ?? 'scheduled',
                'rules'       => isset($data['rules']) ? json_encode($data['rules'], JSON_UNESCAPED_UNICODE) : null,
                'visibility'  => $data['leaderboard_visibility'] ?? 'public',
                'by'          => $data['created_by'] ?? null,
                'now'         => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data): int
    {
        return $this->execute(
            'UPDATE balin_competitions
             SET title = :title, description = :description, start_date = :start, end_date = :end,
                 rules = :rules, leaderboard_visibility = :visibility, updated_at = :now
             WHERE id = :id',
            [
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'start'       => $data['start_date'],
                'end'         => $data['end_date'],
                'rules'       => isset($data['rules']) ? json_encode($data['rules'], JSON_UNESCAPED_UNICODE) : null,
                'visibility'  => $data['leaderboard_visibility'] ?? 'public',
                'id'          => $id,
                'now'         => $this->now(),
            ]
        );
    }

    public function setStatus(int $id, string $status): int
    {
        return $this->execute(
            'UPDATE balin_competitions SET status = :status, updated_at = :now WHERE id = :id',
            ['status' => $status, 'id' => $id, 'now' => $this->now()]
        );
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM balin_competitions WHERE id = :id', ['id' => $id]);
    }

    // ------------------------------------------------------ competition XP

    /**
     * Mirrors a student's competition XP into the summary table.
     * The ledger stays the source of truth; this row is what the board reads.
     */
    public function addXp(int $userId, int $competitionId, int $amount): void
    {
        $this->execute(
            'INSERT INTO balin_competition_xp (user_id, competition_id, xp, updated_at)
             VALUES (:user, :competition, :xp, :now)
             ON DUPLICATE KEY UPDATE xp = xp + VALUES(xp), updated_at = VALUES(updated_at)',
            ['user' => $userId, 'competition' => $competitionId, 'xp' => max(0, $amount), 'now' => $this->now()]
        );
    }

    public function xpFor(int $userId, int $competitionId): int
    {
        return (int) ($this->selectOne(
            'SELECT xp FROM balin_competition_xp WHERE user_id = :user AND competition_id = :competition LIMIT 1',
            ['user' => $userId, 'competition' => $competitionId]
        )['xp'] ?? 0);
    }

    // ------------------------------------------------------------ rewards

    public function rewards(int $competitionId): array
    {
        return $this->select(
            'SELECT r.*, u.full_name AS winner_name
             FROM balin_rewards r
             LEFT JOIN users u ON u.id = r.winner_user_id
             WHERE r.competition_id = :competition
             ORDER BY r.rank_position, r.id',
            ['competition' => $competitionId]
        );
    }

    public function createReward(array $data): int
    {
        return $this->insert(
            'INSERT INTO balin_rewards
                (uuid, competition_id, title, description, rank_position, winner_user_id, status, admin_note, created_at)
             VALUES (:uuid, :competition, :title, :description, :rank, :winner, :status, :note, :now)',
            [
                'uuid'        => $data['uuid'],
                'competition' => $data['competition_id'] ?? null,
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'rank'        => $data['rank_position'] ?? null,
                'winner'      => $data['winner_user_id'] ?? null,
                'status'      => $data['status'] ?? 'draft',
                'note'        => $data['admin_note'] ?? null,
                'now'         => $this->now(),
            ]
        );
    }

    public function assignReward(int $rewardId, ?int $winnerUserId, string $status, ?string $note): int
    {
        return $this->execute(
            'UPDATE balin_rewards
             SET winner_user_id = :winner, status = :status, admin_note = :note, updated_at = :now
             WHERE id = :id',
            ['winner' => $winnerUserId, 'status' => $status, 'note' => $note, 'id' => $rewardId, 'now' => $this->now()]
        );
    }

    public function deleteReward(int $rewardId): int
    {
        return $this->execute('DELETE FROM balin_rewards WHERE id = :id', ['id' => $rewardId]);
    }

    public function rewardsForStudent(int $userId): array
    {
        return $this->select(
            'SELECT r.*, c.title AS competition_title
             FROM balin_rewards r
             LEFT JOIN balin_competitions c ON c.id = r.competition_id
             WHERE r.winner_user_id = :user
             ORDER BY r.id DESC',
            ['user' => $userId]
        );
    }
}
