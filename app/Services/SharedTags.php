<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Core\Request;
use HeleXa\Models\QuestionBank\QbTagRepository;

/**
 * One set of tags for the whole product (qb_tags): questions, درسنامه
 * pages, figure hotspots, flashcard decks, بالین lessons and mind maps all
 * point at the same rows, so «کلیات باکتری‌شناسی» is one thing everywhere and
 * the analysis can put every module's results for it side by side.
 *
 * Each module keeps its own join table; this class reads and writes them
 * the same way, and turns the tag picker's input into ids.
 */
final class SharedTags
{
    /** join table => the column naming the tagged thing */
    private const TABLES = [
        'lesson_page_tags'  => 'page_id',
        'fc_deck_tags'      => 'deck_id',
        'balin_lesson_tags' => 'lesson_id',
        'mindmap_tags'      => 'mindmap_id',
        'lesson_tags'       => 'lesson_id',
        'qb_question_tags'  => 'question_id',
    ];

    public static function ready(string $table): bool
    {
        if (!isset(self::TABLES[$table])) {
            return false;
        }
        try {
            Database::selectOne("SELECT 1 FROM {$table} LIMIT 1");
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * The picker's answer: the ticked tags (tags[]) plus any typed in
     * «برچسب تازه» (new_tags, separated by comma or «،»), which are found by
     * title or created.
     *
     * @return list<int>
     */
    public static function fromRequest(Request $request, string $field = 'tags'): array
    {
        $raw = $request->input($field, []);
        return self::fromValues(is_array($raw) ? $raw : [], (string) $request->input('new_' . $field, ''));
    }

    /**
     * The same from plain values (a JSON body): ticked ids and typed titles.
     *
     * @return list<int>
     */
    public static function fromValues(array $raw, string $typedText): array
    {
        $ids = array_map('intval', array_filter($raw, 'is_scalar'));
        $typed = preg_split('/[,،\n]+/u', $typedText) ?: [];
        $repo = new QbTagRepository();
        foreach (array_slice($typed, 0, 12) as $title) {
            $title = trim(mb_substr(preg_replace('/\s+/u', ' ', $title) ?? '', 0, 96));
            if ($title === '') {
                continue;
            }
            $row = Database::selectOne('SELECT id FROM qb_tags WHERE title = :t LIMIT 1', ['t' => $title]);
            if ($row !== null) {
                $ids[] = (int) $row['id'];
                continue;
            }
            try {
                $ids[] = $repo->create($title, 'chip-gray', 0);
            } catch (\RuntimeException) {
                // lost a race with another admin: the row exists now
                $row = Database::selectOne('SELECT id FROM qb_tags WHERE title = :t LIMIT 1', ['t' => $title]);
                if ($row !== null) {
                    $ids[] = (int) $row['id'];
                }
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /** @param list<int> $tagIds */
    public static function sync(string $table, int $id, array $tagIds): void
    {
        if (!self::ready($table)) {
            return;
        }
        $col = self::TABLES[$table];
        Database::execute("DELETE FROM {$table} WHERE {$col} = :id", ['id' => $id]);
        foreach (array_unique(array_filter(array_map('intval', $tagIds))) as $t) {
            Database::execute("INSERT IGNORE INTO {$table} ({$col}, tag_id) SELECT :id, id FROM qb_tags WHERE id = :t", ['id' => $id, 't' => $t]);
        }
    }

    /** @return list<int> */
    public static function idsFor(string $table, int $id): array
    {
        if (!self::ready($table)) {
            return [];
        }
        $col = self::TABLES[$table];
        return array_map('intval', array_column(Database::select("SELECT tag_id FROM {$table} WHERE {$col} = :id", ['id' => $id]), 'tag_id'));
    }

    /** @return list<array{id:int,title:string,color:?string}> */
    public static function tagsFor(string $table, int $id): array
    {
        if (!self::ready($table)) {
            return [];
        }
        $col = self::TABLES[$table];
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => $r['title'], 'color' => $r['color']], Database::select(
            "SELECT t.id, t.title, t.color FROM {$table} x JOIN qb_tags t ON t.id = x.tag_id WHERE x.{$col} = :id ORDER BY t.sort_order, t.title",
            ['id' => $id]
        ));
    }

    /** Every active tag, for a picker. */
    public static function all(): array
    {
        try {
            return (new QbTagRepository())->all(true);
        } catch (\PDOException) {
            return [];
        }
    }
}
