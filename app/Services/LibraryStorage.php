<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Response;
use HeleXa\Core\Str;

/**
 * Files behind the content library: covers, images, videos, documents.
 *
 * Private storage, served only through a controller that has checked the
 * viewer — the same rule as lesson files. Every upload is identified by its
 * bytes, never by its name or the browser's Content-Type, and the stored name
 * is generated.
 *
 * Videos are streamed with HTTP Range support. Without it a browser cannot
 * seek, and on a phone it would download the whole file before playing.
 */
final class LibraryStorage
{
    private const IMAGE_TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG  => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
        IMAGETYPE_GIF  => ['gif', 'image/gif'],
    ];

    public static function directory(): string
    {
        return PRIVATE_PATH . '/uploads/library';
    }

    public static function maxBytes(): int
    {
        return max(1, min(Settings::int('library_max_mb', 200), 2048)) * 1024 * 1024;
    }

    /**
     * @param  array{tmp_name:string,size:int,error:int,name?:string} $file
     * @param  string $kind image | video | file
     * @return array{path:string, mime:string, size:int, name:string}
     */
    public static function store(array $file, string $kind): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('حجم فایل از سقف سرور (' . ini_get('upload_max_filesize') . ') بیشتر است. '
                . 'برای ویدیوهای بزرگ از نوع «لینک» استفاده کنید.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('فایل دریافت نشد.');
        }
        if ((int) $file['size'] > self::maxBytes()) {
            throw new \RuntimeException('حجم فایل بیش از سقف تعیین‌شده برای کتابخانه است.');
        }

        $tmp = (string) $file['tmp_name'];
        [$ext, $mime] = match ($kind) {
            'image' => self::sniffImage($tmp),
            'video' => self::sniffVideo($tmp),
            'file'  => self::sniffDocument($tmp, (string) ($file['name'] ?? '')),
            default => throw new \InvalidArgumentException('Unknown kind.'),
        };

        $dir = self::directory();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('ساخت پوشه کتابخانه ناموفق بود.');
        }

        $name = date('Ymd') . '-' . Str::token(12) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('ذخیره فایل ناموفق بود.');
        }
        @chmod($dir . '/' . $name, 0640);

        // The original name is kept only as a display label for downloads,
        // stripped to something that cannot break a header.
        $original = preg_replace('/[^\p{L}\p{N}._ -]/u', '', basename((string) ($file['name'] ?? ''))) ?: $name;

        return ['path' => $name, 'mime' => $mime, 'size' => (int) $file['size'], 'name' => mb_substr($original, 0, 180)];
    }

    /** @return array{path:string}|null */
    public static function resolve(?string $name): ?array
    {
        if ($name === null || $name === '' || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || str_contains($name, '..')) {
            return null;
        }
        $root = realpath(self::directory());
        $real = realpath(self::directory() . '/' . $name);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }
        return ['path' => $real];
    }

    public static function forget(?string $name): void
    {
        $resolved = self::resolve($name);
        if ($resolved !== null) {
            @unlink($resolved['path']);
        }
    }

    /**
     * Streams a stored file, honouring a single-range Range header.
     *
     * Read in chunks rather than file_get_contents: a 200 MB lecture must not
     * be loaded into PHP's memory to be sent.
     */
    public static function stream(string $name, string $mime, string $disposition, ?string $downloadName, ?string $rangeHeader): Response
    {
        $resolved = self::resolve($name);
        if ($resolved === null) {
            return Response::make('Not found', 404);
        }

        return self::streamPath($resolved['path'], $mime, $disposition, $downloadName, $rangeHeader);
    }

    /** The same streaming for a file some other storage has already resolved. */
    public static function streamPath(string $path, string $mime, string $disposition, ?string $downloadName, ?string $rangeHeader, array $extraHeaders = []): Response
    {
        $size  = (int) filesize($path);
        $start = 0;
        $end   = $size - 1;
        $status = 200;

        if ($rangeHeader !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $m) === 1) {
            if ($m[1] === '' && $m[2] !== '') {           // last N bytes
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                $end   = $m[2] !== '' ? min((int) $m[2], $size - 1) : $end;
            }
            if ($start > $end || $start >= $size) {
                return Response::make('', 416, ['Content-Range' => 'bytes */' . $size]);
            }
            $status = 206;
        }

        $headers = [
            'Content-Type'           => $mime,
            'Content-Length'         => (string) ($end - $start + 1),
            'Accept-Ranges'          => 'bytes',
            'Cache-Control'          => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => $disposition
                . ($downloadName !== null ? "; filename*=UTF-8''" . rawurlencode($downloadName) : ''),
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }
        // A PDF opened in its own tab is rendered by the browser's viewer,
        // which the panel's strict policy (object-src 'none') would block.
        if ($mime === 'application/pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; object-src 'self'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'self'";
        }
        $headers = $extraHeaders + $headers;

        return Response::streamed(static function () use ($path, $start, $end): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return;
            }
            fseek($handle, $start);
            $left = $end - $start + 1;
            while ($left > 0 && !feof($handle) && connection_status() === CONNECTION_NORMAL) {
                $chunk = fread($handle, (int) min(1048576, $left));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                flush();
                $left -= strlen($chunk);
            }
            fclose($handle);
        }, $status, $headers);
    }

    /* ----------------------------------------------------------- sniffing */

    /** @return array{0:string,1:string} */
    private static function sniffImage(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false || !isset(self::IMAGE_TYPES[$info[2]])) {
            throw new \RuntimeException('فقط تصویر JPG، PNG، WEBP یا GIF پذیرفته می‌شود.');
        }
        return self::IMAGE_TYPES[$info[2]];
    }

    /** @return array{0:string,1:string} */
    private static function sniffVideo(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 16);
        if (substr($head, 4, 4) === 'ftyp') {
            return ['mp4', 'video/mp4'];
        }
        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
            return ['webm', 'video/webm'];
        }
        throw new \RuntimeException('فقط ویدیوی MP4 یا WEBM پذیرفته می‌شود.');
    }

    /** @return array{0:string,1:string} */
    private static function sniffDocument(string $path, string $name): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (str_starts_with($head, '%PDF-')) {
            return ['pdf', 'application/pdf'];
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            // Office files are zip archives; the extension picks which one,
            // but only among these three — the bytes already proved "zip".
            return match ($ext) {
                'docx'  => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                'pptx'  => ['pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
                'xlsx'  => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                default => ['zip', 'application/zip'],
            };
        }
        if (str_starts_with($head, 'ID3') || str_starts_with($head, "\xFF\xFB") || str_starts_with($head, "\xFF\xF3")) {
            return ['mp3', 'audio/mpeg'];
        }
        throw new \RuntimeException('فرمت فایل پشتیبانی نمی‌شود (PDF، Word، PowerPoint، Excel، ZIP یا MP3).');
    }
}
