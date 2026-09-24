<?php
declare(strict_types=1);

namespace HeleXa\Models\QuestionBank;

use HeleXa\Models\BaseRepository;

/**
 * Everything a student reads or writes while practising.
 *
 * Kept apart from QbQuestionRepository on purpose: every query here is
 * restricted to published, undeleted questions, and keeping those queries in
 * their own class means an admin-side query that deliberately includes drafts
 * can never be reached from a student page by accident.
 */
final class QbPracticeRepository extends BaseRepository
{
    /**
     * @param array{sub_subject_id?:int, topic_id?:int, difficulty?:string,
     *              tag_id?:int, mode?:string} $filters
     */
    public function count(int $userId, int $subjectId, array $filters): int
    {
        [$where, $params] = $this->filters($userId, $subjectId, $filters);

        $row = $this->selectOne(
            'SELECT COUNT(*) AS c FROM qb_questions q WHERE ' . implode(' AND ', $where),
            $params
        );

        return (int) ($row['c'] ?? 0);
    }

    /** The question at one position in the filtered sequence, or null past the end. */
    public function at(int $userId, int $subjectId, array $filters, int $position): ?array
    {
        [$where, $params] = $this->filters($userId, $subjectId, $filters);
        $offset = max(0, $position);

        return $this->selectOne(
            'SELECT q.id, q.uuid, q.subject_id, q.sub_subject_id, q.topic_id,
                    q.stem_text, q.stem_image, q.difficulty,
                    ss.title AS sub_subject_title, t.title AS topic_title
             FROM qb_questions q
             LEFT JOIN qb_subjects ss ON ss.id = q.sub_subject_id
             LEFT JOIN qb_subjects t  ON t.id  = q.topic_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY q.id
             LIMIT 1 OFFSET ' . $offset,
            $params
        );
    }

    /**
     * Options without the answer key.
     *
     * is_correct is deliberately not selected. The page that shows a question
     * must not carry its answer; it is returned only by the answer endpoint,
     * after the student has committed.
     *
     * @return array<int,array<string,mixed>>
     */
    public function optionsWithoutKey(int $questionId): array
    {
        return $this->select(
            'SELECT uuid, body_text, body_image FROM qb_options
              WHERE question_id = :q ORDER BY sort_order, id',
            ['q' => $questionId]
        );
    }

