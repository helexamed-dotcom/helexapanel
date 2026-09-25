<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Services\QuestionBank\QbAccess;

/**
 * «چی بخونم، چی نخونم؟» across the whole product.
 *
 * Every module files its content under the same shared tags, so for one
 * student and one tag the results can be put side by side: the question
 * bank and «آزمون‌های من» (last answer to each question), flashcards (last
 * rating of each card), the figure game, بالین — and how much of the tag's
 * درسنامه pages they have read. From that each tag gets a mastery score, a
 * verdict (strong / shaky / weak / untested), the next concrete steps with
 * links, and a week of study that fits a daily budget.
 *
 * Only numbers the student produced are used; a module that is not
 * installed simply contributes nothing.
 */
final class TagMastery
{
    /** How much one result counts, by where it came from. */
    private const WEIGHT = ['questions' => 1.0, 'flashcards' => 0.6, 'figures' => 0.5, 'balin' => 0.8];

    public const STRONG = 0.8;
    public const WEAK   = 0.6;
    /** Weighted results below this are too few to judge. */
    private const MIN_EVIDENCE = 3.0;

    public const LABELS = [
        'strong'   => ['مسلط', 'green'],
        'shaky'    => ['نیاز به تمرین', 'amber'],
        'weak'     => ['ضعف', 'red'],
        'untested' => ['هنوز محک نخورده', 'slate'],
    ];

