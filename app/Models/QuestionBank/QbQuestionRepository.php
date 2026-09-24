<?php
declare(strict_types=1);

namespace HeleXa\Models\QuestionBank;

use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Models\BaseRepository;

/**
 * Questions, their options and their tags.
 *
 * A question is never half-written: the stem, its options and its tags are
 * saved inside one transaction, so a failure part-way leaves no question with
 * three of its four options. Editing replaces the option set wholesale rather
 * than diffing it — an option carries no history worth preserving, and a diff
 * would be a lot of code to avoid rewriting four short rows.
 */
final class QbQuestionRepository extends BaseRepository
{
    public const DIFFICULTIES = ['easy', 'medium', 'hard', 'expert'];

    public const DIFFICULTY_LABELS = [
        'easy'   => 'آسان',
        'medium' => 'متوسط',
        'hard'   => 'دشوار',
        'expert' => 'تخصصی',
    ];

    /* ------------------------------------------------------------ reads */

    /**
     * The admin listing.
     *
     * `unfiled` is a distinct filter value rather than an absent one, because
     * "show me everything" and "show me what nobody has filed yet" are
     * different questions and the second is the one that gets work done.
     *
     * @param array{q?:string, subject_id?:int|string, difficulty?:string,
     *              status?:string, tag_id?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $limit  = max(1, min($limit, 300));
        $offset = max(0, $offset);

        return $this->select(
            'SELECT q.*,
                    s.title  AS subject_title,
                    ss.title AS sub_subject_title,
                    t.title  AS topic_title,
                    (SELECT COUNT(*) FROM qb_options o WHERE o.question_id = q.id) AS option_count,
                    (SELECT COUNT(*) FROM qb_options o WHERE o.question_id = q.id AND o.is_correct = 1) AS correct_count
             FROM qb_questions q
             LEFT JOIN qb_subjects s  ON s.id  = q.subject_id
             LEFT JOIN qb_subjects ss ON ss.id = q.sub_subject_id
             LEFT JOIN qb_subjects t  ON t.id  = q.topic_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY q.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
    }

    public function countMatching(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);

        $row = $this->selectOne(
            'SELECT COUNT(*) AS c FROM qb_questions q WHERE ' . implode(' AND ', $where),
            $params
        );

        return (int) ($row['c'] ?? 0);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT q.*, s.title AS subject_title, ss.title AS sub_subject_title, t.title AS topic_title
             FROM qb_questions q
             LEFT JOIN qb_subjects s  ON s.id  = q.subject_id
             LEFT JOIN qb_subjects ss ON ss.id = q.sub_subject_id
             LEFT JOIN qb_subjects t  ON t.id  = q.topic_id
             WHERE q.uuid = :uuid AND q.deleted_at IS NULL LIMIT 1',
            ['uuid' => $uuid]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function optionsFor(int $questionId): array
    {
        return $this->select(
            'SELECT * FROM qb_options WHERE question_id = :q ORDER BY sort_order, id',
            ['q' => $questionId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function tagsFor(int $questionId): array
    {
        return $this->select(
            'SELECT t.* FROM qb_tags t
               JOIN qb_question_tags qt ON qt.tag_id = t.id
              WHERE qt.question_id = :q
              ORDER BY t.sort_order, t.title',
            ['q' => $questionId]
        );
    }

    /**
     * Tags for many questions in one query.
     *
     * The listing shows every question's tags, and fetching them per row is
     * the textbook N+1: a hundred questions would be a hundred and one
     * queries. This is the one.
     *
     * @param  array<int,int> $questionIds
     * @return array<int,array<int,array<string,mixed>>> keyed by question id
     */
    public function tagsForMany(array $questionIds): array
    {
        $questionIds = array_values(array_unique(array_map('intval', $questionIds)));
        if ($questionIds === []) {
            return [];
        }

        // The ids are cast to int above, so interpolating them cannot carry a
        // quote. Named placeholders cannot be used for an IN list of unknown
        // length, and PDO has no array binding.
        $rows = $this->select(
            'SELECT qt.question_id, t.*
             FROM qb_question_tags qt
             JOIN qb_tags t ON t.id = qt.tag_id
             WHERE qt.question_id IN (' . implode(',', $questionIds) . ')
             ORDER BY t.sort_order, t.title'
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['question_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Live questions for a list of uuids, for bulk actions.
     *
     * @param  array<int,string> $uuids already validated as uuids
     * @return array<int,array<string,mixed>>
     */
    public function findManyByUuids(array $uuids): array
    {
        $uuids = array_values(array_unique($uuids));
        if ($uuids === []) {
            return [];
        }

        // One distinct placeholder per value: native prepares reject repeats.
        $params = [];
        foreach ($uuids as $i => $uuid) {
            $params['u' . $i] = $uuid;
        }

        return $this->select(
            'SELECT id, uuid, status, subject_id FROM qb_questions
              WHERE deleted_at IS NULL AND uuid IN (:' . implode(', :', array_keys($params)) . ')',
            $params
        );
    }

    /**
     * Options for many questions in one query, for the JSON export.
     *
     * @param  array<int,int> $questionIds
     * @return array<int,array<int,array<string,mixed>>> keyed by question id
     */
    public function optionsForMany(array $questionIds): array
    {
        $questionIds = array_values(array_unique(array_map('intval', $questionIds)));
        if ($questionIds === []) {
            return [];
        }

        $grouped = [];
        foreach ($this->select(
            'SELECT * FROM qb_options WHERE question_id IN (' . implode(',', $questionIds) . ') ORDER BY question_id, sort_order, id'
        ) as $row) {
            $grouped[(int) $row['question_id']][] = $row;
        }

        return $grouped;
    }

    /* ----------------------------------------------------------- writes */

    /**
     * @param array{subject_id:?int, sub_subject_id:?int, topic_id:?int,
     *              stem_text:?string, stem_image:?string, difficulty:string,
     *              explanation_text:?string, explanation_image:?string,
     *              status:string} $data
     * @param array<int,array{body_text:?string, body_image:?string, is_correct:bool}> $options
     * @param array<int,int> $tagIds
     */
    public function create(array $data, array $options, array $tagIds, ?int $authorId): string
    {
        $uuid = Str::uuid4();

        Database::transaction(function () use ($data, $options, $tagIds, $authorId, $uuid): void {
            $questionId = $this->insert(
                'INSERT INTO qb_questions
                    (uuid, subject_id, sub_subject_id, topic_id, stem_text, stem_image,
                     difficulty, explanation_text, explanation_image, status, version, created_by, created_at)
                 VALUES
                    (:uuid, :subject, :sub, :topic, :stem, :stem_image,
                     :difficulty, :explanation, :explanation_image, :status, 1, :author, :now)',
                [
                    'uuid'              => $uuid,
                    'subject'           => $data['subject_id'],
                    'sub'               => $data['sub_subject_id'],
                    'topic'             => $data['topic_id'],
                    'stem'              => $data['stem_text'],
                    'stem_image'        => $data['stem_image'],
                    'difficulty'        => $data['difficulty'],
                    'explanation'       => $data['explanation_text'],
                    'explanation_image' => $data['explanation_image'],
                    'status'            => $data['status'],
                    'author'            => $authorId,
                    'now'               => $this->now(),
                ]
            );

            $this->writeOptions($questionId, $options);
            $this->writeTags($questionId, $tagIds);
        });

        return $uuid;
    }

    /**
     * @throws \RuntimeException when another admin saved first
     */
    /** The question's own درسنامه; empty removes it. Kept out of update() so the version check stays about content. */
    public function setLessonNote(int $id, ?string $note): void
    {
        $note = trim((string) $note);
        try {
            $this->execute(
                'UPDATE qb_questions SET lesson_note = :n WHERE id = :id',
                ['n' => $note !== '' ? mb_substr($note, 0, 60000) : null, 'id' => $id]
            );
        } catch (\PDOException) {
            // Before the migration the column does not exist yet.
        }
    }

    public function update(int $id, int $expectedVersion, array $data, array $options, array $tagIds): void
    {
        Database::transaction(function () use ($id, $expectedVersion, $data, $options, $tagIds): void {
            // The version is part of the WHERE, not checked first and then
            // written: two admins pressing Save in the same second both reach
            // this statement, and only the one whose version still matches
            // updates a row. A SELECT-then-UPDATE would let both through.
            $changed = $this->execute(
                'UPDATE qb_questions
                    SET subject_id = :subject, sub_subject_id = :sub, topic_id = :topic,
                        stem_text = :stem, stem_image = :stem_image,
                        difficulty = :difficulty,
                        explanation_text = :explanation, explanation_image = :explanation_image,
                        status = :status,
                        version = version + 1, updated_at = :now
                  WHERE id = :id AND version = :expected AND deleted_at IS NULL',
                [
                    'subject'           => $data['subject_id'],
                    'sub'               => $data['sub_subject_id'],
                    'topic'             => $data['topic_id'],
                    'stem'              => $data['stem_text'],
                    'stem_image'        => $data['stem_image'],
                    'difficulty'        => $data['difficulty'],
                    'explanation'       => $data['explanation_text'],
                    'explanation_image' => $data['explanation_image'],
                    'status'            => $data['status'],
                    'now'               => $this->now(),
                    'id'                => $id,
                    'expected'          => $expectedVersion,
                ]
            );

            if ($changed === 0) {
                throw new \RuntimeException('این سوال توسط مدیر دیگری تغییر کرده است. صفحه را تازه کن و دوباره تلاش کن.');
            }

            $this->execute('DELETE FROM qb_options WHERE question_id = :q', ['q' => $id]);
            $this->writeOptions($id, $options);

            $this->execute('DELETE FROM qb_question_tags WHERE question_id = :q', ['q' => $id]);
            $this->writeTags($id, $tagIds);
        });
    }

    public function setStatus(int $id, string $status): void
    {
        $this->execute(
            'UPDATE qb_questions SET status = :status, version = version + 1, updated_at = :now
              WHERE id = :id AND deleted_at IS NULL',
            ['status' => $status === 'published' ? 'published' : 'draft', 'now' => $this->now(), 'id' => $id]
        );
    }

    /**
     * Soft delete.
     *
     * Hard deletion would take the options with it by cascade and leave no way
     * to recover a question removed by mistake. The row stays and every read
     * path filters on deleted_at IS NULL.
     */
    public function softDelete(int $id): void
    {
        $this->execute(
            'UPDATE qb_questions SET deleted_at = :now WHERE id = :id',
            ['now' => $this->now(), 'id' => $id]
        );
    }

    /** Every stored image filename on a question, for the storage layer to clean up. */
    public function imageNames(int $questionId): array
    {
        $names = [];

        $question = $this->selectOne(
            'SELECT stem_image, explanation_image FROM qb_questions WHERE id = :id',
            ['id' => $questionId]
        );
        if ($question !== null) {
            $names[] = $question['stem_image'];
            $names[] = $question['explanation_image'];
        }

        foreach ($this->optionsFor($questionId) as $option) {
            $names[] = $option['body_image'];
        }

        return array_values(array_filter($names, static fn ($n) => is_string($n) && $n !== ''));
    }

    /* ---------------------------------------------------------- helpers */

    /** @param array<int,array{body_text:?string, body_image:?string, is_correct:bool}> $options */
    private function writeOptions(int $questionId, array $options): void
    {
        $order = 0;
        foreach ($options as $option) {
            $this->insert(
                'INSERT INTO qb_options (uuid, question_id, body_text, body_image, is_correct, sort_order)
                 VALUES (:uuid, :q, :text, :image, :correct, :order)',
                [
                    'uuid'    => Str::uuid4(),
                    'q'       => $questionId,
                    'text'    => $option['body_text'],
                    'image'   => $option['body_image'],
                    'correct' => (int) $option['is_correct'],
                    'order'   => $order++,
                ]
            );
        }
    }

    /** @param array<int,int> $tagIds */
    private function writeTags(int $questionId, array $tagIds): void
    {
        foreach (array_unique(array_map('intval', $tagIds)) as $tagId) {
            if ($tagId <= 0) {
                continue;
            }
            // IGNORE rather than a prior existence check: the primary key is
            // (question_id, tag_id), so a duplicate in the submitted list is
            // the database's problem to reject, not PHP's to detect.
            $this->execute(
                'INSERT IGNORE INTO qb_question_tags (question_id, tag_id) VALUES (:q, :t)',
                ['q' => $questionId, 't' => $tagId]
            );
        }
    }

    /** @return array{0:array<int,string>, 1:array<string,mixed>} */
    private function buildFilters(array $filters): array
    {
        $where  = ['q.deleted_at IS NULL'];
        $params = [];

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            // Distinct placeholders: native prepares reject a repeated name.
            $like    = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $where[] = '(q.stem_text LIKE :term1 OR q.explanation_text LIKE :term2)';
            $params += ['term1' => $like, 'term2' => $like];
        }

        $subject = $filters['subject_id'] ?? '';
        if ($subject === 'unfiled') {
            $where[] = 'q.subject_id IS NULL AND q.sub_subject_id IS NULL AND q.topic_id IS NULL';
        } elseif ((int) $subject > 0) {
            // A question filed under a زیردرس should still appear when its درس
            // is selected, so all three columns are matched.
            $where[] = '(q.subject_id = :subj1 OR q.sub_subject_id = :subj2 OR q.topic_id = :subj3)';
            $params += ['subj1' => (int) $subject, 'subj2' => (int) $subject, 'subj3' => (int) $subject];
        }

        if (in_array((string) ($filters['difficulty'] ?? ''), self::DIFFICULTIES, true)) {
            $where[]              = 'q.difficulty = :difficulty';
            $params['difficulty'] = (string) $filters['difficulty'];
        }

        if (in_array((string) ($filters['status'] ?? ''), ['draft', 'published'], true)) {
            $where[]          = 'q.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        if ((int) ($filters['tag_id'] ?? 0) > 0) {
            $where[]       = 'EXISTS (SELECT 1 FROM qb_question_tags qt WHERE qt.question_id = q.id AND qt.tag_id = :tag)';
            $params['tag'] = (int) $filters['tag_id'];
        }

        return [$where, $params];
    }
}
