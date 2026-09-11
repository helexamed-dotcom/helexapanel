<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Models\Balin\BalinAnswerRepository;
use HeleXa\Models\Balin\BalinProgressRepository;
use HeleXa\Models\Balin\BalinSkillTrackRepository;
use HeleXa\Models\Balin\BalinStatsRepository;
use HeleXa\Models\Balin\BalinStreakRepository;
use HeleXa\Models\Balin\BalinXpRepository;

/**
 * Rebuilds every cached figure for one student from its source.
 *
 * Nothing here computes an increment. Each value is recomputed in full from
 * the ledger or the answers table, so a cache that has drifted — because a
 * row was corrected, a job was missed, or a formula was retuned — is put
 * right by the next call rather than staying wrong forever.
 *
 * That also means this is safe to call more often than strictly necessary,
 * which is why the answer path just calls it rather than reasoning about
 * which figures its particular change could have touched.
 */
final class Recalculator
{
    public function __construct(
        private readonly BalinXpRepository $xp = new BalinXpRepository(),
        private readonly BalinAnswerRepository $answers = new BalinAnswerRepository(),
        private readonly BalinProgressRepository $progress = new BalinProgressRepository(),
        private readonly BalinStatsRepository $stats = new BalinStatsRepository(),
        private readonly BalinSkillTrackRepository $tracks = new BalinSkillTrackRepository(),
        private readonly BalinStreakRepository $streaks = new BalinStreakRepository(),
    ) {
    }

    /** Headline figures: XP, level, accuracy, completion counts. */
    public function userStats(int $userId): array
    {
        $totalXp = $this->xp->totalFor($userId);
        $totals  = $this->answers->totals($userId);

        $values = [
            'total_xp'          => $totalXp,
            'cached_level'      => Level::forXp($totalXp),
            'answered_count'    => $totals['answered'],
            'correct_count'     => $totals['correct'],
            'accuracy_percent'  => Mastery::accuracy($totals['correct'], $totals['answered']),
            'stages_completed'  => $this->progress->countCompletedStages($userId),
            'lessons_completed' => $this->progress->countCompletedLessons($userId),
        ];

        $this->stats->store($userId, $values);

        return $values;
    }

    /** Weighted mastery for one lesson. */
    public function lessonMastery(int $userId, int $lessonId): float
    {
        $weights = $this->answers->lessonWeights($userId, $lessonId);

        $percent = Mastery::percent(
            $weights['weighted_correct'],
            $weights['weight_total']
        );

        $this->stats->storeLessonMastery(
            $userId,
            $lessonId,
            $percent,
            $weights['answered'],
            $weights['correct']
        );

        return $percent;
    }

    /**
     * Global mastery for the given skill tracks — or all of them when none
     * are named. The scope is every tagged question in every lesson, which
     * is what separates a skill track from a lesson's own mastery.
     *
     * @param array<int,int> $trackIds
     */
    public function skillMastery(int $userId, array $trackIds = []): void
    {
        if ($trackIds === []) {
            $trackIds = array_map('intval', array_column($this->tracks->all(true), 'id'));
        }

        foreach (array_unique($trackIds) as $trackId) {
            $weights = $this->answers->skillTrackWeights($userId, $trackId);

            $this->stats->storeSkillMastery(
                $userId,
                $trackId,
                Mastery::percent($weights['weighted_correct'], $weights['weight_total']),
                $weights['answered'],
                $weights['weighted_correct'],
                $weights['weight_total']
            );
        }
    }

    /**
     * Records today as an active day and refreshes the streak counters.
     * The day is the institution's day, not the server's, and the unique key
     * makes the record idempotent however many times it is called.
     *
     * @return array{recorded:bool, current:int, longest:int}
     */
    public function streak(int $userId): array
    {
        $today    = BalinSettings::today();
        $recorded = $this->streaks->recordDay($userId, $today);

        $current = $this->streaks->currentStreak($userId, $today);
        $longest = max($current, $this->streaks->longestStreak($userId));

        $this->streaks->storeState($userId, $current, $longest, $today);

        return ['recorded' => $recorded, 'current' => $current, 'longest' => $longest];
    }

    /**
     * Everything a student's numbers depend on, after an answer or an exam.
     *
     * @param array<int,int> $trackIds tracks the answered question was tagged with
     * @return array{stats:array, streak:array}
     */
    public function afterActivity(int $userId, int $lessonId, array $trackIds = []): array
    {
        $this->lessonMastery($userId, $lessonId);
        $this->skillMastery($userId, $trackIds);

        $streak = $this->streak($userId);
        $stats  = $this->userStats($userId);

        return ['stats' => $stats, 'streak' => $streak];
    }

    /**
     * The clinical performance score, assembled from the parts it weights.
     * Reported for faculty analytics; the student leaderboard does not rank
     * on it, because its consistency term rewards attendance, not skill.
     */
    public function clinicalPerformanceScore(int $userId): float
    {
        $totals = $this->answers->totals($userId);
        $global = $this->answers->globalWeights($userId);

        $accuracy           = Mastery::accuracy($totals['correct'], $totals['answered']);
        $difficultyAdjusted = Mastery::percent($global['weighted_correct'], $global['weight_total']);
        $averageMastery     = $this->stats->averageMastery($userId);

        $completedStages = $this->progress->countCompletedStages($userId);
        $availableStages = $this->publishedStageCount();
        $caseCompletion  = $availableStages > 0
            ? min(100, $completedStages / $availableStages * 100)
            : 0.0;

        $firstActivity = $this->progress->firstActivityAt($userId);
        $daysSince     = $firstActivity === null
            ? 0
            : max(0, (int) ((time() - strtotime($firstActivity)) / 86400) + 1);

        $consistency = Mastery::consistency($this->streaks->activeDayCount($userId), $daysSince);

        return Mastery::clinicalPerformanceScore(
            $accuracy,
            $difficultyAdjusted,
            $averageMastery,
            $caseCompletion,
            $consistency
        );
    }

    private function publishedStageCount(): int
    {
        return (int) (\HeleXa\Core\Database::scalar(
            "SELECT COUNT(*) FROM balin_stages s
             JOIN balin_lessons l ON l.id = s.lesson_id AND l.status = 'published'
             WHERE s.status = 'published'"
        ) ?? 0);
    }
}