    /**
     * @return list<array{id:int,title:string,color:?string,sources:array,score:?float,evidence:float,
     *                     pages:int,read:int,unread_minutes:int,status:string,priority:float}>
     */
    public static function forUser(int $userId): array
    {
        $tags = [];
        foreach (self::rows('SELECT id, title, color FROM qb_tags WHERE is_active = 1', []) as $t) {
            $tags[(int) $t['id']] = [
                'id' => (int) $t['id'], 'title' => $t['title'], 'color' => $t['color'],
                'sources' => [], 'pages' => 0, 'read' => 0, 'unread_minutes' => 0, 'questions_total' => 0,
            ];
        }
        if ($tags === []) {
            return [];
        }

        $add = static function (array $rows, string $source) use (&$tags): void {
            foreach ($rows as $r) {
                $id = (int) $r['tag_id'];
                if (!isset($tags[$id]) || (int) $r['total'] === 0) {
                    continue;
                }
                $s = $tags[$id]['sources'][$source] ?? ['total' => 0, 'correct' => 0];
                $s['total'] += (int) $r['total'];
                $s['correct'] += (int) $r['correct'];
                $tags[$id]['sources'][$source] = $s;
            }
        };

        // The question bank: the latest answer to each question counts once.
        $add(self::rows(
            'SELECT qt.tag_id, COUNT(*) AS total, SUM(a.is_correct) AS correct
             FROM qb_attempts a
             JOIN (SELECT question_id, MAX(id) AS mid FROM qb_attempts WHERE user_id = :u GROUP BY question_id) m ON m.mid = a.id
             JOIN qb_question_tags qt ON qt.question_id = a.question_id
             GROUP BY qt.tag_id',
            ['u' => $userId]
        ), 'questions');
        // «آزمون‌های من»: every answered question of a finished exam.
        $add(self::rows(
            "SELECT qt.tag_id, COUNT(*) AS total, SUM(ea.is_correct = 1) AS correct
             FROM qb_my_exam_answers ea JOIN qb_my_exams e ON e.id = ea.exam_id AND e.user_id = :u AND e.status = 'finished'
             JOIN qb_question_tags qt ON qt.question_id = ea.question_id
             WHERE ea.option_id IS NOT NULL
             GROUP BY qt.tag_id",
            ['u' => $userId]
        ), 'questions');
        // Flashcards: the last rating of each card; «خوب» or «آسان» is a hit.
        $add(self::rows(
            'SELECT dt.tag_id, COUNT(*) AS total, SUM(l.rating >= 3) AS correct
             FROM fc_review_log l
             JOIN (SELECT card_id, MAX(id) AS mid FROM fc_review_log WHERE user_id = :u GROUP BY card_id) m ON m.mid = l.id
             JOIN fc_cards c ON c.id = l.card_id
             JOIN fc_deck_tags dt ON dt.deck_id = c.deck_id
             GROUP BY dt.tag_id',
            ['u' => $userId]
        ), 'flashcards');
        // The figure game: each play's score counts for every tag on its hotspots.
        $add(self::rows(
            'SELECT ft.tag_id, SUM(p.total) AS total, SUM(p.correct) AS correct
             FROM figure_plays p
             JOIN (SELECT DISTINCT figure_id, tag_id FROM figure_spots WHERE tag_id IS NOT NULL) ft ON ft.figure_id = p.figure_id
             WHERE p.user_id = :u
             GROUP BY ft.tag_id',
            ['u' => $userId]
        ), 'figures');
        // بالین: answers inside a tagged lesson.
        $add(self::rows(
            'SELECT bt.tag_id, COUNT(*) AS total, SUM(a.is_correct) AS correct
             FROM balin_answers a JOIN balin_lesson_tags bt ON bt.lesson_id = a.lesson_id
             WHERE a.user_id = :u
             GROUP BY bt.tag_id',
            ['u' => $userId]
        ), 'balin');

        // درسنامه pages: how many exist under the tag and how many are read.
        foreach (self::rows(
            "SELECT pt.tag_id, COUNT(*) AS pages, SUM(r.read_at IS NOT NULL) AS done,
                    SUM(CASE WHEN r.read_at IS NULL THEN p.reading_minutes ELSE 0 END) AS unread_minutes
             FROM lesson_page_tags pt
             JOIN lesson_pages p ON p.id = pt.page_id
             JOIN lessons l ON l.id = p.lesson_id AND l.status = 'published' AND l.deleted_at IS NULL
             LEFT JOIN lesson_page_reads r ON r.page_id = p.id AND r.user_id = :u
             GROUP BY pt.tag_id",
            ['u' => $userId]
        ) as $r) {
            if (isset($tags[(int) $r['tag_id']])) {
                $tags[(int) $r['tag_id']]['pages'] = (int) $r['pages'];
                $tags[(int) $r['tag_id']]['read'] = (int) $r['done'];
                $tags[(int) $r['tag_id']]['unread_minutes'] = (int) $r['unread_minutes'];
            }
        }
        foreach (self::rows(
            "SELECT qt.tag_id, COUNT(*) AS c FROM qb_question_tags qt
             JOIN qb_questions q ON q.id = qt.question_id AND q.status = 'published' AND q.deleted_at IS NULL
             GROUP BY qt.tag_id", []
        ) as $r) {
            if (isset($tags[(int) $r['tag_id']])) {
                $tags[(int) $r['tag_id']]['questions_total'] = (int) $r['c'];
            }
        }

        $out = [];
        foreach ($tags as $t) {
            if ($t['sources'] === [] && $t['pages'] === 0 && $t['questions_total'] === 0) {
                continue; // a tag with nothing under it
            }
            $w = $hit = 0.0;
            foreach ($t['sources'] as $src => $s) {
                $w += self::WEIGHT[$src] * $s['total'];
                $hit += self::WEIGHT[$src] * $s['correct'];
            }
            $t['evidence'] = round($w, 1);
            $t['score'] = $w > 0 ? $hit / $w : null;
            $t['status'] = match (true) {
                $w < self::MIN_EVIDENCE      => 'untested',
                $t['score'] >= self::STRONG  => 'strong',
                $t['score'] < self::WEAK     => 'weak',
                default                      => 'shaky',
            };
            $coverage = $t['pages'] > 0 ? $t['read'] / $t['pages'] : 1.0;
            $confidence = min(1.0, $w / 10);
            // Weak and well-evidenced first; then what was never tried; unread
            // material nudges a tag up, mastery pushes it to the bottom.
            $t['priority'] = round(match ($t['status']) {
                'weak'     => 3 + (1 - $t['score']) * 2 + $confidence,
                'shaky'    => 2 + (self::STRONG - $t['score']) * 4,
                'untested' => 1 + (1 - $coverage),
                default    => 0.2 * (1 - $coverage),
            } + (1 - $coverage) * 0.5, 3);
            $out[] = $t;
        }
        usort($out, static fn (array $a, array $b): int => [$b['priority'], $a['title']] <=> [$a['priority'], $b['title']]);
        return $out;
    }

    /** Headline numbers for the top of the page. */
    public static function summary(array $tags): array
    {
        $w = $hit = 0.0;
        $count = ['strong' => 0, 'shaky' => 0, 'weak' => 0, 'untested' => 0];
        foreach ($tags as $t) {
            $count[$t['status']]++;
            foreach ($t['sources'] as $src => $s) {
                $w += self::WEIGHT[$src] * $s['total'];
                $hit += self::WEIGHT[$src] * $s['correct'];
            }
        }
        return $count + ['accuracy' => $w > 0 ? (int) round($hit * 100 / $w) : null, 'tags' => count($tags)];
    }

