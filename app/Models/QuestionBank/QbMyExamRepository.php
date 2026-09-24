<?php
declare(strict_types=1);

namespace HeleXa\Models\QuestionBank;

use HeleXa\Core\Str;
use HeleXa\Models\BaseRepository;

/**
 * «آزمون‌های من»: the questions a student collected, and the exams they build
 * from them.
 *
 * The collection is a mark ('exam') on qb_marks, so it files itself: a
 * question added from باکتری shows up under باکتری, its زیردرس and its عنوان,
 * with nothing stored beyond the mark. Moving a question in the bank moves it
 * in everyone's collection too.
 *
 * Every read is restricted to published, undeleted questions and to the درس
 * ids the caller passes in — the ones this student may open right now.
 */
final class QbMyExamRepository extends BaseRepository
{
    /** Iran's usual rule: a wrong answer takes back a third of a right one. */
    public const NEGATIVE_WEIGHT = 1 / 3;

    /* ======================================================== collection */

    /**
     * Every collected question with its filing, oldest درس order first.
     *
     * @param array<int,int> $subjectIds the درس ids the student may open
     * @return array<int,array<string,mixed>>
     */
    public function collection(int $userId, array $subjectIds): array
    {
        $ids = $this->intList($subjectIds);
        if ($ids === '') {
            return [];
        }

        return $this->select(
            "SELECT q.id, q.uuid, q.subject_id, q.sub_subject_id, q.topic_id, q.difficulty,
                    s.title AS subject_title, ss.title AS sub_title, t.title AS topic_title,
                    m.created_at AS added_at
             FROM qb_marks m
             JOIN qb_questions q ON q.id = m.question_id AND q.status = 'published' AND q.deleted_at IS NULL
             LEFT JOIN qb_subjects s  ON s.id  = q.subject_id
             LEFT JOIN qb_subjects ss ON ss.id = q.sub_subject_id
             LEFT JOIN qb_subjects t  ON t.id  = q.topic_id
             WHERE m.user_id = :u AND m.mark = 'exam' AND q.subject_id IN ($ids)
             ORDER BY s.sort_order, s.title, ss.sort_order, ss.title, t.sort_order, t.title, q.id",
            ['u' => $userId]
        );
    }

    /**
     * The collection as a tree: درس → زیردرس → عنوان, each with a count.
     *
     * @param array<int,array<string,mixed>> $rows from collection()
     * @return array<int,array{id:int,title:string,count:int,subs:array}>
     */
    public static function tree(array $rows): array
    {
        $tree = [];
        foreach ($rows as $row) {
            $sid = (int) $row['subject_id'];
            $bid = (int) ($row['sub_subject_id'] ?? 0);
            $tid = (int) ($row['topic_id'] ?? 0);

            $tree[$sid] ??= ['id' => $sid, 'title' => (string) $row['subject_title'], 'count' => 0, 'subs' => []];
            $tree[$sid]['count']++;

            $subTitle = $bid > 0 ? (string) $row['sub_title'] : 'کلیات';
            $tree[$sid]['subs'][$bid] ??= ['id' => $bid, 'title' => $subTitle, 'count' => 0, 'topics' => []];
            $tree[$sid]['subs'][$bid]['count']++;

            if ($tid > 0) {
                $tree[$sid]['subs'][$bid]['topics'][$tid] ??= ['id' => $tid, 'title' => (string) $row['topic_title'], 'count' => 0];
                $tree[$sid]['subs'][$bid]['topics'][$tid]['count']++;
            }
        }
        return $tree;
    }

    /* ============================================================= exams */

    /**
     * Creates an exam over the given questions, with a blank answer row for
     * each so the answer sheet can be saved one tick at a time.
     *
     * @param array<int,int> $questionIds already in the order to show them
     */
    public function create(int $userId, string $title, ?int $subjectId, array $questionIds, bool $negative, int $minutes): array
    {
        $uuid = Str::uuid4();
        $id   = $this->insert(
            'INSERT INTO qb_my_exams (uuid, user_id, title, subject_id, question_ids, negative_marking,
                                      duration_minutes, status, started_at)
             VALUES (:uuid, :u, :title, :subject, :qids, :neg, :minutes, \'in_progress\', :now)',
            [
                'uuid'    => $uuid,
                'u'       => $userId,
                'title'   => mb_substr($title, 0, 191),
                'subject' => $subjectId,
                'qids'    => json_encode(array_values(array_map('intval', $questionIds))),
                'neg'     => $negative ? 1 : 0,
                'minutes' => max(0, min(600, $minutes)),
                'now'     => $this->now(),
            ]
        );

        foreach ($questionIds as $questionId) {
            $this->execute(
                'INSERT IGNORE INTO qb_my_exam_answers (exam_id, question_id) VALUES (:e, :q)',
                ['e' => $id, 'q' => (int) $questionId]
            );
        }

        return $this->find($uuid, $userId) ?? [];
    }

