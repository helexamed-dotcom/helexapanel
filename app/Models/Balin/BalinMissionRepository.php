<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Daily and weekly missions.
 *
 * Progress is keyed by a period string — a date for daily, an ISO week for
 * weekly — both computed in the institution timezone. Keying on a string
 * rather than a timestamp range is what makes "did this student already
 * claim today's mission" a unique-key question instead of a date-maths one.
 */
final class BalinMissionRepository extends BaseRepository
{
    public function active(?string $type = null): array
    {
        $filter = ' WHERE is_active = 1';
        $params = [];

        if ($type !== null) {
            $filter .= ' AND mission_type = :type';
            $params['type'] = $type;
        }

        return $this->select("SELECT * FROM balin_missions {$filter} ORDER BY display_order, id", $params);
    }

    public function all(): array
    {
        return $this->select('SELECT * FROM balin_missions ORDER BY mission_type, display_order, id');
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_missions WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function update(int $id, array $data): int
    {
        return $this->execute(
            'UPDATE balin_missions
             SET title = :title, description = :description, rules = :rules,
                 xp_reward = :xp, is_active = :active
             WHERE id = :id',
            [
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'rules'       => json_encode($data['rules'] ?? [], JSON_UNESCAPED_UNICODE),
                'xp'          => $data['xp_reward'] ?? 30,
                'active'      => !empty($data['is_active']) ? 1 : 0,
                'id'          => $id,
            ]
        );
    }

    /** @return array<string,int> decoded targets */
    public function rules(array $mission): array
    {
        $decoded = json_decode((string) ($mission['rules'] ?? ''), true);
        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    public function progressFor(int $userId, int $missionId, string $periodKey): ?array
    {
        return $this->selectOne(
            'SELECT * FROM balin_student_missions
             WHERE user_id = :user AND mission_id = :mission AND period_key = :period LIMIT 1',
            ['user' => $userId, 'mission' => $missionId, 'period' => $periodKey]
        );
    }

    public function storeProgress(int $userId, int $missionId, string $periodKey, array $progress): void
    {
        $this->execute(
            'INSERT INTO balin_student_missions (user_id, mission_id, period_key, progress, updated_at)
             VALUES (:user, :mission, :period, :progress, :now)
             ON DUPLICATE KEY UPDATE progress = VALUES(progress), updated_at = VALUES(updated_at)',
            [
                'user'     => $userId,
                'mission'  => $missionId,
                'period'   => $periodKey,
                'progress' => json_encode($progress, JSON_UNESCAPED_UNICODE),
                'now'      => $this->now(),
            ]
        );
    }

    /**
     * Marks the mission complete, once. Returns false when it was already
     * complete, so the caller knows not to award the XP a second time.
     */
    public function markComplete(int $userId, int $missionId, string $periodKey, int $xp): bool
    {
        $this->execute(
            'INSERT IGNORE INTO balin_student_missions (user_id, mission_id, period_key, updated_at)
             VALUES (:user, :mission, :period, :now)',
            ['user' => $userId, 'mission' => $missionId, 'period' => $periodKey, 'now' => $this->now()]
        );

        return $this->execute(
            'UPDATE balin_student_missions
             SET completed_at = :finished, xp_awarded = :xp, updated_at = :updated
             WHERE user_id = :user AND mission_id = :mission AND period_key = :period
               AND completed_at IS NULL',
            [
                'finished' => $this->now(),
                'updated'  => $this->now(),
                'xp'       => $xp,
                'user'     => $userId,
                'mission'  => $missionId,
                'period'   => $periodKey,
            ]
        ) > 0;
    }
}
