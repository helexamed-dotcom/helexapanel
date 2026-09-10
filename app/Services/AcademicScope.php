<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Models\AcademicRepository;

/**
 * Answers "which terms is this student in".
 *
 * A student can carry units from more than one term at once, so the answer is
 * a list. users.term_id survives as the primary term and is used as a fallback
 * for accounts created before multi-term existed, which is what keeps every
 * older schedule and exam query returning the same rows it used to.
 */
final class AcademicScope
{
    /** @return array<int,int> */
    public static function termIds(array $user): array
    {
        $selected = (new AcademicRepository())->semestersOf((int) $user['id']);

        if ($selected !== []) {
            return $selected;
        }

        return $user['term_id'] !== null ? [(int) $user['term_id']] : [];
    }

    public static function groupId(array $user): ?int
    {
        return $user['group_id'] !== null ? (int) $user['group_id'] : null;
    }

    /**
     * Builds a bound IN clause. Returning the placeholder text alongside the
     * parameters keeps the term list out of the SQL string itself.
     *
     * @param array<int,int> $ids
     * @return array{0:string,1:array<string,int>}
     */
    public static function inClause(array $ids, string $prefix = 'term'): array
    {
        $placeholders = [];
        $params       = [];

        foreach (array_values(array_unique(array_map('intval', $ids))) as $index => $id) {
            $key                = $prefix . $index;
            $placeholders[]     = ':' . $key;
            $params[$key]       = $id;
        }

        return [implode(', ', $placeholders), $params];
    }
}
