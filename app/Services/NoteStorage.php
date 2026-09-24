<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Response;
use HeleXa\Core\Str;

/**
 * Files attached to student notes: PDFs and images.
 *
 * Private storage, served only to the note's owner. The type is read from the
 * bytes; the stored name is generated.
 */
final class NoteStorage
{
    private const IMAGE_TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG  => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    public static function directory(): string
    {
        return PRIVATE_PATH . '/uploads/notes';
    }

    public static function maxBytes(): int
    {
        return max(1, min(Settings::int('notes_file_max_mb', 25), 200)) * 1024 * 1024;
    }

    /** @return array{path:string, mime:string, size:int, name:string} */
    public static function store(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('حجم فایل از سقف سرور (' . ini_get('upload_max_filesize') . ') بیشتر است.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('فایل دریافت نشد.');
        }
        if ((int) $file['size'] > self::maxBytes()) {
            throw new \RuntimeException('حجم فایل بیشتر از ' . fa((string) (self::maxBytes() / 1048576)) . ' مگابایت است.');
        }

        $tmp  = (string) $file['tmp_name'];
        $head = (string) file_get_contents($tmp, false, null, 0, 8);
        if (str_starts_with($head, '%PDF-')) {
            [$ext, $mime] = ['pdf', 'application/pdf'];
        } else {
            $info = @getimagesize($tmp);
            if ($info === false || !isset(self::IMAGE_TYPES[$info[2]])) {
                throw new \RuntimeException('فقط PDF یا تصویر (JPG، PNG، WEBP) پذیرفته می‌شود.');
            }
            [$ext, $mime] = self::IMAGE_TYPES[$info[2]];
        }

        $dir = self::directory();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('ساخت پوشه یادداشت‌ها ناموفق بود.');
        }
        $name = date('Ymd') . '-' . Str::token(12) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('ذخیره فایل ناموفق بود.');
        }
        @chmod($dir . '/' . $name, 0640);

        $original = preg_replace('/[^\p{L}\p{N}._ ()-]/u', '', basename((string) ($file['name'] ?? ''))) ?: $name;

        return ['path' => $name, 'mime' => $mime, 'size' => (int) $file['size'], 'name' => mb_substr($original, 0, 180)];
    }

    public static function resolve(?string $name): ?string
    {
        if ($name === null || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || str_contains($name, '..')) {
            return null;
        }
        $root = realpath(self::directory());
        $real = realpath(self::directory() . '/' . $name);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }
        return $real;
    }

    public static function forget(?string $name): void
    {
        $path = self::resolve($name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    public static function stream(array $file, ?string $range, bool $download): Response
    {
        $path = self::resolve((string) $file['file_path']);
        if ($path === null) {
            return Response::make('Not found', 404);
        }

        return LibraryStorage::streamPath(
            $path,
            (string) $file['file_mime'],
            $download ? 'attachment' : 'inline',
            (string) $file['file_name'],
            $range,
            ['Cache-Control' => 'private, no-store']
        );
    }
}