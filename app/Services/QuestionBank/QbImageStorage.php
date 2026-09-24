<?php
declare(strict_types=1);

namespace HeleXa\Services\QuestionBank;

use HeleXa\Core\Str;
use HeleXa\Services\Settings;

/**
 * Images inside a question: the stem, any option, and the explanation.
 *
 * Stored under storage/private and served through a controller that checks the
 * session — never as a public asset. A question image usually *is* the
 * question (an ECG, a slide, a radiograph), so a guessable URL would hand the
 * bank out to anyone who can count.
 *
 * Two ways in, one way through:
 *
 *   - store()      a normal <input type="file"> upload
 *   - storeBlob()  raw bytes, for an image pasted from the clipboard
 *
 * A pasted screenshot arrives as bytes with no filename at all, so a storage
 * layer that only understands $_FILES cannot accept it. Both paths end at the
 * same validator, which reads the bytes with getimagesize() — the filename and
 * the browser's Content-Type are claims, and neither is consulted.
 */
final class QbImageStorage
{
    private const ALLOWED = [
        IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
        IMAGETYPE_PNG  => ['png',  'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
        IMAGETYPE_GIF  => ['gif',  'image/gif'],
    ];

    /** Beyond this, a browser is being asked to lay out a wall, not a figure. */
    private const MAX_DIMENSION = 5000;

    public static function directory(): string
    {
        return PRIVATE_PATH . '/uploads/qbank';
    }

    /** The admin's configured ceiling, in bytes, clamped to something sane. */
    public static function maxBytes(): int
    {
        $kb = (int) Settings::get('qbank_image_max_kb', 3072);
        $kb = max(256, min($kb, 20480));

        return $kb * 1024;
    }

    /**
     * Stores an ordinary file upload.
     *
     * @param  array{tmp_name:string,size:int,error:int} $file
     * @return string the generated filename, which is all the database stores
     */
    public static function store(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::describeUploadError($error));
        }

        $temporary = (string) ($file['tmp_name'] ?? '');
        if ($temporary === '' || !is_file($temporary)) {
            throw new \RuntimeException('فایل دریافت نشد.');
        }

        if ((int) ($file['size'] ?? 0) > self::maxBytes()) {
            throw new \RuntimeException(self::sizeMessage());
        }

        $extension = self::validate($temporary);

        return self::moveInto($temporary, $extension, true);
    }

    /**
     * Stores raw bytes — a clipboard paste, or a data: URL the editor produced.
     *
     * The bytes are written to a temporary file first and then validated by
     * the same function the upload path uses. Writing first costs one file
     * operation and means there is exactly one place that decides what a valid
     * image is, rather than a second copy of the rules for pasted content that
     * could drift from the first.
     */
    public static function storeBlob(string $bytes): string
    {
        if ($bytes === '') {
            throw new \RuntimeException('تصویری دریافت نشد.');
        }
        if (strlen($bytes) > self::maxBytes()) {
            throw new \RuntimeException(self::sizeMessage());
        }

        $temporary = tempnam(sys_get_temp_dir(), 'qbimg');
        if ($temporary === false) {
            throw new \RuntimeException('ذخیره موقت تصویر ناموفق بود.');
        }

        try {
            if (file_put_contents($temporary, $bytes) === false) {
                throw new \RuntimeException('ذخیره موقت تصویر ناموفق بود.');
            }

            $extension = self::validate($temporary);

            return self::moveInto($temporary, $extension, false);
        } finally {
            // moveInto() renames the file away on success, so this only fires
            // on the failure paths — but it must fire on all of them, or a
            // rejected paste leaves a stray file in the system temp directory.
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Decodes a `data:image/…;base64,…` URL into raw bytes.
     *
     * Returns null for anything that is not one, so a caller can tell "this
     * field held a pasted image" from "this field held a URL" without
     * parsing it twice.
     */
    public static function decodeDataUrl(string $value): ?string
    {
        if (!preg_match('~^data:image/[a-z.+-]+;base64,~i', $value)) {
            return null;
        }

        $comma   = strpos($value, ',');
        $encoded = $comma === false ? '' : substr($value, $comma + 1);

        // Strict mode: a data URL carrying anything outside the base64
        // alphabet is malformed, and decoding it leniently would produce
        // bytes nobody sent.
        $decoded = base64_decode($encoded, true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * Resolves a stored name back to a real file, refusing anything that tries
     * to leave the folder.
     *
     * The mime type comes from a fresh read of the stored bytes rather than
     * from the extension in the name — the same rule the bytes were admitted
     * under.
     *
     * @return array{path:string, mime:string}|null
     */
    public static function resolve(?string $name): ?array
    {
        if ($name === null || $name === ''
            || str_contains($name, '/') || str_contains($name, '\\')
            || str_contains($name, '..') || str_contains($name, "\0")) {
            return null;
        }

        $root = realpath(self::directory());
        $real = realpath(self::directory() . '/' . $name);

        if ($root === false || $real === false
            || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            || !is_file($real)) {
            return null;
        }

        $info = @getimagesize($real);
        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            return null;
        }

        return ['path' => $real, 'mime' => self::ALLOWED[$info[2]][1]];
    }

    /**
     * Removes a stored image.
     *
     * Goes through resolve(), so a name out of the database that has been
     * tampered with cannot delete a file elsewhere on disk. Failure is
     * deliberately silent: an image that is already gone is the state the
     * caller wanted.
     */
    public static function forget(?string $name): void
    {
        $resolved = self::resolve($name);
        if ($resolved !== null) {
            @unlink($resolved['path']);
        }
    }

    /* ---------------------------------------------------------- internals */

    /** @return string the extension the bytes earned */
    private static function validate(string $path): string
    {
        $info = @getimagesize($path);

        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            throw new \RuntimeException('فقط تصویر JPG، PNG، WEBP یا GIF پذیرفته می‌شود.');
        }

        if ((int) $info[0] > self::MAX_DIMENSION || (int) $info[1] > self::MAX_DIMENSION) {
            throw new \RuntimeException('ابعاد تصویر بیش از حد بزرگ است.');
        }

        return self::ALLOWED[$info[2]][0];
    }

    private static function moveInto(string $temporary, string $extension, bool $wasUpload): string
    {
        $directory = self::directory();

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('ساخت پوشه تصاویر ناموفق بود.');
        }

        $name        = date('Ymd') . '-' . Str::token(12) . '.' . $extension;
        $destination = $directory . '/' . $name;

        // move_uploaded_file() is the correct call for a real upload — it also
        // verifies the file genuinely came through PHP's upload handling — but
        // it refuses a file this process wrote itself, which is exactly the
        // pasted-blob case.
        $moved = $wasUpload
            ? move_uploaded_file($temporary, $destination)
            : rename($temporary, $destination);

        if (!$moved) {
            throw new \RuntimeException('ذخیره تصویر ناموفق بود.');
        }

        @chmod($destination, 0640);

        return $name;
    }

    private static function sizeMessage(): string
    {
        return 'حجم تصویر نباید بیشتر از ' . (int) (self::maxBytes() / 1024) . ' کیلوبایت باشد.';
    }

    private static function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم فایل از حد مجاز سرور بیشتر است.',
            UPLOAD_ERR_PARTIAL                        => 'آپلود ناقص ماند. دوباره تلاش کن.',
            UPLOAD_ERR_NO_FILE                        => 'فایلی انتخاب نشده بود.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'سرور نتوانست فایل را بنویسد.',
            default                                   => 'دریافت تصویر ناموفق بود.',
        };
    }
}
