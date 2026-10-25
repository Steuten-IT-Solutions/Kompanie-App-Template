<?php

declare(strict_types=1);

namespace KompanieApp\Core\Mail;

/**
 * Eine Mail an genau einen Empfänger: Betreff, HTML- und Text-Teil (multipart/alternative) und eingebettete Bilder
 * (multipart/related, im HTML als cid:<Kennung>). Absender und Antwortadresse setzt der Mailer aus den Einstellungen.
 *
 * Zugestellt wird nur an $to. Ohne $headerTo steht $to auch in der Kopfzeile To. Mit $headerTo (auch leer) zeigen die
 * Kopfzeilen To und Cc stattdessen $headerTo und $headerCc (E-Mails aus der Verwaltung: jeder Empfänger bekommt die
 * Mail einzeln, sieht aber wie in einem Mailprogramm alle Empfänger in An und Cc, Bcc-Empfänger nie); sind beide leer,
 * steht als To „undisclosed-recipients:;“.
 */
final class Mail
{
    /**
     * @param list<array{cid: string, mime: string, bytes: string, name: string}> $images
     * @param list<array{email: string, name: string}>|null $headerTo
     * @param list<array{email: string, name: string}> $headerCc
     */
    public function __construct(
        public readonly string $to,
        public readonly string $toName,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly array $images = [],
        public readonly ?array $headerTo = null,
        public readonly array $headerCc = [],
    ) {
    }

    /** Stehen in den Kopfzeilen eigene Listen statt des Empfängers, an den zugestellt wird? */
    public function hasHeaderRecipients(): bool
    {
        return $this->headerTo !== null;
    }
}
