<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Models\Balin\BalinMissionRepository;
use HeleXa\Models\Balin\BalinStreakRepository;
use HeleXa\Models\Balin\BalinXpRepository;

/**
 * Daily and weekly missions.
 *
 * Progress is kept per period — a date for daily, an ISO week for weekly,
 * both in the institution's timezone. Completion is a one-shot flip guarded
 * by a unique key, so a mission pays once per period however many times the
 * last qualifying action is replayed.
 */
final class MissionService
{
    public function __construct(
        private readonly BalinMissionRepository $missions = new BalinMissionRepository(),
        private readonly BalinStreakRepository $streaks = new BalinStreakRepository(),
        private readonly BalinXpRepository $xp = new BalinXpRepository(),
    ) {
    }

    public function recordAnswer(int $userId, bool $wasCorrect): void
    {
        $this->bump($userId, ['answers' => 1] + ($wasCorrect ? ['correct' => 1] : []));
    }

    public function recordStageComplete(int $userId): void
    {
        $this->bump($userId, ['stages' => 1]);
    }

    /**
     * Adds to each active mission's counters and completes any that have
     * met their targets.
     *
     * @param array<string,int> $increments
     */
    private function bump(int $userId, array $increments): void
    {
        try {
            foreach ($this->missions->active() as $mission) {
                $period   = $this->periodKey((string) $mission['mission_type']);
                $existing = $this->missions->progressFor($userId, (int) $mission['id'], $period);

                if ($existing !== null && $existing['completed_at'] !== null) {
                    continue;   // already paid for this period
                }

                $progress = $this->decodeProgress($existing);
                foreach ($increments as $key => $amount) {
                    $progress[$key] = ($progress[$key] ?? 0) + $amount;
                }

                $this->missions->storeProgress($userId, (int) $mission['id'], $period, $progress);

                if ($this->isSatisfied($mission, $progress, $userId)) {
                    $this->complete($userId, $mission, $period);
                }
            }
        } catch (\Throwable) {
            // Missions are a bonus layer; never break the activity beneath.
        }
    }

    /**
     * Whether every target in the mission's rules has been met.
     *
     * streak_days is read from the streak table rather than the mission's
     * own counters, because "active on three days" is a fact about the week,
     * not something this mission can count for itself.
     */
    private function isSatisfied(array $mission, array $progress, int $userId): bool
    {
        foreach ($this->missions->rules($mission) as $key => $target) {
            if ($target <= 0) {
                continue;
            }

            $actual = $key === 'streak_days'
                ? $this->daysActiveThisPeriod($userId, (string) $mission['mission_type'])
                : (int) ($progress[$key] ?? 0);

            if ($actual < $target) {
                return false;
            }
        }

        return true;
    }

    private function complete(int $userId, array $mission, string $period): void
    {
        $reward = (int) $mission['xp_reward'];

        if (!$this->missions->markComplete($userId, (int) $mission['id'], $period, $reward)) {
            return;   // another request completed it first
        }

        $this->xp->award(
            $userId,
            $reward,
            $mission['mission_type'] === 'weekly' ? 'weekly_mission' : 'daily_mission',
            'mission',
            (int) $mission['id'],
            'mission:' . $userId . ':' . $mission['id'] . ':' . $period,
            CompetitionService::currentId(),
            ['period' => $period]
        );

        CompetitionService::mirrorXp($userId, $reward);
    }

    private function daysActiveThisPeriod(int $userId, string $type): int
    {
        $timezone = BalinSettings::timezone();
        $now      = new \DateTimeImmutable('now', $timezone);

        if ($type === 'weekly') {
            $monday = $now->modify('monday this week');
            return $this->streaks->daysInRange(
                $userId,
                $monday->format('Y-m-d'),
                $monday->modify('+6 days')->format('Y-m-d')
            );
        }

        return $this->streaks->hasDay($userId, $now->format('Y-m-d')) ? 1 : 0;
    }

    private function periodKey(string $type): string
    {
        return $type === 'weekly' ? BalinSettings::weekKey() : BalinSettings::today();
    }

    private function decodeProgress(?array $row): array
    {
        if ($row === null) {
            return [];
        }
        $decoded = json_decode((string) ($row['progress'] ?? ''), true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /**
     * What to show a student on their profile: each active mission with how
     * far along they are.
     *
     * @return array<int, array<string,mixed>>
     */
    public function statusFor(int $userId): array
    {
        $out = [];

        foreach ($this->missions->active() as $mission) {
            $period   = $this->periodKey((string) $mission['mission_type']);
            $existing = $this->missions->progressFor($userId, (int) $mission['id'], $period);
            $progress = $this->decodeProgress($existing);
            $rules    = $this->missions->rules($mission);

            $targets = [];
            $met     = 0;
            foreach ($rules as $key => $target) {
                $actual = $key === 'streak_days'
                    ? $this->daysActiveThisPeriod($userId, (string) $mission['mission_type'])
                    : (int) ($progress[$key] ?? 0);

                $targets[$key] = ['actual' => min($actual, $target), 'target' => $target];
                if ($actual >= $target) {
                    $met++;
                }
            }

            $out[] = [
                'mission'   => $mission,
                'period'    => $period,
                'targets'   => $targets,
                'completed' => $existing !== null && $existing['completed_at'] !== null,
                'percent'   => $rules === [] ? 0 : (int) round($met / count($rules) * 100),
            ];
        }

        return $out;
    }
}
