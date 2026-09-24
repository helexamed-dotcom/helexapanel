<?php
declare(strict_types=1);

namespace HeleXa\Models;

use HeleXa\Core\Database;
use HeleXa\Core\Str;

/**
 * «درسنامه‌ها»: the lessons, their tags, the questions that point at them,
 * and each student's reading state (highlights, bookmark, finished).
 */
final class LessonRepository extends BaseRepository
{
    public const COLORS = ['indigo', 'violet', 'blue', 'sky', 'teal', 'green', 'amber', 'orange', 'rose', 'pink', 'red', 'slate'];

    public static function ready(): bool
    {
        try {
            Database::selectOne('SELECT 1 FROM lessons LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * @param array{q?:string, subject?:int, tag?:int, status?:string, ids?:array} $f
     * @return list<array>
     */
    public function search(array $f, bool $publishedOnly, int $limit = 200): array
    {
        $where  = ['l.deleted_at IS NULL'];
        $params = [];
        if ($publishedOnly) {
            $where[] = "l.status = 'published'";
        } elseif (in_array($f['status'] ?? '', ['draft', 'published'], true)) {
            $where[] = 'l.status = :st';
            $params['st'] = $f['status'];
        }
        if (($f['q'] ?? '') !== '') {
            $where[] = '(l.title LIKE :q1 OR l.summary LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $f['q']) . '%';
        }
        if ((int) ($f['subject'] ?? 0) > 0) {
            $where[] = '(l.subject_id = :s1 OR l.subject_id IN (SELECT id FROM qb_subjects WHERE parent_id = :s2)
                         OR l.subject_id IN (SELECT c.id FROM qb_subjects c JOIN qb_subjects p ON p.id = c.parent_id WHERE p.parent_id = :s3))';
            $params['s1'] = $params['s2'] = $params['s3'] = (int) $f['subject'];
        }
        if ((int) ($f['tag'] ?? 0) > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM lesson_tags lt WHERE lt.lesson_id = l.id AND lt.tag_id = :tag)';
            $params['tag'] = (int) $f['tag'];
        }
        if (!empty($f['ids'])) {
            $ids = implode(',', array_map('intval', $f['ids']));
            $where[] = "l.id IN ({$ids})";
        }
        $limit = max(1, min(500, $limit));

        return $this->select(
            'SELECT l.id, l.uuid, l.title, l.summary, l.subject_id, l.package_id, l.color, l.cover_path, l.reading_minutes,
                    l.status, l.sort_order, l.created_at, l.updated_at, s.title AS subject_title, p.title AS parent_title
             FROM lessons l
             LEFT JOIN qb_subjects s ON s.id = l.subject_id
             LEFT JOIN qb_subjects p ON p.id = s.parent_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY l.sort_order, l.id DESC LIMIT ' . $limit,
            $params
        );
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT l.*, s.title AS subject_title FROM lessons l LEFT JOIN qb_subjects s ON s.id = l.subject_id
             WHERE l.uuid = :u AND l.deleted_at IS NULL LIMIT 1',
            ['u' => $uuid]
        );
    }

