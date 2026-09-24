<?php
declare(strict_types=1);

namespace HeleXa\Services\Flashcards;

/**
 * When a card should come back, given how the student rated it.
 *
 * A trimmed-down SM-2, the algorithm behind most flashcard apps. Four buttons:
 *
 *   1 دوباره   forgot it          → back in ten minutes, ease drops
 *   2 سخت      remembered, barely → interval grows slowly, ease drops a little
 *   3 خوب      remembered         → interval grows by the ease factor
 *   4 آسان     trivial            → interval grows faster, ease rises
 *
 * Pure: no database, no clock of its own. The caller passes the current state
 * and "now", which is what lets the rules be tested line by line.
 */
final class Scheduler
{
    public const AGAIN = 1;
    public const HARD  = 2;
    public const GOOD  = 3;
    public const EASY  = 4;

    public const LABELS = [
        self::AGAIN => 'دوباره',
        self::HARD  => 'سخت',
        self::GOOD  => 'خوب',
        self::EASY  => 'آسان',
    ];

    private const EASE_DEFAULT = 250;
    private const EASE_MIN     = 130;
    private const EASE_MAX     = 350;
    private const MAX_INTERVAL = 365;
    private const RELEARN_MINUTES = 10;

    /** A card whose interval has reached this many days counts as learned. */
    public const MASTERED_DAYS = 21;

    /**
     * @param array{reps:int, lapses:int, ease:int, interval_days:int}|null $state  null for a new card
     * @return array{reps:int, lapses:int, ease:int, interval_days:int, due_at:string, last_rating:int}
     */
    public static function next(?array $state, int $rating, \DateTimeImmutable $now): array
    {
        if (!isset(self::LABELS[$rating])) {
            throw new \InvalidArgumentException('Unknown rating.');
        }

        $reps     = (int) ($state['reps'] ?? 0);
        $lapses   = (int) ($state['lapses'] ?? 0);
        $ease     = (int) ($state['ease'] ?? self::EASE_DEFAULT);
        $interval = (int) ($state['interval_days'] ?? 0);

        if ($rating === self::AGAIN) {
            // Forgotten: the run starts over and the card returns within the
            // same sitting, so the student sees it again before leaving.
            return [
                'reps'          => 0,
                'lapses'        => $lapses + ($reps > 0 ? 1 : 0),
                'ease'          => self::clampEase($ease - 20),
                'interval_days' => 0,
                'due_at'        => $now->modify('+' . self::RELEARN_MINUTES . ' minutes')->format('Y-m-d H:i:s'),
                'last_rating'   => $rating,
            ];
        }

        $newInterval = match ($rating) {
            self::HARD => $reps === 0 ? 1 : max($interval + 1, (int) round($interval * 1.2)),
            self::GOOD => match ($reps) {
                0       => 1,
                1       => 3,
                default => max($interval + 1, (int) round($interval * $ease / 100)),
            },
            self::EASY => $reps === 0 ? 4 : max($interval + 2, (int) round($interval * $ease / 100 * 1.3)),
        };

        $newEase = match ($rating) {
            self::HARD => $ease - 15,
            self::EASY => $ease + 15,
            default    => $ease,
        };

        $newInterval = min(self::MAX_INTERVAL, $newInterval);

        return [
            'reps'          => $reps + 1,
            'lapses'        => $lapses,
            'ease'          => self::clampEase($newEase),
            'interval_days' => $newInterval,
            'due_at'        => $now->modify('+' . $newInterval . ' days')->format('Y-m-d H:i:s'),
            'last_rating'   => $rating,
        ];
    }

    /**
     * The four buttons' labels with the delay each would schedule, so the
     * student sees "خوب · ۳ روز" before choosing, as mature flashcard apps do.
     *
     * @return array<int,string>
     */
    public static function previews(?array $state, \DateTimeImmutable $now): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $rating) {
            $next = self::next($state, $rating, $now);
            $out[$rating] = $rating === self::AGAIN
                ? self::RELEARN_MINUTES . ' دقیقه'
                : self::humanDays($next['interval_days']);
        }
        return $out;
    }

    public static function humanDays(int $days): string
    {
        return match (true) {
            $days < 30  => $days . ' روز',
            $days < 365 => round($days / 30, 1) . ' ماه',
            default     => round($days / 365, 1) . ' سال',
        };
    }

    /** new | learning | review | mastered */
    public static function stage(?array $state): string
    {
        if ($state === null) {
            return 'new';
        }
        $interval = (int) $state['interval_days'];

        return match (true) {
            (int) $state['reps'] === 0   => 'learning',
            $interval >= self::MASTERED_DAYS => 'mastered',
            default                      => 'review',
        };
    }

    private static function clampEase(int $ease): int
    {
        return max(self::EASE_MIN, min(self::EASE_MAX, $ease));
    }
}
