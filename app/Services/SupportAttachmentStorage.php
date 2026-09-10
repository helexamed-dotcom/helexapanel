<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Str;

/**
 * Photos attached to a support ticket message.
 *
 * Kept separate from AvatarStorage on purpose: an avatar is "one file per
 * user, replaced each time"; a ticket attachment is "one more file, kept
 * forever alongside the message it belongs to". Same validation approach —
 * the bytes are checked with getimagesize(), never the filename or the
 * caller-supplied MIME type — but a different storage layout.
 *
 * Stored under storage/private, exactly like avatars: never web-reachable by
 * a guessed URL, only through a controller that checks the requester is
 * either the ticket's own student or an admin with manage_messages.
 */
final class SupportAttachmentStorage
{
    private const MAX_BYTES = 3 * 1024 * 1024;

    private const ALLOWED = [
        IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
        IMAGETYPE_PNG  => ['png',  'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    private function directory(): string
    {
        return PRIVATE_PATH . '/uploads/support';
    }

    /** @param array{tmp_name:string,size:int,error:int} $file */
    public function store(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('دریافت تصویر ناموفق بود.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('حجم تصویر نباید بیشتر از ۳ مگابایت باشد.');
        }

        $temporary = (string) $file['tmp_name'];
        $info      = @getimagesize($temporary);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            throw new \RuntimeException('فقط تصویر JPG، PNG یا WEBP پذیرفته می‌شود.');
        }
        if ((int) $info[0] > 4000 || (int) $info[1] > 4000) {
            throw new \RuntimeException('ابعاد تصویر بیش از حد بزرگ است.');
        }

        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('ساخت پوشه ضمیمه‌ها ناموفق بود.');
        }

        [$extension] = self::ALLOWED[$info[2]];
        $name        = date('Ymd') . '-' . Str::token(10) . '.' . $extension;
        $destination = $directory . '/' . $name;

        if (!move_uploaded_file($temporary, $destination) && !@rename($temporary, $destination)) {
            throw new \RuntimeException('ذخیره تصویر ناموفق بود.');
        }
        @chmod($destination, 0640);

        return $name;
    }

    /**
     * Resolves a stored name back to a real, readable image path, refusing
     * anything that tries to escape the folder. The mime type comes from a
     * fresh getimagesize() on the stored bytes, not the extension in the
     * name — consistent with how the bytes were validated on the way in.
     *
     * @return array{path:string, mime:string}|null
     */
    public function resolve(?string $name): ?array
    {
        if ($name === null || $name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..') || str_contains($name, "\0")) {
            return null;
        }

        $root = realpath($this->directory());
        $real = realpath($this->directory() . '/' . $name);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }

        $info = @getimagesize($real);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            return null;
        }

        return ['path' => $real, 'mime' => self::ALLOWED[$info[2]][1]];
    }
}
