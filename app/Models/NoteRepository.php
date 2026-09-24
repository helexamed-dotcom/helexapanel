<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * A student's notes and their attachments.
 *
 * Every read and write is scoped by user_id in the query itself, so another
 * student's uuid simply matches nothing.
 */
final class NoteRepository extends BaseRepository
{
    public const COLORS = ['yellow', 'green', 'blue', 'pink', 'purple', 'gray'];

    /** @param array{q?:string, scope?:string} $filters */
    public function listFor(int $userId, array $filters = [], int $limit = 60, int $offset = 0): array
    {
        [$where, $params] = $this->filters($userId, $filters);

        return $this->select(
            'SELECT n.id, n.uuid, n.title, n.color, n.ink_count, n.created_at, n.updated_at,
                    SUBSTRING(n.body, 1, 220) AS snippet,
                    cc.uuid AS content_uuid, cc.title AS content_title, c.title AS course_title,
                    (SELECT COUNT(*) FROM student_note_files f WHERE f.note_id = n.id) AS file_count
             FROM student_notes n
             LEFT JOIN course_contents cc ON cc.id = n.content_id
             LEFT JOIN courses c ON c.id = cc.course_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY COALESCE(n.updated_at, n.created_at) DESC
             LIMIT ' . max(1, min($limit, 100)) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function countFor(int $userId, array $filters = []): int
    {
        [$where, $params] = $this->filters($userId, $filters);

        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM student_notes n WHERE ' . implode(' AND ', $where),
            $params
        )['c'] ?? 0);
    }

    public function find(int $userId, string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT n.*, cc.uuid AS content_uuid, cc.title AS content_title
             FROM student_notes n
             LEFT JOIN course_contents cc ON cc.id = n.content_id
             WHERE n.uuid = :uuid AND n.user_id = :u AND n.deleted_at IS NULL LIMIT 1',
            ['uuid' => $uuid, 'u' => $userId]
        );
    }

    public function exists(string $uuid): bool
    {
        return $this->selectOne('SELECT id FROM student_notes WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]) !== null;
    }

    /** Pins for one lesson, for the frame. */
    public function pinsFor(int $userId, int $contentId): array
    {
        return array_map(static fn (array $r): array => [
            'uuid'  => $r['uuid'],
            'x'     => (int) $r['pos_x'],
            'y'     => (int) $r['pos_y'],
            'w'     => (int) $r['pos_w'],
            'color' => $r['color'],
            'title' => (string) ($r['title'] ?? ''),
        ], $this->select(
            'SELECT uuid, pos_x, pos_y, pos_w, color, title FROM student_notes
             WHERE user_id = :u AND content_id = :c AND deleted_at IS NULL AND pos_x IS NOT NULL
             ORDER BY id LIMIT 300',
            ['u' => $userId, 'c' => $contentId]
        ));
    }

    public function countForContent(int $userId, int $contentId): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(*) AS c FROM student_notes WHERE user_id = :u AND content_id = :c AND deleted_at IS NULL',
            ['u' => $userId, 'c' => $contentId]
        )['c'] ?? 0);
    }

    public function create(int $userId, string $uuid, array $data): int
    {
        return $this->insert(
            'INSERT INTO student_notes (uuid, user_id, content_id, pos_x, pos_y, pos_w, color, title, body, created_at, updated_at)
             VALUES (:uuid, :u, :content, :x, :y, :w, :color, :title, :body, :now1, :now2)',
            [
                'uuid'    => $uuid,
                'u'       => $userId,
                'content' => $data['content_id'] ?? null,
                'x'       => $data['pos_x'] ?? null,
                'y'       => $data['pos_y'] ?? null,
                'w'       => $data['pos_w'] ?? null,
                'color'   => in_array($data['color'] ?? '', self::COLORS, true) ? $data['color'] : 'yellow',
                'title'   => $data['title'] ?? null,
                'body'    => $data['body'] ?? null,
                'now1'    => $this->now(),
                'now2'    => $this->now(),
            ]
        );
    }

    /** Writes only the columns given; keys are whitelisted. */
    public function update(int $id, array $fields): void
    {
        $allowed = ['title', 'body', 'color', 'ink', 'ink_ratio', 'ink_count', 'pos_x', 'pos_y', 'pos_w'];
        $sets = [];
        $params = ['id' => $id, 'now' => $this->now()];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $fields)) {
                $sets[] = $column . ' = :' . $column;
                $params[$column] = $fields[$column];
            }
        }
        if ($sets === []) {
            return;
        }
        $this->execute(
            'UPDATE student_notes SET ' . implode(', ', $sets) . ', updated_at = :now WHERE id = :id',
            $params
        );
    }

    public function touch(int $id): void
    {
        $this->execute('UPDATE student_notes SET updated_at = :now WHERE id = :id', ['now' => $this->now(), 'id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->execute('UPDATE student_notes SET deleted_at = :now WHERE id = :id', ['now' => $this->now(), 'id' => $id]);
    }

    /* ------------------------------------------------------------- files */

    public function files(int $noteId): array
    {
        return $this->select(
            'SELECT uuid, file_name, file_mime, file_size, created_at,
                    (ink IS NOT NULL AND ink <> \'{}\') AS has_ink
             FROM student_note_files WHERE note_id = :n ORDER BY id',
            ['n' => $noteId]
        );
    }

    public function countFiles(int $noteId): int
    {
        return (int) ($this->selectOne('SELECT COUNT(*) AS c FROM student_note_files WHERE note_id = :n', ['n' => $noteId])['c'] ?? 0);
    }

    public function findFile(int $noteId, string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT * FROM student_note_files WHERE note_id = :n AND uuid = :uuid LIMIT 1',
            ['n' => $noteId, 'uuid' => $uuid]
        );
    }

    public function addFile(int $noteId, int $userId, string $uuid, array $stored): void
    {
        $this->insert(
            'INSERT INTO student_note_files (uuid, note_id, user_id, file_path, file_name, file_mime, file_size, created_at)
             VALUES (:uuid, :n, :u, :path, :name, :mime, :size, :now)',
            [
                'uuid' => $uuid, 'n' => $noteId, 'u' => $userId,
                'path' => $stored['path'], 'name' => $stored['name'],
                'mime' => $stored['mime'], 'size' => $stored['size'], 'now' => $this->now(),
            ]
        );
    }

    public function setFileInk(int $fileId, string $json): void
    {
        $this->execute(
            'UPDATE student_note_files SET ink = :ink, updated_at = :now WHERE id = :id',
            ['ink' => $json, 'now' => $this->now(), 'id' => $fileId]
        );
    }

    public function deleteFile(int $fileId): void
    {
        $this->execute('DELETE FROM student_note_files WHERE id = :id', ['id' => $fileId]);
    }

    /* ----------------------------------------------------------- helpers */

    /** @return array{0:array<int,string>,1:array<string,mixed>} */
    private function filters(int $userId, array $filters): array
    {
        $where  = ['n.user_id = :u', 'n.deleted_at IS NULL'];
        $params = ['u' => $userId];

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $where[] = '(n.title LIKE :q1 OR n.body LIKE :q2)';
            $params += ['q1' => $like, 'q2' => $like];
        }
        $scope = (string) ($filters['scope'] ?? '');
        if ($scope === 'lessons') {
            $where[] = 'n.content_id IS NOT NULL';
        } elseif ($scope === 'free') {
            $where[] = 'n.content_id IS NULL';
        }

        return [$where, $params];
    }
}