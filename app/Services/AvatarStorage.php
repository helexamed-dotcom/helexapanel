<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Str;

/**
 * Profile photos.
 *
 * Avatars live outside the web root like every other upload and are served by
 * a controller. Only real raster images are accepted: SVG is refused outright
 * because an SVG is a document that can carry script, and it would run on our
 * own origin the moment a browser rendered it.
 */
final class AvatarStorage
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    private const ALLOWED = [
        IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
        IMAGETYPE_PNG  => ['png',  'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    public function directory(): string
    {
        return PRIVATE_PATH . '/uploads/avatars';
    }

    /** @return string relative path to store on the user row */
    public function store(array $file, int $userId): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('آپلود تصویر ناموفق بود.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('حجم تصویر نباید بیشتر از ۲ مگابایت باشد.');
        }

        $temporary = (string) $file['tmp_name'];

        // The real test is whether the bytes parse as an image, not what the
        // filename or the browser-supplied MIME type claims.
        $info = @getimagesize($temporary);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            throw new \RuntimeException('فقط تصویر JPG، PNG یا WEBP پذیرفته می‌شود.');
        }
        if ((int) $info[0] > 4000 || (int) $info[1] > 4000) {
            throw new \RuntimeException('ابعاد تصویر بیش از حد بزرگ است.');
        }

        [$extension] = self::ALLOWED[$info[2]];

        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('ساخت پوشه تصاویر ناموفق بود.');
        }

        $name        = $userId . '-' . Str::token(8) . '.' . $extension;
        $destination = $directory . '/' . $name;

        if (!move_uploaded_file($temporary, $destination) && !rename($temporary, $destination)) {
            throw new \RuntimeException('ذخیره تصویر ناموفق بود.');
        }
        @chmod($destination, 0640);

        return 'avatars/' . $name;
    }

    /** @return array{path:string, mime:string}|null */
    public function resolve(?string $relativePath): ?array
    {
        if ($relativePath === null || $relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }

        $root = realpath(PRIVATE_PATH . '/uploads');
        $real = realpath(PRIVATE_PATH . '/uploads/' . $relativePath);

        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }

        $info = @getimagesize($real);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            return null;
        }

        return ['path' => $real, 'mime' => self::ALLOWED[$info[2]][1]];
    }

    public function delete(?string $relativePath): void
    {
        $resolved = $this->resolve($relativePath);
        if ($resolved !== null) {
            @unlink($resolved['path']);
        }
    }
}
