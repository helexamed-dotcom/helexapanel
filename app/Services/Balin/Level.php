<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

/**
 * The XP ↔ level curve.
 *
 *     XP required to reach level n :  10·n² + 10        (n ≥ 1)
 *     level for a given XP total   :  ⌊ √( (XP−10) / 10 ) ⌋
 *
 * There is no maximum level. The curve is quadratic, so each level costs
 * more than the last without ever closing.
 *
 * Nothing stores a level. XP is the only fact; the level is derived from it
 * on every read, which is what lets the curve be retuned later without
 * migrating anyone's account. balin_user_stats.cached_level exists purely
 * so a leaderboard does not have to recompute a thousand of these per page,
 * and it is rewritten from this class whenever XP moves.
 */
final class Level
{
    /**
     * XP needed to be *at* a level. Level 0 is where everyone starts and
     * costs nothing; the published formula only describes n ≥ 1.
     */
    public static function xpForLevel(int $level): int
    {
        if ($level <= 0) {
            return 0;
        }
        return 10 * $level * $level + 10;
    }

    /**
     * The level a total buys.
     *
     * The square root is only the opening guess. sqrt(9) can land a hair
     * below 3 in binary floating point, and on an exact boundary — which is
     * precisely where a student just levelled up — that would show the level
     * before the one they earned. The two loops walk the guess onto the
     * correct integer, so the answer is exact at every boundary.
     */
    public static function forXp(int $xp): int
    {
        if ($xp < self::xpForLevel(1)) {
            return 0;
        }

        $level = (int) floor(sqrt(max(0, $xp - 10) / 10));

        while (self::xpForLevel($level + 1) <= $xp) {
            $level++;
        }
        while ($level > 0 && self::xpForLevel($level) > $xp) {
            $level--;
        }

        return $level;
    }

    /**
     * Everything a progress bar needs, derived rather than stored.
     *
     * @return array{level:int, xp:int, current_level_xp:int, next_level_xp:int,
     *               xp_into_level:int, xp_for_next:int, percent:float}
     */
    public static function progress(int $xp): array
    {
        $xp    = max(0, $xp);
        $level = self::forXp($xp);

        $current = self::xpForLevel($level);
        $next    = self::xpForLevel($level + 1);
        $span    = max(1, $next - $current);
        $into    = max(0, $xp - $current);

        return [
            'level'            => $level,
            'xp'               => $xp,
            'current_level_xp' => $current,
            'next_level_xp'    => $next,
            'xp_into_level'    => $into,
            'xp_for_next'      => max(0, $next - $xp),
            'percent'          => round(min(100, $into / $span * 100), 1),
        ];
    }
}
