<?php

declare(strict_types=1);

namespace KompanieApp\Core\Mail;

/**
 * Eine Mail an genau einen Empfänger: Betreff, HTML- und Text-Teil (multipart/alternative) und eingebettete Bilder
 * (multipart/related, im HTML als cid:<Kennung>). Absender und Antwortadresse setzt der Mailer aus den Einstellungen.
 */
final class Mail
{
    /**
     * @param list<array{cid: string, mime: string, bytes: string, name: string}> $images
     */
    public function __construct(
        public readonly string $to,
        public readonly string $toName,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly array $images = [],
    ) {
    }
}
