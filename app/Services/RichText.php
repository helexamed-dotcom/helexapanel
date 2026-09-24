<?php
declare(strict_types=1);

namespace HeleXa\Services;

/**
 * The one door rich text goes through: the درسنامه editor, a JSON import,
 * a mind-map note, a product description.
 *
 * An allow-list, not a block-list. Every element is either on the list (and
 * keeps only its listed attributes, with checked values) or is unwrapped to
 * its text; script, style, iframe and friends are dropped with everything
 * inside them. Inline styles survive only as colour, background colour,
 * alignment and a bounded font size — exactly what the editor's toolbar can
 * produce — so a pasted Word document keeps its highlights and loses its
 * fonts, and nothing can position, hide or load anything.
 *
 * Every block also gets a short `data-b` id, stored with the text. A
 * student's own highlights are anchored to those ids, so an admin fixing a
 * typo in one paragraph does not shift every highlight after it.
 */
final class RichText
{
    private const BLOCKS = ['p', 'h2', 'h3', 'h4', 'blockquote', 'li', 'td', 'th', 'figcaption', 'pre'];

    private const TAGS = [
        'p' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'blockquote' => [], 'pre' => [], 'code' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'br' => [], 'hr' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [],
        'mark' => [], 'span' => [], 'div' => [], 'figure' => [], 'figcaption' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
        'a' => ['href'], 'img' => ['src', 'alt', 'width', 'height'],
    ];

    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea',
        'select', 'svg', 'math', 'link', 'meta', 'base', 'noscript', 'template', 'frame', 'frameset', 'video', 'audio'];

    private const CLASSES = '/^(callout|callout-(note|tip|warn|key|danger)|hl-(yellow|green|pink|blue|orange|violet)|lx-(lead|small|big|keyword|term|step)|align-(right|left|center|justify))$/';

    private const NAMED_COLORS = ['black', 'white', 'red', 'green', 'blue', 'orange', 'purple', 'gray', 'grey', 'yellow', 'transparent'];

    /** @param string $imagePrefix where images may point, e.g. /media/lessons/ */
    public static function clean(string $html, string $imagePrefix = '/media/lessons/'): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="hx-root">' . $html . '</div>', LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('hx-root');
        if ($root === null) {
            return '';
        }
        self::walk($root, $imagePrefix);

        $ids = [];
        $out = '';
        foreach ($root->childNodes as $child) {
            self::stampBlocks($child, $ids);
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    /** Text only, for search, summaries and reading time. */
    public static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags(preg_replace('~<(br|/p|/h[2-4]|/li|/tr|/blockquote)[^>]*>~i', "\n", $html) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n{3,}/", "\n\n", $text) ?? '') ?? '');
    }

    public static function readingMinutes(string $html): int
    {
        $words = count(preg_split('/\s+/u', self::plain($html), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $images = substr_count(strtolower($html), '<img');
        return max(1, (int) ceil($words / 180 + $images * 0.2));
    }

    /** The h2/h3 headings, for a table of contents: [[level, text, id]]. */
    public static function headings(string $html): array
    {
        preg_match_all('~<h([23])[^>]*data-b="([a-z0-9]+)"[^>]*>(.*?)</h\1>~isu', $html, $m, PREG_SET_ORDER);
        return array_map(static fn (array $h): array => [(int) $h[1], trim(html_entity_decode(strip_tags($h[3]), ENT_QUOTES, 'UTF-8')), $h[2]], $m);
    }

    private static function walk(\DOMNode $node, string $imagePrefix): void
    {
        // Copy first: the list changes as children are removed or unwrapped.
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            if (!isset(self::TAGS[$tag])) {
                self::walk($child, $imagePrefix);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            self::attributes($child, $tag, $imagePrefix);
            if ($tag === 'img' && !$child->hasAttribute('src')) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child, $imagePrefix);
        }
    }

    private static function attributes(\DOMElement $el, string $tag, string $imagePrefix): void
    {
        $keep = [];
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name  = strtolower($attr->name);
            $value = trim($attr->value);
            if ($name === 'style') {
                $style = self::style($value);
                if ($style !== '') {
                    $keep['style'] = $style;
                }
            } elseif ($name === 'class') {
                $classes = array_filter(preg_split('/\s+/', $value) ?: [], static fn ($c) => preg_match(self::CLASSES, $c) === 1);
                if ($classes !== []) {
                    $keep['class'] = implode(' ', array_slice(array_unique($classes), 0, 4));
                }
            } elseif ($name === 'data-b' && preg_match('/^[a-z0-9]{3,12}$/', $value) === 1) {
                $keep['data-b'] = $value;
            } elseif (in_array($name, self::TAGS[$tag], true)) {
                $clean = self::attribute($tag, $name, $value, $imagePrefix);
                if ($clean !== null) {
                    $keep[$name] = $clean;
                }
            }
        }
        while ($el->attributes->length) {
            $el->removeAttribute($el->attributes->item(0)->name);
        }
        foreach ($keep as $k => $v) {
            $el->setAttribute($k, $v);
        }
        if ($tag === 'a' && isset($keep['href'])) {
            if (!str_starts_with($keep['href'], '/')) {
                $el->setAttribute('target', '_blank');
            }
            $el->setAttribute('rel', 'noopener noreferrer');
        }
        if ($tag === 'img') {
            $el->setAttribute('loading', 'lazy');
        }
    }

    private static function attribute(string $tag, string $name, string $value, string $imagePrefix): ?string
    {
        switch ($name) {
            case 'href':
                if (preg_match('~^(https?://|mailto:)[^\s"<>]+$~i', $value) === 1) {
                    return $value;
                }
                return preg_match('~^/(student|media)/[A-Za-z0-9/_\-?=&%.#]*$~', $value) === 1 ? $value : null;
            case 'src':
                $prefix = preg_quote($imagePrefix, '~');
                return preg_match('~^' . $prefix . '[0-9]{8}-[a-f0-9]{16,40}\.(jpg|png|webp|gif)$~', $value) === 1 ? $value : null;
            case 'alt':
                return mb_substr($value, 0, 200);
            case 'width':
            case 'height':
            case 'colspan':
            case 'rowspan':
                return ctype_digit($value) && (int) $value > 0 && (int) $value <= 4000 ? $value : null;
        }
        return null;
    }

    private static function style(string $style): string
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (!str_contains($decl, ':')) {
                continue;
            }
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            $val  = strtolower($val);
            if (($prop === 'color' || $prop === 'background-color' || $prop === 'background') && self::isColor($val)) {
                $out[$prop === 'background' ? 'background-color' : $prop] = $val;
            } elseif ($prop === 'text-align' && in_array($val, ['right', 'left', 'center', 'justify', 'start', 'end'], true)) {
                $out['text-align'] = $val;
            } elseif ($prop === 'font-size' && preg_match('/^(0\.[6-9]\d*|1(\.\d+)?|2(\.[0-5]\d*)?)em$|^(1[0-9]|2[0-9]|3[0-6])px$/', $val) === 1) {
                $out['font-size'] = $val;
            } elseif ($prop === 'font-weight' && in_array($val, ['bold', '700', '800', 'normal', '400'], true)) {
                $out['font-weight'] = $val;
            } elseif ($prop === 'text-decoration' && in_array($val, ['underline', 'line-through', 'none'], true)) {
                $out['text-decoration'] = $val;
            }
        }
        $parts = [];
        foreach ($out as $k => $v) {
            $parts[] = $k . ': ' . $v;
        }
        return implode('; ', $parts);
    }

    private static function isColor(string $v): bool
    {
        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/', $v) === 1
            || preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $v) === 1
            || in_array($v, self::NAMED_COLORS, true);
    }

    /** Gives every block without an id one, unique within the document. */
    private static function stampBlocks(\DOMNode $node, array &$ids): void
    {
        if ($node instanceof \DOMElement) {
            if (in_array(strtolower($node->tagName), self::BLOCKS, true)) {
                $id = $node->getAttribute('data-b');
                if ($id === '' || isset($ids[$id])) {
                    do {
                        $id = substr(bin2hex(random_bytes(4)), 0, 6);
                    } while (isset($ids[$id]));
                    $node->setAttribute('data-b', $id);
                }
                $ids[$id] = true;
            }
            foreach ($node->childNodes as $c) {
                self::stampBlocks($c, $ids);
            }
        }
    }
}
