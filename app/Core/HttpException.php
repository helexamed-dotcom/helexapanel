<?php
declare(strict_types=1);

namespace HeleXa\Core;

/**
 * Thrown to abort a request with a specific status code.
 * The message is safe to show; details go to the log, never to the browser.
 */
final class HttpException extends \RuntimeException
{
    public function __construct(private int $statusCode, string $message = '')
    {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public static function forbidden(string $message = 'دسترسی مجاز نیست.'): self
    {
        return new self(403, $message);
    }

    public static function notFound(string $message = 'صفحه مورد نظر یافت نشد.'): self
    {
        return new self(404, $message);
    }

    public static function tooManyRequests(string $message = 'تعداد درخواست‌ها بیش از حد مجاز است.'): self
    {
        return new self(429, $message);
    }

    public static function unauthorized(string $message = 'ابتدا وارد حساب کاربری شوید.'): self
    {
        return new self(401, $message);
    }
}
