<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * The twenty (or however many) level tiers that give a number a name.
 *
 * Cached for the request because a leaderboard page asks for the same
 * twenty rows once per listed student.
 */
final class BalinRankTierRepository extends BaseRepository
{
    private static ?array $cache = null;

    public function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        return self::$cache = $this->select(
            'SELECT id, min_level, max_level, title, icon, color, description, display_order, version
             FROM balin_rank_tiers
             ORDER BY min_level'
        );
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_rank_tiers WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function create(array $data): int
    {
        self::flush();

        return $this->insert(
            'INSERT INTO balin_rank_tiers (min_level, max_level, title, icon, color, description, display_order)
             VALUES (:min, :max, :title, :icon, :color, :description, :order)',
            [
                'min'         => $data['min_level'],
                'max'         => $data['max_level'],
                'title'       => $data['title'],
                'icon'        => $data['icon'] ?? null,
                'color'       => $data['color'] ?? null,
                'description' => $data['description'] ?? null,
                'order'       => $data['display_order'] ?? 1,
            ]
        );
    }

    /**
     * Optimistic locking: the update only lands when the version the editor
     * loaded is still the current one, so a second admin's save cannot
     * silently discard the first.
     */
    public function update(int $id, array $data, int $expectedVersion): bool
    {
        self::flush();

        $changed = $this->execute(
            'UPDATE balin_rank_tiers
             SET min_level = :min, max_level = :max, title = :title, icon = :icon,
                 color = :color, description = :description, display_order = :order,
                 version = version + 1
             WHERE id = :id AND version = :version',
            [
                'min'         => $data['min_level'],
                'max'         => $data['max_level'],
                'title'       => $data['title'],
                'icon'        => $data['icon'] ?? null,
                'color'       => $data['color'] ?? null,
                'description' => $data['description'] ?? null,
                'order'       => $data['display_order'] ?? 1,
                'id'          => $id,
                'version'     => $expectedVersion,
            ]
        );

        return $changed > 0;
    }

    public function delete(int $id): int
    {
        self::flush();
        return $this->execute('DELETE FROM balin_rank_tiers WHERE id = :id', ['id' => $id]);
    }

    /** Ranges must not overlap, or a level would have two names. */
    public function overlaps(int $min, int $max, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM balin_rank_tiers WHERE min_level <= :max AND max_level >= :min';
        $params = ['min' => $min, 'max' => $max];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return $this->selectOne($sql . ' LIMIT 1', $params) !== null;
    }
}
