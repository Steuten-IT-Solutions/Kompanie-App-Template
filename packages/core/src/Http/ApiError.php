<?php

declare(strict_types=1);

namespace KompanieApp\Core\Http;

/**
 * Fehler, der als JSON-Antwort {code, message} mit HTTP-Status ausgeliefert wird.
 * Die Meldung ist für Menschen gedacht und wird in der App angezeigt.
 */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self(400, 'invalid', $message);
    }

    public static function notFound(string $message): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function notLoggedIn(): self
    {
        return new self(401, 'not_logged_in', 'Bitte melde dich an.');
    }

    public static function forbidden(string $message): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function upstream(string $message): self
    {
        return new self(502, 'upstream_error', $message);
    }
}
