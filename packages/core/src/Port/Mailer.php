<?php

declare(strict_types=1);

namespace KompanieApp\Core\Port;

use KompanieApp\Core\Mail\Mail;
use KompanieApp\Core\Mail\MailResult;

/**
 * Mailversand (Issue #172), allgemein und nicht an das Schwarze Brett gebunden. Umsetzung in beiden Varianten mit
 * PHPMailer über den SMTP-Server der Kompanie (Mail\PhpMailerMailer); nur die Herkunft der PHPMailer-Klassen
 * unterscheidet sich (eigenständig: mitgeliefert, WordPress: die Kopie von WordPress). Tests nutzen einen Test-Mailer.
 *
 * Eine Verbindung bleibt zwischen mehreren send() offen (Versand in Portionen) und wird mit close() beendet.
 */
interface Mailer
{
    /**
     * Verbindung aufbauen (inkl. Verschlüsselung und Anmeldung) und wieder schließen.
     *
     * @return array{ok: bool, message: string, size: int|null} size: vom Server gemeldete Höchstgröße (SIZE, RFC 1870)
     */
    public function check(): array;

    /** Größe der fertigen Mail in Bytes (wie sie an den Server ginge, mit eingebetteten Bildern). */
    public function measure(Mail $mail): int;

    /** Eine Mail an einen Empfänger senden. */
    public function send(Mail $mail): MailResult;

    /**
     * Höchstgröße, die der Server nach dem letzten Verbindungsaufbau gemeldet hat (SIZE mit Zahl > 0); null, wenn er
     * keine meldet (SIZE fehlt, SIZE 0 oder ohne Zahl) oder noch keine Verbindung bestand.
     */
    public function serverMaxSize(): ?int;

    public function close(): void;
}
