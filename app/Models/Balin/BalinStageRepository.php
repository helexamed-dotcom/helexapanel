<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Stages: the steps on a lesson's map.
 *
 * Whether a stage is open to a student is not stored on the stage — it is
 * derived per student by StageGate, from the previous stage's completion and
 * any gating checkpoint exam. A stored lock could be edited by a request;
 * a derived one cannot.
 */
final class BalinStageRepository extends BaseRepository
{
    public const ORDER_STEP = 1000;

    public function forLesson(int $lessonId, bool $publishedOnly = false): array
    {
        $filter = $publishedOnly ? " AND s.status = 'published'" : '';

        return $this->select(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM balin_blocks b WHERE b.stage_id = s.id) AS block_count,
                    (SELECT COUNT(*) FROM balin_blocks b2
                      WHERE b2.stage_id = s.id AND b2.is_required = 1 AND b2.status = 'published') AS required_count
             FROM balin_stages s
             WHERE s.lesson_id = :lesson {$filter}
             ORDER BY s.display_order, s.id",
            ['lesson' => $lessonId]
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM balin_stages WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_stages WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO balin_stages
                (uuid, lesson_id, title, subtitle, description, display_order, status,
                 is_final_case, xp_reward, estimated_minutes, created_at)
             VALUES (:uuid, :lesson, :title, :subtitle, :description, :order, :status,
                     :final, :xp, :minutes, :now)',
            [
                'uuid'        => $data['uuid'],
                'lesson'      => $data['lesson_id'],
                'title'       => $data['title'],
                'subtitle'    => $data['subtitle'] ?? null,
                'description' => $data['description'] ?? null,
                'order'       => $data['display_order'] ?? $this->nextOrder((int) $data['lesson_id']),
                'status'      => $data['status'] ?? 'draft',
                'final'       => !empty($data['is_final_case']) ? 1 : 0,
                'xp'          => $data['xp_reward'] ?? 0,
                'minutes'     => $data['estimated_minutes'] ?? 0,
                'now'         => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data, int $expectedVersion): bool
    {
        return $this->execute(
            'UPDATE balin_stages
             SET title = :title, subtitle = :subtitle, description = :description,
                 is_final_case = :final, xp_reward = :xp, estimated_minutes = :minutes,
                 version = version + 1, content_version = content_version + 1, updated_at = :now
             WHERE id = :id AND version = :version',
            [
                'title'       => $data['title'],
                'subtitle'    => $data['subtitle'] ?? null,
                'description' => $data['description'] ?? null,
                'final'       => !empty($data['is_final_case']) ? 1 : 0,
                'xp'          => $data['xp_reward'] ?? 0,
                'minutes'     => $data['estimated_minutes'] ?? 0,
                'id'          => $id,
                'version'     => $expectedVersion,
                'now'         => $this->now(),
            ]
        ) > 0;
    }

    public function setStatus(int $id, string $status): int
    {
        return $this->execute(
            'UPDATE balin_stages SET status = :status, updated_at = :now WHERE id = :id',
            ['status' => $status, 'id' => $id, 'now' => $this->now()]
        );
    }

    public function setOrder(int $id, int $order): int
    {
        return $this->execute(
            'UPDATE balin_stages SET display_order = :order, updated_at = :now WHERE id = :id',
            ['order' => $order, 'id' => $id, 'now' => $this->now()]
        );
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM balin_stages WHERE id = :id', ['id' => $id]);
    }

    public function nextOrder(int $lessonId): int
    {
        $max = (int) ($this->selectOne(
            'SELECT MAX(display_order) AS m FROM balin_stages WHERE lesson_id = :lesson',
            ['lesson' => $lessonId]
        )['m'] ?? 0);

        return $max + self::ORDER_STEP;
    }

    /**
     * Renumbers a lesson's stages back onto clean 1000-steps.
     * Only needed when repeated insertions have squeezed the gaps shut.
     */
    public function rebalance(int $lessonId): void
    {
        $order = self::ORDER_STEP;
        foreach ($this->forLesson($lessonId) as $stage) {
            $this->setOrder((int) $stage['id'], $order);
            $order += self::ORDER_STEP;
        }
    }
}
