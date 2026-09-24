<?php
declare(strict_types=1);

namespace HeleXa\Services\Flashcards;

use HeleXa\Services\Settings;

/**
 * Turns an uploaded spreadsheet or pasted text into cards.
 *
 * Three ways in, one shape out:
 *
 *   .xlsx    read by XlsxReader
 *   .csv     comma, semicolon or tab separated; UTF-8, or the Windows-1256
 *            that Persian Excel writes when told to "Save as CSV"
 *   pasted   cells copied straight out of Excel or Google Sheets arrive as
 *            tab-separated lines — the path that works on any server,
 *            including one without the zip extension
 *
 * Columns: A = روی کارت, B = پشت کارت, C = راهنما (optional),
 *          D = نام جلسه (optional, admin course import only).
 *
 * A header row is recognised and skipped, so a file with or without titles
 * both work. Rows with an empty front or back are counted and skipped rather
 * than failing the whole file — one blank line in row 800 should not throw
 * away the other 799.
 */
final class CardImporter
{
    private const MAX_FILE_BYTES = 8 * 1024 * 1024;
    private const MAX_FRONT      = 2000;
    private const MAX_HINT       = 500;

    private const HEADER_WORDS = [
        'front', 'back', 'question', 'answer', 'term', 'definition', 'word', 'meaning', 'hint', 'session',
        'رو', 'روی کارت', 'پشت', 'پشت کارت', 'سوال', 'سؤال', 'جواب', 'پاسخ', 'کلمه', 'معنی', 'واژه',
        'راهنما', 'جلسه', 'نام جلسه', 'عنوان',
    ];

    public static function maxRows(): int
    {
        return max(10, min((int) Settings::get('fc_import_max_rows', 2000), 10000));
    }

    /**
     * @param array{tmp_name:string,size:int,error:int,name?:string} $file
     * @return array{cards:array<int,array{front:string,back:string,hint:?string,session:?string}>, skipped:int, truncated:bool}
     */
    public static function fromUpload(array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('فایلی دریافت نشد.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('حجم فایل نباید بیشتر از ۸ مگابایت باشد.');
        }

        $path = (string) $file['tmp_name'];

        // The bytes decide, not the extension: a CSV renamed to .xlsx is read
        // as text, and an .xlsx saved without its extension still opens.
        if (XlsxReader::looksLikeXlsx($path)) {
            return self::fromRows(XlsxReader::rows($path, self::maxRows()));
        }

        $name = strtolower((string) ($file['name'] ?? ''));
        if (str_ends_with($name, '.xls')) {
            throw new \RuntimeException('فرمت قدیمی xls پشتیبانی نمی‌شود. فایل را در اکسل با فرمت xlsx یا CSV ذخیره کن.');
        }

        $text = (string) file_get_contents($path);
        if (str_contains(substr($text, 0, 2048), "\0")) {
            throw new \RuntimeException('این فایل متنی یا اکسل نیست.');
        }

        return self::fromText($text);
    }

    /** @return array{cards:array, skipped:int, truncated:bool} */
    public static function fromText(string $text): array
    {
        $text = self::toUtf8($text);
        if (trim($text) === '') {
            throw new \RuntimeException('متنی برای ورود پیدا نشد.');
        }

        $delimiter = self::detectDelimiter($text);
        $rows      = [];
        $handle    = fopen('php://temp', 'r+');
        fwrite($handle, $text);
        rewind($handle);

        // fgetcsv rather than explode: a quoted cell may contain the
        // delimiter or a line break, and Excel quotes exactly those cells.
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(static fn ($v) => (string) $v, $row);
            if (count($rows) > self::maxRows() + 1) {
                break;
            }
        }
        fclose($handle);

        return self::fromRows($rows);
    }

    /**
     * @param array<int,array<int,string>> $rows
     * @return array{cards:array, skipped:int, truncated:bool}
     */
    public static function fromRows(array $rows): array
    {
        if ($rows !== [] && self::isHeader($rows[0])) {
            array_shift($rows);
        }

        $max       = self::maxRows();
        $truncated = count($rows) > $max;
        $rows      = array_slice($rows, 0, $max);

        $cards   = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $front = self::clean($row[0] ?? '', self::MAX_FRONT);
            $back  = self::clean($row[1] ?? '', self::MAX_FRONT);

            if ($front === '' && $back === '' && trim(implode('', $row)) === '') {
                continue;                           // a blank line is not worth reporting
            }
            if ($front === '' || $back === '') {
                $skipped++;
                continue;
            }

            $hint    = self::clean($row[2] ?? '', self::MAX_HINT);
            $session = self::clean($row[3] ?? '', 191);

            $cards[] = [
                'front'   => $front,
                'back'    => $back,
                'hint'    => $hint === '' ? null : $hint,
                'session' => $session === '' ? null : $session,
            ];
        }

        if ($cards === []) {
            throw new \RuntimeException('هیچ کارت معتبری پیدا نشد. ستون اول باید «روی کارت» و ستون دوم «پشت کارت» باشد.');
        }

        return ['cards' => $cards, 'skipped' => $skipped, 'truncated' => $truncated];
    }

    /* ---------------------------------------------------------- internals */

    private static function isHeader(array $row): bool
    {
        $first  = mb_strtolower(trim((string) ($row[0] ?? '')));
        $second = mb_strtolower(trim((string) ($row[1] ?? '')));

        return in_array($first, self::HEADER_WORDS, true) && in_array($second, self::HEADER_WORDS, true);
    }

    /**
     * Pasted cells are tab-separated; a CSV from Persian Excel is often
     * semicolon-separated because the comma is the regional decimal mark.
     * The delimiter that appears most in the first lines wins.
     */
    private static function detectDelimiter(string $text): string
    {
        $sample = implode("\n", array_slice(preg_split('/\R/u', $text) ?: [], 0, 20));
        $counts = [
            "\t" => substr_count($sample, "\t"),
            ';'  => substr_count($sample, ';'),
            ','  => substr_count($sample, ','),
        ];
        arsort($counts);
        $best = (string) array_key_first($counts);

        return $counts[$best] > 0 ? $best : "\t";
    }

    private static function toUtf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        } elseif (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            // Excel's "Unicode Text" export is UTF-16 with a byte-order mark.
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-16');
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = function_exists('iconv') ? @iconv('Windows-1256', 'UTF-8//IGNORE', $text) : false;
            $text = $converted !== false ? $converted : (string) mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private static function clean(string $value, int $max): string
    {
        // Control characters other than line breaks and tabs are dropped:
        // they have no business on a card and some break JSON consumers.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);

        return mb_substr($value, 0, $max);
    }
}
