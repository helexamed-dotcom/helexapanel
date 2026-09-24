<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Services\Settings;

/**
 * Typed access to the balin_* keys in the project's settings table.
 *
 * Balin does not get a settings table of its own. The project already has
 * one with typed values and a per-request cache, and a second copy would
 * mean two places to look when a number is wrong.
 *
 * Every tunable the spec names has a default here as well as a seeded row,
 * so a missing row degrades to a sane value instead of a zero that would
 * quietly break a formula.
 */
final class BalinSettings
{
    public const STATUS_COMING_SOON = 'coming_soon';
    public const STATUS_PUBLISHED   = 'published';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_DISABLED    = 'disabled';

    public const STATUSES = [
        self::STATUS_COMING_SOON,
        self::STATUS_PUBLISHED,
        self::STATUS_MAINTENANCE,
        self::STATUS_DISABLED,
    ];

    public static function status(): string
    {
        $value = (string) Settings::get('balin_status', self::STATUS_COMING_SOON);
        return in_array($value, self::STATUSES, true) ? $value : self::STATUS_COMING_SOON;
    }

    public static function comingSoonText(): string
    {
        $text = trim((string) Settings::get('balin_coming_soon_text', ''));
        return $text !== ''
            ? $text
            : 'جزیره بالین قراره به زودی در هلکسا منتشر بشه؛ آماده باش رفیق، هنوز مسیر بزرگی در پیش داریم. 🏝️';
    }

    public static function maintenanceText(): string
    {
        $text = trim((string) Settings::get('balin_maintenance_text', ''));
        return $text !== '' ? $text : 'جزیره بالین در حال به‌روزرسانی است. به‌زودی برمی‌گردیم.';
    }

    public static function maintenanceEta(): string
    {
        return trim((string) Settings::get('balin_maintenance_eta', ''));
    }

    /**
     * One IANA zone drives every day boundary in Balin: streaks, daily and
     * weekly missions, competition windows and checkpoint cooldowns. They
     * must agree, or a student can be "active today" for one and not the
     * other at the same instant.
     */
    public static function timezone(): \DateTimeZone
    {
        $name = (string) Settings::get('balin_timezone', 'Asia/Tehran');
        try {
            return new \DateTimeZone($name);
        } catch (\Throwable) {
            return new \DateTimeZone('Asia/Tehran');
        }
    }

    public static function xpPerCorrect(): int
    {
        return max(0, Settings::int('balin_xp_per_correct', 10));
    }

    public static function hintPenaltyPercent(): int
    {
        return max(0, min(100, Settings::int('balin_hint_penalty_percent', 50)));
    }

    /** @return array<string,float> difficulty => weight */
    public static function difficultyWeights(): array
    {
        return [
            'easy'   => (float) max(0.01, Settings::int('balin_weight_easy', 1)),
            'medium' => (float) max(0.01, Settings::int('balin_weight_medium', 2)),
            'hard'   => (float) max(0.01, Settings::int('balin_weight_hard', 3)),
            'expert' => (float) max(0.01, Settings::int('balin_weight_expert', 4)),
        ];
    }

    public static function finalCaseMultiplier(): float
    {
        $raw = (string) Settings::get('balin_final_case_multiplier', '1.5');
        $value = is_numeric($raw) ? (float) $raw : 1.5;
        return $value > 0 ? $value : 1.5;
    }

    /** repeat_last | admin_defined — what a level above the last tier is called. */
    public static function rankOverflowMode(): string
    {
        $mode = (string) Settings::get('balin_rank_overflow_mode', 'repeat_last');
        return in_array($mode, ['repeat_last', 'admin_defined'], true) ? $mode : 'repeat_last';
    }

    public static function leaderboardPageSize(): int
    {
        return max(5, min(100, Settings::int('balin_leaderboard_page_size', 25)));
    }

    public static function leaderboardRebuildMinutes(): int
    {
        return max(1, Settings::int('balin_leaderboard_rebuild_minutes', 15));
    }

    /** empty | last_ended — what to show when no competition is running. */
    public static function noCompetitionMode(): string
    {
        $mode = (string) Settings::get('balin_no_competition_mode', 'empty');
        return in_array($mode, ['empty', 'last_ended'], true) ? $mode : 'empty';
    }

    public static function answerRatePerMinute(): int
    {
        return max(1, Settings::int('balin_answer_rate_per_minute', 20));
    }

    public static function previewTtlMinutes(): int
    {
        return max(5, Settings::int('balin_preview_ttl_minutes', 60));
    }

    /**
     * The player's token on the lesson map, per gender. An uploaded image
     * (path under public_html/assets/) or, without one, a fitting emoji.
     *
     * @return array{image:?string, emoji:string}
     */
    public static function avatarFor(?string $gender): array
    {
        $key   = $gender === 'female' ? 'balin_avatar_female' : 'balin_avatar_male';
        $image = trim((string) Settings::get($key, ''));
        if ($image === '' && $gender === null) {
            $image = trim((string) Settings::get('balin_avatar_male', ''));
        }

        return [
            'image' => $image !== '' && preg_match('~^images/[A-Za-z0-9/_.-]+$~', $image) === 1 ? $image : null,
            'emoji' => $gender === 'female' ? '👩‍⚕️' : '👨‍⚕️',
        ];
    }

    public static function mediaMaxBytes(string $kind): int
    {
        $megabytes = match ($kind) {
            'audio' => Settings::int('balin_media_max_audio_mb', 15),
            'video' => Settings::int('balin_media_max_video_mb', 60),
            default => Settings::int('balin_media_max_image_mb', 4),
        };
        return max(1, $megabytes) * 1024 * 1024;
    }

    /** Today's date in the institution timezone, as YYYY-MM-DD. */
    public static function today(?int $timestamp = null): string
    {
        return (new \DateTimeImmutable('@' . ($timestamp ?? time())))
            ->setTimezone(self::timezone())
            ->format('Y-m-d');
    }

    /** ISO week key (2026-W37) in the institution timezone. */
    public static function weekKey(?int $timestamp = null): string
    {
        return (new \DateTimeImmutable('@' . ($timestamp ?? time())))
            ->setTimezone(self::timezone())
            ->format('o-\WW');
    }
}
