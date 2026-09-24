<?php
declare(strict_types=1);

namespace HeleXa\Services;

/**
 * The tree inside a mind map: validating what the editor (or an imported
 * file) sends, and the formats a map comes in and goes out as.
 *
 * A node is {id, text, note?, color?, shape?, marker?, image?, lesson?,
 * collapsed?, children[]}. Everything is plain text; nothing from a node is
 * ever rendered as HTML. Anything unknown is dropped.
 */
final class MindmapTree
{
    public const MAX_NODES  = 1500;
    public const MAX_DEPTH  = 14;
    public const COLORS     = ['indigo', 'violet', 'blue', 'sky', 'teal', 'green', 'amber', 'orange', 'rose', 'pink', 'red', 'slate'];
    public const SHAPES     = ['rounded', 'pill', 'rect', 'underline', 'cloud'];
    public const THEMES     = ['classic' => 'کلاسیک', 'rainbow' => 'رنگین‌کمان', 'pastel' => 'پاستلی', 'night' => 'شب', 'mono' => 'ساده'];
    public const LAYOUTS    = ['map' => 'دو طرفه', 'side' => 'یک طرفه', 'down' => 'درختی (از بالا)'];

    /**
     * @param array<string,int> $lessonIds uuid => id of the درسنامه‌ها that exist
     * @return array{root:array, count:int, lessons:list<int>}
     */
    public static function clean(mixed $root, array $lessonIds): array
    {
        $count = 0;
        $seen = [];
        $lessons = [];
        $node = is_array($root) ? self::node($root, 0, $count, $seen, $lessonIds, $lessons) : null;
        if ($node === null) {
            $node = ['id' => 'root', 'text' => 'موضوع اصلی', 'children' => []];
            $count = 1;
        }
        return ['root' => $node, 'count' => $count, 'lessons' => array_values(array_unique($lessons))];
    }