    public function findPublished(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT * FROM qb_questions
              WHERE uuid = :uuid AND status = \'published\' AND deleted_at IS NULL LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /** @return array<int,array<string,mixed>> with is_correct, for grading only */
    public function optionsWithKey(int $questionId): array
    {
        return $this->select(
            'SELECT id, uuid, is_correct FROM qb_options WHERE question_id = :q ORDER BY sort_order, id',
            ['q' => $questionId]
        );
    }

    public function recordAttempt(int $userId, int $questionId, ?int $optionId, ?int $subjectId, bool $correct): void
    {
        $this->insert(
            'INSERT INTO qb_attempts (user_id, question_id, option_id, subject_id, is_correct, answered_at)
             VALUES (:user, :question, :option, :subject, :correct, :now)',
            [
                'user'     => $userId,
                'question' => $questionId,
                'option'   => $optionId,
                'subject'  => $subjectId,
                'correct'  => (int) $correct,
                'now'      => $this->now(),
            ]
        );
    }

    /** @return array{answered:int, correct:int} distinct questions, across all subjects */
    public function totalsFor(int $userId): array
    {
        $row = $this->selectOne(
            'SELECT COUNT(DISTINCT question_id) AS answered,
                    COUNT(DISTINCT CASE WHEN is_correct = 1 THEN question_id END) AS correct
               FROM qb_attempts WHERE user_id = :u',
            ['u' => $userId]
        );

        return ['answered' => (int) ($row['answered'] ?? 0), 'correct' => (int) ($row['correct'] ?? 0)];
    }

    public function hasAttempted(int $userId, int $questionId): bool
    {
        return $this->selectOne(
            'SELECT id FROM qb_attempts WHERE user_id = :u AND question_id = :q LIMIT 1',
            ['u' => $userId, 'q' => $questionId]
        ) !== null;
    }

    /**
     * Whether this student may see a question's answer: they answered it,
     * or it was in one of their finished «آزمون‌های من» — even left blank,
     * the exam is over and its key is theirs to read.
     */
    public function hasSeenAnswer(int $userId, int $questionId): bool
    {
        if ($this->hasAttempted($userId, $questionId)) {
            return true;
        }
        try {
            return $this->selectOne(
                "SELECT 1 FROM qb_my_exam_answers a
                 JOIN qb_my_exams e ON e.id = a.exam_id AND e.user_id = :u AND e.status = 'finished'
                 WHERE a.question_id = :q LIMIT 1",
                ['u' => $userId, 'q' => $questionId]
            ) !== null;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * The question an image belongs to, and which part of it.
     *
     * Used by the student image route: a filename is only served when it
     * belongs to a published question, so a draft's figures cannot be pulled
     * by name before the question is released.
     *
     * @return array{question_id:int, subject_id:?int, part:string}|null
     */
    public function ownerOfImage(string $name): ?array
    {
        $row = $this->selectOne(
            'SELECT id AS question_id, subject_id,
                    CASE WHEN stem_image = :a THEN \'stem\' ELSE \'explanation\' END AS part
             FROM qb_questions
             WHERE (stem_image = :b OR explanation_image = :c)
               AND status = \'published\' AND deleted_at IS NULL
             LIMIT 1',
            ['a' => $name, 'b' => $name, 'c' => $name]
        );

        if ($row === null) {
            $row = $this->selectOne(
                'SELECT q.id AS question_id, q.subject_id, \'option\' AS part
                 FROM qb_options o
                 JOIN qb_questions q ON q.id = o.question_id
                 WHERE o.body_image = :name AND q.status = \'published\' AND q.deleted_at IS NULL
                 LIMIT 1',
                ['name' => $name]
            );
        }

        if ($row === null) {
            return null;
        }

        return [
            'question_id' => (int) $row['question_id'],
            'subject_id'  => $row['subject_id'] === null ? null : (int) $row['subject_id'],
            'part'        => (string) $row['part'],
        ];
    }

    /** @return array{answered:int, correct:int, distinct:int} */
    public function statsFor(int $userId, int $subjectId): array
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS answered,
                    COALESCE(SUM(is_correct), 0) AS correct,
                    COUNT(DISTINCT question_id) AS distinct_q
             FROM qb_attempts WHERE user_id = :u AND subject_id = :s',
            ['u' => $userId, 's' => $subjectId]
        );

        return [
            'answered' => (int) ($row['answered'] ?? 0),
            'correct'  => (int) ($row['correct'] ?? 0),
            'distinct' => (int) ($row['distinct_q'] ?? 0),
        ];
    }

    /**
     * The درس as a tree, for its overview page: every زیردرس and عنوان with
     * how many published questions it holds and how many of them this student
     * has answered, answered right on the latest try, or got wrong.
     *
     * @return array{total:int, done:int, right:int, wrong:int, children:list<array>}
     */
    public function outline(int $userId, int $subjectId): array
    {
        $rows = $this->select(
            "SELECT q.sub_subject_id AS sid, q.topic_id AS tid, COUNT(*) AS total,
                    SUM(l.qid IS NOT NULL) AS done, COALESCE(SUM(l.ok = 1), 0) AS rightc, COALESCE(SUM(l.ok = 0), 0) AS wrongc
             FROM qb_questions q
             LEFT JOIN (
                 SELECT a.question_id AS qid, a.is_correct AS ok
                 FROM qb_attempts a
                 JOIN (SELECT question_id, MAX(id) AS mid FROM qb_attempts WHERE user_id = :u GROUP BY question_id) m ON m.mid = a.id
             ) l ON l.qid = q.id
             WHERE q.deleted_at IS NULL AND q.status = 'published' AND q.subject_id = :s
             GROUP BY q.sub_subject_id, q.topic_id",
            ['u' => $userId, 's' => $subjectId]
        );

        $nodes = [];
        foreach ($this->filingOptions($subjectId) as $n) {
            $nodes[(int) $n['id']] = $n + ['total' => 0, 'done' => 0, 'right' => 0, 'wrong' => 0, 'children' => []];
        }
        $sum = ['total' => 0, 'done' => 0, 'right' => 0, 'wrong' => 0];
        $loose = ['id' => 0, 'title' => 'سوال‌های بدون زیردرس', 'depth' => 2, 'parent_id' => $subjectId,
                  'total' => 0, 'done' => 0, 'right' => 0, 'wrong' => 0, 'children' => []];
        foreach ($rows as $r) {
            $add = ['total' => (int) $r['total'], 'done' => (int) $r['done'], 'right' => (int) $r['rightc'], 'wrong' => (int) $r['wrongc']];
            foreach ($add as $k => $v) {
                $sum[$k] += $v;
            }
            $sid = (int) $r['sid'];
            $tid = (int) $r['tid'];
            $target = isset($nodes[$sid]) ? $sid : null;
            if ($target === null) {
                foreach ($add as $k => $v) {
                    $loose[$k] += $v;
                }
                continue;
            }
            foreach ($add as $k => $v) {
                $nodes[$sid][$k] += $v;
            }
            if ($tid > 0 && isset($nodes[$tid])) {
                foreach ($add as $k => $v) {
                    $nodes[$tid][$k] += $v;
                }
            }
        }

        $children = [];
        foreach ($nodes as $id => $n) {
            if ((int) $n['depth'] === 3 && isset($nodes[(int) $n['parent_id']])) {
                $nodes[(int) $n['parent_id']]['children'][] = $n;
            }
        }
        foreach ($nodes as $n) {
            if ((int) $n['depth'] === 2) {
                $children[] = $n;
            }
        }
        if ($loose['total'] > 0) {
            $children[] = $loose;
        }

        return $sum + ['children' => $children];
    }

    /** Children of the subject that hold at least one published question, for the filter. */
    public function filingOptions(int $subjectId): array
    {
        return $this->select(
            'SELECT s.id, s.parent_id, s.depth, s.title
             FROM qb_subjects s
             WHERE s.is_active = 1
               AND (s.parent_id = :a OR s.parent_id IN (SELECT id FROM qb_subjects WHERE parent_id = :b))
             ORDER BY s.depth, s.sort_order, s.title',
            ['a' => $subjectId, 'b' => $subjectId]
        );
    }

    /* ------------------------------------------- per-student history */

    public const MODES = ['new', 'answered', 'correct', 'wrong', 'saved', 'review'];

    /**
     * A page of the filtered sequence, for the list view.
     *
     * Ordered exactly as at() orders, so the row at index i of page p is the
     * question at position (p-1)*size + i + 1 in the player — which is what
     * lets a list row link straight to the same question in practice mode.
     *
     * @return array<int,array<string,mixed>>
     */
    public function page(int $userId, int $subjectId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->filters($userId, $subjectId, $filters);
        $params['u_last']  = $userId;
        $params['u_count'] = $userId;
        $params['u_saved'] = $userId;
        $params['u_rev']   = $userId;

        return $this->select(
            'SELECT q.id, q.uuid, q.stem_text, q.stem_image, q.difficulty,
                    ss.title AS sub_subject_title, t.title AS topic_title,
                    (SELECT a.is_correct FROM qb_attempts a WHERE a.question_id = q.id AND a.user_id = :u_last
                      ORDER BY a.id DESC LIMIT 1) AS last_correct,
                    (SELECT COUNT(*) FROM qb_attempts a WHERE a.question_id = q.id AND a.user_id = :u_count) AS attempts,
                    EXISTS (SELECT 1 FROM qb_marks m WHERE m.question_id = q.id AND m.user_id = :u_saved AND m.mark = \'saved\') AS is_saved,
                    EXISTS (SELECT 1 FROM qb_marks m WHERE m.question_id = q.id AND m.user_id = :u_rev AND m.mark = \'review\') AS is_review
             FROM qb_questions q
             LEFT JOIN qb_subjects ss ON ss.id = q.sub_subject_id
             LEFT JOIN qb_subjects t  ON t.id  = q.topic_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY q.id
             LIMIT ' . max(1, min($limit, 100)) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    /**
     * This student's most recent attempt at one question, and how many there
     * have been. The chosen option comes back as its uuid so the page can
     * mark it; it is null when the question was edited since and the option
     * row no longer exists.
     *
     * @return array{is_correct:bool, option_uuid:?string, attempts:int}|null
     */
    public function lastAttempt(int $userId, int $questionId): ?array
    {
        $row = $this->selectOne(
            'SELECT a.is_correct, o.uuid AS option_uuid,
                    (SELECT COUNT(*) FROM qb_attempts x WHERE x.user_id = :u2 AND x.question_id = :q2) AS attempts
             FROM qb_attempts a
             LEFT JOIN qb_options o ON o.id = a.option_id
             WHERE a.user_id = :u1 AND a.question_id = :q1
             ORDER BY a.id DESC LIMIT 1',
            ['u1' => $userId, 'q1' => $questionId, 'u2' => $userId, 'q2' => $questionId]
        );

        if ($row === null) {
            return null;
        }

        return [
            'is_correct'  => (int) $row['is_correct'] === 1,
            'option_uuid' => $row['option_uuid'] !== null ? (string) $row['option_uuid'] : null,
            'attempts'    => (int) $row['attempts'],
        ];
    }

    /** @return array<int,string> the marks this student has on this question */
    public function marksFor(int $userId, int $questionId): array
    {
        $rows = $this->select(
            'SELECT mark FROM qb_marks WHERE user_id = :u AND question_id = :q',
            ['u' => $userId, 'q' => $questionId]
        );

        return array_map(static fn (array $r): string => (string) $r['mark'], $rows);
    }

    /** Flips one mark and returns whether it is now set. */
    public function toggleMark(int $userId, int $questionId, string $mark): bool
    {
        $removed = $this->execute(
            'DELETE FROM qb_marks WHERE user_id = :u AND question_id = :q AND mark = :m',
            ['u' => $userId, 'q' => $questionId, 'm' => $mark]
        );

        if ($removed > 0) {
            return false;
        }

        // IGNORE: two quick taps both find nothing to delete and both insert;
        // the primary key keeps one and the second is a no-op, not an error.
        $this->execute(
            'INSERT IGNORE INTO qb_marks (user_id, question_id, mark, created_at) VALUES (:u, :q, :m, :now)',
            ['u' => $userId, 'q' => $questionId, 'm' => $mark, 'now' => $this->now()]
        );

        return true;
    }

    /**
     * How the class answered one question: each option's share of students.
     *
     * Only each student's FIRST attempt counts. Counting every attempt would
     * let one student who drilled a question ten times outweigh nine who
     * answered it once, and repeated attempts drift towards the right answer
     * anyway — the first attempt is the honest picture of what people think.
     *
     * @return array{total:int, options:array<string,int>} option uuid → percent
     */
    public function distribution(int $questionId): array
    {
        $rows = $this->select(
            'SELECT o.uuid, COUNT(*) AS c
             FROM qb_attempts a
             JOIN qb_options o ON o.id = a.option_id
             WHERE a.question_id = :q1
               AND a.id IN (SELECT MIN(f.id) FROM qb_attempts f WHERE f.question_id = :q2 GROUP BY f.user_id)
             GROUP BY o.uuid',
            ['q1' => $questionId, 'q2' => $questionId]
        );

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['c'];
        }

        $percent = [];
        foreach ($rows as $row) {
            $percent[(string) $row['uuid']] = $total > 0 ? (int) round((int) $row['c'] * 100 / $total) : 0;
        }

        return ['total' => $total, 'options' => $percent];
    }

    /** @return array{0:array<int,string>, 1:array<string,mixed>} */
    private function filters(int $userId, int $subjectId, array $filters): array
    {
        $where  = [
            'q.deleted_at IS NULL',
            "q.status = 'published'",
            'q.subject_id = :subject',
        ];
        $params = ['subject' => $subjectId];

        if ((int) ($filters['sub_subject_id'] ?? 0) > 0) {
            $where[]       = 'q.sub_subject_id = :sub';
            $params['sub'] = (int) $filters['sub_subject_id'];
        }
        if ((int) ($filters['topic_id'] ?? 0) > 0) {
            $where[]         = 'q.topic_id = :topic';
            $params['topic'] = (int) $filters['topic_id'];
        }
        if (in_array((string) ($filters['difficulty'] ?? ''), QbQuestionRepository::DIFFICULTIES, true)) {
            $where[]              = 'q.difficulty = :difficulty';
            $params['difficulty'] = (string) $filters['difficulty'];
        }
        if ((int) ($filters['tag_id'] ?? 0) > 0) {
            $where[]       = 'EXISTS (SELECT 1 FROM qb_question_tags qt WHERE qt.question_id = q.id AND qt.tag_id = :tag)';
            $params['tag'] = (int) $filters['tag_id'];
        }

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $where[]       = 'q.stem_text LIKE :term';
            $params['term'] = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
        }

        // Every status filter is resolved against this student's own rows
        // and nobody else's. "correct" and "wrong" look at the latest attempt
        // only: a question missed once and answered right since has been
        // learned, and belongs in the first list, not the second.
        $latest = '(SELECT a.is_correct FROM qb_attempts a
                     WHERE a.question_id = q.id AND a.user_id = :u_mode
                     ORDER BY a.id DESC LIMIT 1)';

        switch ((string) ($filters['mode'] ?? '')) {
            case 'new':
                $where[] = 'NOT EXISTS (SELECT 1 FROM qb_attempts a WHERE a.question_id = q.id AND a.user_id = :u_mode)';
                break;
            case 'answered':
                $where[] = 'EXISTS (SELECT 1 FROM qb_attempts a WHERE a.question_id = q.id AND a.user_id = :u_mode)';
                break;
            case 'correct':
                $where[] = $latest . ' = 1';
                break;
            case 'wrong':
                $where[] = $latest . ' = 0';
                break;
            case 'saved':
            case 'review':
                $where[]        = 'EXISTS (SELECT 1 FROM qb_marks m WHERE m.question_id = q.id AND m.user_id = :u_mode AND m.mark = :mark)';
                $params['mark'] = (string) $filters['mode'];
                break;
            default:
                break;
        }
        if (str_contains(implode(' ', $where), ':u_mode')) {
            $params['u_mode'] = $userId;
        }
        return [$where, $params];
    }
}
