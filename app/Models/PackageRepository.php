<?php
declare(strict_types=1);

namespace HeleXa\Models;

final class PackageRepository extends BaseRepository
{
    /** What a package can carry besides courses. */
    public const ITEM_TYPES = ['qbank_subject', 'balin_lesson', 'flashcard_course'];

    public function all(): array
    {
        return $this->select(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM package_courses pc WHERE pc.package_id = p.id) AS course_count,
                    (SELECT COUNT(*) FROM package_items pi WHERE pi.package_id = p.id) AS item_count,
                    (SELECT COUNT(*) FROM package_activations pa
                      WHERE pa.package_id = p.id AND pa.status = "active") AS member_count
             FROM packages p
             WHERE p.deleted_at IS NULL
             ORDER BY p.sort_order, p.id'
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT * FROM packages WHERE uuid = :uuid AND deleted_at IS NULL LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne(
            'SELECT * FROM packages WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $id]
        );
    }

    public function create(array $data): int
    {
        return $this->insert(
            'INSERT INTO packages (uuid, title, description, color, status, auto_grant_new_courses,
                                   is_full_access, is_free, sort_order, created_by, created_at)
             VALUES (:uuid, :title, :description, :color, :status, :auto_grant,
                     :full, :free, :sort_order, :created_by, :now)',
            [
                'uuid'        => $data['uuid'],
                'title'       => $data['title'],
                'description' => $data['description'] ?: null,
                'color'       => $data['color'],
                'status'      => $data['status'],
                'auto_grant'  => (int) $data['auto_grant_new_courses'],
                'full'        => (int) ($data['is_full_access'] ?? 0),
                'free'        => (int) ($data['is_free'] ?? 0),
                'sort_order'  => (int) $data['sort_order'],
                'created_by'  => $data['created_by'],
                'now'         => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE packages SET title = :title, description = :description, color = :color,
                    status = :status, auto_grant_new_courses = :auto_grant, is_full_access = :full, is_free = :free,
                    sort_order = :sort_order, updated_at = :now
             WHERE id = :id AND deleted_at IS NULL',
            [
                'title'       => $data['title'],
                'description' => $data['description'] ?: null,
                'color'       => $data['color'],
                'status'      => $data['status'],
                'auto_grant'  => (int) $data['auto_grant_new_courses'],
                'full'        => (int) ($data['is_full_access'] ?? 0),
                'free'        => (int) ($data['is_free'] ?? 0),
                'sort_order'  => (int) $data['sort_order'],
                'now'         => $this->now(),
                'id'          => $id,
            ]
        );
    }

    public function softDelete(int $id): void
    {
        $this->execute(
            'UPDATE packages SET deleted_at = :now, status = \'archived\' WHERE id = :id AND deleted_at IS NULL',
            ['now' => $this->now(), 'id' => $id]
        );
    }

    /* -------------------------------------------------------- contents */

    public function courses(int $packageId): array
    {
        return $this->select(
            'SELECT c.*, pc.added_at
             FROM package_courses pc JOIN courses c ON c.id = pc.course_id
             WHERE pc.package_id = :package AND c.deleted_at IS NULL
             ORDER BY pc.sort_order, c.sort_order, c.id',
            ['package' => $packageId]
        );
    }

    /** @return array<int,int> */
    public function courseIds(int $packageId): array
    {
        return array_map('intval', array_column(
            $this->select('SELECT course_id FROM package_courses WHERE package_id = :package', ['package' => $packageId]),
            'course_id'
        ));
    }

    public function addCourse(int $packageId, int $courseId): bool
    {
        return $this->execute(
            'INSERT IGNORE INTO package_courses (package_id, course_id, sort_order, added_at)
             VALUES (:package, :course, 0, :now)',
            ['package' => $packageId, 'course' => $courseId, 'now' => $this->now()]
        ) > 0;
    }

    public function removeCourse(int $packageId, int $courseId): void
    {
        $this->execute(
            'DELETE FROM package_courses WHERE package_id = :package AND course_id = :course',
            ['package' => $packageId, 'course' => $courseId]
        );
    }

    /* -------------------------------------- items beyond courses */

    /** @return array<int,int> item ids of one kind inside this package */
    public function itemIds(int $packageId, string $type): array
    {
        if (!in_array($type, self::ITEM_TYPES, true)) {
            return [];
        }
        return array_map('intval', array_column(
            $this->select(
                'SELECT item_id FROM package_items
                 WHERE package_id = :package AND item_type = :type ORDER BY sort_order, item_id',
                ['package' => $packageId, 'type' => $type]
            ),
            'item_id'
        ));
    }

