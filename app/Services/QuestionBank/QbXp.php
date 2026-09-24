<?php
declare(strict_types=1);

namespace HeleXa\Services\QuestionBank;

use HeleXa\Models\Balin\BalinXpRepository;
use HeleXa\Services\Balin\CompetitionService;
use HeleXa\Services\Balin\Level;
use HeleXa\Services\Balin\Recalculator;
use HeleXa\Services\Settings;

/**
 * XP for the question bank, paid into the same ledger as Balin island so a
 * student has one level across the whole site.
 *
 * Paid only when the student's FIRST attempt at a question is correct. The
 * explanation is shown after any answer, so paying for a later correct answer
 * would pay for reading the key.
 */
final class QbXp
{
    public const DEFAULTS = ['easy' => 5, 'medium' => 10, 'hard' => 15, 'expert' => 20];

    public static function enabled(): bool
    {
        return Settings::bool('qbank_xp_enabled', true);
    }

    public static function amountFor(string $difficulty): int
    {
        $default = self::DEFAULTS[$difficulty] ?? self::DEFAULTS['medium'];

        return max(0, min(Settings::int('qbank_xp_' . $difficulty, $default), 500));
    }

    /**
     * @return array{xp:int, level:int, levelled_up:bool, total:int}|null null when nothing was paid
     */
    public static function award(int $userId, int $questionId, string $difficulty): ?array
    {
        $amount = self::enabled() ? self::amountFor($difficulty) : 0;
        if ($amount <= 0) {
            return null;
        }

        try {
            $ledger = new BalinXpRepository();
            $before = Level::forXp($ledger->totalFor($userId));
            $result = $ledger->award(
                $userId,
                $amount,
                'question_correct',
                'qbank_question',
                $questionId,
                'qbank:' . $userId . ':' . $questionId,
                CompetitionService::currentId(),
                ['difficulty' => $difficulty]
            );
            if (!$result['awarded']) {
                return null;
            }

            CompetitionService::mirrorXp($userId, $amount);
            $stats = (new Recalculator())->userStats($userId);
            $total = (int) $stats['total_xp'];
            $level = Level::forXp($total);

            return ['xp' => $amount, 'level' => $level, 'levelled_up' => $level > $before, 'total' => $total];
        } catch (\Throwable $e) {
            // The answer is already recorded; a failed reward must not fail it.
            error_log('[qbank xp] ' . $e->getMessage());
            return null;
        }
    }
}
