<?php
declare(strict_types=1);

namespace HeleXa\Models\QuestionBank;

use HeleXa\Core\Str;
use HeleXa\Models\BaseRepository;

/**
 * The درس ← زیردرس ← عنوان tree.
 *
 * One table, three depths. See the migration for why it is not three tables.
 * Nothing here trusts a caller's idea of depth: a child's depth is always read
 * from its parent row and incremented, so a hand-edited form field cannot
 * create a depth-9 row or a زیردرس with no درس above it.
 */
final class QbSubjectRepository extends BaseRepository
{
    /** The tree is three levels deep by design; a fourth is refused, not silently flattened. */
    public const MAX_DEPTH = 3;

    public const DEPTH_LABELS = [
        1 => 'درس',
        2 => 'زیردرس',
        3 => 'عنوان',
    ];

    /* ------------------------------------------------------------ reads */

    /** @return array<int,array<string,mixed>> */
    public function children(?int $parentId, bool $activeOnly = false): array
    {
        $filter = $activeOnly ? ' AND is_active = 1' : '';

        // A NULL parent cannot be matched with `=`, so the two cases need
        // different SQL rather than one query with a null-valued parameter.
        if ($parentId === null) {
            return $this->select(
                'SELECT * FROM qb_subjects WHERE parent_id IS NULL' . $filter
                . ' ORDER BY sort_order, title'
            );
        }

        return $this->select(
            'SELECT * FROM qb_subjects WHERE parent_id = :parent' . $filter
            . ' ORDER BY sort_order, title',
            ['parent' => $parentId]
        );
    }