    /** One exam, only ever the owner's. */
    public function find(string $uuid, int $userId): ?array
    {
        $row = $this->selectOne(
            'SELECT * FROM qb_my_exams WHERE uuid = :uuid AND user_id = :u LIMIT 1',
            ['uuid' => $uuid, 'u' => $userId]
        );
        if ($row !== null) {
            $row['question_ids'] = array_values(array_map('intval', json_decode((string) $row['question_ids'], true) ?: []));
        }
        return $row;
    }

    /** @return array<int,array<string,mixed>> newest first */
    public function history(int $userId, int $limit = 30): array
    {
        return $this->select(
            'SELECT e.*, s.title AS subject_title
             FROM qb_my_exams e
             LEFT JOIN qb_subjects s ON s.id = e.subject_id
             WHERE e.user_id = :u
             ORDER BY e.started_at DESC
             LIMIT ' . max(1, min(200, $limit)),
            ['u' => $userId]
        );
    }

    public function delete(int $examId, int $userId): void
    {
        $this->execute('DELETE FROM qb_my_exams WHERE id = :id AND user_id = :u', ['id' => $examId, 'u' => $userId]);
    }

    /**
     * The exam's questions with their options, in exam order. The answer key
     * is included only when $withKey is true — that is, once it is finished.
     *
     * @param array<int,int> $questionIds
     * @return array<int,array<string,mixed>>
     */
    public function questions(array $questionIds, bool $withKey): array
    {
        $ids = $this->intList($questionIds);
        if ($ids === '') {
            return [];
        }

        $rows = $this->select(
            "SELECT q.id, q.uuid, q.subject_id, q.sub_subject_id, q.topic_id, q.stem_text, q.stem_image,
                    q.difficulty, q.explanation_text, q.explanation_image,
                    s.title AS subject_title, ss.title AS sub_title, t.title AS topic_title
             FROM qb_questions q
             LEFT JOIN qb_subjects s  ON s.id  = q.subject_id
             LEFT JOIN qb_subjects ss ON ss.id = q.sub_subject_id
             LEFT JOIN qb_subjects t  ON t.id  = q.topic_id
             WHERE q.id IN ($ids) AND q.deleted_at IS NULL"
        );

        $options = $this->select(
            'SELECT id, uuid, question_id, body_text, body_image' . ($withKey ? ', is_correct' : '') . "
             FROM qb_options WHERE question_id IN ($ids) ORDER BY sort_order, id"
        );
        $byQuestion = [];
        foreach ($options as $option) {
            $byQuestion[(int) $option['question_id']][] = $option;
        }

        $byId = [];
        foreach ($rows as $row) {
            if (!$withKey) {
                // The explanation is the answer in prose; it waits too.
                $row['explanation_text']  = null;
                $row['explanation_image'] = null;
            }
            $row['options'] = $byQuestion[(int) $row['id']] ?? [];
            $byId[(int) $row['id']] = $row;
        }

        $ordered = [];
        foreach ($questionIds as $id) {
            if (isset($byId[(int) $id])) {
                $ordered[] = $byId[(int) $id];
            }
        }
        return $ordered;
    }

    /** @return array<int,array{option_id:?int,is_correct:?int}> keyed by question id */
    public function answers(int $examId): array
    {
        $out = [];
        foreach ($this->select(
            'SELECT question_id, option_id, is_correct FROM qb_my_exam_answers WHERE exam_id = :e',
            ['e' => $examId]
        ) as $row) {
            $out[(int) $row['question_id']] = [
                'option_id'  => $row['option_id'] === null ? null : (int) $row['option_id'],
                'is_correct' => $row['is_correct'] === null ? null : (int) $row['is_correct'],
            ];
        }
        return $out;
    }

    /**
     * Saves one choice. The option must belong to the question; a null
     * option clears the answer (the student un-ticked it).
     */
    public function saveAnswer(int $examId, int $questionId, ?int $optionId): bool
    {
        if ($optionId !== null && $this->selectOne(
            'SELECT 1 FROM qb_options WHERE id = :o AND question_id = :q LIMIT 1',
            ['o' => $optionId, 'q' => $questionId]
        ) === null) {
            return false;
        }

        return $this->execute(
            'UPDATE qb_my_exam_answers SET option_id = :o, answered_at = :now
             WHERE exam_id = :e AND question_id = :q',
            ['o' => $optionId, 'now' => $optionId === null ? null : $this->now(), 'e' => $examId, 'q' => $questionId]
        ) >= 0;
    }

