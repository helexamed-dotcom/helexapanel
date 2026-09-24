<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Models\ScheduleRepository;

/**
 * The weekly classes one student actually attends.
 *
 * For every term the student is in, the plans of ALL groups of that term (and
 * the term-wide plan) are offered. The student marks, in their profile, which
 * classes they have taken — one from group 23 of term 5, another from group 24
 * of term 4, and so on.
 *
 *  - Until they have chosen anything, each term shows the student's own group
 *    plan (or the term-wide one), exactly as before.
 *  - Once they have chosen, only the chosen classes are shown, in every term.
 *
 * Every page that lists classes (weekly plan, calendar, dashboard, profile,
 * app API) reads from here, so they always agree.
 */
final class StudentSchedule
{
    /** @var array<int,array> per-user cache for this request */
    private static array $cache = [];

    /**
     * @return array<int, array{
     *   term_id:int, term_title:string, schedule:?array, items:array<int,array>,
     *   all_items:array<int,array>, choosable:bool, custom:bool
     * }>
     */
    public static function blocks(array $user): array
    {
        $userId = (int) $user['id'];
        if (isset(self::$cache[$userId])) {
            return self::$cache[$userId];
        }

        $repo    = new ScheduleRepository();
        $groupId = AcademicScope::groupId($user);
        $ready   = ScheduleRepository::choiceReady();
        $picks   = [];
        if ($ready) {
            try {
                $picks = $repo->picksFor($userId);
            } catch (\PDOException) {
                $ready = false;
            }
        }

        $blocks = [];
        foreach ($repo->forStudentTerms(AcademicScope::termIds($user), $groupId) as $entry) {
            $all = [];
            if ($ready) {
                $visible = $repo->visibleForTerm((int) $entry['term_id'], $groupId);
                $all     = $repo->itemsForSchedules(array_column($visible, 'id'));
            }
            foreach ($all as &$item) {
                $item['term_title'] = $entry['term_title'];
            }
            unset($item);

            $blocks[] = [
                'term_id'    => (int) $entry['term_id'],
                'term_title' => $entry['term_title'],
                'schedule'   => $entry['schedule'],
                'items'      => [],
                'all_items'  => $all,
                'choosable'  => $all !== [],
                'custom'     => false,
            ];
        }

        // Chosen anywhere means "show only what I chose", everywhere.
        $custom = false;
        foreach ($blocks as $block) {
            foreach ($block['all_items'] as $item) {
                if (isset($picks[(int) $item['id']])) {
                    $custom = true;
                    break 2;
                }
            }
        }

        foreach ($blocks as &$block) {
            if ($custom) {
                $block['custom'] = true;
                $block['items']  = array_values(array_filter(
                    $block['all_items'],
                    static fn (array $i): bool => isset($picks[(int) $i['id']])
                ));
                continue;
            }

            $default = $block['schedule'];
            if ($default === null) {
                continue;
            }
            if ($block['all_items'] !== []) {
                $defaultId      = (int) $default['id'];
                $block['items'] = array_values(array_filter(
                    $block['all_items'],
                    static fn (array $i): bool => (int) $i['schedule_id'] === $defaultId
                ));
            } else {
                $block['items'] = $repo->items((int) $default['id']);
                foreach ($block['items'] as &$item) {
                    $item['term_title'] = $block['term_title'];
                }
                unset($item);
            }
        }
        unset($block);

        return self::$cache[$userId] = $blocks;
    }

    /**
     * Every weekly plan of every term the student is in, one block per plan,
     * in reading order: per term, the student's own group first, then the
     * term-wide plan, then the other groups. Each class says whether the
     * student has taken it.
     *
     * @return array<int, array{term_title:string, schedule:?array, own:bool, byDay:array<int,array>, count:int}>
     */
    public static function plans(array $user): array
    {
        $repo    = new ScheduleRepository();
        $groupId = AcademicScope::groupId($user);
        $picked  = [];
        if (ScheduleRepository::choiceReady()) {
            try {
                $picked = $repo->picksFor((int) $user['id']);
            } catch (\PDOException) {
                $picked = [];
            }
        }

        $out = [];
        foreach ($repo->forStudentTerms(AcademicScope::termIds($user), $groupId) as $entry) {
            $visible = $repo->visibleForTerm((int) $entry['term_id'], $groupId);
            if ($visible === []) {
                $out[] = ['term_title' => $entry['term_title'], 'schedule' => null, 'own' => false, 'byDay' => [], 'count' => 0];
                continue;
            }

            $bySchedule = [];
            foreach ($repo->itemsForSchedules(array_column($visible, 'id')) as $item) {
                $item['picked'] = isset($picked[(int) $item['id']]);
                $bySchedule[(int) $item['schedule_id']][(int) $item['weekday']][] = $item;
            }

            foreach ($visible as $schedule) {
                $byDay = $bySchedule[(int) $schedule['id']] ?? [];
                $out[] = [
                    'term_title' => $entry['term_title'],
                    'schedule'   => $schedule,
                    'own'        => $groupId !== null && (int) ($schedule['group_id'] ?? 0) === $groupId,
                    'byDay'      => $byDay,
                    'count'      => array_sum(array_map('count', $byDay)),
                ];
            }
        }

        return $out;
    }

