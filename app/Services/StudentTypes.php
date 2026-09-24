<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;

/**
 * نوع دانشجو: ترمی، علوم پایه، دستیاری … — defined by the admin, requested by
 * the student, approved by the admin. The type decides which sections of the
 * site the student sees (see Modules).
 *
 * Every read is guarded: before the migration runs there are no types, every
 * student counts as "no type" and every section stays on.
 */
final class StudentTypes
{
    public const COLORS = ['blue', 'violet', 'teal', 'amber', 'rose', 'green', 'sky', 'orange', 'indigo', 'pink', 'slate', 'red'];

    /** @var array<int,int> user id => type id */
    private static array $typeOf = [];

    public static function ready(): bool
    {
        try {
            Database::selectOne('SELECT 1 FROM student_types LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** The approved type of this user, or 0. */
    public static function typeIdOf(array $user): int
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            return 0;
        }
        if (array_key_exists('student_type_id', $user)) {
            return (int) $user['student_type_id'];
        }
        if (!isset(self::$typeOf[$id])) {
            try {
                $row = Database::selectOne('SELECT student_type_id FROM users WHERE id = :id', ['id' => $id]);
                self::$typeOf[$id] = (int) ($row['student_type_id'] ?? 0);
            } catch (\PDOException) {
                self::$typeOf[$id] = 0;
            }
        }
        return self::$typeOf[$id];
    }

    /** @return list<array> */
    public static function all(bool $activeOnly = false): array
    {
        try {
            $rows = Database::select(
                'SELECT t.*, (SELECT COUNT(*) FROM users u WHERE u.student_type_id = t.id AND u.deleted_at IS NULL) AS members
                 FROM student_types t' . ($activeOnly ? ' WHERE t.is_active = 1' : '') . '
                 ORDER BY t.sort_order, t.id'
            );
        } catch (\PDOException) {
            return [];
        }
        foreach ($rows as &$row) {
            $row['modules_list'] = self::decodeModules($row['modules'] ?? '[]');
        }
        return $rows;
    }

    public static function find(int $id): ?array
    {
        try {
            $row = Database::selectOne('SELECT * FROM student_types WHERE id = :id', ['id' => $id]);
        } catch (\PDOException) {
            return null;
        }
        if ($row !== null) {
            $row['modules_list'] = self::decodeModules($row['modules'] ?? '[]');
        }
        return $row;
    }

    /** The student's current type (approved) and latest request. */
    public static function stateFor(int $userId): array
    {
        $state = ['type' => null, 'request' => null];
        try {
            $user = Database::selectOne('SELECT student_type_id FROM users WHERE id = :id', ['id' => $userId]);
            if (!empty($user['student_type_id'])) {
                $state['type'] = self::find((int) $user['student_type_id']);
            }
            $state['request'] = Database::selectOne(
                'SELECT r.*, t.title AS type_title FROM student_type_requests r
                 JOIN student_types t ON t.id = r.type_id
                 WHERE r.user_id = :u ORDER BY r.id DESC LIMIT 1',
                ['u' => $userId]
            );
        } catch (\PDOException) {
            // not migrated
        }
        return $state;
    }

    /**
     * A student asks to be a type. A type that needs no approval is applied at
     * once; otherwise the request waits for an admin. An earlier pending
     * request is replaced, never stacked.
     */
    public static function request(int $userId, int $typeId, string $note): string
    {
        $type = self::find($typeId);
        if ($type === null || (int) $type['is_active'] !== 1) {
            return 'invalid';
        }

        Database::execute(
            "UPDATE student_type_requests SET status = 'rejected', admin_note = 'جایگزین با درخواست جدید', handled_at = NOW()
             WHERE user_id = :u AND status = 'pending'",
            ['u' => $userId]
        );

        $auto = (int) $type['requires_approval'] === 0;
        Database::insert(
            'INSERT INTO student_type_requests (user_id, type_id, note, status, handled_at, created_at)
             VALUES (:u, :t, :n, :s, :h, NOW())',
            ['u' => $userId, 't' => $typeId, 'n' => mb_substr($note, 0, 500),
             's' => $auto ? 'approved' : 'pending', 'h' => $auto ? date('Y-m-d H:i:s') : null]
        );
        if ($auto) {
            self::assign($userId, $typeId);
            return 'approved';
        }
        return 'pending';
    }

    public static function assign(int $userId, ?int $typeId): void
    {
        Database::execute('UPDATE users SET student_type_id = :t WHERE id = :u', ['t' => $typeId, 'u' => $userId]);
        self::$typeOf[$userId] = (int) $typeId;
        Modules::flush();
    }

    public static function pendingCount(): int
    {
        try {
            return (int) (Database::selectOne("SELECT COUNT(*) AS c FROM student_type_requests WHERE status = 'pending'")['c'] ?? 0);
        } catch (\PDOException) {
            return 0;
        }
    }

    /** @return list<string> */
    public static function decodeModules(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $list), static fn (string $k): bool => Modules::exists($k)));
    }
}
