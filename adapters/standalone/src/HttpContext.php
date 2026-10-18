<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

/** Zugriff auf die laufende HTTP-Anfrage (Cookies, Header, Adresse) und das Setzen von Cookies. */
final class HttpContext
{
    /**
     * @param array<string, string> $cookies
     * @param array<string, mixed> $server
     */
    public function __construct(
        private array $cookies,
        private readonly array $server,
        public readonly string $basePath,
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        return new self($_COOKIE, $_SERVER, $basePath);
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $this->server[$key] ?? ($name === 'Content-Type' ? ($this->server['CONTENT_TYPE'] ?? null) : null);
        return is_string($v) ? $v : null;
    }

    /** Ob die Anfrage einen Inhalt mitbringt (Content-Length > 0 oder stückweise übertragen). */
    public function hasBody(): bool
    {
        return (int) ($this->server['CONTENT_LENGTH'] ?? $this->server['HTTP_CONTENT_LENGTH'] ?? 0) > 0
            || isset($this->server['HTTP_TRANSFER_ENCODING']);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off')
            || (string) ($this->server['SERVER_PORT'] ?? '') === '443'
            || strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function host(): string
    {
        return (string) ($this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost');
    }

    /** Basisadresse der Installation, z. B. https://example.org/app/ */
    public function baseUrl(): string
    {
        return ($this->isHttps() ? 'https' : 'http') . '://' . $this->host() . $this->basePath;
    }

    public function clientIp(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function setCookie(string $name, string $value, int $expires): void
    {
        $this->cookies[$name] = $value;
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => $this->basePath,
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
