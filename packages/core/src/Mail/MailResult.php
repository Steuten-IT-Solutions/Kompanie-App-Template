<?php

declare(strict_types=1);

namespace KompanieApp\Core\Mail;

/**
 * Ergebnis eines Versands. permanent: Ein neuer Versuch hilft nicht (z. B. 5xx wie 552 „zu groß“ oder eine ungültige
 * Adresse); sonst (Verbindung, 4xx) wird es später erneut versucht.
 */
final class MailResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $error = '',
        public readonly bool $permanent = false,
        public readonly ?int $code = null,
    ) {
    }

    public static function sent(): self
    {
        return new self(true);
    }

    /**
     * Fehlertext fürs Protokoll des Servers ohne E-Mail-Adressen: SMTP-Server wiederholen sie oft
     * („550 <name@example.org>: User unknown“), und das Protokoll soll keine personenbezogenen Daten sammeln.
     */
    public static function redactAddresses(string $text): string
    {
        return (string) preg_replace('/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/', '[Adresse]', $text);
    }
}
