<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * The ordered contents of a stage: chat lines, questions, media, dividers.
 *
 * Same 1000-step ordering as stages, for the same reason — a drag in the
 * builder should rewrite one row, not the whole stage.
 */
final class BalinBlockRepository extends BaseRepository
{
    public const ORDER_STEP = 1000;

    /** Everything the builder needs, including the joined character and media. */
    public function forStage(int $stageId, bool $publishedOnly = false): array
    {
        $filter = $publishedOnly ? " AND b.status = 'published'" : '';

        return $this->select(
            "SELECT b.*,
                    c.name AS character_name, c.icon AS character_icon, c.side AS character_side,
                    c.char_type AS character_type, c.color AS character_color, c.svg_path AS character_svg,
                    m.uuid AS media_uuid, m.kind AS media_kind, m.storage_path AS media_path,
                    m.alt_text AS media_alt, m.caption AS media_caption, m.transcript AS media_transcript,
                    m.visibility AS media_visibility, m.width_percent AS media_width,
                    m.position AS media_position, m.allow_download AS media_download, m.mime AS media_mime,
                    q.uuid AS question_uuid, q.prompt AS question_prompt, q.difficulty AS question_difficulty,
                    q.hint AS question_hint, q.xp_reward AS question_xp,
                    e.uuid AS exam_uuid, e.title AS exam_title, e.is_gating AS exam_gating
             FROM balin_blocks b
             LEFT JOIN balin_characters c ON c.id = b.character_id
             LEFT JOIN balin_media m ON m.id = b.media_id
             LEFT JOIN balin_questions q ON q.id = b.question_id
             LEFT JOIN balin_checkpoint_exams e ON e.id = b.checkpoint_exam_id
             WHERE b.stage_id = :stage {$filter}
             ORDER BY b.display_order, b.id",
            ['stage' => $stageId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_blocks WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM balin_blocks WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO balin_blocks
                (uuid, stage_id, block_type, display_order, is_required, status, character_id,
                 side_override, body, media_id, question_id, checkpoint_exam_id, animation, settings, created_at)
             VALUES (:uuid, :stage, :type, :order, :required, :status, :character,
                     :side, :body, :media, :question, :exam, :animation, :settings, :now)',
            [
                'uuid'      => $data['uuid'],
                'stage'     => $data['stage_id'],
                'type'      => $data['block_type'],
                'order'     => $data['display_order'] ?? $this->nextOrder((int) $data['stage_id']),
                'required'  => !empty($data['is_required']) ? 1 : 0,
                'status'    => $data['status'] ?? 'published',
                'character' => $data['character_id'] ?? null,
                'side'      => $data['side_override'] ?? null,
                'body'      => $data['body'] ?? null,
                'media'     => $data['media_id'] ?? null,
                'question'  => $data['question_id'] ?? null,
                'exam'      => $data['checkpoint_exam_id'] ?? null,
                'animation' => $data['animation'] ?? null,
                'settings'  => isset($data['settings']) ? json_encode($data['settings'], JSON_UNESCAPED_UNICODE) : null,
                'now'       => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data, int $expectedVersion): bool
    {
        return $this->execute(
            'UPDATE balin_blocks
             SET block_type = :type, is_required = :required, status = :status, character_id = :character,
                 side_override = :side, body = :body, media_id = :media, question_id = :question,
                 checkpoint_exam_id = :exam, animation = :animation, settings = :settings,
                 version = version + 1, updated_at = :now
             WHERE id = :id AND version = :version',
            [
                'type'      => $data['block_type'],
                'required'  => !empty($data['is_required']) ? 1 : 0,
                'status'    => $data['status'] ?? 'published',
                'character' => $data['character_id'] ?? null,
                'side'      => $data['side_override'] ?? null,
                'body'      => $data['body'] ?? null,
                'media'     => $data['media_id'] ?? null,
                'question'  => $data['question_id'] ?? null,
                'exam'      => $data['checkpoint_exam_id'] ?? null,
                'animation' => $data['animation'] ?? null,
                'settings'  => isset($data['settings']) ? json_encode($data['settings'], JSON_UNESCAPED_UNICODE) : null,
                'id'        => $id,
                'version'   => $expectedVersion,
                'now'       => $this->now(),
            ]
        ) > 0;
    }

    public function setOrder(int $id, int $order): int
    {
        return $this->execute(
            'UPDATE balin_blocks SET display_order = :order, updated_at = :now WHERE id = :id',
            ['order' => $order, 'id' => $id, 'now' => $this->now()]
        );
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM balin_blocks WHERE id = :id', ['id' => $id]);
    }

    public function nextOrder(int $stageId): int
    {
        $max = (int) ($this->selectOne(
            'SELECT MAX(display_order) AS m FROM balin_blocks WHERE stage_id = :stage',
            ['stage' => $stageId]
        )['m'] ?? 0);

        return $max + self::ORDER_STEP;
    }

    /**
     * Puts a block between its neighbours by halving the gap. With 1000-step
     * seeding this stays whole-numbered for ten or so moves before the gap
     * closes, at which point rebalance() opens it again.
     */
    public function moveBetween(int $id, ?int $beforeOrder, ?int $afterOrder, int $stageId): void
    {
        if ($beforeOrder === null && $afterOrder === null) {
            $this->setOrder($id, $this->nextOrder($stageId));
            return;
        }

        $target = $beforeOrder === null
            ? max(1, (int) $afterOrder - self::ORDER_STEP)
            : ($afterOrder === null
                ? (int) $beforeOrder + self::ORDER_STEP
                : (int) floor(((int) $beforeOrder + (int) $afterOrder) / 2));

        if ($beforeOrder !== null && $afterOrder !== null && $target <= (int) $beforeOrder) {
            $this->rebalance($stageId);
            return;
        }

        $this->setOrder($id, $target);
    }

    public function rebalance(int $stageId): void
    {
        $order = self::ORDER_STEP;
        foreach ($this->forStage($stageId) as $block) {
            $this->setOrder((int) $block['id'], $order);
            $order += self::ORDER_STEP;
        }
    }

    /**
     * Whether this stage actually presents this question.
     *
     * The block is what places a question in a stage, not the question's own
     * stage_id — that column is only a convenience for the author's filters,
     * and is legitimately null for a question written straight into the bank
     * and then dropped into a stage. Asking the blocks is therefore the only
     * answer that matches what the student is looking at.
     */
    public function hasQuestion(int $stageId, int $questionId): bool
    {
        return $this->selectOne(
            "SELECT 1 FROM balin_blocks
             WHERE stage_id = :stage AND question_id = :question
               AND block_type = 'question' AND status = 'published'
             LIMIT 1",
            ['stage' => $stageId, 'question' => $questionId]
        ) !== null;
    }

    public function countRequired(int $stageId): int
    {
        return (int) ($this->selectOne(
            "SELECT COUNT(*) AS c FROM balin_blocks
             WHERE stage_id = :stage AND is_required = 1 AND status = 'published'",
            ['stage' => $stageId]
        )['c'] ?? 0);
    }
}
