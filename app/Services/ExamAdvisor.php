<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Models\LessonRepository;

/**
 * «چی بخونم، چی نخونم؟» — reading advice from one finished exam.
 *
 * Every question answered wrong or left blank points at the درسنامه‌ها that
 * teach it (linked by hand, by a shared tag, or by topic). Those are ranked
 * by how many of the student's misses each one covers, so the first card is
 * the single most useful thing to read. Topics answered at 80% or better are
 * strengths: their درسنامه‌ها are listed as safe to skip for now.
 */
final class ExamAdvisor
{
    /**
     * @param array $questions  the exam's questions (id, …)
     * @param array $answers    question id => [option_id, is_correct]
     * @param array $performance per-topic groups from QbMyExamRepository::performance()
     * @return array{read:list<array>, skip:list<array>, strong:list<array>, weak:list<array>, perQuestion:array<int,list<array>>, plan:list<string>}
     */
    public static function advise(array $questions, array $answers, array $performance): array
    {
        $out = ['read' => [], 'skip' => [], 'strong' => [], 'weak' => [], 'perQuestion' => [], 'plan' => []];
        foreach ($performance as $g) {
            if ($g['total'] >= 1 && $g['percent'] >= 80) {
                $out['strong'][] = $g;
            } elseif ($g['percent'] < 50) {
                $out['weak'][] = $g;
            }
        }

        if (!Modules::enabled('lessons') || !LessonRepository::ready()) {
            $out['plan'] = self::plan($out, 0);
            return $out;
        }

        $repo  = new LessonRepository();
        $score = [];
        $okLessons = [];
        foreach ($questions as $n => $q) {
            $qid = (int) $q['id'];
            $a = $answers[$qid] ?? ['option_id' => null, 'is_correct' => null];
            $missed = $a['option_id'] === null || (int) $a['is_correct'] !== 1;
            try {
                $lessons = $repo->forQuestion($qid, 3);
            } catch (\PDOException) {
                $lessons = [];
            }
            $out['perQuestion'][$qid] = $lessons;
            foreach ($lessons as $l) {
                $id = (int) $l['id'];
                if ($missed) {
                    $score[$id] ??= ['lesson' => $l, 'missed' => 0, 'numbers' => [], 'direct' => 0];
                    $score[$id]['missed']++;
                    $score[$id]['numbers'][] = $n + 1;
                    $score[$id]['direct'] += $l['why'] === 'link' ? 1 : 0;
                } else {
                    $okLessons[$id] = $l;
                }
            }
        }

        uasort($score, static fn (array $a, array $b): int => [$b['missed'], $b['direct']] <=> [$a['missed'], $a['direct']]);
        $out['read'] = array_slice(array_values($score), 0, 6);
        // Taught only questions the student got right: nothing to read there now.
        $out['skip'] = array_slice(array_values(array_diff_key($okLessons, $score)), 0, 6);
        $out['plan'] = self::plan($out, count($out['read']));

        return $out;
    }

    /** Three or four short steps, in the order they should be done. */
    private static function plan(array $a, int $lessonCount): array
    {
        $steps = [];
        if ($lessonCount > 0) {
            $first = $a['read'][0]['lesson']['title'];
            $steps[] = 'اول درسنامه «' . $first . '» را بخوان؛ ' . fa((string) $a['read'][0]['missed']) . ' سوالی که از دست دادی را توضیح می‌دهد.';
        }
        if ($a['weak'] !== []) {
            $titles = array_slice(array_column($a['weak'], 'title'), 0, 3);
            $steps[] = 'بخش‌های ضعیف: ' . implode('، ', $titles) . ' — بعد از خواندن، فقط سوال‌های غلط همین بخش‌ها را دوباره تمرین کن.';
        }
        if ($a['strong'] !== []) {
            $steps[] = 'در ' . implode('، ', array_slice(array_column($a['strong'], 'title'), 0, 3)) . ' مسلطی؛ فعلاً وقتت را این‌جا نگذار.';
        }
        $steps[] = 'چند روز بعد یک آزمون تازه از همین سوال‌ها بساز تا ببینی چقدر یاد گرفته‌ای.';
        return $steps;
    }
}