    /** @return array<string,array<int,int>> every kind at once, keyed by type */
    public function itemsByType(int $packageId): array
    {
        $out = array_fill_keys(self::ITEM_TYPES, []);
        foreach ($this->select(
            'SELECT item_type, item_id FROM package_items WHERE package_id = :package ORDER BY sort_order, item_id',
            ['package' => $packageId]
        ) as $row) {
            $type = (string) $row['item_type'];
            if (isset($out[$type])) {
                $out[$type][] = (int) $row['item_id'];
            }
        }
        return $out;
    }

    /** Replaces the chosen items of one kind. @param array<int,int> $ids */
    public function syncItems(int $packageId, string $type, array $ids): void
    {
        if (!in_array($type, self::ITEM_TYPES, true)) {
            return;
        }
        $wanted  = array_values(array_unique(array_map('intval', $ids)));
        $current = $this->itemIds($packageId, $type);

        foreach (array_diff($wanted, $current) as $itemId) {
            $this->execute(
                'INSERT IGNORE INTO package_items (package_id, item_type, item_id, sort_order, added_at)
                 VALUES (:package, :type, :item, 0, :now)',
                ['package' => $packageId, 'type' => $type, 'item' => $itemId, 'now' => $this->now()]
            );
        }
        foreach (array_diff($current, $wanted) as $itemId) {
            $this->execute(
                'DELETE FROM package_items WHERE package_id = :package AND item_type = :type AND item_id = :item',
                ['package' => $packageId, 'type' => $type, 'item' => $itemId]
            );
        }
    }

    /* ------------------------------------------------------ free packages */

    /** Published packages every student receives. */
    public function freePackages(): array
    {
        return $this->select(
            "SELECT * FROM packages
             WHERE is_free = 1 AND deleted_at IS NULL AND status = 'published'
             ORDER BY sort_order, id"
        );
    }

    /* ------------------------------------------- the full-access package */

    /** The live "everything" activation a student holds, if any. */
    public function fullAccessFor(int $userId): ?array
    {
        return $this->selectOne(
            'SELECT pa.*, p.title AS package_title, p.uuid AS package_uuid
             FROM package_activations pa
             JOIN packages p ON p.id = pa.package_id AND p.deleted_at IS NULL
             WHERE pa.user_id = :user AND p.is_full_access = 1 AND pa.status = \'active\'
               AND (pa.starts_at IS NULL OR pa.starts_at <= :now1)
               AND (pa.ends_at   IS NULL OR pa.ends_at   >= :now2)
             ORDER BY pa.ends_at IS NULL DESC, pa.ends_at DESC
             LIMIT 1',
            ['user' => $userId, 'now1' => $this->now(), 'now2' => $this->now()]
        );
    }

    /** @return array<int,int> every student holding a live full-access package */
    public function fullAccessHolderIds(): array
    {
        return array_map('intval', array_column(
            $this->select(
                'SELECT DISTINCT pa.user_id
                 FROM package_activations pa
                 JOIN packages p ON p.id = pa.package_id AND p.deleted_at IS NULL
                 WHERE p.is_full_access = 1 AND pa.status = \'active\'
                   AND (pa.starts_at IS NULL OR pa.starts_at <= :now1)
                   AND (pa.ends_at   IS NULL OR pa.ends_at   >= :now2)',
                ['now1' => $this->now(), 'now2' => $this->now()]
            ),
            'user_id'
        ));
    }

    /**
     * Whether some *other* live package of this student also hands over this
     * item. Suspending one package must not take away what a second one gives.
     */
    public function itemHeldElsewhere(int $userId, int $exceptPackageId, string $type, int $itemId): bool
    {
        return $this->selectOne(
            'SELECT 1
             FROM package_activations pa
             JOIN packages p ON p.id = pa.package_id AND p.deleted_at IS NULL
             LEFT JOIN package_items pi ON pi.package_id = p.id AND pi.item_type = :type AND pi.item_id = :item
             WHERE pa.user_id = :user AND pa.package_id <> :except AND pa.status = \'active\'
               AND (pa.starts_at IS NULL OR pa.starts_at <= :now1)
               AND (pa.ends_at   IS NULL OR pa.ends_at   >= :now2)
               AND (p.is_full_access = 1 OR pi.item_id IS NOT NULL)
             LIMIT 1',
            [
                'user' => $userId, 'except' => $exceptPackageId, 'type' => $type, 'item' => $itemId,
                'now1' => $this->now(), 'now2' => $this->now(),
            ]
        ) !== null;
    }

