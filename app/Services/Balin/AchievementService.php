<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Models\Balin\BalinAchievementRepository;
use HeleXa\Models\Balin\BalinCheckpointRepository;
use HeleXa\Models\Balin\BalinLeaderboardRepository;
use HeleXa\Models\Balin\BalinSkillTrackRepository;
use HeleXa\Models\Balin\BalinStatsRepository;
use HeleXa\Models\Balin\BalinStreakRepository;
use HeleXa\Models\Balin\BalinXpRepository;

/**
 * Unlocking achievements.
 *
 * Runs after any activity and re-checks every rule from scratch. That is
 * deliberately wasteful: it means an achievement added next month unlocks
 * retroactively for students who already met it, and a missed run costs
 * nothing because the next one catches up.
 *
 * Unlocks are idempotent at the database level, so re-checking cannot
 * award the same tier twice or pay its XP twice.
 */
final class AchievementService
{
    /** A tier's XP bonus. Deliberately modest: the badge is the reward. */
    private const TIER_XP = ['bronze' => 20, 'silver' => 50, 'gold' => 120, 'platinum' => 300];

    public function __construct(
        private readonly BalinAchievementRepository $achievements = new BalinAchievementRepository(),
        private readonly BalinStatsRepository $stats = new BalinStatsRepository(),
        private readonly BalinStreakRepository $streaks = new BalinStreakRepository(),
        private readonly BalinCheckpointRepository $checkpoints = new BalinCheckpointRepository(),
        private readonly BalinSkillTrackRepository $tracks = new BalinSkillTrackRepository(),
        private readonly BalinXpRepository $xp = new BalinXpRepository(),
    ) {
    }

    /**
     * @return array<int, array{achievement:array, tier:string}> newly unlocked, for the UI to celebrate
     */
    public function evaluate(int $userId): array
    {
        $unlocked = [];

        try {
            $counters = $this->counters($userId);

            foreach ($this->achievements->all(true) as $achievement) {
                $count = $this->countFor($achievement, $counters, $userId);
                if ($count === null) {
                    continue;
                }

                foreach ($this->earnedTiers($achievement, $count) as $tier) {
                    if ($this->achievements->unlock($userId, (int) $achievement['id'], $tier, ['count' => $count])) {
                        $this->awardTierXp($userId, $achievement, $tier);
                        $unlocked[] = ['achievement' => $achievement, 'tier' => $tier];
                    }
                }
            }
        } catch (\Throwable) {
            // An achievement is a garnish. It must never break the activity
            // that triggered the check.
        }

        return $unlocked;
    }

    /** The counters every rule reads, gathered once. */
    private function counters(int $userId): array
    {
        $stats = $this->stats->findOrEmpty($userId);

        return [
            'answered'  => (int) $stats['answered_count'],
            'correct'   => (int) $stats['correct_count'],
            'stages'    => (int) $stats['stages_completed'],
            'lessons'   => (int) $stats['lessons_completed'],
            'streak'    => (int) $this->streaks->state($userId)['longest_streak'],
        ];
    }

    /**
     * The number a rule's thresholds are compared against, or null when the
     * rule names something this installation does not know how to count —
     * better to skip it than to invent a number.
     */
    private function countFor(array $achievement, array $counters, int $userId): ?int
    {
        return match ($achievement['rule_type']) {
            'first_answer'           => $counters['answered'] > 0 ? 1 : 0,
            'correct_answers'        => $counters['correct'],
            'stages_completed'       => $counters['stages'],
            'lessons_completed'      => $counters['lessons'],
            'streak_days'            => $counters['streak'],
            'skill_track_mastery'    => $this->skillTrackCount($achievement, $userId),
            'checkpoint_first_pass'  => $this->firstPassCount($userId),
            'weekly_champion'        => $this->weeklyChampionCount($userId),
            default                  => null,
        };
    }

    /**
     * Answered questions in the named track — the same count its badge
     * thresholds are written against, so the track's own badge and this
     * achievement agree.
     */
    private function skillTrackCount(array $achievement, int $userId): ?int
    {
        $slug = (string) ($this->achievements->config($achievement)['skill_slug'] ?? '');
        if ($slug === '') {
            return null;
        }

        $track = $this->tracks->findBySlug($slug);
        if ($track === null) {
            return null;
        }

        $mastery = $this->stats->skillMastery($userId)[(int) $track['id']] ?? null;

        return $mastery === null ? 0 : (int) $mastery['answered_count'];
    }

    /** Exams passed on the very first attempt. */
    private function firstPassCount(int $userId): int
    {
        return (int) (\HeleXa\Core\Database::scalar(
            'SELECT COUNT(*) FROM balin_checkpoint_attempts
             WHERE user_id = :user AND passed = 1 AND attempt_number = 1 AND is_preview = 0',
            ['user' => $userId]
        ) ?? 0);
    }

    private function weeklyChampionCount(int $userId): int
    {
        $rank = (new BalinLeaderboardRepository())->rankFor($userId, 'weekly_xp', null);

        return $rank !== null && (int) $rank['rank_position'] === 1 ? 1 : 0;
    }

    /**
     * Every tier the count has reached, lowest first.
     *
     * All of them, not just the highest: a student who jumps straight past
     * bronze to gold should hold the whole set, and awarding them in order
     * keeps the unlock feed readable.
     *
     * @return array<int,string>
     */
    private function earnedTiers(array $achievement, int $count): array
    {
        $thresholds = $this->achievements->thresholds($achievement);

        // No thresholds means a single-shot achievement: held or not held.
        if ($thresholds === []) {
            return $count > 0 ? ['bronze'] : [];
        }

        $earned = [];
        foreach (['bronze', 'silver', 'gold', 'platinum'] as $tier) {
            if (isset($thresholds[$tier]) && $count >= $thresholds[$tier]) {
                $earned[] = $tier;
            }
        }

        return $earned;
    }

    private function awardTierXp(int $userId, array $achievement, string $tier): void
    {
        $amount = self::TIER_XP[$tier] ?? 0;
        if ($amount <= 0) {
            return;
        }

        $this->xp->award(
            $userId,
            $amount,
            'achievement',
            'achievement',
            (int) $achievement['id'],
            'achievement:' . $userId . ':' . $achievement['id'] . ':' . $tier,
            CompetitionService::currentId(),
            ['slug' => $achievement['slug'], 'tier' => $tier]
        );
    }
}
