<?php
declare(strict_types=1);

namespace HeleXa\Models;

/** What a student wrote with the pen directly on a lesson. */
final class InkRepository extends BaseRepository
{
    /** @return array<int,mixed> */
    public function strokesFor(int $userId, int $contentId): array
    {
        $row = $this->selectOne(
            'SELECT strokes FROM lesson_ink WHERE user_id = :u AND content_id = :c LIMIT 1',
            ['u' => $userId, 'c' => $contentId]
        );
        $data = $row !== null ? json_decode((string) $row['strokes'], true) : null;

        return is_array($data) ? $data : [];
    }

    public function save(int $userId, int $contentId, string $json, int $count): void
    {
        if ($count === 0) {
            $this->execute('DELETE FROM lesson_ink WHERE user_id = :u AND content_id = :c', ['u' => $userId, 'c' => $contentId]);
            return;
        }
        $this->execute(
            'INSERT INTO lesson_ink (user_id, content_id, strokes, stroke_count, updated_at)
             VALUES (:u, :c, :s, :n, :now)
             ON DUPLICATE KEY UPDATE strokes = VALUES(strokes), stroke_count = VALUES(stroke_count), updated_at = VALUES(updated_at)',
            ['u' => $userId, 'c' => $contentId, 's' => $json, 'n' => $count, 'now' => $this->now()]
        );
    }
}