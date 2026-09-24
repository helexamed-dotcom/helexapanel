<?php
declare(strict_types=1);

namespace HeleXa\Services\Flashcards;

/**
 * Reads the first worksheet of an .xlsx file into rows of strings.
 *
 * No library: an .xlsx is a zip of XML parts, and the parts needed for plain
 * cell values are small and well documented. Pulling in a spreadsheet package
 * for "column A is the front, column B is the back" would add megabytes to a
 * shared host and a dependency to keep patched.
 *
 * Defensive by construction, because the file comes from a user:
 *   - every zip entry's declared size is checked before it is read, so a
 *     small file that inflates to gigabytes (a zip bomb) is refused unread;
 *   - the worksheet is read with XMLReader, streaming, and stops at the row
 *     ceiling instead of materialising the whole sheet;
 *   - XML is parsed with LIBXML_NONET and without entity substitution, so a
 *     crafted part cannot reach the network or expand entities.
 */
final class XlsxReader
{
    private const MAX_PART_BYTES = 30 * 1024 * 1024;
    private const MAX_COLUMNS    = 8;

    public static function available(): bool
    {
        return class_exists(\ZipArchive::class) && class_exists(\XMLReader::class);
    }

    /** True when the bytes start like a zip archive — which every .xlsx is. */
    public static function looksLikeXlsx(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 4);
        fclose($handle);

        return $head === "PK\x03\x04";
    }

    /**
     * @return array<int,array<int,string>>
     * @throws \RuntimeException with a message fit for the user
     */
    public static function rows(string $path, int $maxRows): array
    {
        if (!self::available()) {
            throw new \RuntimeException(
                'خواندن فایل اکسل روی این سرور فعال نیست (افزونه zip). '
                . 'سلول‌ها را از اکسل کپی کن و در کادر «چسباندن متن» قرار بده، یا فایل را CSV ذخیره کن.'
            );
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('فایل اکسل باز نشد. مطمئن شو فرمت آن xlsx است.');
        }

        try {
            $sheetPath = self::firstSheetPath($zip);
            $shared    = self::sharedStrings($zip);
            $xml       = self::readPart($zip, $sheetPath);

            return self::parseSheet($xml, $shared, $maxRows);
        } finally {
            $zip->close();
        }
    }

    /* ---------------------------------------------------------- internals */

    private static function readPart(\ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            throw new \RuntimeException('ساختار فایل اکسل ناقص است.');
        }
        if ((int) $stat['size'] > self::MAX_PART_BYTES) {
            throw new \RuntimeException('فایل اکسل بیش از حد بزرگ است.');
        }

        $data = $zip->getFromName($name);
        if ($data === false) {
            throw new \RuntimeException('خواندن فایل اکسل ناموفق بود.');
        }

        return $data;
    }

    private static function loadXml(string $xml): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $doc      = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            throw new \RuntimeException('محتوای فایل اکسل قابل خواندن نیست.');
        }

        return $doc;
    }

    /**
     * The first sheet in workbook order — not "sheet1.xml", which is only the
     * first sheet until someone reorders the tabs.
     */
    private static function firstSheetPath(\ZipArchive $zip): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';

        if ($zip->statName('xl/workbook.xml') === false || $zip->statName('xl/_rels/workbook.xml.rels') === false) {
            return $fallback;
        }

        $workbook = self::loadXml(self::readPart($zip, 'xl/workbook.xml'));
        $rels     = self::loadXml(self::readPart($zip, 'xl/_rels/workbook.xml.rels'));

        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheets = $workbook->xpath('//m:sheets/m:sheet');
        if (!$sheets) {
            return $fallback;
        }

        $relId = (string) ($sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');

        foreach ($rels->children() as $rel) {
            if ((string) $rel['Id'] === $relId) {
                $target = ltrim((string) $rel['Target'], '/');
                $target = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                // A target is a path inside the archive; anything that tries
                // to climb out of it is ignored rather than followed.
                return str_contains($target, '..') ? $fallback : $target;
            }
        }

        return $fallback;
    }

    /** @return array<int,string> */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        if ($zip->statName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $doc     = self::loadXml(self::readPart($zip, 'xl/sharedStrings.xml'));
        $strings = [];

        foreach ($doc->si as $si) {
            // Plain cells hold one <t>; rich-text cells hold several runs,
            // each with its own <t>. Concatenating every <t> covers both.
            $text = '';
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $text .= (string) $t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param array<int,string> $shared
     * @return array<int,array<int,string>>
     */
    private static function parseSheet(string $xml, array $shared, int $maxRows): array
    {
        $reader = new \XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET)) {
            throw new \RuntimeException('برگه اکسل قابل خواندن نیست.');
        }

        $rows    = [];
        $row     = null;
        $col     = 0;
        $type    = '';
        $inValue = false;
        $value   = '';

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                switch ($reader->localName) {
                    case 'row':
                        $row = [];
                        break;
                    case 'c':
                        $ref  = (string) $reader->getAttribute('r');
                        $col  = $ref !== '' ? self::columnIndex($ref) : count($row ?? []);
                        $type = (string) $reader->getAttribute('t');
                        $value = '';
                        break;
                    case 'v':
                    case 't':
                        $inValue = true;
                        break;
                }
            } elseif ($reader->nodeType === \XMLReader::TEXT || $reader->nodeType === \XMLReader::CDATA
                      || $reader->nodeType === \XMLReader::SIGNIFICANT_WHITESPACE) {
                if ($inValue) {
                    $value .= $reader->value;
                }
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT) {
                switch ($reader->localName) {
                    case 'v':
                    case 't':
                        $inValue = false;
                        break;
                    case 'c':
                        if ($row !== null && $col < self::MAX_COLUMNS) {
                            $row[$col] = $type === 's' ? ($shared[(int) $value] ?? '') : $value;
                        }
                        break;
                    case 'row':
                        if ($row !== null) {
                            $rows[] = self::densify($row);
                            if (count($rows) >= $maxRows + 1) {   // +1 for a possible header row
                                $reader->close();
                                return $rows;
                            }
                        }
                        $row = null;
                        break;
                }
            }
        }

        $reader->close();
        return $rows;
    }

    /** "C12" → 2 */
    private static function columnIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref)) ?? '';
        $index   = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }
        return max(0, $index - 1);
    }

    /**
     * Fills the gaps a sparse row leaves (Excel omits empty cells entirely),
     * so column C is always index 2.
     *
     * @param array<int,string> $row
     * @return array<int,string>
     */
    private static function densify(array $row): array
    {
        if ($row === []) {
            return [];
        }
        $out = [];
        for ($i = 0, $max = max(array_keys($row)); $i <= $max; $i++) {
            $out[] = (string) ($row[$i] ?? '');
        }
        return $out;
    }
}
