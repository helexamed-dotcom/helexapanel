<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Core\Logger;

/**
 * برنزی / نقره‌ای / طلایی — a student's tier, derived from what they hold.
 *
 *   gold    holds a course, a package, Balin island and the question bank
 *   silver  holds at least one package
 *   bronze  holds at least one course
 *   none    holds nothing yet
 *
 * Never stored. A stored tier is a second copy of the access tables that has
 * to be kept in step with every grant, revoke, expiry and suspension — and the
 * day one path forgets, the badge lies. Four indexed EXISTS checks are cheap,
 * and the list page asks for many students in four queries, not four each.
 */
final class StudentTier
{
    public const NONE   = 'none';
    public const BRONZE = 'bronze';
    public const SILVER = 'silver';
    public const GOLD   = 'gold';

    public const LABELS = [
        self::NONE   => 'بدون اشتراک',
        self::BRONZE => 'کاربر برنزی',
        self::SILVER => 'کاربر نقره‌ای',
        self::GOLD   => 'کاربر طلایی',
    ];

    public const ICONS = [
        self::NONE   => '○',
        self::BRONZE => '🥉',
        self::SILVER => '🥈',
        self::GOLD   => '🥇',
    ];

    /** Pure rule, so it can be tested without a database. */
    public static function decide(bool $courses, bool $packages, bool $balin, bool $qbank): string
    {
        return match (true) {
            $courses && $packages && $balin && $qbank => self::GOLD,
            $packages                                 => self::SILVER,
            $courses                                  => self::BRONZE,
            default                                   => self::NONE,
        };
    }

    /**
     * @return array{tier:string, label:string, icon:string,
     *               has:array{courses:bool, packages:bool, balin:bool, qbank:bool}}
     */
    public static function forStudent(int $userId): array
    {
        return self::forMany([$userId])[$userId];
    }

    /**
     * @param  array<int,int> $userIds
     * @return array<int,array{tier:string, label:string, icon:string, has:array<string,bool>}>
     */
    public static function forMany(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $i): bool => $i > 0)));
        $out = [];
        if ($ids === []) {
            return $out;
        }

        $list = implode(',', $ids);
        $now  = date('Y-m-d H:i:s');

        // Same window rules the viewer enforces: active, started, not ended.
        $courses = self::idSet(
            "SELECT DISTINCT sc.user_id FROM student_courses sc
               JOIN courses c ON c.id = sc.course_id AND c.status = 'published' AND c.deleted_at IS NULL
              WHERE sc.user_id IN ({$list}) AND sc.status = 'active'
                AND (sc.starts_at IS NULL OR sc.starts_at <= :n1)
                AND (sc.ends_at   IS NULL OR sc.ends_at   >= :n2)",
            ['n1' => $now, 'n2' => $now]
        );
        $packages = self::idSet(
            "SELECT DISTINCT pa.user_id FROM package_activations pa
               JOIN packages p ON p.id = pa.package_id AND p.deleted_at IS NULL
              WHERE pa.user_id IN ({$list}) AND pa.status = 'active'
                AND (pa.starts_at IS NULL OR pa.starts_at <= :n1)
                AND (pa.ends_at   IS NULL OR pa.ends_at   >= :n2)",
            ['n1' => $now, 'n2' => $now]
        );
        $balin = self::idSet(
            "SELECT user_id FROM balin_student_access WHERE user_id IN ({$list}) AND is_enabled = 1"
        );
        $qbank = self::idSet(
            "SELECT DISTINCT a.user_id FROM qb_student_access a
               JOIN qb_subjects s ON s.id = a.subject_id AND s.is_active = 1
              WHERE a.user_id IN ({$list})"
        );

        foreach ($ids as $id) {
            $has = [
                'courses'  => isset($courses[$id]),
                'packages' => isset($packages[$id]),
                'balin'    => isset($balin[$id]),
                'qbank'    => isset($qbank[$id]),
            ];
            $tier = self::decide($has['courses'], $has['packages'], $has['balin'], $has['qbank']);
            $out[$id] = ['tier' => $tier, 'label' => self::LABELS[$tier], 'icon' => self::ICONS[$tier], 'has' => $has];
        }

        return $out;
    }

    /**
     * Runs one membership query. A module whose migration has not been run
     * yet counts as "not held" instead of taking the student list down with
     * it — the tier is a badge, not a reason for a page to fail.
     *
     * @return array<int,true>
     */
    private static function idSet(string $sql, array $params = []): array
    {
        try {
            $rows = Database::select($sql, $params);
        } catch (\PDOException $e) {
            Logger::warning('StudentTier query skipped: ' . $e->getMessage());
            return [];
        }

        $set = [];
        foreach ($rows as $row) {
            $set[(int) $row['user_id']] = true;
        }
        return $set;
    }
}
