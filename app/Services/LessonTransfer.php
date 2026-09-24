<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Database;
use HeleXa\Core\Str;
use HeleXa\Models\LessonRepository;

/**
 * درسنامه‌ها in and out as JSON.
 *
 * A درسنامه has `sections` (زیردرس‌ها), each with `pages`; a page carries
 * its own `tags` and its text either as `html` (what export writes) or as
 * `blocks` — a list simple enough for an AI to produce: headings,
 * paragraphs, callout boxes, lists and tables, with **bold**, __underline__
 * and ==highlight== inline. Both end in RichText::clean(), the same door
 * the editor uses. A version-1 file (one `html`/`blocks` per درسنامه) still
 * imports, as one زیردرس with one page. A `uuid` that already exists updates
 * that درسنامه: زیردرس‌ها are matched by title, pages by uuid or title, and
 * nothing the file leaves out is deleted. The درس is matched by its titles
 * and tags are created on the way in.
 */
final class LessonTransfer
{
    public const FORMAT = 'helexa-lessons';

    public static function export(array $rows): array
    {
        $repo = new LessonRepository();
        $out = [];
        foreach ($rows as $row) {
            $full = $repo->find((int) $row['id']);
            if ($full === null) {
                continue;
            }
            $sections = [];
            foreach (LessonRepository::pagesReady() ? $repo->outline((int) $full['id']) : [] as $sec) {
                $pages = [];
                foreach ($sec['pages'] as $p) {
                    $body = $repo->page((int) $full['id'], $p['uuid']);
                    $pages[] = ['uuid' => $p['uuid'], 'title' => $p['title'], 'tags' => array_column($p['tags'], 'title'), 'html' => (string) ($body['body_html'] ?? '')];
                }
                $sections[] = ['title' => $sec['title'], 'pages' => $pages];
            }
            $out[] = [
                'uuid'     => $full['uuid'],
                'title'    => $full['title'],
                'summary'  => $full['summary'],
                'subject'  => SubjectTree::pathOf($full['subject_id'] === null ? null : (int) $full['subject_id']),
                'color'    => $full['color'],
                'status'   => $full['status'],
                'sections' => $sections,
            ];
        }
        return ['format' => self::FORMAT, 'version' => 2, 'exported_at' => date('c'), 'lessons' => $out];
    }