    /* ----------------------------------------------------- activations */

    public function activation(int $userId, int $packageId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM package_activations WHERE user_id = :user AND package_id = :package LIMIT 1',
            ['user' => $userId, 'package' => $packageId]
        );
    }

    /**
     * Creates or refreshes an activation and returns its row.
     * The unique key on (user_id, package_id) makes a repeated click update the
     * same activation rather than creating a second one.
     */
    public function upsertActivation(array $data): array
    {
        $existing = $this->activation((int) $data['user_id'], (int) $data['package_id']);

        if ($existing !== null) {
            $this->execute(
                'UPDATE package_activations
                 SET status = :status, starts_at = :starts, ends_at = :ends,
                     course_count = :count, activated_by = :by, updated_at = :now
                 WHERE id = :id',
                [
                    'status' => $data['status'],
                    'starts' => $data['starts_at'],
                    'ends'   => $data['ends_at'],
                    'count'  => (int) $data['course_count'],
                    'by'     => $data['activated_by'],
                    'now'    => $this->now(),
                    'id'     => (int) $existing['id'],
                ]
            );
            return $this->activation((int) $data['user_id'], (int) $data['package_id']) ?? $existing;
        }

        $this->insert(
            'INSERT INTO package_activations
                (uuid, user_id, package_id, status, starts_at, ends_at, course_count, activated_by, created_at)
             VALUES (:uuid, :user, :package, :status, :starts, :ends, :count, :by, :now)',
            [
                'uuid'    => $data['uuid'],
                'user'    => $data['user_id'],
                'package' => $data['package_id'],
                'status'  => $data['status'],
                'starts'  => $data['starts_at'],
                'ends'    => $data['ends_at'],
                'count'   => (int) $data['course_count'],
                'by'      => $data['activated_by'],
                'now'     => $this->now(),
            ]
        );

        return $this->activation((int) $data['user_id'], (int) $data['package_id']) ?? [];
    }

    public function members(int $packageId): array
    {
        return $this->select(
            'SELECT pa.*, u.uuid AS user_uuid, u.full_name, u.username
             FROM package_activations pa JOIN users u ON u.id = pa.user_id
             WHERE pa.package_id = :package AND u.deleted_at IS NULL
             ORDER BY pa.created_at DESC',
            ['package' => $packageId]
        );
    }

    /** @return array<int,int> user ids holding this package right now */
    public function activeMemberIds(int $packageId): array
    {
        return array_map('intval', array_column(
            $this->select(
                'SELECT user_id FROM package_activations
                 WHERE package_id = :package AND status = \'active\'
                   AND (ends_at IS NULL OR ends_at >= :now)',
                ['package' => $packageId, 'now' => $this->now()]
            ),
            'user_id'
        ));
    }

    public function setActivationStatus(int $id, string $status): void
    {
        $this->execute(
            'UPDATE package_activations SET status = :status, updated_at = :now WHERE id = :id',
            ['status' => $status, 'now' => $this->now(), 'id' => $id]
        );
    }

    public function findActivation(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM package_activations WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    /** Packages a student currently holds, for their own courses page. */
    /** Every activation a student has, in any state — for the admin. */
    public function activationsForStudent(int $userId): array
    {
        return $this->select(
            'SELECT pa.*, p.uuid AS package_uuid, p.title AS package_title,
                    (pa.status = \'active\'
                      AND (pa.starts_at IS NULL OR pa.starts_at <= :now1)
                      AND (pa.ends_at   IS NULL OR pa.ends_at   >= :now2)) AS is_live
             FROM package_activations pa
             JOIN packages p ON p.id = pa.package_id AND p.deleted_at IS NULL
             WHERE pa.user_id = :user
             ORDER BY p.sort_order, p.title',
            ['user' => $userId, 'now1' => $this->now(), 'now2' => $this->now()]
        );
    }

    public function forStudent(int $userId): array
    {
        return $this->select(
            'SELECT p.uuid, p.title, p.color, pa.status, pa.ends_at, pa.course_count
             FROM package_activations pa JOIN packages p ON p.id = pa.package_id
             WHERE pa.user_id = :user AND pa.status = \'active\' AND p.deleted_at IS NULL
               AND (pa.starts_at IS NULL OR pa.starts_at <= :now1)
               AND (pa.ends_at   IS NULL OR pa.ends_at   >= :now2)
             ORDER BY p.sort_order, p.id',
            ['user' => $userId, 'now1' => $this->now(), 'now2' => $this->now()]
        );
    }
}