    private static function node(array $n, int $depth, int &$count, array &$seen, array $lessonIds, array &$lessons): ?array
    {
        if ($count >= self::MAX_NODES || $depth > self::MAX_DEPTH) {
            return null;
        }
        $count++;
        $id = is_string($n['id'] ?? null) && preg_match('/^[a-z0-9]{1,16}$/', $n['id']) === 1 && !isset($seen[$n['id']])
            ? $n['id'] : substr(bin2hex(random_bytes(5)), 0, 9);
        $seen[$id] = true;
        $text = self::line((string) ($n['text'] ?? ''), 300);
        $out = ['id' => $id, 'text' => $text !== '' ? $text : '…'];

        $note = trim(mb_substr(str_replace("\r", '', (string) ($n['note'] ?? '')), 0, 2000));
        if ($note !== '') {
            $out['note'] = $note;
        }
        if (in_array($n['color'] ?? null, self::COLORS, true)) {
            $out['color'] = $n['color'];
        }
        if (in_array($n['shape'] ?? null, self::SHAPES, true)) {
            $out['shape'] = $n['shape'];
        }
        $marker = self::line((string) ($n['marker'] ?? ''), 8);
        if ($marker !== '') {
            $out['marker'] = $marker;
        }
        $image = (string) ($n['image'] ?? '');
        if (preg_match('/^[0-9]{8}-[A-Za-z0-9]{16,40}\.(jpg|png|webp|gif)$/', $image) === 1) {
            $out['image'] = $image;
        }
        $lesson = strtolower((string) ($n['lesson'] ?? ''));
        if ($lesson !== '' && isset($lessonIds[$lesson])) {
            $out['lesson'] = $lesson;
            $lessons[] = $lessonIds[$lesson];
        }
        if (!empty($n['collapsed'])) {
            $out['collapsed'] = true;
        }
        $kids = [];
        foreach (is_array($n['children'] ?? null) ? $n['children'] : [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $k = self::node($c, $depth + 1, $count, $seen, $lessonIds, $lessons);
            if ($k !== null) {
                $kids[] = $k;
            }
        }
        $out['children'] = $kids;
        return $out;
    }

    private static function line(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        return trim(mb_substr(preg_replace('/\s+/u', ' ', $s) ?? '', 0, $max));
    }

    /**
     * An indented outline — a list pasted from Word, Markdown bullets, or
     * tab/space indented lines — as a tree. The first line is the centre.
     */
    public static function fromOutline(string $text): ?array
    {
        $lines = preg_split('/\R/u', str_replace("\t", '    ', $text)) ?: [];
        $items = [];
        foreach ($lines as $l) {
            if (trim($l) === '') {
                continue;
            }
            $indent = strlen($l) - strlen(ltrim($l, ' '));
            $body = ltrim($l, ' ');
            $hashes = 0;
            if (preg_match('/^(#{1,6})\s+/', $body, $m) === 1) {
                $hashes = strlen($m[1]);
                $body = substr($body, strlen($m[0]));
            }
            $body = preg_replace('/^([-*+•·]|\d+[.)])\s+/u', '', $body) ?? $body;
            $level = $hashes > 0 ? ($hashes - 1) * 100 : 1000 + $indent;
            $items[] = [$level, trim($body)];
        }
        if ($items === []) {
            return null;
        }
        $root = ['id' => 'root', 'text' => $items[0][1], 'children' => []];
        $stack = [[-1, &$root]];
        $i = 0;
        foreach (array_slice($items, 1) as [$level, $body]) {
            $node = ['id' => 'n' . (++$i), 'text' => $body, 'children' => []];
            while (count($stack) > 1 && $stack[count($stack) - 1][0] >= $level) {
                array_pop($stack);
            }
            $parent = &$stack[count($stack) - 1][1];
            $parent['children'][] = $node;
            $stack[] = [$level, &$parent['children'][count($parent['children']) - 1]];
            unset($parent);
        }
        return $root;
    }

    /** The map as an indented outline, for copying into notes or Word. */
    public static function toOutline(array $node, int $depth = 0): string
    {
        $out = str_repeat('  ', $depth) . ($depth === 0 ? '' : '- ') . $node['text'] . "\n";
        foreach ($node['children'] ?? [] as $c) {
            $out .= self::toOutline($c, $depth + 1);
        }
        return $out;
    }

    /** Every node's text, for search. */
    public static function texts(array $node): string
    {
        $s = $node['text'] . ' ' . ($node['note'] ?? '');
        foreach ($node['children'] ?? [] as $c) {
            $s .= ' ' . self::texts($c);
        }
        return $s;
    }

    public static function sample(): array
    {
        return [
            'format'  => 'helexa.mindmap',
            'version' => 1,
            'title'   => 'چرخه قلبی',
            'theme'   => 'rainbow',
            'layout'  => 'map',
            'root'    => [
                'text' => 'چرخه قلبی', 'marker' => '🫀',
                'children' => [
                    ['text' => 'سیستول', 'color' => 'rose', 'children' => [
                        ['text' => 'انقباض ایزوولومتریک', 'note' => 'همه دریچه‌ها بسته‌اند؛ فشار بطن بالا می‌رود.'],
                        ['text' => 'تخلیه سریع'],
                        ['text' => 'تخلیه آهسته'],
                    ]],
                    ['text' => 'دیاستول', 'color' => 'blue', 'children' => [
                        ['text' => 'شل شدن ایزوولومتریک'],
                        ['text' => 'پر شدن سریع', 'marker' => '⭐'],
                        ['text' => 'دیاستازیس'],
                        ['text' => 'انقباض دهلیز'],
                    ]],
                    ['text' => 'صداهای قلب', 'color' => 'amber', 'children' => [
                        ['text' => 'S1: بسته شدن دریچه‌های AV'],
                        ['text' => 'S2: بسته شدن دریچه‌های سینی'],
                    ]],
                ],
            ],
        ];
    }

    public static function aiPrompt(): string
    {
        return "یک نقشه ذهنی درباره «[موضوع]» برای دانشجوی پزشکی بساز و فقط JSON معتبر برگردان، با این ساختار:\n"
            . "{\"format\":\"helexa.mindmap\",\"version\":1,\"title\":\"...\",\"theme\":\"rainbow\",\"layout\":\"map\",\"root\":{\"text\":\"موضوع اصلی\",\"children\":[{\"text\":\"شاخه\",\"color\":\"rose\",\"note\":\"توضیح کوتاه اختیاری\",\"marker\":\"⭐\",\"children\":[]}]}}\n"
            . "رنگ‌های مجاز: " . implode('، ', self::COLORS) . ". حداکثر ۵ سطح عمق و متن هر گره کوتاه (زیر ۸ کلمه) باشد؛ توضیح بیشتر را در note بنویس.";
    }
}
