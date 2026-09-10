<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Str;

/**
 * Admin-managed brand and course images: logo, default avatars, course icons.
 *
 * These differ from AvatarStorage in one important way: they are meant to be
 * displayed broadly across the site (topbar, login screen, course cards), so
 * they are stored directly under public_html/assets/images and served
 * straight by the web server rather than through a PHP controller. That is
 * fine for images that are not private, which these are not — a logo or a
 * course icon carries nothing that needs a session check to view.
 *
 * SVG is explicitly allowed, unlike AvatarStorage. An SVG file is a document
 * that can carry a <script>, so before anything is written to disk it is
 * parsed and stripped of scripts, event-handler attributes, and any
 * reference to an external resource. What is written back is a plain vector
 * image, nothing else.
 */
final class ImageAssetStorage
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    private const RASTER = [
        IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
        IMAGETYPE_PNG  => ['png',  'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    /** Elements an uploaded SVG is never allowed to contain. */
    private const SVG_FORBIDDEN_TAGS = [
        'script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'animate', 'set',
    ];

    public static function root(): string
    {
        return PUBLIC_PATH . '/assets/images';
    }

    private static function directoryFor(string $subdir): string
    {
        $subdir = trim($subdir, '/');
        if ($subdir !== '' && preg_match('/^[a-z0-9_-]+$/i', $subdir) !== 1) {
            throw new \InvalidArgumentException('نام پوشه نامعتبر است.');
        }
        return $subdir === '' ? self::root() : self::root() . '/' . $subdir;
    }

    /**
     * Validates and stores an uploaded image; returns the path stored on a
     * settings value or a courses.thumbnail_path column, relative to
     * public_html/assets/ (e.g. "images/logo-ab12cd34.png").
     */
    public static function storeUploaded(array $file, string $subdir = ''): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('آپلود فایل ناموفق بود.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('حجم فایل نباید بیشتر از ۲ مگابایت باشد.');
        }

        $temporary   = (string) $file['tmp_name'];
        $originalExt = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        $directory = self::directoryFor($subdir);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('ساخت پوشه تصاویر ناموفق بود.');
        }

        // SVG is text, not a bitmap, so it cannot be checked with getimagesize
        // the way the raster formats are. It is detected by extension and then
        // by content, and only accepted once every dangerous node is stripped.
        if ($originalExt === 'svg') {
            $raw = (string) file_get_contents($temporary);
            $clean = self::sanitizeSvg($raw);
            if ($clean === null) {
                throw new \RuntimeException('فایل SVG نامعتبر یا ناایمن است.');
            }

            $name = Str::token(10) . '.svg';
            $path = $directory . '/' . $name;
            if (file_put_contents($path, $clean) === false) {
                throw new \RuntimeException('ذخیره تصویر ناموفق بود.');
            }
            @chmod($path, 0644);

            return self::relative($path);
        }

        // Raster formats: the real test is whether the bytes parse as an
        // image, never the filename or the browser-supplied MIME type.
        $info = @getimagesize($temporary);
        if ($info === false || !isset(self::RASTER[$info[2]])) {
            throw new \RuntimeException('فقط تصویر JPG، PNG، WEBP یا SVG پذیرفته می‌شود.');
        }

        [$extension] = self::RASTER[$info[2]];
        $name = Str::token(10) . '.' . $extension;
        $path = $directory . '/' . $name;

        if (!move_uploaded_file($temporary, $path) && !@rename($temporary, $path)) {
            throw new \RuntimeException('ذخیره تصویر ناموفق بود.');
        }
        @chmod($path, 0644);

        return self::relative($path);
    }

    /**
     * Lists image files already present in a folder — including ones an
     * admin dropped in over FTP — so they can be picked without re-uploading.
     *
     * @return array<int, array{path:string, name:string}>
     */
    public static function listExisting(string $subdir = ''): array
    {
        $directory = self::directoryFor($subdir);
        if (!is_dir($directory)) {
            return [];
        }

        $out = [];
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === '.htaccess' || $name === 'README.md') {
                continue;
            }
            $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
                continue;
            }
            $full = $directory . '/' . $name;
            if (is_file($full)) {
                $out[] = ['path' => self::relative($full), 'name' => $name];
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    /** Deletes a previously stored image, scoped strictly under assets/images. */
    public static function delete(?string $relativePath): void
    {
        $resolved = self::resolve($relativePath);
        if ($resolved !== null) {
            @unlink($resolved);
        }
    }

    /** Resolves a stored relative path to a real filesystem path, or null if it escapes the folder. */
    private static function resolve(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }
        // Stored values look like "images/xyz.png"; the root here is assets/.
        $root = realpath(PUBLIC_PATH . '/assets');
        $real = realpath(PUBLIC_PATH . '/assets/' . $relativePath);

        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return is_file($real) ? $real : null;
    }

    private static function relative(string $absolutePath): string
    {
        $assetsRoot = PUBLIC_PATH . '/assets/';
        return ltrim(str_replace($assetsRoot, '', $absolutePath), '/');
    }

    /**
     * Parses the SVG and removes anything that is not a static drawing
     * instruction. Returns null when the file cannot be parsed at all, which
     * is treated the same as "unsafe": a logo does not need to be recovered
     * from broken markup.
     */
    private static function sanitizeSvg(string $raw): ?string
    {
        if (mb_strlen($raw) > 512000 || stripos($raw, '<?php') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        // LIBXML_NONET blocks network access during parsing; libxml (since
        // 2.9, which is what every current PHP ships) already refuses to
        // resolve external entities by default, so no separate flag for that
        // is needed.
        $loaded = $document->loadXML($raw, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null) {
            return null;
        }
        if (strtolower($document->documentElement->localName ?? '') !== 'svg') {
            return null;
        }

        self::stripDangerousNodes($document);

        $clean = $document->saveXML($document->documentElement);
        return is_string($clean) && $clean !== '' ? $clean : null;
    }

    /** Lower-cases an element's local-name() for a case-insensitive XPath match. */
    private const XPATH_LOWER =
        "translate(local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";

    private static function stripDangerousNodes(\DOMDocument $document): void
    {
        $xpath = new \DOMXPath($document);

        // Whole elements that have no business in a static logo/icon.
        // SVG tag names are case-sensitive in the document (foreignObject has
        // a capital O), so the comparison is done on a lower-cased copy of
        // local-name() rather than assuming a fixed case.
        foreach (self::SVG_FORBIDDEN_TAGS as $tag) {
            $query = '//*[' . self::XPATH_LOWER . '="' . $tag . '"]';
            foreach (iterator_to_array($xpath->query($query) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Every attribute is inspected on every remaining element: drop any
        // event handler (onload, onclick, ...) and any reference that points
        // off the document (http(s), javascript:, data:text/html).
        foreach (iterator_to_array($xpath->query('//@*') ?: []) as $attribute) {
            $name  = strtolower($attribute->nodeName);
            $value = trim((string) $attribute->nodeValue);

            $isHandler   = str_starts_with($name, 'on');
            $isReference = in_array($name, ['href', 'xlink:href', 'src'], true);
            $isUnsafeRef = $isReference && !str_starts_with($value, '#')
                && (preg_match('/^\s*(https?:|javascript:|data:text\/html)/i', $value) === 1
                    || !str_starts_with($value, 'data:image/'));

            if ($isHandler || $isUnsafeRef) {
                $attribute->ownerElement?->removeAttributeNode($attribute);
            }
        }

        // <style> blocks can carry expression()/url(javascript:) in old
        // engines and are of no value in a small static icon either way.
        foreach (iterator_to_array($xpath->query('//*[' . self::XPATH_LOWER . '="style"]') ?: []) as $style) {
            $style->parentNode?->removeChild($style);
        }
    }
}
