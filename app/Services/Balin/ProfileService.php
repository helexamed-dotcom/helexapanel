<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Models\Balin\BalinAchievementRepository;
use HeleXa\Models\Balin\BalinLeaderboardRepository;
use HeleXa\Models\Balin\BalinRankTierRepository;
use HeleXa\Models\Balin\BalinSkillTrackRepository;
use HeleXa\Models\Balin\BalinStatsRepository;
use HeleXa\Models\Balin\BalinStreakRepository;

/**
 * Assembles a student's profile: level and rank, mastery, skills, streak,
 * achievements and standing.
 *
 * Everything shown is read from the caches that Recalculator maintains, so
 * a profile page is a handful of indexed reads rather than a pile of
 * aggregates over the whole history.
 */
final class ProfileService
{
    public function __construct(
        private readonly BalinStatsRepository $stats = new BalinStatsRepository(),
        private readonly BalinRankTierRepository $tiers = new BalinRankTierRepository(),
        private readonly BalinSkillTrackRepository $tracks = new BalinSkillTrackRepository(),
        private readonly BalinStreakRepository $streaks = new BalinStreakRepository(),
        private readonly BalinAchievementRepository $achievements = new BalinAchievementRepository(),
        private readonly BalinLeaderboardRepository $boards = new BalinLeaderboardRepository(),
    ) {
    }

    public function forStudent(int $userId): array
    {
        $stats = $this->stats->findOrEmpty($userId);
        $xp    = (int) $stats['total_xp'];

        $progress = Level::progress($xp);
        $rank     = RankTitle::forLevel(
            $progress['level'],
            $this->tiers->all(),
            BalinSettings::rankOverflowMode()
        );

        return [
            'stats'          => $stats,
            'level'          => $progress,
            'rank'           => $rank,
            'streak'         => $this->streaks->state($userId),
            'skills'         => $this->skills($userId),
            'lesson_mastery' => $this->stats->lessonMastery($userId),
            'achievements'   => $this->achievements->bestTiers($userId),
            'weakest_skill'  => $this->stats->weakestSkill($userId),
            'missions'       => (new MissionService())->statusFor($userId),
            'ranks'          => [
                'overall' => $this->boards->rankFor($userId, 'overall_xp', null),
                'weekly'  => $this->boards->rankFor($userId, 'weekly_xp', null),
                'mastery' => $this->boards->rankFor($userId, 'mastery', null),
            ],
        ];
    }

    /**
     * Every active skill track with the student's standing in it.
     *
     * A track with too few answered questions reports "not enough data"
     * rather than a percentage. Two correct answers out of two is not
     * eighty-percent-plus mastery of history taking, and showing it as such
     * would be a number the student has every reason to believe.
     *
     * @return array<int, array<string,mixed>>
     */
    public function skills(int $userId): array
    {
        $mastery = $this->stats->skillMastery($userId);
        $out     = [];

        foreach ($this->tracks->all(true) as $track) {
            $trackId    = (int) $track['id'];
            $row        = $mastery[$trackId] ?? null;
            $answered   = (int) ($row['answered_count'] ?? 0);
            $minimum    = (int) $track['min_questions_for_reliable_mastery'];
            $reliable   = Mastery::isReliable($answered, $minimum);
            $thresholds = $this->tracks->thresholds($track);

            $out[] = [
                'track'            => $track,
                'answered_count'   => $answered,
                'minimum_required' => $minimum,
                'reliable'         => $reliable,
                'mastery_percent'  => $reliable ? (float) ($row['mastery_percent'] ?? 0) : null,
                'badge'            => Mastery::badgeTier($answered, $thresholds),
                'badge_label'      => Mastery::BADGE_LABELS[Mastery::badgeTier($answered, $thresholds) ?? ''] ?? null,
                'next_threshold'   => $this->nextThreshold($answered, $thresholds),
            ];
        }

        return $out;
    }

    /**
     * The next badge and how far away it is, so the bar has somewhere to go.
     *
     * @return array{tier:string, at:int, remaining:int}|null
     */
    private function nextThreshold(int $answered, array $thresholds): ?array
    {
        foreach (['bronze', 'silver', 'gold', 'platinum'] as $tier) {
            if (isset($thresholds[$tier]) && $answered < (int) $thresholds[$tier]) {
                return [
                    'tier'      => $tier,
                    'at'        => (int) $thresholds[$tier],
                    'remaining' => (int) $thresholds[$tier] - $answered,
                ];
            }
        }

        return null;
    }

    /** The rank title for any level, used by the leaderboard rows. */
    public function rankFor(int $level): array
    {
        return RankTitle::forLevel($level, $this->tiers->all(), BalinSettings::rankOverflowMode());
    }
}
