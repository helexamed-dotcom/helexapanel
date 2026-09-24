<?php
declare(strict_types=1);

namespace HeleXa\Services;

use HeleXa\Core\Str;

/**
 * Images for the newer modules — درسنامه، نقشه ذهنی، بازی با شکل، فروشگاه،
 * پست‌های پروفایل، رسید کارت به کارت — one folder each under
 * storage/private/uploads, served only through controllers that check who
 * is asking.
 *
 * The same rules as the question bank's images: the bytes decide what the
 * file is (getimagesize), never its name or the browser's claim; names are
 * generated; a stored name that tries to leave its folder resolves to
 * nothing.
 */
final class MediaStore
{
    public const FOLDERS = ['lessons', 'mindmaps', 'figures', 'shop', 'posts', 'receipts'];

    private const ALLOWED = [
        IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
        IMAGETYPE_PNG  => ['png',  'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
        IMAGETYPE_GIF  => ['gif',  'image/gif'],
    ];
    private const MAX_DIMENSION = 6000;

    public function __construct(private string $folder)
    {
        if (!in_array($folder, self::FOLDERS, true)) {
            throw new \InvalidArgumentException('Unknown media folder.');
        }
    }

    public function directory(): string
    {
        return PRIVATE_PATH . '/uploads/' . $this->folder;
    }

    public function maxBytes(): int
    {
        return max(256, min(Settings::int('media_max_kb', 6144), 20480)) * 1024;
    }

    /** @param array{tmp_name:string,size:int,error:int} $file */
    public function store(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? 'حجم تصویر بیش از حد مجاز است.' : 'فایل دریافت نشد.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new \RuntimeException('فایل دریافت نشد.');
        }
        if ((int) ($file['size'] ?? 0) > $this->maxBytes()) {
            throw new \RuntimeException('حجم تصویر بیش از حد مجاز است.');
        }
        return $this->moveInto($tmp, $this->validate($tmp), is_uploaded_file($tmp));
    }

    /** Raw bytes: a pasted image or a data: URL from an editor. */
    public function storeBlob(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > $this->maxBytes()) {
            throw new \RuntimeException($bytes === '' ? 'تصویری دریافت نشد.' : 'حجم تصویر بیش از حد مجاز است.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'hxm');
        if ($tmp === false) {
            throw new \RuntimeException('ذخیره موقت تصویر ناموفق بود.');
        }
        try {
            file_put_contents($tmp, $bytes);
            return $this->moveInto($tmp, $this->validate($tmp), false);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    public static function decodeDataUrl(string $value): ?string
    {
        if (!preg_match('~^data:image/[a-z.+-]+;base64,~i', $value)) {
            return null;
        }
        $decoded = base64_decode(substr($value, (int) strpos($value, ',') + 1), true);
        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /** @return array{path:string, mime:string}|null */
    public function resolve(?string $name): ?array
    {
        if ($name === null || preg_match('/^[0-9]{8}-[A-Za-z0-9]{16,40}\.(jpg|png|webp|gif)$/', $name) !== 1) {
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

    /** @return array{0:int,1:int}|null width, height */
    public function dimensions(string $name): ?array
    {
        $r = $this->resolve($name);
        $info = $r === null ? false : @getimagesize($r['path']);
        return $info === false ? null : [(int) $info[0], (int) $info[1]];
    }

    public function forget(?string $name): void
    {
        $r = $this->resolve($name);
        if ($r !== null) {
            @unlink($r['path']);
        }
    }

    /** A private-cache image response, or null when there is no such file. */
    public function response(?string $name): ?\HeleXa\Core\Response
    {
        $r = $this->resolve($name);
        if ($r === null) {
            return null;
        }
        $bytes = (string) file_get_contents($r['path']);
        return \HeleXa\Core\Response::make($bytes, 200, [
            'Content-Type'           => $r['mime'],
            'Content-Length'         => (string) strlen($bytes),
            'Cache-Control'          => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline',
        ]);
    }

    private function validate(string $path): string
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

    private function moveInto(string $tmp, string $ext, bool $wasUpload): string
    {
        $dir = $this->directory();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('ساخت پوشه تصاویر ناموفق بود.');
        }
        $name = date('Ymd') . '-' . Str::token(10) . '.' . $ext;
        $ok = $wasUpload ? move_uploaded_file($tmp, $dir . '/' . $name) : rename($tmp, $dir . '/' . $name);
        if (!$ok) {
            throw new \RuntimeException('ذخیره تصویر ناموفق بود.');
        }
        @chmod($dir . '/' . $name, 0640);
        return $name;
    }
}
