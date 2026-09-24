<?php
declare(strict_types=1);

namespace HeleXa\Controllers\Student;

use HeleXa\Core\Controller;
use HeleXa\Core\Request;
use HeleXa\Core\Response;
use HeleXa\Models\ContentStatusRepository;
use HeleXa\Models\StudyMarkRepository;
use HeleXa\Services\Auth;
use HeleXa\Services\StudyAnalytics;
use HeleXa\Services\TagMastery;

/**
 * «تحلیل عملکرد»: strengths and weaknesses by shared tag across every
 * module, what to read and what to leave, a week's plan — and the study
 * time underneath.
 */
final class AnalyticsController extends Controller
{
    public const BUDGETS = [30, 45, 60, 90, 120];

    public function index(Request $request, array $params = []): Response
    {
        $userId   = (int) Auth::id();
        $weeksAgo = max(0, min(3, $request->int('week', 0)));
        $budget   = in_array($request->int('budget'), self::BUDGETS, true) ? $request->int('budget') : 60;

        $week     = StudyAnalytics::week($userId, $weeksAgo);
        $previous = StudyAnalytics::week($userId, $weeksAgo + 1);
        $tags     = TagMastery::forUser($userId);

        // The three topics to work on first, each with its concrete steps.
        $focus = [];
        foreach ($tags as $t) {
            if ($t['status'] === 'strong') {
                continue;
            }
            $steps = TagMastery::steps($userId, $t);
            if ($steps !== []) {
                $focus[] = $t + ['steps' => $steps];
            }
            if (count($focus) === 3) {
                break;
            }
        }

        return $this->page('layouts.app', 'student.analytics', [
            'title'         => 'تحلیل عملکرد',
            'tags'          => $tags,
            'summary'       => TagMastery::summary($tags),
            'focus'         => $focus,
            'strong'        => array_values(array_filter($tags, static fn ($t) => $t['status'] === 'strong')),
            'skip'          => TagMastery::skippable($userId, $tags),
            'plan'          => TagMastery::plan($userId, $tags, $budget),
            'budget'        => $budget,
            'budgets'       => self::BUDGETS,
            'week'          => $week,
            'weeksAgo'      => $weeksAgo,
            'previousTotal' => $previous['total'],
            'courses'       => StudyAnalytics::coursesThisWeek($userId, $weeksAgo),
            'breakdown'     => (new ContentStatusRepository())->breakdownByCourse($userId),
            'extraCss'      => ['analytics'],
        ]);
    }

    /**
     * POST /student/analytics/plan — the week's plan into «درس‌های من»,
     * each step due on its day. Recomputed here, so the page cannot hand in
     * steps of its own.
     */
    public function savePlan(Request $request, array $params = []): Response
    {
        $userId = (int) Auth::id();
        $budget = in_array($request->int('budget'), self::BUDGETS, true) ? $request->int('budget') : 60;
        $marks  = new StudyMarkRepository();
        $added  = 0;
        foreach (TagMastery::plan($userId, TagMastery::forUser($userId), $budget) as $day) {
            foreach ($day['items'] as $item) {
                $marks->add($userId, 'custom', null, self::ICONS[$item['kind']] . ' ' . $item['title'] . ' · ' . $item['tag'],
                    $item['url'], 'برنامه تحلیل عملکرد — ' . fa((string) $item['minutes']) . ' دقیقه', $day['date']);
                $added++;
            }
        }
        $this->flash($added ? 'success' : 'error', $added
            ? fa((string) $added) . ' کار با تاریخ هر روز به «درس‌های من» اضافه شد.'
            : 'فعلاً چیزی برای برنامه‌ریزی نیست؛ اول چند سوال یا فلش‌کارت تمرین کن.');

        return $this->redirect($added ? '/student/study' : '/student/analytics');
    }

    public const ICONS = ['read' => '📘', 'reread' => '🔁', 'practice' => '❓', 'flash' => '🃏', 'retest' => '🎯'];
}
