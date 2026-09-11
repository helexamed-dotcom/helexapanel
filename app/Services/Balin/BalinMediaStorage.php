<?php
declare(strict_types=1);

namespace HeleXa\Services\Balin;

use HeleXa\Core\Str;

/**
 * Uploads for the images, audio and video inside a clinical case.
 *
 * Stored under storage/private and served through a controller that checks
 * the session, not as a public asset. A clinical photograph is teaching
 * material about a patient presentation; it should not be reachable by
 * guessing a URL, which is the default for anything under public_html.
 *
 * Validation never trusts the filename or the browser's Content-Type. Images
 * are parsed; audio and video are checked against the magic bytes of the
 * formats actually allowed. The stored name is generated, so a hostile
 * filename cannot shape the path it is written to.
 *
 * Character icons and lesson covers are not handled here — they are public
 * by nature and go through ImageAssetStorage, which already sanitises SVG.
 */
final class BalinMediaStorage
{
    private const RASTER = [
        IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
        IMAGETYPE_PNG  => ['png',  'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
        IMAGETYPE_GIF  => ['gif',  'image/gif'],
    ];

    /**
     * Leading bytes that identify the containers we accept.
     * Checked against the file itself, because an extension is a claim and
     * a Content-Type header is a claim the browser makes on the user's behalf.
     */
    private const SIGNATURES = [
        'audio' => [
            ['mime' => 'audio/mpeg', 'ext' => 'mp3',  'offset' => 0, 'bytes' => "ID3"],
            ['mime' => 'audio/mpeg', 'ext' => 'mp3',  'offset' => 0, 'bytes' => "\xFF\xFB"],
            ['mime' => 'audio/mpeg', 'ext' => 'mp3',  'offset' => 0, 'bytes' => "\xFF\xF3"],
            ['mime' => 'audio/mpeg', 'ext' => 'mp3',  'offset' => 0, 'bytes' => "\xFF\xF2"],
            ['mime' => 'audio/wav',  'ext' => 'wav',  'offset' => 0, 'bytes' => 'RIFF'],
            ['mime' => 'audio/ogg',  'ext' => 'ogg',  'offset' => 0, 'bytes' => 'OggS'],
            ['mime' => 'audio/mp4',  'ext' => 'm4a',  'offset' => 4, 'bytes' => 'ftyp'],
        ],
        'video' => [
            ['mime' => 'video/mp4',  'ext' => 'mp4',  'offset' => 4, 'bytes' => 'ftyp'],
            ['mime' => 'video/webm', 'ext' => 'webm', 'offset' => 0, 'bytes' => "\x1A\x45\xDF\xA3"],
        ],
    ];

    public static function directory(): string
    {
        return PRIVATE_PATH . '/uploads/balin';
    }

    /**
     * Validates and stores one upload.
     *
     * @param array{tmp_name:string,size:int,error:int,name?:string} $file
     * @return array{storage_path:string, mime:string, byte_size:int, checksum:string, kind:string}
     */
    public static function store(array $file, string $kind): array
    {
        if (!in_array($kind, ['image', 'audio', 'video'], true)) {
            throw new \InvalidArgumentException('نوع رسانه پشتیبانی نمی‌شود.');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('دریافت فایل ناموفق بود.');
        }

        $size = (int) ($file['size'] ?? 0);
        $max  = BalinSettings::mediaMaxBytes($kind);
        if ($size <= 0 || $size > $max) {
            throw new \RuntimeException(sprintf(
                'حجم فایل نباید بیشتر از %d مگابایت باشد.',
                (int) ($max / 1024 / 1024)
            ));
        }

        $temporary = (string) $file['tmp_name'];
        if (!is_readable($temporary)) {
            throw new \RuntimeException('فایل آپلودشده قابل خواندن نیست.');
        }

        [$extension, $mime] = $kind === 'image'
            ? self::inspectImage($temporary)
            : self::inspectContainer($temporary, $kind);

        $directory = self::directory() . '/' . $kind;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('ساخت پوشه رسانه ناموفق بود.');
        }

        // The name is ours, never the uploader's.
        $name        = date('Ym') . '-' . Str::token(12) . '.' . $extension;
        $destination = $directory . '/' . $name;

        if (!move_uploaded_file($temporary, $destination) && !@rename($temporary, $destination)) {
            throw new \RuntimeException('ذخیره فایل ناموفق بود.');
        }
        @chmod($destination, 0640);

        return [
            'storage_path' => $kind . '/' . $name,
            'mime'         => $mime,
            'byte_size'    => $size,
            'checksum'     => (string) hash_file('sha256', $destination),
            'kind'         => $kind,
        ];
    }

    /**
     * Resolves a stored path back to a readable file, refusing anything that
     * climbs out of the media folder.
     *
     * @return array{path:string, mime:string}|null
     */
    public static function resolve(?string $storagePath, string $mime): ?array
    {
        if ($storagePath === null || $storagePath === '' || str_contains($storagePath, "\0")) {
            return null;
        }
        if (str_contains($storagePath, '..') || str_starts_with($storagePath, '/')) {
            return null;
        }

        $root = realpath(self::directory());
        $real = realpath(self::directory() . '/' . $storagePath);

        if ($root === false || $real === false) {
            return null;
        }
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }

        return ['path' => $real, 'mime' => $mime];
    }

    public static function delete(?string $storagePath): void
    {
        $resolved = self::resolve($storagePath, 'application/octet-stream');
        if ($resolved !== null) {
            @unlink($resolved['path']);
        }
    }

    /**
     * The real test for a bitmap is whether the bytes parse as one.
     *
     * @return array{0:string, 1:string} extension, mime
     */
    private static function inspectImage(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false || !isset(self::RASTER[$info[2]])) {
            throw new \RuntimeException('فقط تصویر JPG، PNG، WEBP یا GIF پذیرفته می‌شود.');
        }
        if ((int) $info[0] > 8000 || (int) $info[1] > 8000) {
            throw new \RuntimeException('ابعاد تصویر بیش از حد بزرگ است.');
        }

        return self::RASTER[$info[2]];
    }

    /**
     * Audio and video cannot be parsed the way an image can, so the check is
     * the container signature at the head of the file.
     *
     * @return array{0:string, 1:string} extension, mime
     */
    private static function inspectContainer(string $path, string $kind): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('خواندن فایل ناموفق بود.');
        }
        $head = (string) fread($handle, 32);
        fclose($handle);

        foreach (self::SIGNATURES[$kind] as $signature) {
            $slice = substr($head, $signature['offset'], strlen($signature['bytes']));
            if ($slice === $signature['bytes']) {
                return [$signature['ext'], $signature['mime']];
            }
        }

        throw new \RuntimeException($kind === 'audio'
            ? 'فقط فایل صوتی MP3، WAV، M4A یا OGG پذیرفته می‌شود.'
            : 'فقط ویدیوی MP4 یا WEBM پذیرفته می‌شود.');
    }
}