    public function find(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM lessons WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function save(?int $id, array $d, int $actorId): int
    {
        $params = [
            'title'   => $d['title'],
            'summary' => $d['summary'] ?: null,
            'subject' => $d['subject_id'] ?: null,
            'package' => $d['package_id'] ?: null,
            'color'   => in_array($d['color'] ?? '', self::COLORS, true) ? $d['color'] : 'indigo',
            'body'    => $d['body_html'],
            'minutes' => $d['reading_minutes'],
            'status'  => ($d['status'] ?? '') === 'published' ? 'published' : 'draft',
            'sort'    => (int) ($d['sort_order'] ?? 0),
        ];
        if ($id !== null) {
            $this->execute(
                'UPDATE lessons SET title = :title, summary = :summary, subject_id = :subject, package_id = :package, color = :color,
                        body_html = :body, reading_minutes = :minutes, status = :status, sort_order = :sort, updated_at = NOW()
                 WHERE id = :id',
                $params + ['id' => $id]
            );
            return $id;
        }
        return $this->insert(
            'INSERT INTO lessons (uuid, title, summary, subject_id, package_id, color, body_html, reading_minutes, status, sort_order, created_by, created_at)
             VALUES (:uuid, :title, :summary, :subject, :package, :color, :body, :minutes, :status, :sort, :by, NOW())',
            $params + ['uuid' => Str::uuid4(), 'by' => $actorId]
        );
    }

    public function setCover(int $id, ?string $path): void
    {
        $this->execute('UPDATE lessons SET cover_path = :p, updated_at = NOW() WHERE id = :id', ['p' => $path, 'id' => $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->execute('UPDATE lessons SET status = :s, updated_at = NOW() WHERE id = :id',
            ['s' => $status === 'published' ? 'published' : 'draft', 'id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->execute('UPDATE lessons SET deleted_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    /* ------------------------------------------------------------- tags */

    /** @return list<array{id:int,title:string,color:string}> */
    public function tagsFor(int $lessonId): array
    {
        return $this->select(
            'SELECT t.id, t.title, t.color FROM lesson_tags lt JOIN qb_tags t ON t.id = lt.tag_id WHERE lt.lesson_id = :l ORDER BY t.sort_order, t.title',
            ['l' => $lessonId]
        );
    }

    /** @param list<int> $tagIds */
    public function syncTags(int $lessonId, array $tagIds): void
    {
        $this->execute('DELETE FROM lesson_tags WHERE lesson_id = :l', ['l' => $lessonId]);
        foreach (array_unique(array_filter(array_map('intval', $tagIds))) as $t) {
            $this->execute('INSERT IGNORE INTO lesson_tags (lesson_id, tag_id) SELECT :l, id FROM qb_tags WHERE id = :t', ['l' => $lessonId, 't' => $t]);
        }
    }

    /** Tag id => number of published lessons carrying it. */
    public function tagCounts(): array
    {
        $out = [];
        foreach ($this->select(
            "SELECT lt.tag_id, COUNT(*) AS c FROM lesson_tags lt JOIN lessons l ON l.id = lt.lesson_id
             WHERE l.status = 'published' AND l.deleted_at IS NULL GROUP BY lt.tag_id"
        ) as $r) {
            $out[(int) $r['tag_id']] = (int) $r['c'];
        }
        return $out;
    }

    /* -------------------------------------------------- question links */

    /** @return list<int> */
    public function lessonIdsForQuestion(int $questionId): array
    {
        return array_map('intval', array_column(
            $this->select('SELECT lesson_id FROM qb_question_lessons WHERE question_id = :q', ['q' => $questionId]), 'lesson_id'));
    }

    /** @param list<int> $lessonIds */
    public function syncQuestionLessons(int $questionId, array $lessonIds): void
    {
        $this->execute('DELETE FROM qb_question_lessons WHERE question_id = :q', ['q' => $questionId]);
        foreach (array_unique(array_filter(array_map('intval', $lessonIds))) as $l) {
            $this->execute('INSERT IGNORE INTO qb_question_lessons (question_id, lesson_id) SELECT :q, id FROM lessons WHERE id = :l',
                ['q' => $questionId, 'l' => $l]);
        }
    }

    /**
     * The published درسنامه‌ها that teach one question: the ones it points at
     * directly first, then those that share a tag with it, then those filed
     * under its own topic / زیردرس.
     *
     * @return list<array{id:int, uuid:string, title:string, why:string}>
     */
    public function forQuestion(int $questionId, int $limit = 4): array
    {
        $q = $this->selectOne('SELECT subject_id, sub_subject_id, topic_id FROM qb_questions WHERE id = :id', ['id' => $questionId]);
        if ($q === null) {
            return [];
        }
        $rows = $this->select(
            "SELECT l.id, l.uuid, l.title, l.package_id,
                    CASE WHEN EXISTS (SELECT 1 FROM qb_question_lessons x WHERE x.lesson_id = l.id AND x.question_id = :q1) THEN 'link'
                         WHEN EXISTS (SELECT 1 FROM lesson_tags lt JOIN qb_question_tags qt ON qt.tag_id = lt.tag_id
                                      WHERE lt.lesson_id = l.id AND qt.question_id = :q2) THEN 'tag'
                         ELSE 'topic' END AS why
             FROM lessons l
             WHERE l.status = 'published' AND l.deleted_at IS NULL AND (
                   EXISTS (SELECT 1 FROM qb_question_lessons x WHERE x.lesson_id = l.id AND x.question_id = :q3)
                OR EXISTS (SELECT 1 FROM lesson_tags lt JOIN qb_question_tags qt ON qt.tag_id = lt.tag_id WHERE lt.lesson_id = l.id AND qt.question_id = :q4)
                OR (l.subject_id IS NOT NULL AND l.subject_id IN (:t, :s)))
             ORDER BY FIELD(why, 'link', 'tag', 'topic'), l.sort_order
             LIMIT " . max(1, min(10, $limit)),
            ['q1' => $questionId, 'q2' => $questionId, 'q3' => $questionId, 'q4' => $questionId,
             't' => (int) ($q['topic_id'] ?: -1), 's' => (int) ($q['sub_subject_id'] ?: -1)]
        );
        return $rows;
    }

    /* -------------------------------------------------- reading state */

    public function readState(int $userId, int $lessonId): ?array
    {
        return $this->selectOne('SELECT * FROM lesson_reads WHERE user_id = :u AND lesson_id = :l', ['u' => $userId, 'l' => $lessonId]);
    }

    public function touch(int $userId, int $lessonId): void
    {
        $this->execute(
            'INSERT INTO lesson_reads (user_id, lesson_id, opened_at, updated_at) VALUES (:u, :l, NOW(), NOW())
             ON DUPLICATE KEY UPDATE opened_at = NOW()',
            ['u' => $userId, 'l' => $lessonId]
        );
    }

    public function saveState(int $userId, int $lessonId, array $fields): void
    {
        $sets = [];
        $params = ['u' => $userId, 'l' => $lessonId];
        foreach (['highlights', 'progress', 'bookmarked', 'read_at'] as $k) {
            if (array_key_exists($k, $fields)) {
                $sets[] = "{$k} = :{$k}";
                $params[$k] = $fields[$k];
            }
        }
        if ($sets === []) {
            return;
        }
        $this->touch($userId, $lessonId);
        $this->execute('UPDATE lesson_reads SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE user_id = :u AND lesson_id = :l', $params);
    }

    /** lesson id => state, for the library cards. */
    public function statesFor(int $userId): array
    {
        $out = [];
        foreach ($this->select('SELECT lesson_id, progress, bookmarked, read_at FROM lesson_reads WHERE user_id = :u', ['u' => $userId]) as $r) {
            $out[(int) $r['lesson_id']] = $r;
        }
        return $out;
    }
}
