<?php
declare(strict_types=1);

namespace HeleXa\Models;

/**
 * What each student holds, counted for a whole page of them at once.
 *
 * The access page for one student reads the modules' own repositories. This
 * one exists for the list in front of it, where doing that per row would be
 * twenty students times five modules of queries. Everything here is a single
 * grouped query per module, keyed by user id.
 *
 * Every module is optional — a site that has not run the flashcards migration
 * still gets the list, with that column empty.
 */
final class AccessOverviewRepository extends BaseRepository
{
    /**
     * @param array<int,int> $userIds
     * @return array<int,array{courses:int,packages:int,balin:bool,lessons:int,qbank:int,flashcards:int,full:bool}>
     */
    public function summaries(array $userIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $userIds), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }
        // Cast to int above, so the list is safe to write into the SQL. It has
        // to be: a placeholder per row would be a new prepared statement for
        // every page size.
        $in  = implode(',', $ids);
        $now = $this->now();

        $blank = ['courses' => 0, 'packages' => 0, 'balin' => false, 'lessons' => 0,
                  'qbank' => 0, 'flashcards' => 0, 'full' => false];
        $out   = array_fill_keys($ids, $blank);

        $count = function (string $sql, array $params = []) use ($in): array {
            $rows = $this->optional(fn (): array => $this->select(str_replace('{ids}', $in, $sql), $params));
            $map  = [];
            foreach ($rows as $row) {
                $map[(int) $row['user_id']] = (int) ($row['c'] ?? 0);
            }
            return $map;
        };

        $courses = $count(
            "SELECT user_id, COUNT(*) AS c FROM student_courses
             WHERE user_id IN ({ids}) AND status = 'active' GROUP BY user_id"
        );
        $packages = $count(
            "SELECT user_id, COUNT(*) AS c FROM package_activations
             WHERE user_id IN ({ids}) AND status = 'active' GROUP BY user_id"
        );
        $qbank = $count(
            'SELECT user_id, COUNT(*) AS c FROM qb_student_access WHERE user_id IN ({ids}) GROUP BY user_id'
        );
        $cards = $count(
            'SELECT user_id, COUNT(*) AS c FROM fc_access WHERE user_id IN ({ids}) GROUP BY user_id'
        );

        // Balin: the island itself, then the lessons that are open — every
        // published "open" lesson the student was not blocked from, plus the
        // "granted" ones they were given.
        $island = $this->optional(fn (): array => $this->select(
            'SELECT user_id FROM balin_student_access WHERE user_id IN (' . $in . ') AND is_enabled = 1'
        ));
        $openTotal = (int) ($this->optional(fn (): array => $this->select(
            "SELECT COUNT(*) AS c FROM balin_lessons WHERE status = 'published' AND access_mode = 'open'"
        ))[0]['c'] ?? 0);
        $blocked = $count(
            "SELECT b.user_id, COUNT(*) AS c FROM balin_lesson_blocks b
               JOIN balin_lessons l ON l.id = b.lesson_id AND l.status = 'published' AND l.access_mode = 'open'
              WHERE b.user_id IN ({ids}) GROUP BY b.user_id"
        );
        $granted = $count(
            "SELECT g.user_id, COUNT(*) AS c FROM balin_lesson_grants g
               JOIN balin_lessons l ON l.id = g.lesson_id AND l.status = 'published' AND l.access_mode = 'granted'
              WHERE g.user_id IN ({ids}) GROUP BY g.user_id"
        );

        $full = $this->optional(fn (): array => $this->select(
            'SELECT DISTINCT pa.user_id
             FROM package_activations pa
             JOIN packages p ON p.id = pa.package_id AND p.deleted_at IS NULL
             WHERE pa.user_id IN (' . $in . ") AND p.is_full_access = 1 AND pa.status = 'active'
               AND (pa.starts_at IS NULL OR pa.starts_at <= :now1)
               AND (pa.ends_at   IS NULL OR pa.ends_at   >= :now2)",
            ['now1' => $now, 'now2' => $now]
        ));

        foreach ($ids as $id) {
            $out[$id]['courses']    = $courses[$id] ?? 0;
            $out[$id]['packages']   = $packages[$id] ?? 0;
            $out[$id]['qbank']      = $qbank[$id] ?? 0;
            $out[$id]['flashcards'] = $cards[$id] ?? 0;
            $out[$id]['lessons']    = max(0, $openTotal - ($blocked[$id] ?? 0)) + ($granted[$id] ?? 0);
        }
        foreach ($island as $row) {
            $out[(int) $row['user_id']]['balin'] = true;
        }
        foreach ($full as $row) {
            $out[(int) $row['user_id']]['full'] = true;
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function optional(callable $read): array
    {
        try {
            return $read();
        } catch (\Throwable) {
            return [];
        }
    }
}
