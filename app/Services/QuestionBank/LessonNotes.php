<?php
declare(strict_types=1);

namespace HeleXa\Services\QuestionBank;

use HeleXa\Core\Database;

/**
 * «درسنامه»: the short lesson behind a question.
 *
 * A درسنامه can sit on a question itself or on any level of the tree it is
 * filed under. The most specific one wins — the question's own, then its
 * عنوان, then its زیردرس, then its درس — so one note written for a whole
 * topic serves every question in it, and a single question can still
 * carry its own.
 *
 * The text is written by admins (or imported from JSON) as plain text with a
 * few light marks; it is always escaped first and only then given
 * formatting, so nothing in it can become markup of its own.
 */
final class LessonNotes
{
    /**
     * @return array{title:string, html:string, source:string}|null null when
     *         nothing on the question's path has a درسنامه
     */
    public static function forQuestion(array $question): ?array
    {
        $own = trim((string) ($question['lesson_note'] ?? ''));
        if ($own !== '') {
            return ['title' => 'درسنامه این سوال', 'html' => self::html($own), 'source' => 'question'];
        }

        foreach (['topic_id', 'sub_subject_id', 'subject_id'] as $column) {
            $id = (int) ($question[$column] ?? 0);
            if ($id <= 0) {
                continue;
            }
            try {
                $row = Database::selectOne('SELECT title, lesson_note FROM qb_subjects WHERE id = :id LIMIT 1', ['id' => $id]);
            } catch (\PDOException) {
                return null;   // before the migration there are no درسنامه‌ها
            }
            $note = trim((string) ($row['lesson_note'] ?? ''));
            if ($note !== '') {
                return ['title' => 'درسنامه: ' . $row['title'], 'html' => self::html($note), 'source' => $column];
            }
        }

        return null;
    }

    /**
     * Escapes, then allows a handful of marks:
     *   ## heading · - bullet · 1. numbered · **bold** · blank line = paragraph
     */
    public static function html(string $text): string
    {
        $text  = str_replace(["\r\n", "\r"], "\n", trim($text));
        $lines = explode("\n", $text);
        $out   = '';
        $list  = null;   // 'ul' | 'ol' | null
        $para  = [];

        $inline = static function (string $line): string {
            $safe = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $safe);
        };
        $flushPara = static function () use (&$para, &$out, $inline): void {
            if ($para !== []) {
                $out .= '<p>' . implode('<br>', array_map($inline, $para)) . '</p>';
                $para = [];
            }
        };
        $closeList = static function () use (&$list, &$out): void {
            if ($list !== null) {
                $out .= '</' . $list . '>';
                $list = null;
            }
        };

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $flushPara();
                $closeList();
                continue;
            }
            if (preg_match('/^#{1,3}\s+(.+)$/u', $trim, $m) === 1) {
                $flushPara();
                $closeList();
                $out .= '<h4>' . $inline($m[1]) . '</h4>';
                continue;
            }
            if (preg_match('/^[-•*]\s+(.+)$/u', $trim, $m) === 1) {
                $flushPara();
                if ($list !== 'ul') {
                    $closeList();
                    $out .= '<ul>';
                    $list = 'ul';
                }
                $out .= '<li>' . $inline($m[1]) . '</li>';
                continue;
            }
            if (preg_match('/^[0-9۰-۹]+[.)]\s+(.+)$/u', $trim, $m) === 1) {
                $flushPara();
                if ($list !== 'ol') {
                    $closeList();
                    $out .= '<ol>';
                    $list = 'ol';
                }
                $out .= '<li>' . $inline($m[1]) . '</li>';
                continue;
            }
            $closeList();
            $para[] = $trim;
        }
        $flushPara();
        $closeList();

        return $out;
    }
}
