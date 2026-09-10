<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class HighlightRepository extends BaseRepository
{
    public function forContent(int $userId, int $contentId): array
    {
        return $this->select(
            'SELECT uuid, kind, color, anchor, quote, note, content_version, created_at
             FROM content_highlights
             WHERE user_id = :user AND content_id = :content
             ORDER BY id',
            ['user' => $userId, 'content' => $contentId]
        );
    }

    public function countFor(int $userId, int $contentId): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM content_highlights WHERE user_id = :user AND content_id = :content',
            ['user' => $userId, 'content' => $contentId]
        )['c'] ?? 0);
    }

    public function exists(int $userId, string $uuid): bool
    {
        return $this->selectOne(
            'SELECT 1 FROM content_highlights WHERE user_id = :user AND uuid = :uuid LIMIT 1',
            ['user' => $userId, 'uuid' => $uuid]
        ) !== null;
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO content_highlights
                (uuid, user_id, content_id, course_id, kind, color, anchor, quote, note, content_version, created_at)
             VALUES (:uuid, :user, :content, :course, :kind, :color, :anchor, :quote, :note, :version, :now)',
            [
                'uuid'    => $data['uuid'],
                'user'    => $data['user_id'],
                'content' => $data['content_id'],
                'course'  => $data['course_id'],
                'kind'    => $data['kind'],
                'color'   => $data['color'],
                'anchor'  => json_encode($data['anchor'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'quote'   => $data['quote'],
                'note'    => $data['note'],
                'version' => $data['content_version'],
                'now'     => $this->now(),
            ]
        );
    }

    /** Scoped by user, so another student's uuid simply matches nothing. */
    public function delete(int $userId, string $uuid): int
    {
        return $this->execute(
            'DELETE FROM content_highlights WHERE user_id = :user AND uuid = :uuid',
            ['user' => $userId, 'uuid' => $uuid]
        );
    }

    public function updateColor(int $userId, string $uuid, string $color): int
    {
        return $this->execute(
            'UPDATE content_highlights SET color = :color, updated_at = :now
             WHERE user_id = :user AND uuid = :uuid',
            ['color' => $color, 'now' => $this->now(), 'user' => $userId, 'uuid' => $uuid]
        );
    }

    /** Everything the student has highlighted, newest first, for a review page. */
    public function recentForUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 300));
        return $this->select(
            'SELECT h.uuid, h.kind, h.color, h.quote, h.created_at,
                    cc.uuid AS content_uuid, cc.title AS content_title,
                    c.uuid AS course_uuid, c.title AS course_title
             FROM content_highlights h
             JOIN course_contents cc ON cc.id = h.content_id
             JOIN courses c ON c.id = h.course_id
             WHERE h.user_id = :user AND cc.deleted_at IS NULL AND c.deleted_at IS NULL
             ORDER BY h.id DESC LIMIT ' . $limit,
            ['user' => $userId]
        );
    }
}