    /**
     * The concrete next steps for one tag: which pages to read (unread first;
     * all of them again when the tag is weak and everything is read), which
     * questions to practise, which flashcards to review.
     *
     * @return list<array{kind:string,title:string,url:string,minutes:int,tag:string,tag_id:int}>
     */
    public static function steps(int $userId, array $tag): array
    {
        $id = (int) $tag['id'];
        $steps = [];
        $status = $tag['status'];
        if ($status === 'strong') {
            return [];
        }

        // Pages under the tag; a weak tag re-reads what was read, others only what is new.
        $pages = self::rows(
            "SELECT p.uuid, p.title, p.reading_minutes, l.uuid AS lesson_uuid, l.title AS lesson_title, l.package_id,
                    (r.read_at IS NOT NULL) AS is_read
             FROM lesson_page_tags pt JOIN lesson_pages p ON p.id = pt.page_id
             JOIN lessons l ON l.id = p.lesson_id AND l.status = 'published' AND l.deleted_at IS NULL
             JOIN lesson_sections s ON s.id = p.section_id
             LEFT JOIN lesson_page_reads r ON r.page_id = p.id AND r.user_id = :u
             WHERE pt.tag_id = :t
             ORDER BY is_read, l.sort_order, s.sort_order, p.sort_order LIMIT 6",
            ['u' => $userId, 't' => $id]
        );
        $held = self::heldPackages($userId);
        foreach ($pages as $p) {
            if ((int) $p['is_read'] === 1 && $status !== 'weak') {
                continue;
            }
            if (!empty($p['package_id']) && !isset($held[(int) $p['package_id']])) {
                continue; // a درسنامه of a package the student does not hold
            }
            $steps[] = [
                'kind' => (int) $p['is_read'] === 1 ? 'reread' : 'read', 'title' => $p['title'],
                'sub' => $p['lesson_title'], 'url' => '/student/lessons/' . $p['lesson_uuid'] . '/p/' . $p['uuid'],
                'minutes' => max(2, (int) $p['reading_minutes']), 'tag' => $tag['title'], 'tag_id' => $id,
            ];
            if (count(array_filter($steps, static fn ($s) => $s['kind'] !== 'practice')) >= 3) {
                break;
            }
        }

        // Questions: the درس that has the most questions under the tag and is open to the student.
        foreach (self::rows(
            "SELECT s.id, s.uuid, s.title, COUNT(*) AS c FROM qb_question_tags qt
             JOIN qb_questions q ON q.id = qt.question_id AND q.status = 'published' AND q.deleted_at IS NULL
             JOIN qb_subjects s ON s.id = q.subject_id AND s.is_active = 1
             WHERE qt.tag_id = :t GROUP BY s.id, s.uuid, s.title ORDER BY c DESC LIMIT 3",
            ['t' => $id]
        ) as $s) {
            try {
                if (!QbAccess::allowsSubject($userId, (int) $s['id'])) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $mode = match ($status) { 'weak', 'shaky' => ($tag['sources']['questions']['total'] ?? 0) > ($tag['sources']['questions']['correct'] ?? 0) ? 'wrong' : 'new', default => 'new' };
            $n = min(10, (int) $s['c']);
            $steps[] = [
                'kind' => 'practice', 'title' => ($mode === 'wrong' ? 'دوباره: سوال‌های غلط' : 'تمرین ' . fa((string) $n) . ' سوال'),
                'sub' => $s['title'], 'url' => '/student/qbank/' . $s['uuid'] . '?tag=' . $id . '&mode=' . $mode,
                'minutes' => max(5, (int) round($n * 1.2)), 'tag' => $tag['title'], 'tag_id' => $id,
            ];
            break;
        }

        // Flashcards of a tagged session, for spaced review of what is shaky.
        foreach (self::rows(
            "SELECT d.uuid, d.title FROM fc_deck_tags dt JOIN fc_decks d ON d.id = dt.deck_id AND d.course_id IS NOT NULL
             JOIN fc_courses c ON c.id = d.course_id AND c.status = 'published'
             JOIN fc_access a ON a.course_id = c.id AND a.user_id = :u
             WHERE dt.tag_id = :t ORDER BY d.sort_order LIMIT 1",
            ['t' => $id, 'u' => $userId]
        ) as $d) {
            $steps[] = [
                'kind' => 'flash', 'title' => 'مرور فلش‌کارت‌ها', 'sub' => $d['title'],
                'url' => '/student/flashcards/deck/' . $d['uuid'], 'minutes' => 8, 'tag' => $tag['title'], 'tag_id' => $id,
            ];
        }
        return $steps;
    }

    /**
     * What is safe to leave for now: the read pages of mastered tags.
     *
     * @return list<array{title:string,url:string,tag:string}>
     */
    public static function skippable(int $userId, array $tags, int $limit = 6): array
    {
        $strong = array_map(static fn ($t) => (int) $t['id'], array_filter($tags, static fn ($t) => $t['status'] === 'strong'));
        if ($strong === []) {
            return [];
        }
        return self::rows(
            "SELECT DISTINCT p.title, CONCAT('/student/lessons/', l.uuid, '/p/', p.uuid) AS url, t.title AS tag
             FROM lesson_page_tags pt JOIN lesson_pages p ON p.id = pt.page_id
             JOIN lessons l ON l.id = p.lesson_id AND l.status = 'published' AND l.deleted_at IS NULL
             JOIN qb_tags t ON t.id = pt.tag_id
             WHERE pt.tag_id IN (" . implode(',', $strong) . ')
               AND NOT EXISTS (SELECT 1 FROM lesson_page_tags o JOIN qb_tags ot ON ot.id = o.tag_id
                               WHERE o.page_id = p.id AND o.tag_id NOT IN (' . implode(',', $strong) . '))
             LIMIT ' . max(1, $limit),
            []
        );
    }

    /**
     * A week of study from the top tags' steps. One or two topics a day
     * (never more than the daily budget), reading before practice, each
     * page only once even when it carries several weak tags, and a short
     * re-test of every weak or shaky topic three days after it was studied —
     * spaced, so the plan checks that it worked.
     *
     * @return list<array{date:string,label:string,long:string,minutes:int,items:list<array>}>
     */
    public static function plan(int $userId, array $tags, int $budget, int $days = 7): array
    {
        $groups = [];
        $seen = [];
        foreach (array_slice(array_values(array_filter($tags, static fn ($t) => $t['status'] !== 'strong')), 0, 10) as $t) {
            $steps = [];
            foreach (self::steps($userId, $t) as $step) {
                if (!isset($seen[$step['url']])) {
                    $seen[$step['url']] = true;
                    $steps[] = $step;
                }
            }
            if ($steps !== []) {
                $groups[] = ['tag' => $t, 'steps' => $steps];
            }
        }

        $week = [];
        $today = new \DateTimeImmutable('today');
        for ($d = 0; $d < $days; $d++) {
            $date = $today->modify('+' . $d . ' day');
            $week[] = [
                'date' => $date->format('Y-m-d'),
                'label' => $d === 0 ? 'امروز' : ($d === 1 ? 'فردا' : Jalali::WEEKDAYS[Jalali::weekdayIndex($date->getTimestamp())]),
                'long' => Jalali::longDate($date->getTimestamp()), 'minutes' => 0, 'items' => [], 'topics' => [],
            ];
        }
        $place = static function (int $d, array $item) use (&$week, $days, $budget): bool {
            if ($d >= $days || ($week[$d]['minutes'] > 0 && $week[$d]['minutes'] + $item['minutes'] > $budget)) {
                return false;
            }
            $week[$d]['items'][] = $item;
            $week[$d]['minutes'] += $item['minutes'];
            $week[$d]['topics'][$item['tag_id']] = $item['tag'];
            return true;
        };

        $day = 0;
        foreach ($groups as $g) {
            // A day holds two topics at most; a third starts tomorrow.
            while ($day < $days && count($week[$day]['topics']) >= 2 && !isset($week[$day]['topics'][$g['tag']['id']])) {
                $day++;
            }
            $studied = null;
            foreach ($g['steps'] as $step) {
                while ($day < $days && !$place($day, $step)) {
                    $day++;
                }
                if ($day >= $days) {
                    break 2;
                }
                $studied = $day;
            }
            if ($studied !== null && in_array($g['tag']['status'], ['weak', 'shaky'], true)) {
                foreach ($g['steps'] as $step) {
                    if ($step['kind'] === 'practice') {
                        $retest = ['kind' => 'retest', 'title' => 'آزمون دوباره «' . $g['tag']['title'] . '»', 'sub' => 'ببین یاد گرفته‌ای یا نه',
                            'url' => preg_replace('/&mode=\w+/', '&mode=answered', $step['url']) ?? $step['url'], 'minutes' => 8,
                            'tag' => $g['tag']['title'], 'tag_id' => (int) $g['tag']['id']];
                        for ($r = $studied + 3; $r < $days && !$place($r, $retest); $r++) {
                        }
                        break;
                    }
                }
            }
        }
        return $week;
    }

    /** @return array<int,true> */
    private static function heldPackages(int $userId): array
    {
        return AccessProfile::heldMap($userId);
    }

    private static function rows(string $sql, array $params): array
    {
        try {
            return Database::select($sql, $params);
        } catch (\PDOException) {
            return []; // that module is not installed here
        }
    }
}
