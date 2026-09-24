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
        foreach (['highlights', 'progress', 'bookmarked', 'read_at', 'last_page_id'] as $k) {
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

    /* ============================================ زیردرس‌ها and pages */

    public static function pagesReady(): bool
    {
        try {
            Database::selectOne('SELECT 1 FROM lesson_pages LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * The whole outline: زیردرس‌ها in order, each with its pages (no bodies)
     * and each page's tags; with $userId, whether that student finished it.
     *
     * @return list<array{id:int,title:string,sort_order:int,pages:list<array>}>
     */
    public function outline(int $lessonId, ?int $userId = null): array
    {
        $sections = [];
        foreach ($this->select('SELECT id, title, sort_order FROM lesson_sections WHERE lesson_id = :l ORDER BY sort_order, id', ['l' => $lessonId]) as $s) {
            $s['id'] = (int) $s['id'];
            $s['pages'] = [];
            $sections[$s['id']] = $s;
        }
        $read = $userId === null ? '0 AS is_read' : '(SELECT r.read_at IS NOT NULL FROM lesson_page_reads r WHERE r.page_id = p.id AND r.user_id = :u) AS is_read';
        $params = ['l' => $lessonId] + ($userId === null ? [] : ['u' => $userId]);
        $pages = $this->select(
            "SELECT p.id, p.uuid, p.section_id, p.title, p.reading_minutes, p.sort_order, p.updated_at, {$read}
             FROM lesson_pages p WHERE p.lesson_id = :l ORDER BY p.sort_order, p.id",
            $params
        );
        $tags = $this->pageTagsFor(array_map('intval', array_column($pages, 'id')));
        foreach ($pages as $p) {
            $p['id'] = (int) $p['id'];
            $p['is_read'] = (int) ($p['is_read'] ?? 0) === 1;
            $p['tags'] = $tags[$p['id']] ?? [];
            if (isset($sections[(int) $p['section_id']])) {
                $sections[(int) $p['section_id']]['pages'][] = $p;
            }
        }
        return array_values($sections);
    }

    /** Pages in reading order (the outline flattened). */
    public function pageList(int $lessonId, ?int $userId = null): array
    {
        $out = [];
        foreach ($this->outline($lessonId, $userId) as $s) {
            foreach ($s['pages'] as $p) {
                $p['section_title'] = $s['title'];
                $out[] = $p;
            }
        }
        return $out;
    }

    public function page(int $lessonId, string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT p.*, s.title AS section_title FROM lesson_pages p JOIN lesson_sections s ON s.id = p.section_id
             WHERE p.lesson_id = :l AND p.uuid = :u LIMIT 1',
            ['l' => $lessonId, 'u' => $uuid]
        );
    }

    public function selectPageUuid(int $pageId): string
    {
        return (string) ($this->selectOne('SELECT uuid FROM lesson_pages WHERE id = :id', ['id' => $pageId])['uuid'] ?? '');
    }

    public function section(int $lessonId, int $id): ?array
    {
        return $this->selectOne('SELECT * FROM lesson_sections WHERE id = :id AND lesson_id = :l', ['id' => $id, 'l' => $lessonId]);
    }

    public function addSection(int $lessonId, string $title): int
    {
        $next = (int) ($this->selectOne('SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM lesson_sections WHERE lesson_id = :l', ['l' => $lessonId])['n'] ?? 0);
        return $this->insert('INSERT INTO lesson_sections (lesson_id, title, sort_order, created_at) VALUES (:l, :t, :o, NOW())',
            ['l' => $lessonId, 't' => $title, 'o' => $next]);
    }

    public function renameSection(int $id, string $title): void
    {
        $this->execute('UPDATE lesson_sections SET title = :t WHERE id = :id', ['t' => $title, 'id' => $id]);
    }

    public function deleteSection(int $lessonId, int $id): void
    {
        $this->execute('DELETE FROM lesson_sections WHERE id = :id AND lesson_id = :l', ['id' => $id, 'l' => $lessonId]);
        $this->refresh($lessonId);
    }

    /** Moves a زیردرس one place up (-1) or down (+1) and renumbers the rest. */
    public function moveSection(int $lessonId, int $id, int $dir): void
    {
        $ids = array_map('intval', array_column($this->select('SELECT id FROM lesson_sections WHERE lesson_id = :l ORDER BY sort_order, id', ['l' => $lessonId]), 'id'));
        $this->renumber('lesson_sections', $this->swap($ids, $id, $dir));
    }

    public function movePage(int $lessonId, array $page, int $dir): void
    {
        $ids = array_map('intval', array_column($this->select('SELECT id FROM lesson_pages WHERE section_id = :s ORDER BY sort_order, id', ['s' => (int) $page['section_id']]), 'id'));
        $this->renumber('lesson_pages', $this->swap($ids, (int) $page['id'], $dir));
    }

    /**
     * Saves a page. A page moved to another زیردرس goes to its end.
     *
     * @param array{section_id:int,title:string,body_html:string,reading_minutes:int} $d
     */
    public function savePage(int $lessonId, ?array $page, array $d): int
    {
        $params = ['s' => $d['section_id'], 't' => $d['title'], 'b' => $d['body_html'], 'm' => max(1, $d['reading_minutes'])];
        $moved = $page === null || (int) $page['section_id'] !== (int) $d['section_id'];
        $order = $moved
            ? (int) ($this->selectOne('SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM lesson_pages WHERE section_id = :s', ['s' => $d['section_id']])['n'] ?? 0)
            : (int) $page['sort_order'];
        if ($page !== null) {
            $this->execute('UPDATE lesson_pages SET section_id = :s, title = :t, body_html = :b, reading_minutes = :m, sort_order = :o, updated_at = NOW() WHERE id = :id',
                $params + ['o' => $order, 'id' => (int) $page['id']]);
            $id = (int) $page['id'];
        } else {
            $id = $this->insert(
                'INSERT INTO lesson_pages (uuid, lesson_id, section_id, title, body_html, reading_minutes, sort_order, created_at, updated_at)
                 VALUES (:u, :l, :s, :t, :b, :m, :o, NOW(), NOW())',
                $params + ['u' => Str::uuid4(), 'l' => $lessonId, 'o' => $order]
            );
        }
        return $id;
    }

    public function deletePage(int $lessonId, int $pageId): void
    {
        $this->execute('DELETE FROM lesson_pages WHERE id = :id AND lesson_id = :l', ['id' => $pageId, 'l' => $lessonId]);
        $this->refresh($lessonId);
    }

    /** @param list<int> $tagIds */
    public function syncPageTags(int $lessonId, int $pageId, array $tagIds): void
    {
        $this->execute('DELETE FROM lesson_page_tags WHERE page_id = :p', ['p' => $pageId]);
        foreach (array_unique(array_filter(array_map('intval', $tagIds))) as $t) {
            $this->execute('INSERT IGNORE INTO lesson_page_tags (page_id, tag_id) SELECT :p, id FROM qb_tags WHERE id = :t', ['p' => $pageId, 't' => $t]);
        }
        $this->refresh($lessonId);
    }

    /** @return array<int, list<array{id:int,title:string,color:?string}>> page id => tags */
    public function pageTagsFor(array $pageIds): array
    {
        $pageIds = array_values(array_filter(array_map('intval', $pageIds)));
        if ($pageIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->select(
            'SELECT pt.page_id, t.id, t.title, t.color FROM lesson_page_tags pt JOIN qb_tags t ON t.id = pt.tag_id
             WHERE pt.page_id IN (' . implode(',', $pageIds) . ') ORDER BY t.sort_order, t.title'
        ) as $r) {
            $out[(int) $r['page_id']][] = ['id' => (int) $r['id'], 'title' => $r['title'], 'color' => $r['color']];
        }
        return $out;
    }

    /**
     * Keeps the درسنامه in step with its pages: its tags are every page's
     * tags (so the library, the questions' «درسنامه مرتبط» and the exam
     * advice keep working on the درسنامه as a whole), and its reading time
     * is the pages' sum.
     */
    public function refresh(int $lessonId): void
    {
        $this->execute('DELETE FROM lesson_tags WHERE lesson_id = :l', ['l' => $lessonId]);
        $this->execute(
            'INSERT IGNORE INTO lesson_tags (lesson_id, tag_id)
             SELECT DISTINCT p.lesson_id, pt.tag_id FROM lesson_page_tags pt JOIN lesson_pages p ON p.id = pt.page_id WHERE p.lesson_id = :l',
            ['l' => $lessonId]
        );
        $this->execute(
            'UPDATE lessons SET reading_minutes = GREATEST(1, (SELECT COALESCE(SUM(reading_minutes), 0) FROM lesson_pages WHERE lesson_id = :l1)), updated_at = NOW() WHERE id = :l2',
            ['l1' => $lessonId, 'l2' => $lessonId]
        );
    }

    /** lesson id => [pages, sections] for the library cards. */
    public function counts(): array
    {
        $out = [];
        foreach ($this->select(
            'SELECT l.id, (SELECT COUNT(*) FROM lesson_pages p WHERE p.lesson_id = l.id) AS pages,
                    (SELECT COUNT(*) FROM lesson_sections s WHERE s.lesson_id = l.id) AS sections
             FROM lessons l WHERE l.deleted_at IS NULL'
        ) as $r) {
            $out[(int) $r['id']] = ['pages' => (int) $r['pages'], 'sections' => (int) $r['sections']];
        }
        return $out;
    }

    /** lesson id => pages this student has finished. */
    public function pagesReadBy(int $userId): array
    {
        $out = [];
        foreach ($this->select(
            'SELECT p.lesson_id, COUNT(*) AS c FROM lesson_page_reads r JOIN lesson_pages p ON p.id = r.page_id
             WHERE r.user_id = :u AND r.read_at IS NOT NULL GROUP BY p.lesson_id',
            ['u' => $userId]
        ) as $r) {
            $out[(int) $r['lesson_id']] = (int) $r['c'];
        }
        return $out;
    }

    public function pageState(int $userId, int $pageId): ?array
    {
        return $this->selectOne('SELECT * FROM lesson_page_reads WHERE user_id = :u AND page_id = :p', ['u' => $userId, 'p' => $pageId]);
    }

    public function savePageState(int $userId, int $pageId, array $fields): void
    {
        $this->execute(
            'INSERT INTO lesson_page_reads (user_id, page_id, opened_at, updated_at) VALUES (:u, :p, NOW(), NOW())
             ON DUPLICATE KEY UPDATE opened_at = NOW()',
            ['u' => $userId, 'p' => $pageId]
        );
        $sets = [];
        $params = ['u' => $userId, 'p' => $pageId];
        foreach (['highlights', 'read_at'] as $k) {
            if (array_key_exists($k, $fields)) {
                $sets[] = "{$k} = :{$k}";
                $params[$k] = $fields[$k];
            }
        }
        if ($sets !== []) {
            $this->execute('UPDATE lesson_page_reads SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE user_id = :u AND page_id = :p', $params);
        }
    }

    /** @param list<int> $ids */
    private function swap(array $ids, int $id, int $dir): array
    {
        $i = array_search($id, $ids, true);
        $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
        if ($i !== false && $j >= 0 && $j < count($ids)) {
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        }
        return $ids;
    }

    private function renumber(string $table, array $ids): void
    {
        foreach ($ids as $n => $id) {
            $this->execute("UPDATE {$table} SET sort_order = :o WHERE id = :id", ['o' => $n, 'id' => $id]);
        }
    }
}