    /** @return array{created:int, updated:int, errors:list<string>} */
    public static function import(string $raw, int $actorId, bool $publish, int $defaultSubject): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $result['errors'][] = 'JSON معتبر نیست.';
            return $result;
        }
        $list = $data['lessons'] ?? (array_is_list($data) ? $data : [$data]);
        if (!is_array($list) || $list === []) {
            $result['errors'][] = 'درسنامه‌ای در فایل نیست.';
            return $result;
        }

        $repo = new LessonRepository();
        foreach (array_slice($list, 0, 300) as $i => $item) {
            $n = fa((string) ($i + 1));
            if (!is_array($item)) {
                $result['errors'][] = "مورد {$n}: ساختار نادرست";
                continue;
            }
            $title = trim(mb_substr((string) ($item['title'] ?? ''), 0, 191));
            // Version 1: one text per درسنامه → one زیردرس with one page.
            $sections = is_array($item['sections'] ?? null) ? $item['sections'] : [[
                'title' => $title,
                'pages' => [['title' => $title, 'tags' => $item['tags'] ?? [], 'html' => $item['html'] ?? null, 'blocks' => $item['blocks'] ?? []]],
            ]];
            if ($title === '') {
                $result['errors'][] = "مورد {$n}: عنوان ندارد";
                continue;
            }
            $subject = is_array($item['subject'] ?? null) ? SubjectTree::findPath($item['subject']) : 0;
            $existing = is_string($item['uuid'] ?? null) ? $repo->findByUuid($item['uuid']) : null;

            $id = $repo->save($existing === null ? null : (int) $existing['id'], [
                'title'           => $title,
                'summary'         => trim(mb_substr((string) ($item['summary'] ?? ''), 0, 500)),
                'subject_id'      => $subject ?: ($existing['subject_id'] ?? $defaultSubject),
                'package_id'      => $existing['package_id'] ?? null,
                'color'           => (string) ($item['color'] ?? ($existing['color'] ?? 'indigo')),
                'body_html'       => (string) ($existing['body_html'] ?? ''),
                'reading_minutes' => (int) ($existing['reading_minutes'] ?? 1),
                'status'          => $publish ? 'published' : (string) ($item['status'] ?? ($existing['status'] ?? 'draft')),
                'sort_order'      => (int) ($item['sort_order'] ?? ($existing['sort_order'] ?? 0)),
            ], $actorId);

            if (LessonRepository::pagesReady()) {
                $pagesDone = self::importSections($repo, $id, $sections, $n, $result['errors']);
                if ($pagesDone === 0 && $existing === null) {
                    $result['errors'][] = "مورد {$n}: هیچ صفحه‌ای با متن نداشت";
                }
                $repo->refresh($id);
            }
            $existing === null ? $result['created']++ : $result['updated']++;
        }
        return $result;
    }

    /**
     * زیردرس‌ها by title, pages by uuid (within this درسنامه) or by title
     * within their زیردرس; each page's tags replace what it had.
     */
    private static function importSections(LessonRepository $repo, int $lessonId, array $sections, string $n, array &$errors): int
    {
        $outline = $repo->outline($lessonId);
        $byTitle = [];
        foreach ($outline as $sec) {
            $byTitle[$sec['title']] = $sec;
        }
        $done = 0;
        foreach (array_slice($sections, 0, 60) as $si => $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $secTitle = trim(mb_substr((string) ($sec['title'] ?? ''), 0, 191)) ?: 'زیردرس ' . fa((string) ($si + 1));
            $sectionId = isset($byTitle[$secTitle]) ? (int) $byTitle[$secTitle]['id'] : $repo->addSection($lessonId, $secTitle);
            $known = $byTitle[$secTitle]['pages'] ?? [];
            foreach (array_slice((array) ($sec['pages'] ?? []), 0, 200) as $pi => $pg) {
                if (!is_array($pg)) {
                    continue;
                }
                $pTitle = trim(mb_substr((string) ($pg['title'] ?? ''), 0, 191));
                $html = RichText::clean(isset($pg['html']) && is_string($pg['html']) ? $pg['html'] : self::blocksToHtml((array) ($pg['blocks'] ?? [])));
                if ($pTitle === '' || $html === '') {
                    $errors[] = "مورد {$n}، «{$secTitle}»، صفحه " . fa((string) ($pi + 1)) . ': عنوان یا متن ندارد';
                    continue;
                }
                $page = is_string($pg['uuid'] ?? null) ? $repo->page($lessonId, $pg['uuid']) : null;
                if ($page === null) {
                    foreach ($known as $k) {
                        if ($k['title'] === $pTitle) {
                            $page = $repo->page($lessonId, $k['uuid']);
                        }
                    }
                }
                $pageId = $repo->savePage($lessonId, $page, [
                    'section_id' => $sectionId, 'title' => $pTitle, 'body_html' => $html, 'reading_minutes' => RichText::readingMinutes($html),
                ]);
                $repo->syncPageTags($lessonId, $pageId, self::tagIds(is_array($pg['tags'] ?? null) ? $pg['tags'] : []));
                $done++;
            }
        }
        return $done;
    }

    /** Tag titles → ids, creating the missing ones. @return list<int> */
    public static function tagIds(array $titles): array
    {
        $ids = [];
        foreach (array_slice($titles, 0, 20) as $t) {
            $t = trim(mb_substr((string) $t, 0, 96));
            if ($t === '') {
                continue;
            }
            $row = Database::selectOne('SELECT id FROM qb_tags WHERE title = :t LIMIT 1', ['t' => $t]);
            if ($row === null) {
                $ids[] = Database::insert(
                    "INSERT INTO qb_tags (uuid, title, color, sort_order, is_active, created_at) VALUES (:u, :t, 'chip-blue', 0, 1, NOW())",
                    ['u' => Str::uuid4(), 't' => $t]
                );
            } else {
                $ids[] = (int) $row['id'];
            }
        }
        return $ids;
    }

    /** The simple block list an AI can write, as HTML. */
    public static function blocksToHtml(array $blocks): string
    {
        $out = '';
        foreach (array_slice($blocks, 0, 800) as $b) {
            if (is_string($b)) {
                $out .= '<p>' . self::inline($b) . '</p>';
                continue;
            }
            if (!is_array($b)) {
                continue;
            }
            $type = (string) ($b['type'] ?? 'p');
            $text = self::inline((string) ($b['text'] ?? ''));
            $out .= match ($type) {
                'h1', 'h2' => '<h2>' . $text . '</h2>',
                'h3'       => '<h3>' . $text . '</h3>',
                'h4'       => '<h4>' . $text . '</h4>',
                'quote'    => '<blockquote>' . $text . '</blockquote>',
                'callout'  => '<div class="callout callout-' . (in_array($b['tone'] ?? '', ['note', 'tip', 'warn', 'key', 'danger'], true) ? $b['tone'] : 'note') . '"><p>' . $text . '</p></div>',
                'list'     => self::list($b),
                'table'    => self::table($b),
                'hr'       => '<hr>',
                default    => '<p' . (isset($b['highlight']) ? ' class="hl-' . (preg_match('/^(yellow|green|pink|blue|orange|violet)$/', (string) $b['highlight']) ? $b['highlight'] : 'yellow') . '"' : '') . '>' . $text . '</p>',
            };
        }
        return $out;
    }

    private static function list(array $b): string
    {
        $tag = !empty($b['ordered']) ? 'ol' : 'ul';
        $items = '';
        foreach (array_slice((array) ($b['items'] ?? []), 0, 200) as $it) {
            $items .= '<li>' . self::inline(is_scalar($it) ? (string) $it : '') . '</li>';
        }
        return "<{$tag}>{$items}</{$tag}>";
    }

    private static function table(array $b): string
    {
        $rows = '';
        foreach (array_slice((array) ($b['rows'] ?? []), 0, 100) as $r => $row) {
            $cells = '';
            foreach (array_slice((array) $row, 0, 12) as $cell) {
                $tag = $r === 0 && !empty($b['header']) ? 'th' : 'td';
                $cells .= "<{$tag}>" . self::inline(is_scalar($cell) ? (string) $cell : '') . "</{$tag}>";
            }
            $rows .= '<tr>' . $cells . '</tr>';
        }
        return '<table><tbody>' . $rows . '</tbody></table>';
    }

    /** **bold**, __underline__, ==highlight==, !!red!! — escaped first. */
    private static function inline(string $text): string
    {
        $t = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $t) ?? $t;
        $t = preg_replace('/__(.+?)__/u', '<u>$1</u>', $t) ?? $t;
        $t = preg_replace('/==(.+?)==/u', '<mark class="hl-yellow">$1</mark>', $t) ?? $t;
        $t = preg_replace('/!!(.+?)!!/u', '<span style="color: #dc2626">$1</span>', $t) ?? $t;
        return nl2br($t, false);
    }

    public static function sample(): string
    {
        return (string) json_encode([
            'format'  => self::FORMAT,
            'version' => 2,
            'lessons' => [[
                'title'    => 'باکتری‌شناسی',
                'summary'  => 'از کلیات تا باکتری‌های مهم بالینی',
                'subject'  => ['میکروب‌شناسی'],
                'color'    => 'teal',
                'sections' => [
                    ['title' => 'کلیات باکتری‌شناسی', 'pages' => [
                        ['title' => 'ساختار سلول باکتری', 'tags' => ['کلیات باکتری‌شناسی'], 'blocks' => [
                            ['type' => 'h2', 'text' => 'دیواره سلولی'],
                            ['type' => 'p', 'text' => '**پپتیدوگلیکان** در گرم مثبت‌ها ضخیم و در گرم منفی‌ها ==نازک== است.'],
                            ['type' => 'callout', 'tone' => 'key', 'text' => 'LPS فقط در غشای خارجی گرم منفی‌ها هست.'],
                        ]],
                        ['title' => 'رنگ‌آمیزی گرم', 'tags' => ['کلیات باکتری‌شناسی'], 'blocks' => [
                            ['type' => 'list', 'ordered' => true, 'items' => ['کریستال ویوله', 'لوگل', 'الکل', 'سافرانین']],
                        ]],
                    ]],
                    ['title' => 'کوکسی‌های گرم مثبت', 'pages' => [
                        ['title' => 'استافیلوکوک‌ها', 'tags' => ['کوکسی‌های گرم مثبت', 'استافیلوکوک'], 'blocks' => [
                            ['type' => 'table', 'header' => true, 'rows' => [['گونه', 'کوآگولاز'], ['S. aureus', 'مثبت'], ['S. epidermidis', 'منفی']]],
                        ]],
                    ]],
                ],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function aiPrompt(): string
    {
        return <<<'TXT'
تو یک استاد پزشکی هستی. برای موضوعی که می‌دهم یک «درسنامه» کامل و دقیق به فارسی بنویس و خروجی را فقط به صورت JSON معتبر با این ساختار برگردان (هیچ متن دیگری ننویس):

{"format":"helexa-lessons","version":2,"lessons":[{"title":"نام درس","summary":"یک جمله","subject":["نام درس در بانک سوال"],"color":"indigo","sections":[{"title":"زیردرس ۱","pages":[{"title":"عنوان صفحه","tags":["برچسب۱"],"blocks":[ ... ]}]}]}]}

هر درسنامه چند زیردرس (sections) دارد و هر زیردرس چند صفحه (pages). هر صفحه کوتاه و درباره یک مبحث باشد (حدود ۳۰۰ تا ۸۰۰ کلمه) و برچسب‌هایش دقیقاً همان نام مبحث باشد (مثلاً «کلیات باکتری‌شناسی») تا با بانک سوال و فلش‌کارت یکی شود.

انواع بلوک:
- {"type":"h2","text":"تیتر اصلی"} و {"type":"h3","text":"تیتر فرعی"}
- {"type":"p","text":"پاراگراف"}  (برای هایلایت کل پاراگراف: "highlight":"yellow|green|pink|blue|orange|violet")
- {"type":"list","ordered":true|false,"items":["...","..."]}
- {"type":"callout","tone":"note|tip|warn|key|danger","text":"نکته مهم"}
- {"type":"table","header":true,"rows":[["ستون۱","ستون۲"],["...","..."]]}
- {"type":"quote","text":"..."} و {"type":"hr"}

قالب‌بندی داخل متن: **پررنگ**، __زیرخط‌دار__، ==هایلایت==، !!قرمز!!
درسنامه را با تیترهای منظم، نکات کلیدی در callout از نوع key، و یک جدول جمع‌بندی در پایان بنویس.
TXT;
    }
}
