<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Models\Balin\BalinCompetitionRepository;

/**
 * The weekly competition, and the XP that counts toward it.
 *
 * Total XP is the whole history; competition XP is only what was earned
 * inside the running window. Keeping them apart is what stops a student who
 * banked a large total months ago from topping this week's board without
 * doing anything this week.
 *
 * Both come from the same ledger rows — the ledger stamps the competition
 * that was running when the XP was earned — so the summary table is a cache
 * and the board can always be rebuilt from the ledger alone.
 */
final class CompetitionService
{
    /** Resolved once per request; the window does not move mid-request. */
    private static ?int $currentId = null;
    private static bool $resolved  = false;

    public static function currentId(): ?int
    {
        if (self::$resolved) {
            return self::$currentId;
        }

        self::$resolved = true;

        try {
            $current = (new BalinCompetitionRepository())->current();
            self::$currentId = $current === null ? null : (int) $current['id'];
        } catch (\Throwable) {
            // A competition is a nice-to-have; never let it break an answer.
            self::$currentId = null;
        }

        return self::$currentId;
    }

    public static function flush(): void
    {
        self::$resolved  = false;
        self::$currentId = null;
    }

    /** Adds to the competition summary when a competition is running. */
    public static function mirrorXp(int $userId, int $amount): void
    {
        $competitionId = self::currentId();
        if ($competitionId === null || $amount <= 0) {
            return;
        }

        try {
            (new BalinCompetitionRepository())->addXp($userId, $competitionId, $amount);
        } catch (\Throwable) {
            // The ledger already has the truth; the mirror can be rebuilt.
        }
    }

    /**
     * What a student should see when no competition is running, which the
     * install decides explicitly rather than leaving to chance.
     *
     * @return array{competition:?array, mode:string}
     */
    public function currentOrFallback(): array
    {
        $repository = new BalinCompetitionRepository();
        $current    = $repository->current();

        if ($current !== null) {
            return ['competition' => $current, 'mode' => 'active'];
        }

        if (BalinSettings::noCompetitionMode() === 'last_ended') {
            return ['competition' => $repository->lastEnded(), 'mode' => 'last_ended'];
        }

        return ['competition' => null, 'mode' => 'empty'];
    }
}