    /** Maps an option uuid to its id within one question. */
    public function optionIdFor(int $questionId, string $optionUuid): ?int
    {
        $row = $this->selectOne(
            'SELECT id FROM qb_options WHERE uuid = :u AND question_id = :q LIMIT 1',
            ['u' => $optionUuid, 'q' => $questionId]
        );
        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Grades the exam and closes it.
     *
     * @return array{correct:int, wrong:int, blank:int, score:float, raw:float}
     */
    public function finish(array $exam): array
    {
        $examId = (int) $exam['id'];
        $total  = max(1, count($exam['question_ids']));

        // Mark each answer right or wrong from the key, in one statement.
        $this->execute(
            'UPDATE qb_my_exam_answers a
             LEFT JOIN qb_options o ON o.id = a.option_id
             SET a.is_correct = CASE WHEN a.option_id IS NULL THEN NULL ELSE COALESCE(o.is_correct, 0) END
             WHERE a.exam_id = :e',
            ['e' => $examId]
        );

        $row = $this->selectOne(
            'SELECT SUM(is_correct = 1) AS c, SUM(is_correct = 0) AS w
             FROM qb_my_exam_answers WHERE exam_id = :e',
            ['e' => $examId]
        );
        $correct = (int) ($row['c'] ?? 0);
        $wrong   = (int) ($row['w'] ?? 0);
        $blank   = max(0, $total - $correct - $wrong);

        $raw   = round($correct * 100 / $total, 2);
        $score = (int) $exam['negative_marking'] === 1
            ? round(($correct - $wrong * self::NEGATIVE_WEIGHT) * 100 / $total, 2)
            : $raw;

        $this->execute(
            "UPDATE qb_my_exams
             SET status = 'finished', correct_count = :c, wrong_count = :w, blank_count = :b,
                 score_percent = :score, raw_percent = :raw, finished_at = :now
             WHERE id = :id",
            ['c' => $correct, 'w' => $wrong, 'b' => $blank, 'score' => $score, 'raw' => $raw,
             'now' => $this->now(), 'id' => $examId]
        );

        return ['correct' => $correct, 'wrong' => $wrong, 'blank' => $blank, 'score' => $score, 'raw' => $raw];
    }

    /* ========================================================== analysis */

    /**
     * How the student did, topic by topic, across every finished exam: the
     * latest answer to each question counts, so something learned since a
     * bad exam stops counting against them.
     *
     * @return array<int,array{key:string,title:string,path:string,total:int,correct:int,percent:int}>
     */
    public function performance(int $userId, ?int $examId = null): array
    {
        $scope  = $examId !== null ? 'AND e.id = :exam' : '';
        $params = ['u' => $userId];
        if ($examId !== null) {
            $params['exam'] = $examId;
        }

        $rows = $this->select(
            "SELECT a.question_id, a.is_correct, q.subject_id, q.sub_subject_id, q.topic_id,
                    s.uuid AS subject_uuid, s.title AS subject_title, ss.title AS sub_title, t.title AS topic_title
             FROM qb_my_exam_answers a
             JOIN qb_my_exams e   ON e.id = a.exam_id AND e.user_id = :u AND e.status = 'finished' $scope
             JOIN qb_questions q  ON q.id = a.question_id
             LEFT JOIN qb_subjects s  ON s.id  = q.subject_id
             LEFT JOIN qb_subjects ss ON ss.id = q.sub_subject_id
             LEFT JOIN qb_subjects t  ON t.id  = q.topic_id
             ORDER BY e.finished_at ASC, e.id ASC",
            $params
        );

        // Latest answer per question: later rows overwrite earlier ones.
        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row['question_id']] = $row;
        }

        $groups = [];
        foreach ($latest as $row) {
            if ((int) ($row['topic_id'] ?? 0) > 0) {
                $key   = 't' . $row['topic_id'];
                $title = (string) $row['topic_title'];
            } elseif ((int) ($row['sub_subject_id'] ?? 0) > 0) {
                $key   = 's' . $row['sub_subject_id'];
                $title = (string) $row['sub_title'];
            } else {
                $key   = 'd' . $row['subject_id'];
                $title = (string) $row['subject_title'];
            }
            $path = implode(' › ', array_filter([(string) $row['subject_title'], (string) ($row['sub_title'] ?? '')]));

            $groups[$key] ??= [
                'key' => $key, 'title' => $title, 'path' => $path,
                'subject_id' => (int) $row['subject_id'],
                'subject_uuid' => (string) ($row['subject_uuid'] ?? ''),
                'sub_id'     => (int) ($row['sub_subject_id'] ?? 0),
                'topic_id'   => (int) ($row['topic_id'] ?? 0),
                'node_id' => (int) substr($key, 1),
                'total' => 0, 'correct' => 0, 'percent' => 0,
            ];
            $groups[$key]['total']++;
            if ((int) $row['is_correct'] === 1) {
                $groups[$key]['correct']++;
            }
        }

        foreach ($groups as &$group) {
            $group['percent'] = (int) round($group['correct'] * 100 / max(1, $group['total']));
        }
        unset($group);

        usort($groups, static fn (array $a, array $b): int => $a['percent'] <=> $b['percent'] ?: $b['total'] <=> $a['total']);
        return $groups;
    }

    /** "1,2,3" from a list of ids, cast to int — safe to write into SQL. */
    private function intList(array $ids): string
    {
        $clean = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        return implode(',', $clean);
    }
}
