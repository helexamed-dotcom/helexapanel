<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;
use PDOException;

/**
 * Achievements and the tiers students have unlocked.
 *
 * A tier is unlocked once and stays unlocked. The unique key on
 * (user, achievement, tier) is what makes the unlock idempotent: the
 * evaluator can re-run after every answer without ever awarding twice.
 */
final class BalinAchievementRepository extends BaseRepository
{
    private const DUPLICATE = '23000';

    public function all(bool $activeOnly = false): array
    {
        $filter = $activeOnly ? ' WHERE is_active = 1' : '';

        return $this->select("SELECT * FROM balin_achievements {$filter} ORDER BY display_order, id");
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->selectOne('SELECT * FROM balin_achievements WHERE slug = :slug LIMIT 1', ['slug' => $slug]);
    }

    /** @return bool true when this call is the one that unlocked the tier */
    public function unlock(int $userId, int $achievementId, string $tier, array $metadata = []): bool
    {
        try {
            $this->insert(
                'INSERT INTO balin_student_achievements (user_id, achievement_id, tier, unlocked_at, metadata)
                 VALUES (:user, :achievement, :tier, :now, :metadata)',
                [
                    'user'        => $userId,
                    'achievement' => $achievementId,
                    'tier'        => $tier,
                    'now'         => $this->now(),
                    'metadata'    => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
                ]
            );

            return true;
        } catch (PDOException $e) {
            if ($e->getCode() !== self::DUPLICATE) {
                throw $e;
            }
            return false;   // already held
        }
    }

    /** @return array<int, array<string,mixed>> every unlocked tier for one student */
    public function forStudent(int $userId): array
    {
        return $this->select(
            'SELECT sa.*, a.slug, a.title, a.description, a.icon, a.category
             FROM balin_student_achievements sa
             JOIN balin_achievements a ON a.id = sa.achievement_id
             WHERE sa.user_id = :user
             ORDER BY sa.unlocked_at DESC',
            ['user' => $userId]
        );
    }

    /**
     * The highest tier a student holds per achievement, which is what the
     * profile shows — a gold badge replaces the silver one rather than
     * sitting next to it.
     *
     * @return array<int, array<string,mixed>> keyed by achievement id
     */
    public function bestTiers(int $userId): array
    {
        $order = ['bronze' => 1, 'silver' => 2, 'gold' => 3, 'platinum' => 4];
        $best  = [];

        foreach ($this->forStudent($userId) as $row) {
            $id   = (int) $row['achievement_id'];
            $rank = $order[$row['tier']] ?? 0;
            if (!isset($best[$id]) || $rank > ($order[$best[$id]['tier']] ?? 0)) {
                $best[$id] = $row;
            }
        }

        return $best;
    }

    public function countFor(int $userId): int
    {
        return count($this->bestTiers($userId));
    }

    /** @return array<string,int> decoded thresholds, empty when the achievement has no tiers */
    public function thresholds(array $achievement): array
    {
        $decoded = json_decode((string) ($achievement['tier_thresholds'] ?? ''), true);
        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /** @return array<string,mixed> decoded rule configuration */
    public function config(array $achievement): array
    {
        $decoded = json_decode((string) ($achievement['rule_config'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }
}
