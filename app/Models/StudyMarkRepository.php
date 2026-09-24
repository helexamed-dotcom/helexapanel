<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * «درس‌های من»: what a student marked to read.
 *
 * A mark points at something on the site (a lesson, a library item, a
 * question-bank topic, a Balin lesson, a course) or is free text the student
 * wrote. Marking the same thing twice brings the existing mark back to the
 * top of the list instead of adding a copy.
 */
final class StudyMarkRepository extends BaseRepository
{
    public const KINDS = [
        'content'      => ['📄', 'جزوه / محتوا'],
        'library'      => ['📚', 'کتابخانه'],
        'qbank_topic'  => ['🧠', 'بانک سوال'],
        'balin_lesson' => ['🏝️', 'جزیره بالین'],
        'course'       => ['🎓', 'دوره'],
        'custom'       => ['✍️', 'یادداشت خودم'],
    ];

    /** @return array<int,array<string,mixed>> open first (by due date), then done */
    public function forUser(int $userId, bool $includeDone = true): array
    {
        return $this->select(
            'SELECT * FROM study_marks WHERE user_id = :u' . ($includeDone ? '' : ' AND done_at IS NULL') . '
             ORDER BY done_at IS NOT NULL, done_at DESC, due_date IS NULL, due_date, created_at DESC
             LIMIT 300',
            ['u' => $userId]
        );
    }

    /** @return array{open:int, due:int} due = open with a date today or earlier */
    public function counts(int $userId): array
    {
        try {
            $row = $this->selectOne(
                'SELECT SUM(done_at IS NULL) AS open_count,
                        SUM(done_at IS NULL AND due_date IS NOT NULL AND due_date <= CURDATE()) AS due_count
                 FROM study_marks WHERE user_id = :u',
                ['u' => $userId]
            );
        } catch (\PDOException) {
            return ['open' => 0, 'due' => 0];
        }
        return ['open' => (int) ($row['open_count'] ?? 0), 'due' => (int) ($row['due_count'] ?? 0)];
    }

    public function add(int $userId, string $kind, ?int $refId, string $title, ?string $url, ?string $note, ?string $dueDate): int
    {
        $kind  = array_key_exists($kind, self::KINDS) ? $kind : 'custom';
        $title = mb_substr(trim($title), 0, 191);
        // Only a path on this site: "/…", never "//host/…", which a browser
        // would read as a link to another site.
        $url   = $url !== null && preg_match('~^/(?!/)[A-Za-z0-9/_\-?=&%.]*$~', $url) === 1 ? mb_substr($url, 0, 255) : null;
        $due   = $dueDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) === 1 ? $dueDate : null;

        if ($kind !== 'custom' && $refId !== null) {
            $existing = $this->selectOne(
                'SELECT id FROM study_marks WHERE user_id = :u AND kind = :k AND ref_id = :r LIMIT 1',
                ['u' => $userId, 'k' => $kind, 'r' => $refId]
            );
            if ($existing !== null) {
                $this->execute(
                    'UPDATE study_marks SET done_at = NULL, title = :t, url = :url, created_at = :now,
                            due_date = COALESCE(:due, due_date)
                     WHERE id = :id',
                    ['t' => $title, 'url' => $url, 'now' => $this->now(), 'due' => $due, 'id' => (int) $existing['id']]
                );
                return (int) $existing['id'];
            }
        }

        return $this->insert(
            'INSERT INTO study_marks (user_id, kind, ref_id, title, url, note, due_date, created_at)
             VALUES (:u, :k, :r, :t, :url, :note, :due, :now)',
            [
                'u' => $userId, 'k' => $kind, 'r' => $refId, 't' => $title, 'url' => $url,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
                'due' => $due, 'now' => $this->now(),
            ]
        );
    }

    public function has(int $userId, string $kind, int $refId): bool
    {
        try {
            return $this->selectOne(
                'SELECT 1 FROM study_marks WHERE user_id = :u AND kind = :k AND ref_id = :r AND done_at IS NULL LIMIT 1',
                ['u' => $userId, 'k' => $kind, 'r' => $refId]
            ) !== null;
        } catch (\PDOException) {
            return false;
        }
    }

    public function toggleDone(int $id, int $userId): void
    {
        $this->execute(
            'UPDATE study_marks SET done_at = CASE WHEN done_at IS NULL THEN :now ELSE NULL END
             WHERE id = :id AND user_id = :u',
            ['now' => $this->now(), 'id' => $id, 'u' => $userId]
        );
    }

    public function delete(int $id, int $userId): void
    {
        $this->execute('DELETE FROM study_marks WHERE id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]);
    }

    public function clearDone(int $userId): int
    {
        return $this->execute('DELETE FROM study_marks WHERE user_id = :u AND done_at IS NOT NULL', ['u' => $userId]);
    }
}
