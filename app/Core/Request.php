<?php
declare(strict_types=1);

namespace HeleXa\Core;

/**
 * Read-only view of the incoming request. Everything here is UNTRUSTED.
 */
final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $files;

    public function __construct(array $query, array $body, array $server, array $files = [])
    {
        $this->query  = $query;
        $this->body   = $body;
        $this->server = $server;
        $this->files  = $files;
    }

    public static function capture(): self
    {
        $body = $_POST;
        // Accept JSON bodies for the internal API.
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains(strtolower($type), 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }
        return new self($_GET, $body, $_SERVER, $_FILES);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $uri  = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $path = '/' . trim(rawurldecode($path), '/');
        return $path === '//' ? '/' : $path;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key): bool
    {
        return in_array($this->input($key), ['1', 1, true, 'true', 'on', 'yes'], true);
    }

    public function all(): array
    {
        return $this->body + $this->query;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function cookie(string $name): ?string
    {
        $value = $_COOKIE[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function ip(): string
    {
        // Proxy headers are only honoured when the operator explicitly enables it,
        // otherwise a client could spoof its IP and defeat rate limiting.
        if ((bool) Config::get('app.security.trust_proxy', false)) {
            $forwarded = $this->header('X-Forwarded-For');
            if (is_string($forwarded) && $forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }
        $ip = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function acceptLanguage(): string
    {
        return mb_substr((string) ($this->server['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 120);
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($this->server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if ((bool) Config::get('app.security.trust_proxy', false)) {
            return strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        }
        return false;
    }

    /**
     * True when PHP silently threw the request body away.
     *
     * When a POST exceeds post_max_size, PHP does not raise an error: it
     * empties $_POST and $_FILES and carries on. The next thing that happens
     * is a CSRF failure, so the admin sees "the form expired" and has no way
     * to guess that the real problem was an upload limit. Detecting it here
     * turns a misleading 419 into an accurate message.
     */
    public function exceededPostLimit(): bool
    {
        if ($this->method() !== 'POST') {
            return false;
        }
        $declared = (int) ($this->server['CONTENT_LENGTH'] ?? 0);
        if ($declared <= 0) {
            return false;
        }
        if ($this->body !== [] || $this->files !== []) {
            return false;
        }
        return $declared > self::bytesFromIni('post_max_size');
    }

    public function contentLength(): int
    {
        return (int) ($this->server['CONTENT_LENGTH'] ?? 0);
    }

    /** Turns "48M" into bytes; returns 0 when the directive is unlimited. */
    public static function bytesFromIni(string $directive): int
    {
        $raw = trim((string) ini_get($directive));
        if ($raw === '' || $raw === '-1' || $raw === '0') {
            return 0;
        }

        $unit  = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g'     => $value * 1024 * 1024 * 1024,
            'm'     => $value * 1024 * 1024,
            'k'     => $value * 1024,
            default => $value,
        };
    }

    public function isAjax(): bool
    {
        return strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest'
            || str_contains(strtolower((string) $this->header('Accept')), 'application/json');
    }

    public function host(): string
    {
        return (string) ($this->server['HTTP_HOST'] ?? Config::get('app.app.url', ''));
    }
}
