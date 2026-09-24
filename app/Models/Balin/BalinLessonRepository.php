<?php
declare(strict_types=1);

namespace HeleXa\Models\Balin;

use HeleXa\Models\BaseRepository;

/**
 * Clinical lessons — the top level of the island (قلب و عروق، عفونی، …).
 *
 * Ordering uses gaps of 1000 rather than 1, 2, 3. Dropping a lesson between
 * two others then rewrites one row instead of renumbering the whole list,
 * which matters once an admin is dragging things around in the builder.
 */
final class BalinLessonRepository extends BaseRepository
{
    public const ORDER_STEP = 1000;

    /** Admin listing: every lesson whatever its state, with its counts. */
    public function all(): array
    {
        return $this->select(
            'SELECT l.*,
                    (SELECT COUNT(*) FROM balin_stages s WHERE s.lesson_id = l.id) AS stage_count,
                    (SELECT COUNT(*) FROM balin_questions q WHERE q.lesson_id = l.id) AS question_count,
                    (SELECT COUNT(*) FROM balin_checkpoint_exams e WHERE e.lesson_id = l.id) AS exam_count
             FROM balin_lessons l
             ORDER BY l.display_order, l.id'
        );
    }

    /**
     * A lesson is open to a student when it is published and either
     *   - access_mode = 'open'    and no admin closed it for them, or
     *   - access_mode = 'granted' and they were given it.
     *
     * New lessons are created as 'granted', so nothing reaches a student until
     * someone says so; the lessons that existed before this rule stay 'open'.
     */
    private const OPEN_TO_STUDENT =
        "((l.access_mode = 'open'
            AND NOT EXISTS (SELECT 1 FROM balin_lesson_blocks b
                             WHERE b.lesson_id = l.id AND b.user_id = %s))
          OR (l.access_mode = 'granted'
            AND EXISTS (SELECT 1 FROM balin_lesson_grants g
                         WHERE g.lesson_id = l.id AND g.user_id = %s)))";

    /** Student listing: published lessons only, with this student's progress. */
    public function publishedForStudent(int $userId): array
    {
        return $this->select(
            // The student id is bound once per subquery under its own name:
            // prepared statements bind by position, so a repeated name would
            // be rejected outright.
            "SELECT l.id, l.uuid, l.slug, l.title, l.description, l.cover_path, l.icon, l.color,
                    l.estimated_minutes, l.xp_reward, l.display_order,
                    (SELECT COUNT(*) FROM balin_stages s
                      WHERE s.lesson_id = l.id AND s.status = 'published') AS stage_count,
                    (SELECT COUNT(*) FROM balin_student_progress p
                       JOIN balin_stages s2 ON s2.id = p.stage_id AND s2.status = 'published'
                      WHERE p.lesson_id = l.id AND p.user_id = :progress_user
                        AND p.status = 'completed') AS completed_count,
                    COALESCE((SELECT m.mastery_percent FROM balin_student_lesson_mastery m
                               WHERE m.lesson_id = l.id AND m.user_id = :mastery_user), 0) AS mastery_percent
             FROM balin_lessons l
             WHERE l.status = 'published'
               AND " . sprintf(self::OPEN_TO_STUDENT, ':block_user', ':grant_user') . "
             ORDER BY l.display_order, l.id",
            ['progress_user' => $userId, 'mastery_user' => $userId,
             'block_user'    => $userId, 'grant_user'  => $userId]
        );
    }

    /**
     * Whether an admin has closed this lesson for this one student.
     *
     * Checked by every student route that serves a lesson, a stage or an
     * exam, not only by the island listing — a hidden card is not a closed
     * door.
     */
    public function isBlockedFor(int $userId, int $lessonId): bool
    {
        // "Blocked" now means "not open to them", whichever of the two ways a
        // lesson is governed: every route that guards a lesson asks this one
        // question, so both rules have to live behind it.
        return $this->selectOne(
            'SELECT 1 FROM balin_lessons l
             WHERE l.id = :lesson AND ' . sprintf(self::OPEN_TO_STUDENT, ':block_user', ':grant_user') . ' LIMIT 1',
            ['lesson' => $lessonId, 'block_user' => $userId, 'grant_user' => $userId]
        ) === null;
    }

    /** @return array<int,int> lesson ids closed for this student */
    public function blockedIdsFor(int $userId): array
    {
        $rows = $this->select('SELECT lesson_id FROM balin_lesson_blocks WHERE user_id = :u', ['u' => $userId]);
        return array_map(static fn (array $r): int => (int) $r['lesson_id'], $rows);
    }

    /* ------------------------------------------------- per-student access */

    /** @return array<int,int> lesson ids this student was granted one by one */
    public function grantedIdsFor(int $userId): array
    {
        $rows = $this->select('SELECT lesson_id FROM balin_lesson_grants WHERE user_id = :u', ['u' => $userId]);
        return array_map(static fn (array $r): int => (int) $r['lesson_id'], $rows);
    }

    /** @return array<int,int> every lesson id, published or not, this student may open */
    public function openIdsFor(int $userId): array
    {
        $rows = $this->select(
            'SELECT l.id FROM balin_lessons l
             WHERE ' . sprintf(self::OPEN_TO_STUDENT, ':block_user', ':grant_user'),
            ['block_user' => $userId, 'grant_user' => $userId]
        );
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Writes the set of lessons a student may open, whichever rule each one
     * follows: an 'open' lesson is closed by adding a block, a 'granted' one
     * is opened by adding a grant. The admin ticks one box either way.
     *
     * @param array<int,int> $openIds
     */
    public function setOpenLessons(int $userId, array $openIds, ?int $adminId): void
    {
        $wanted = array_values(array_unique(array_map('intval', $openIds)));
        $modes  = $this->select('SELECT id, access_mode FROM balin_lessons');

        $blocks = [];
        $grants = [];
        foreach ($modes as $row) {
            $id   = (int) $row['id'];
            $open = in_array($id, $wanted, true);
            if ((string) $row['access_mode'] === 'granted') {
                if ($open) {
                    $grants[] = $id;
                }
            } elseif (!$open) {
                $blocks[] = $id;
            }
        }

        $this->syncBlocks($userId, $blocks, $adminId);
        $this->syncGrants($userId, $grants, $adminId);
    }

    /** @param array<int,int> $lessonIds replaces this student's one-by-one grants */
    public function syncGrants(int $userId, array $lessonIds, ?int $adminId): void
    {
        $wanted  = array_values(array_unique(array_map('intval', $lessonIds)));
        $current = $this->grantedIdsFor($userId);

        $this->grant($userId, array_diff($wanted, $current), $adminId);
        foreach (array_diff($current, $wanted) as $lessonId) {
            $this->execute('DELETE FROM balin_lesson_grants WHERE user_id = :u AND lesson_id = :l',
                ['u' => $userId, 'l' => $lessonId]);
        }
    }

    /**
     * Adds grants without taking any away — what a package activation does.
     *
     * @param iterable<int> $lessonIds
     */
    public function grant(int $userId, iterable $lessonIds, ?int $adminId): int
    {
        $added = 0;
        foreach ($lessonIds as $lessonId) {
            $added += $this->execute(
                'INSERT IGNORE INTO balin_lesson_grants (user_id, lesson_id, granted_by, created_at)
                 VALUES (:u, :l, :a, :now)',
                ['u' => $userId, 'l' => (int) $lessonId, 'a' => $adminId, 'now' => $this->now()]
            );
        }
        return $added;
    }

    /** @param iterable<int> $lessonIds */
    public function revokeGrants(int $userId, iterable $lessonIds): void
    {
        foreach ($lessonIds as $lessonId) {
            $this->execute('DELETE FROM balin_lesson_grants WHERE user_id = :u AND lesson_id = :l',
                ['u' => $userId, 'l' => (int) $lessonId]);
        }
    }

    /**
     * Replaces the set of lessons closed for a student.
     *
     * @param array<int,int> $lessonIds
     */
    public function syncBlocks(int $userId, array $lessonIds, ?int $adminId): void
    {
        $wanted  = array_values(array_unique(array_map('intval', $lessonIds)));
        $current = $this->blockedIdsFor($userId);

        foreach (array_diff($wanted, $current) as $lessonId) {
            $this->execute(
                'INSERT IGNORE INTO balin_lesson_blocks (user_id, lesson_id, blocked_by, created_at)
                 VALUES (:u, :l, :a, :now)',
                ['u' => $userId, 'l' => $lessonId, 'a' => $adminId, 'now' => $this->now()]
            );
        }
        foreach (array_diff($current, $wanted) as $lessonId) {
            $this->execute('DELETE FROM balin_lesson_blocks WHERE user_id = :u AND lesson_id = :l',
                ['u' => $userId, 'l' => $lessonId]);
        }
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM balin_lessons WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM balin_lessons WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM balin_lessons WHERE slug = :slug';
        $params = ['slug' => $slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }
        return $this->selectOne($sql . ' LIMIT 1', $params) !== null;
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO balin_lessons
                (uuid, slug, title, description, cover_path, icon, color, display_order, status, access_mode,
                 estimated_minutes, xp_reward, extra_notes, created_by, created_at)
             VALUES (:uuid, :slug, :title, :description, :cover, :icon, :color, :order, :status, :access,
                     :minutes, :xp, :notes, :by, :now)',
            [
                // A new lesson reaches nobody until it is given out, however
                // it was made — the builder, an import, anything.
                'access'      => ($data['access_mode'] ?? '') === 'open' ? 'open' : 'granted',
                'uuid'        => $data['uuid'],
                'slug'        => $data['slug'],
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'cover'       => $data['cover_path'] ?? null,
                'icon'        => $data['icon'] ?? null,
                'color'       => $data['color'] ?? null,
                'order'       => $data['display_order'] ?? $this->nextOrder(),
                'status'      => $data['status'] ?? 'draft',
                'minutes'     => $data['estimated_minutes'] ?? 0,
                'xp'          => $data['xp_reward'] ?? 0,
                'notes'       => $data['extra_notes'] ?? null,
                'by'          => $data['created_by'] ?? null,
                'now'         => $this->now(),
            ]
        );
    }

    /**
     * Saves only when the version the editor loaded is still current.
     * Returns false when another admin saved first, so the caller can say so
     * instead of overwriting work that was never seen.
     */
    public function update(int $id, array $data, int $expectedVersion): bool
    {
        return $this->execute(
            'UPDATE balin_lessons
             SET slug = :slug, title = :title, description = :description, cover_path = :cover,
                 icon = :icon, color = :color, estimated_minutes = :minutes, xp_reward = :xp,
                 extra_notes = :notes, display_order = :order, access_mode = :access,
                 version = version + 1, content_version = content_version + 1, updated_at = :now
             WHERE id = :id AND version = :version',
            [
                'access'      => ($data['access_mode'] ?? '') === 'open' ? 'open' : 'granted',
                'slug'        => $data['slug'],
                'title'       => $data['title'],
                'description' => $data['description'] ?? null,
                'cover'       => $data['cover_path'] ?? null,
                'icon'        => $data['icon'] ?? null,
                'color'       => $data['color'] ?? null,
                'minutes'     => $data['estimated_minutes'] ?? 0,
                'xp'          => $data['xp_reward'] ?? 0,
                'notes'       => $data['extra_notes'] ?? null,
                'order'       => $data['display_order'] ?? self::ORDER_STEP,
                'id'          => $id,
                'version'     => $expectedVersion,
                'now'         => $this->now(),
            ]
        ) > 0;
    }

    public function setStatus(int $id, string $status): int
    {
        return $this->execute(
            'UPDATE balin_lessons
             SET status = :status,
                 published_at = CASE WHEN :status_check = \'published\' AND published_at IS NULL
                                     THEN :published_at ELSE published_at END,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'status'       => $status,
                'status_check' => $status,
                'published_at' => $this->now(),
                'updated_at'   => $this->now(),
                'id'           => $id,
            ]
        );
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM balin_lessons WHERE id = :id', ['id' => $id]);
    }

    public function nextOrder(): int
    {
        $max = (int) ($this->selectOne('SELECT MAX(display_order) AS m FROM balin_lessons')['m'] ?? 0);
        return $max + self::ORDER_STEP;
    }

    /** How many students already finished a stage of this lesson, for the edit warning. */
    public function studentsWithProgress(int $lessonId): int
    {
        return (int) ($this->selectOne(
            'SELECT COUNT(DISTINCT user_id) AS c FROM balin_student_progress
             WHERE lesson_id = :lesson AND status = \'completed\'',
            ['lesson' => $lessonId]
        )['c'] ?? 0);
    }
}
