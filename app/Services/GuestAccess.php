<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Core\HttpException;

/**
 * The only door into content for someone with no account.
 *
 * It is deliberately a separate class from ContentAccess rather than a flag
 * inside it. ContentAccess answers "may this signed-in student read this?" and
 * every branch of it assumes a user exists. Bolting a guest case onto it would
 * put an anonymous code path inside the function that guards the whole library,
 * which is exactly where a mistake would be most expensive.
 *
 * Here the query itself is the guard: a row is returned only when the lesson is
 * ticked for guests, published, and belongs to a published course. There is no
 * enrolment, no session and no way to widen the result with a parameter.
 */
final class GuestAccess
{
    public static function isEnabled(): bool
    {
        return Settings::bool('guest_mode_enabled', true);
    }

    /**
     * @throws HttpException when guest mode is off or the lesson is not public
     */
    public static function content(string $uuid): array
    {
        if (!self::isEnabled()) {
            throw HttpException::notFound();
        }

        $content = Database::selectOne(
            "SELECT cc.id, cc.uuid, cc.title, cc.description, cc.content_type, cc.storage_kind,
                    cc.storage_path, cc.checksum, cc.byte_size, cc.is_printable,
                    c.title AS course_title, c.uuid AS course_uuid
             FROM course_contents cc
             JOIN courses c ON c.id = cc.course_id
             WHERE cc.uuid = :uuid
               AND cc.guest_visible = 1
               AND cc.status = 'published'  AND cc.deleted_at IS NULL
               AND c.status  = 'published'  AND c.deleted_at  IS NULL
             LIMIT 1",
            ['uuid' => $uuid]
        );

        if ($content === null) {
            // Same answer whether the lesson does not exist or is simply not
            // public: a 403 here would confirm which UUIDs are real.
            throw HttpException::notFound();
        }

        return $content;
    }

    /** Everything a visitor may browse, grouped by course. */
    public static function catalogue(): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        $rows = Database::select(
            "SELECT cc.uuid, cc.title, cc.description, cc.content_type, cc.byte_size,
                    c.uuid AS course_uuid, c.title AS course_title, c.color, c.sort_order
             FROM course_contents cc
             JOIN courses c ON c.id = cc.course_id
             WHERE cc.guest_visible = 1
               AND cc.status = 'published'  AND cc.deleted_at IS NULL
               AND c.status  = 'published'  AND c.deleted_at  IS NULL
             ORDER BY c.sort_order, c.id, cc.sort_order, cc.id
             LIMIT 200"
        );

        $byCourse = [];
        foreach ($rows as $row) {
            $key = (string) $row['course_uuid'];
            if (!isset($byCourse[$key])) {
                $byCourse[$key] = [
                    'uuid'    => $row['course_uuid'],
                    'title'   => $row['course_title'],
                    'color'   => $row['color'],
                    'lessons' => [],
                ];
            }
            $byCourse[$key]['lessons'][] = $row;
        }

        return array_values($byCourse);
    }

    public static function count(): int
    {
        return (int) (Database::selectOne(
            "SELECT COUNT(*) AS c FROM course_contents cc JOIN courses c ON c.id = cc.course_id
             WHERE cc.guest_visible = 1 AND cc.status = 'published' AND cc.deleted_at IS NULL
               AND c.status = 'published' AND c.deleted_at IS NULL"
        )['c'] ?? 0);
    }
}
