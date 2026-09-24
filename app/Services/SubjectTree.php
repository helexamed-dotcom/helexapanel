<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;

/**
 * The question bank's درس → زیردرس → عنوان tree, reused to file درسنامه‌ها,
 * نقشه‌های ذهنی and بازی با شکل under the same subjects.
 */
final class SubjectTree
{
    /** @return list<array{id:int, label:string, title:string, depth:int, parent_id:int}> */
    public static function options(bool $activeOnly = true): array
    {
        try {
            $rows = Database::select('SELECT id, parent_id, depth, title FROM qb_subjects'
                . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY depth, sort_order, title');
        } catch (\PDOException) {
            return [];
        }
        $byParent = [];
        foreach ($rows as $r) {
            $byParent[(int) ($r['parent_id'] ?? 0)][] = $r;
        }
        $out = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $s) {
                $out[] = ['id' => (int) $s['id'], 'label' => str_repeat('— ', $depth) . $s['title'], 'title' => (string) $s['title'],
                          'depth' => $depth, 'parent_id' => (int) ($s['parent_id'] ?? 0)];
                $walk((int) $s['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }

    /** Only the top-level درس‌ها. */
    public static function roots(): array
    {
        return array_values(array_filter(self::options(), static fn (array $o): bool => $o['depth'] === 0));
    }

    /** "فیزیولوژی › قلب › چرخه قلبی" → the deepest subject id, creating nothing. */
    public static function findPath(array $titles): int
    {
        $parent = null;
        $id = 0;
        foreach ($titles as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            try {
                $row = Database::selectOne(
                    'SELECT id FROM qb_subjects WHERE title = :t AND ' . ($parent === null ? 'parent_id IS NULL' : 'parent_id = :p') . ' LIMIT 1',
                    $parent === null ? ['t' => $t] : ['t' => $t, 'p' => $parent]
                );
            } catch (\PDOException) {
                return 0;
            }
            if ($row === null) {
                return $id;
            }
            $id = $parent = (int) $row['id'];
        }
        return $id;
    }

    /** The titles from the root down to this subject. */
    public static function pathOf(?int $id): array
    {
        $out = [];
        $guard = 0;
        while ($id !== null && $id > 0 && $guard++ < 5) {
            try {
                $row = Database::selectOne('SELECT parent_id, title FROM qb_subjects WHERE id = :id', ['id' => $id]);
            } catch (\PDOException) {
                break;
            }
            if ($row === null) {
                break;
            }
            array_unshift($out, (string) $row['title']);
            $id = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }
        return $out;
    }
}
