<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

/**
 * Mastery, accuracy and the clinical performance score.
 *
 * XP is the game, level is the progression, mastery is the only one of the
 * three that claims to say something about clinical knowledge. They are kept
 * apart deliberately: a student can farm neither of the last two, because
 * both are computed from first-time answers alone.
 *
 *     Mastery = Σ(weightᵢ × correctᵢ) / Σ(weightᵢ)
 *
 * The weight is the question's difficulty, times 1.5 when the question is a
 * step of a final case. Getting a hard question right is worth more than
 * getting an easy one right, which a plain percentage cannot express.
 */
final class Mastery
{
    /**
     * The weight one answered question contributes.
     *
     * @param array<string,float>|null $weights difficulty => weight; settings when null
     */
    public static function weightFor(string $difficulty, bool $isFinalCase = false, ?array $weights = null): float
    {
        $weights ??= BalinSettings::difficultyWeights();
        $weight = $weights[$difficulty] ?? ($weights['medium'] ?? 2.0);

        if ($isFinalCase) {
            $weight *= BalinSettings::finalCaseMultiplier();
        }

        return round($weight, 2);
    }

    /**
     * Weighted mastery as a percentage.
     *
     * @param array<int, array{weight:float|string, is_correct:bool|int}> $answers
     */
    public static function fromAnswers(array $answers): float
    {
        $weightTotal    = 0.0;
        $weightedCorrect = 0.0;

        foreach ($answers as $answer) {
            $weight = (float) ($answer['weight'] ?? 1.0);
            if ($weight <= 0) {
                continue;
            }
            $weightTotal += $weight;
            if ((bool) ($answer['is_correct'] ?? false)) {
                $weightedCorrect += $weight;
            }
        }

        return self::percent($weightedCorrect, $weightTotal);
    }

    /** Shared by every cached mastery row, which stores the two sums rather than the answers. */
    public static function percent(float $weightedCorrect, float $weightTotal): float
    {
        if ($weightTotal <= 0) {
            return 0.0;
        }
        return round(min(100, max(0, $weightedCorrect / $weightTotal * 100)), 2);
    }

    /** Plain hit rate, deliberately unweighted — the honest "how often was I right". */
    public static function accuracy(int $correct, int $answered): float
    {
        if ($answered <= 0) {
            return 0.0;
        }
        return round(min(100, max(0, $correct / $answered * 100)), 2);
    }

    /**
     * Whether a skill track has enough answers behind it to show a number.
     *
     * Two answered questions out of two is not "100% mastery", it is noise.
     * Below the track's own threshold the profile says so instead of
     * printing a figure the student would reasonably believe.
     */
    public static function isReliable(int $answeredCount, int $minimumRequired): bool
    {
        return $answeredCount >= max(1, $minimumRequired);
    }

    /**
     * Clinical Performance Score.
     *
     *     CPS = 0.30·Accuracy + 0.25·DifficultyAdjusted + 0.20·AvgMastery
     *         + 0.15·CaseCompletion + 0.10·Consistency
     *
     * Every input is already a 0–100 percentage, so the result is one too.
     * Consistency is included here for faculty reporting only; the student
     * leaderboard never ranks on it, because rewarding "logged in often"
     * is not the same as rewarding clinical skill.
     */
    public static function clinicalPerformanceScore(
        float $accuracy,
        float $difficultyAdjusted,
        float $averageMastery,
        float $caseCompletionRate,
        float $consistency
    ): float {
        $clamp = static fn (float $value): float => min(100, max(0, $value));

        $score = 0.30 * $clamp($accuracy)
               + 0.25 * $clamp($difficultyAdjusted)
               + 0.20 * $clamp($averageMastery)
               + 0.15 * $clamp($caseCompletionRate)
               + 0.10 * $clamp($consistency);

        return round($score, 2);
    }

    /**
     * How evenly the work was spread, as active days over the days since the
     * student started. A student who studied on 12 of the last 30 days
     * scores 40, one who crammed everything into a weekend scores far less.
     */
    public static function consistency(int $activeDays, int $daysSinceStart): float
    {
        if ($daysSinceStart <= 0) {
            return $activeDays > 0 ? 100.0 : 0.0;
        }
        return round(min(100, $activeDays / $daysSinceStart * 100), 2);
    }

    /**
     * The badge a count has earned, or null when it has earned none.
     *
     * @param array<string,int> $thresholds e.g. {"bronze":10,"silver":30,"gold":75,"platinum":150}
     */
    public static function badgeTier(int $count, array $thresholds): ?string
    {
        $order = ['bronze', 'silver', 'gold', 'platinum'];
        $earned = null;

        foreach ($order as $tier) {
            if (!isset($thresholds[$tier])) {
                continue;
            }
            if ($count >= (int) $thresholds[$tier]) {
                $earned = $tier;
            }
        }

        return $earned;
    }

    public const BADGE_LABELS = [
        'bronze'   => 'برنز',
        'silver'   => 'نقره',
        'gold'     => 'طلا',
        'platinum' => 'پلاتین',
    ];
}
