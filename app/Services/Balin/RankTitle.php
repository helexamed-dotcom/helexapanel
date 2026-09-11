<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

/**
 * The narrative name for a level.
 *
 * Two hundred titles are not stored as two hundred rows. Twenty tiers of ten
 * levels each are stored, and the title shown is built from the tier plus the
 * student's position inside it:
 *
 *     tier   = the tier whose range contains the level
 *     step   = level − tier.min_level + 1
 *     title  = "{tier.title} · قدم {step}"
 *
 * So level 95 reads "رزیدنت میانی · قدم ۵". Retuning the XP curve or adding
 * tiers changes every title without a migration, because nothing here is
 * written down per level.
 *
 * Tier rows are passed in rather than queried, so this class stays pure and
 * testable; BalinRankTierRepository is what actually reads them.
 */
final class RankTitle
{
    /**
     * @param array<int, array<string,mixed>> $tiers rows from balin_rank_tiers, any order
     * @return array{tier:?array<string,mixed>, level:int, step:int, title:string,
     *               icon:string, is_overflow:bool}
     */
    public static function forLevel(int $level, array $tiers, string $overflowMode = 'repeat_last'): array
    {
        $level = max(0, $level);

        $tiers = array_values(array_filter(
            $tiers,
            static fn (array $tier): bool => isset($tier['min_level'], $tier['max_level'])
        ));
        usort(
            $tiers,
            static fn (array $a, array $b): int => (int) $a['min_level'] <=> (int) $b['min_level']
        );

        if ($tiers === []) {
            return self::bare($level);
        }

        $first = $tiers[0];
        $last  = $tiers[count($tiers) - 1];

        // Level zero sits below the first tier. Showing "قدم ۰" or borrowing
        // level one's step would both be wrong, so the tier is named without
        // a step: the student is at the start of it, not inside it yet.
        if ($level < (int) $first['min_level']) {
            return [
                'tier'        => $first,
                'level'       => $level,
                'step'        => 0,
                'title'       => (string) $first['title'],
                'icon'        => (string) ($first['icon'] ?? ''),
                'is_overflow' => false,
            ];
        }

        foreach ($tiers as $tier) {
            if ($level >= (int) $tier['min_level'] && $level <= (int) $tier['max_level']) {
                return self::compose($tier, $level, false);
            }
        }

        // Past the last tier. The curve has no ceiling, so this is reachable
        // and must not throw or read as "unranked".
        if ($overflowMode === 'repeat_last') {
            // The step keeps counting: 201 in a 191–200 tier is قدم ۱۱.
            return self::compose($last, $level, true);
        }

        return self::bare($level);
    }

    /** @param array<string,mixed> $tier */
    private static function compose(array $tier, int $level, bool $overflow): array
    {
        $step = $level - (int) $tier['min_level'] + 1;

        return [
            'tier'        => $tier,
            'level'       => $level,
            'step'        => $step,
            'title'       => (string) $tier['title'] . ' · قدم ' . \HeleXa\Services\Jalali::digits((string) $step),
            'icon'        => (string) ($tier['icon'] ?? ''),
            'is_overflow' => $overflow,
        ];
    }

    /** No tier covers this level and the install does not want the last one repeated. */
    private static function bare(int $level): array
    {
        return [
            'tier'        => null,
            'level'       => $level,
            'step'        => 0,
            'title'       => 'سطح ' . \HeleXa\Services\Jalali::digits((string) $level),
            'icon'        => '',
            'is_overflow' => true,
        ];
    }
}
