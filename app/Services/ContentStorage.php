<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Config;
use HeleXa\Core\Logger;
use HeleXa\Core\Str;

/**
 * Stores uploaded lesson HTML outside the web root and reports what is inside it.
 *
 * The admin is trusted to author HTML, so the file is stored verbatim: sanitising
 * it would break the very layouts, fonts and scripts the lessons depend on.
 * What we do instead is (a) keep it unreachable except through an authorised
 * controller, and (b) tell the admin exactly what the file will try to load.
 */
final class ContentStorage
{
    private const ALLOWED_EXTENSIONS = ['html', 'htm'];

    /** Hosts a lesson may legitimately pull web fonts or styles from. */
    public const FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'cdnjs.cloudflare.com'];

    public function lessonRoot(): string
    {
        return PRIVATE_PATH . '/lessons';
    }

    /**
     * @param array $file entry from $_FILES
     * @return array{path:string, checksum:string, size:int, report:array, original:string}
     * @throws \RuntimeException on any validation failure
     */
    public function store(array $file, string $contentUuid): array
    {
        $this->assertValidUpload($file);

        $original  = (string) ($file['name'] ?? 'lesson.html');
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('فقط فایل با پسوند .html یا .htm پذیرفته می‌شود.');
        }

        $maxBytes = Settings::int('content_max_upload_mb', 25) * 1024 * 1024;
        $size     = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new \RuntimeException(sprintf('حجم فایل باید بین ۱ بایت تا %d مگابایت باشد.', (int) ($maxBytes / 1048576)));
        }

        $temporary = (string) $file['tmp_name'];
        $head      = (string) file_get_contents($temporary, false, null, 0, 65536);

        // A .html file containing PHP tags is either a mistake or an attack.
        // Nothing here is ever executed, but we refuse it rather than store it.
        if (str_contains($head, '<?php') || str_contains($head, '<?=')) {
            throw new \RuntimeException('فایل شامل کد PHP است و پذیرفته نمی‌شود.');
        }

        // The directory name is a random UUID, so the path cannot be guessed
        // even if the private folder were ever exposed by a server misconfiguration.
        $directory = $this->lessonRoot() . '/' . $contentUuid;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('ساخت پوشه ذخیره‌سازی ناموفق بود.');
        }

        $destination = $directory . '/index.html';
        if (!move_uploaded_file($temporary, $destination) && !rename($temporary, $destination)) {
            throw new \RuntimeException('ذخیره فایل ناموفق بود.');
        }
        @chmod($destination, 0640);

        $checksum = (string) hash_file('sha256', $destination);
        $report   = $this->scan($destination);

        Logger::info('Lesson stored', ['uuid' => $contentUuid, 'size' => $size]);

        return [
            'path'     => $contentUuid . '/index.html',   // relative; resolved against lessonRoot()
            'checksum' => $checksum,
            'size'     => (int) filesize($destination),
            'report'   => $report,
            'original' => mb_substr($original, 0, 191),
        ];
    }

    /**
     * Stores HTML pasted straight into the admin panel.
     * Goes through the same validation, storage and scanning path as an
     * uploaded file, so there is only ever one way content reaches a student.
     *
     * @return array{path:string, checksum:string, size:int, report:array, original:string}
     */
    public function storeFromString(string $html, string $contentUuid, string $label = 'inline.html'): array
    {
        $html = trim($html);
        if ($html === '') {
            throw new \RuntimeException('کدی وارد نشده است.');
        }

        $maxBytes = Settings::int('content_max_upload_mb', 25) * 1024 * 1024;
        if (strlen($html) > $maxBytes) {
            throw new \RuntimeException(sprintf('حجم کد از %d مگابایت بیشتر است.', (int) ($maxBytes / 1048576)));
        }
        if (str_contains($html, '<?php') || str_contains($html, '<?=')) {
            throw new \RuntimeException('کد شامل تگ PHP است و پذیرفته نمی‌شود.');
        }

        $directory = $this->lessonRoot() . '/' . $contentUuid;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('ساخت پوشه ذخیره‌سازی ناموفق بود.');
        }

        $destination = $directory . '/index.html';
        if (file_put_contents($destination, $html, LOCK_EX) === false) {
            throw new \RuntimeException('ذخیره کد ناموفق بود.');
        }
        @chmod($destination, 0640);

        return [
            'path'     => $contentUuid . '/index.html',
            'checksum' => (string) hash_file('sha256', $destination),
            'size'     => (int) filesize($destination),
            'report'   => $this->scan($destination) + ['source' => 'paste'],
            'original' => mb_substr($label, 0, 191),
        ];
    }

    /**
     * Reads a stored lesson back so the admin can edit it in the panel.
     * Returns null when the file is larger than the editor should handle;
     * a multi-megabyte textarea would freeze the browser.
     */
    public function read(string $relativePath, int $maxBytes = 1048576): ?string
    {
        $absolute = $this->resolve($relativePath);
        if (filesize($absolute) > $maxBytes) {
            return null;
        }
        $contents = file_get_contents($absolute);
        return $contents === false ? null : $contents;
    }

    /**
     * Resolves a stored relative path to an absolute one, refusing anything
     * that escapes the lessons directory.
     */
    public function resolve(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            throw new \RuntimeException('INVALID_PATH');
        }

        $root = realpath($this->lessonRoot());
        $real = realpath($this->lessonRoot() . '/' . $relativePath);

        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            throw new \RuntimeException('CONTENT_FILE_MISSING');
        }

        return $real;
    }

    public function delete(string $contentUuid): void
    {
        $directory = $this->lessonRoot() . '/' . $contentUuid;
        $real      = realpath($directory);
        $root      = realpath($this->lessonRoot());

        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return;
        }
        foreach ((array) glob($real . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($real);
    }

    /**
     * Inspects the lesson and reports anything that affects how it must be served:
     * external hosts, browser storage use, inline asset weight.
     */
    public function scan(string $absolutePath): array
    {
        $html = (string) file_get_contents($absolutePath);

        $hosts = [];
        if (preg_match_all('#\b(?:src|href)\s*=\s*["\']https?://([^/"\'\s]+)#i', $html, $matches) > 0) {
            $hosts = array_values(array_unique(array_map('strtolower', $matches[1])));
        }
        if (preg_match_all('#url\(\s*["\']?https?://([^/"\'\)\s]+)#i', $html, $cssMatches) > 0) {
            $hosts = array_values(array_unique(array_merge($hosts, array_map('strtolower', $cssMatches[1]))));
        }

        $dataImages = preg_match_all('#data:image/[a-z+]+;base64#i', $html);
        $dataFonts  = preg_match_all('#data:font/[a-z0-9]+;base64#i', $html);

        $unknownHosts = array_values(array_diff($hosts, self::FONT_HOSTS));

        return [
            'bytes'           => strlen($html),
            'external_hosts'  => $hosts,
            'unknown_hosts'   => $unknownHosts,
            'uses_storage'    => (bool) preg_match('#\b(localStorage|sessionStorage|indexedDB)\b#', $html),
            'uses_cookie'     => str_contains($html, 'document.cookie'),
            'uses_network'    => (bool) preg_match('#\b(fetch\(|XMLHttpRequest|EventSource|WebSocket)\b#', $html),
            'touches_parent'  => (bool) preg_match('#\bwindow\.(parent|top|opener)\b#', $html),
            'has_iframe'      => str_contains($html, '<iframe'),
            'script_blocks'   => preg_match_all('#<script\b#i', $html),
            'style_blocks'    => preg_match_all('#<style\b#i', $html),
            'inline_images'   => $dataImages,
            'inline_fonts'    => $dataFonts,
            'has_head'        => (bool) preg_match('#<head[^>]*>#i', $html),
            'scanned_at'      => date('c'),
        ];
    }

    private function assertValidUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => 'حجم فایل از حد مجاز سرور بیشتر است (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE  => 'حجم فایل از حد مجاز فرم بیشتر است.',
                UPLOAD_ERR_PARTIAL    => 'آپلود ناقص انجام شد. دوباره تلاش کنید.',
                UPLOAD_ERR_NO_FILE    => 'فایلی انتخاب نشده است.',
                UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور در دسترس نیست.',
                UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل روی دیسک ناموفق بود.',
            ];
            throw new \RuntimeException($messages[$error] ?? 'آپلود فایل ناموفق بود.');
        }

        if (!is_uploaded_file((string) ($file['tmp_name'] ?? '')) && !is_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \RuntimeException('فایل آپلودشده معتبر نیست.');
        }
    }
}
