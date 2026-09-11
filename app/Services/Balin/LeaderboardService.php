<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Models\Balin\BalinLeaderboardRepository;
use HeleXa\Models\Balin\BalinLessonRepository;
use HeleXa\Models\Balin\BalinSkillTrackRepository;
use HeleXa\Models\Balin\BalinStreakRepository;
use HeleXa\Models\Balin\BalinXpRepository;

/**
 * Building the leaderboards.
 *
 * Every board is rebuilt from its source — the XP ledger, the mastery cache,
 * the streak table — and written to a snapshot the pages read. Two things
 * follow from that: a page view costs an indexed lookup rather than a scan,
 * and a row edited directly in the snapshot table is corrected by the next
 * rebuild, so tampering with it buys nothing.
 */
final class LeaderboardService
{
    public function __construct(
        private readonly BalinLeaderboardRepository $boards = new BalinLeaderboardRepository(),
        private readonly BalinXpRepository $xp = new BalinXpRepository(),
        private readonly BalinStreakRepository $streaks = new BalinStreakRepository(),
        private readonly BalinLessonRepository $lessons = new BalinLessonRepository(),
        private readonly BalinSkillTrackRepository $tracks = new BalinSkillTrackRepository(),
    ) {
    }

    /**
     * Rebuilds every board. Cheap enough to run on a schedule; the row
     * counts here are a few hundred, not millions.
     *
     * @return array<string,int> board name => rows written
     */
    public function rebuildAll(): array
    {
        $written = [];

        $overall = $this->xp->leaderboardRows();
        $this->boards->replaceBoard('overall_xp', null, $overall);
        $written['overall_xp'] = count($overall);

        $competitionId = CompetitionService::currentId();
        if ($competitionId !== null) {
            $weekly = $this->xp->competitionLeaderboardRows($competitionId);
            $this->boards->replaceBoard('weekly_xp', null, $weekly);
            $written['weekly_xp'] = count($weekly);
        } else {
            // No competition running: an empty board is honest, a stale one
            // from last week is not.
            $this->boards->replaceBoard('weekly_xp', null, []);
            $written['weekly_xp'] = 0;
        }

        $mastery = $this->boards->masterySourceRows();
        $this->boards->replaceBoard('mastery', null, $mastery);
        $written['mastery'] = count($mastery);

        $streak = $this->streaks->leaderboardRows();
        $this->boards->replaceBoard('streak', null, $streak);
        $written['streak'] = count($streak);

        foreach ($this->lessons->all() as $lesson) {
            if ($lesson['status'] !== 'published') {
                continue;
            }
            $rows = $this->boards->lessonSourceRows((int) $lesson['id']);
            $this->boards->replaceBoard('lesson', (int) $lesson['id'], $rows);
            $written['lesson:' . $lesson['id']] = count($rows);
        }

        foreach ($this->tracks->all(true) as $track) {
            $rows = $this->boards->skillSourceRows((int) $track['id']);
            $this->boards->replaceBoard('skill', (int) $track['id'], $rows);
            $written['skill:' . $track['id']] = count($rows);
        }

        return $written;
    }

    /** True when the newest snapshot is older than the configured interval. */
    public function isStale(): bool
    {
        $generated = $this->boards->generatedAt('overall_xp', null);
        if ($generated === null) {
            return true;
        }

        return strtotime($generated) < time() - BalinSettings::leaderboardRebuildMinutes() * 60;
    }

    /**
     * Rebuilds only when the snapshot has aged out. Called from the board
     * page so an installation with no cron still shows fresh numbers,
     * without every request paying for a rebuild.
     */
    public function rebuildIfStale(): void
    {
        if (!$this->isStale()) {
            return;
        }

        try {
            $this->rebuildAll();
        } catch (\Throwable) {
            // A stale board beats a broken page.
        }
    }

    /**
     * One page of a board, with the viewer's own rank attached even when it
     * falls outside the page.
     *
     * @return array{rows:array, total:int, page:int, pages:int, own_rank:?array, generated_at:?string}
     */
    public function page(string $boardType, ?int $scopeId, int $page, ?int $viewerId): array
    {
        $perPage = BalinSettings::leaderboardPageSize();
        $page    = max(1, $page);
        $total   = $this->boards->countBoard($boardType, $scopeId);

        return [
            'rows'         => $this->boards->page($boardType, $scopeId, $perPage, ($page - 1) * $perPage),
            'total'        => $total,
            'page'         => $page,
            'pages'        => max(1, (int) ceil($total / $perPage)),
            'own_rank'     => $viewerId === null ? null : $this->boards->rankFor($viewerId, $boardType, $scopeId),
            'generated_at' => $this->boards->generatedAt($boardType, $scopeId),
        ];
    }
}