    /** Whether the student has chosen their classes. */
    public static function isCustom(array $user): bool
    {
        foreach (self::blocks($user) as $block) {
            if ($block['custom']) {
                return true;
            }
        }
        return false;
    }

    /** Whether there is anything to choose from at all. */
    public static function canChoose(array $user): bool
    {
        foreach (self::blocks($user) as $block) {
            if ($block['choosable']) {
                return true;
            }
        }
        return false;
    }

    /** One weekday's classes across every term, earliest first. */
    public static function forWeekday(array $user, int $weekday): array
    {
        $out = [];
        foreach (self::blocks($user) as $block) {
            foreach ($block['items'] as $item) {
                if ((int) $item['weekday'] === $weekday) {
                    $out[] = $item;
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $a['start_time'], (string) $b['start_time']));

        return $out;
    }

    /** @return array<int, array<int,array>> weekday => the student's classes that day */
    public static function week(array $user): array
    {
        $days = [];
        for ($d = 0; $d <= 6; $d++) {
            $list = self::forWeekday($user, $d);
            if ($list !== []) {
                $days[$d] = $list;
            }
        }
        return $days;
    }

    /**
     * The choice page: per term, the classes grouped by name, and under each
     * name one option per group plan that teaches it.
     *
     * @return array<int, array{term_id:int, term_title:string, lessons:array}>
     */
    public static function choices(array $user): array
    {
        $blocks = self::blocks($user);
        $out    = [];

        foreach ($blocks as $block) {
            if (!$block['choosable']) {
                continue;
            }
            $shown   = array_flip(array_map(static fn (array $i): int => (int) $i['id'], $block['items']));
            $ownId   = $block['schedule'] !== null ? (int) $block['schedule']['id'] : 0;
            $lessons = [];

            foreach ($block['all_items'] as $item) {
                $key = self::norm((string) $item['title']);
                $opt = (int) $item['schedule_id'];
                if (!isset($lessons[$key])) {
                    $lessons[$key] = ['title' => (string) $item['title'], 'options' => []];
                }
                if (!isset($lessons[$key]['options'][$opt])) {
                    $lessons[$key]['options'][$opt] = [
                        'label'    => $item['group_title'] ?: 'کل ترم',
                        'schedule' => (string) $item['schedule_title'],
                        'own'      => $opt === $ownId,
                        'items'    => [],
                        'checked'  => true,
                    ];
                }
                $lessons[$key]['options'][$opt]['items'][] = $item;
                // An option counts as chosen only when every session of it is shown.
                if (!isset($shown[(int) $item['id']])) {
                    $lessons[$key]['options'][$opt]['checked'] = false;
                }
            }

            foreach ($lessons as &$lesson) {
                $lesson['options'] = array_values($lesson['options']);
            }
            unset($lesson);

            $out[] = [
                'term_id'    => $block['term_id'],
                'term_title' => $block['term_title'],
                'lessons'    => array_values($lessons),
            ];
        }

        return $out;
    }

    /**
     * Saves the chosen classes. Anything that is not one of the student's own
     * terms' classes is ignored.
     *
     * @param  array<int,int> $itemIds
     * @return array{saved:int, clashes:array<int,string>}
     */
    public static function save(array $user, array $itemIds): array
    {
        $allowed = [];
        $byId    = [];
        foreach (self::blocks($user) as $block) {
            foreach ($block['all_items'] as $item) {
                $allowed[(int) $item['id']] = (int) $block['term_id'];
                $byId[(int) $item['id']]    = $item;
            }
        }

        $picks = [];
        foreach ($itemIds as $id) {
            $id = (int) $id;
            if (isset($allowed[$id])) {
                $picks[$id] = $allowed[$id];
            }
        }

        (new ScheduleRepository())->replacePicks((int) $user['id'], $picks);
        unset(self::$cache[(int) $user['id']]);

        return ['saved' => count($picks), 'clashes' => self::clashes(array_intersect_key($byId, $picks))];
    }

    /** Pairs of chosen sessions that overlap in time on the same day. */
    private static function clashes(array $items): array
    {
        $items = array_values($items);
        $out   = [];
        $count = count($items);
        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                $x = $items[$a];
                $y = $items[$b];
                if ((int) $x['weekday'] !== (int) $y['weekday']) {
                    continue;
                }
                if ((string) $x['start_time'] < (string) $y['end_time'] && (string) $y['start_time'] < (string) $x['end_time']) {
                    $out[] = Jalali::WEEKDAYS[(int) $x['weekday']] . ': «' . $x['title'] . '» و «' . $y['title'] . '»';
                }
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 10);
    }

    private static function norm(string $s): string
    {
        $s = str_replace(['ي', 'ك', "\u{200C}"], ['ی', 'ک', ' '], $s);

        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? $s);
    }
}
