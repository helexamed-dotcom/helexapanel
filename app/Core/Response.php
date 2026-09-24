<?php
declare(strict_types=1);

namespace HeleXa\Core;

final class Response
{
    private string $body = '';
    private int $status = 200;
    private array $headers = [];
    /** @var (callable():void)|null writes the body itself, for large files */
    private $writer = null;

    /**
     * A response whose body is written by a callback when it is sent, so a
     * large file is copied to the client in chunks instead of being held in
     * memory as a string.
     */
    public static function streamed(callable $writer, int $status = 200, array $headers = []): self
    {
        $response = self::make('', $status, $headers);
        $response->writer = $writer;
        return $response;
    }

    public static function make(string $body = '', int $status = 200, array $headers = []): self
    {
        $response = new self();
        $response->body    = $body;
        $response->status  = $status;
        $response->headers = $headers;
        return $response;
    }

    public static function html(string $body, int $status = 200): self
    {
        return self::make($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(array $data, int $status = 200): self
    {
        return self::make(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function redirect(string $to, int $status = 302): self
    {
        // Only internal redirects: an open redirect is a phishing primitive.
        if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = '/';
        }
        return self::make('', $status, ['Location' => $to]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        if ($this->writer !== null) {
            ($this->writer)();
            return;
        }
        echo $this->body;
    }
}