    /**
     * Every row at once, with its question count, as a flat list in tree order.
     *
     * The management page needs the whole tree, and three levels means three
     * round trips if each level is fetched on demand — or one query and a
     * regroup in PHP. This is the second. The count is a correlated subquery
     * per row rather than a join, because a question may reference the same
     * row from any of three columns and a join would multiply rows.
     *
     * @return array<int,array<string,mixed>>
     */
    public function tree(): array
    {
        $rows = $this->select(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM qb_questions q
                      WHERE q.deleted_at IS NULL
                        AND (q.subject_id = s.id OR q.sub_subject_id = s.id OR q.topic_id = s.id)
                    ) AS question_count
             FROM qb_subjects s
             ORDER BY s.depth, s.sort_order, s.title'
        );

        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
        }

        $flat = [];
        $walk = function (int $parentKey) use (&$walk, &$flat, $byParent): void {
            foreach ($byParent[$parentKey] ?? [] as $row) {
                $flat[] = $row;
                $walk((int) $row['id']);
            }
        };
        $walk(0);

        return $flat;
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM qb_subjects WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne('SELECT * FROM qb_subjects WHERE uuid = :uuid LIMIT 1', ['uuid' => $uuid]);
    }

    /** Depth-1 rows only — the unit access is granted in. */
    public function roots(bool $activeOnly = true): array
    {
        return $this->children(null, $activeOnly);
    }

    /**
     * True when $childId sits underneath $ancestorId.
     *
     * The question form lets all three levels be chosen independently, so the
     * server has to check that the زیردرس really belongs to the درس rather
     * than trusting that the cascading select was used. Walking up from the
     * child is at most MAX_DEPTH reads; the loop counter is a guard against a
     * parent cycle, which the schema permits even though the UI cannot make
     * one.
     */
    public function isDescendantOf(int $childId, int $ancestorId): bool
    {
        $current = $this->find($childId);

        for ($step = 0; $step < self::MAX_DEPTH && $current !== null; $step++) {
            $parentId = $current['parent_id'] === null ? null : (int) $current['parent_id'];
            if ($parentId === null) {
                return false;
            }
            if ($parentId === $ancestorId) {
                return true;
            }
            $current = $this->find($parentId);
        }

        return false;
    }

    /** @return array<int,array<string,mixed>> the row, then its parents, nearest first */
    public function ancestry(int $id): array
    {
        $chain   = [];
        $current = $this->find($id);

        for ($step = 0; $step < self::MAX_DEPTH && $current !== null; $step++) {
            $chain[] = $current;
            if ($current['parent_id'] === null) {
                break;
            }
            $current = $this->find((int) $current['parent_id']);
        }

        return $chain;
    }

    /* ----------------------------------------------------------- writes */

    /**
     * @param  array{title:string, description?:string, sort_order?:int} $data
     * @throws \RuntimeException when the parent is missing or already deepest
     */
    public function create(?int $parentId, array $data, ?int $authorId): int
    {
        $depth = 1;

        if ($parentId !== null) {
            $parent = $this->find($parentId);
            if ($parent === null) {
                throw new \RuntimeException('سرشاخه انتخاب‌شده پیدا نشد.');
            }

            $depth = (int) $parent['depth'] + 1;
            if ($depth > self::MAX_DEPTH) {
                throw new \RuntimeException('ساختار بیش از سه سطح نمی‌شود: درس، زیردرس، عنوان.');
            }
        }

        $title = trim($data['title']);
        if ($title === '') {
            throw new \RuntimeException('عنوان نمی‌تواند خالی باشد.');
        }
        if ($this->siblingExists($parentId, $title, null)) {
            throw new \RuntimeException('در همین سطح، موردی با این عنوان از قبل هست.');
        }

        return $this->insert(
            'INSERT INTO qb_subjects (uuid, parent_id, depth, title, description, sort_order, is_active, created_by, created_at)
             VALUES (:uuid, :parent, :depth, :title, :description, :sort_order, 1, :author, :now)',
            [
                'uuid'        => Str::uuid4(),
                'parent'      => $parentId,
                'depth'       => $depth,
                'title'       => $title,
                'description' => ($data['description'] ?? '') !== '' ? $data['description'] : null,
                'sort_order'  => (int) ($data['sort_order'] ?? 0),
                'author'      => $authorId,
                'now'         => $this->now(),
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('این مورد پیدا نشد.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('عنوان نمی‌تواند خالی باشد.');
        }

        $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        if ($this->siblingExists($parentId, $title, $id)) {
            throw new \RuntimeException('در همین سطح، موردی با این عنوان از قبل هست.');
        }

        $this->execute(
            'UPDATE qb_subjects
                SET title = :title, description = :description, sort_order = :sort_order,
                    is_active = :is_active, updated_at = :now
              WHERE id = :id',
            [
                'title'       => $title,
                'description' => ($data['description'] ?? '') !== '' ? $data['description'] : null,
                'sort_order'  => (int) ($data['sort_order'] ?? 0),
                'is_active'   => (int) (bool) ($data['is_active'] ?? 1),
                'now'         => $this->now(),
                'id'          => $id,
            ]
        );
    }

    /** «درسنامه» of a درس / زیردرس / عنوان; an empty text removes it. */
    public function setLessonNote(int $id, string $note): void
    {
        $note = trim($note);
        $this->execute(
            'UPDATE qb_subjects SET lesson_note = :n, updated_at = :now WHERE id = :id',
            ['n' => $note !== '' ? mb_substr($note, 0, 60000) : null, 'now' => $this->now(), 'id' => $id]
        );
    }

    public function toggle(int $id): void
    {
        $this->execute(
            'UPDATE qb_subjects SET is_active = 1 - is_active, updated_at = :now WHERE id = :id',
            ['now' => $this->now(), 'id' => $id]
        );
    }

    /**
     * Deletes the row and, by the schema's cascade, everything under it.
     *
     * Questions are NOT deleted with it: their three taxonomy columns are ON
     * DELETE SET NULL, so a question whose درس is removed becomes unfiled and
     * shows up in the "بدون طبقه‌بندی" filter. Deleting a subject is an act of
     * tidying the syllabus, and it should never be a way to lose written work
     * — which is also why the controller reports how many questions were
     * orphaned rather than doing it quietly.
     */
    public function delete(int $id): void
    {
        $this->execute('DELETE FROM qb_subjects WHERE id = :id', ['id' => $id]);
    }

    /** How many live questions point at this row from any of the three columns. */
    public function questionCount(int $id): int
    {
        $row = $this->selectOne(
            'SELECT COUNT(*) AS c FROM qb_questions
              WHERE deleted_at IS NULL
                AND (subject_id = :a OR sub_subject_id = :b OR topic_id = :c)',
            ['a' => $id, 'b' => $id, 'c' => $id]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * The sibling-name check.
     *
     * The unique key covers depth 2 and 3, but MySQL treats NULLs as distinct
     * in a unique index, so two depth-1 rows could both be called «آناتومی»
     * without the database objecting. This closes that hole in the one place
     * both create and update pass through.
     */
    private function siblingExists(?int $parentId, string $title, ?int $exceptId): bool
    {
        $params = ['title' => $title];
        $sql    = 'SELECT id FROM qb_subjects WHERE title = :title AND ';
        $sql   .= $parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent';

        if ($parentId !== null) {
            $params['parent'] = $parentId;
        }
        if ($exceptId !== null) {
            $sql .= ' AND id <> :except';
            $params['except'] = $exceptId;
        }

        return $this->selectOne($sql . ' LIMIT 1', $params) !== null;
    }
}
