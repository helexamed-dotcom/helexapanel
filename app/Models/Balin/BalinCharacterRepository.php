<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * The teacher and the students who carry the dialogue.
 *
 * These are teaching props with no link to the users table, on purpose: a
 * character is authored content, and tying one to a real account would make
 * deleting that account a content problem.
 */
final class BalinCharacterRepository extends BaseRepository
{
    public function all(bool $activeOnly = false): array
    {
        $filter = $activeOnly ? ' WHERE is_active = 1' : '';

        return $this->select(
            "SELECT * FROM balin_characters {$filter} ORDER BY display_order, id"
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_characters WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM balin_characters WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO balin_characters
                (uuid, name, char_type, gender, icon, svg_path, side, color, is_active, display_order, created_at)
             VALUES (:uuid, :name, :type, :gender, :icon, :svg, :side, :color, :active, :order, :now)',
            [
                'uuid'   => $data['uuid'],
                'name'   => $data['name'],
                'type'   => $data['char_type'] ?? 'student',
                'gender' => $data['gender'] ?? 'male',
                'icon'   => $data['icon'] ?? null,
                'svg'    => $data['svg_path'] ?? null,
                'side'   => $data['side'] ?? 'left',
                'color'  => $data['color'] ?? null,
                'active' => !empty($data['is_active']) ? 1 : 0,
                'order'  => $data['display_order'] ?? 100,
                'now'    => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data, int $expectedVersion): bool
    {
        return $this->execute(
            'UPDATE balin_characters
             SET name = :name, char_type = :type, gender = :gender, icon = :icon, svg_path = :svg,
                 side = :side, color = :color, is_active = :active, display_order = :order,
                 version = version + 1, updated_at = :now
             WHERE id = :id AND version = :version',
            [
                'name'    => $data['name'],
                'type'    => $data['char_type'] ?? 'student',
                'gender'  => $data['gender'] ?? 'male',
                'icon'    => $data['icon'] ?? null,
                'svg'     => $data['svg_path'] ?? null,
                'side'    => $data['side'] ?? 'left',
                'color'   => $data['color'] ?? null,
                'active'  => !empty($data['is_active']) ? 1 : 0,
                'order'   => $data['display_order'] ?? 100,
                'id'      => $id,
                'version' => $expectedVersion,
                'now'     => $this->now(),
            ]
        ) > 0;
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM balin_characters WHERE id = :id', ['id' => $id]);
    }

    public function usageCount(int $id): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM balin_blocks WHERE character_id = :id',
            ['id' => $id]
        )['c'] ?? 0);
    }
}
