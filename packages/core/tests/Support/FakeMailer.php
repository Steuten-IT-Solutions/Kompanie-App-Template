<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\Mail\Mail;
use KompanieApp\Core\Mail\MailResult;
use KompanieApp\Core\Port\Mailer;

/** Test-Mailer (Issue #172): verschickt nichts, merkt sich die Mails; Ergebnisse lassen sich je Adresse vorgeben. */
final class FakeMailer implements Mailer
{
    /** @var list<Mail> */
    public array $sent = [];
    /** @var array<string, list<MailResult>> Adresse => nächste Ergebnisse (sonst Erfolg) */
    public array $results = [];
    public ?int $size = null;
    public bool $reachable = true;
    public int $checks = 0;
    public int $closed = 0;
    /** Größe, die measure() meldet; null = Länge von HTML und Text plus Bilder (Base64) */
    public ?int $measured = null;

    public function check(): array
    {
        $this->checks++;
        return $this->reachable
            ? ['ok' => true, 'message' => 'Verbindung zum Mailserver hergestellt.', 'size' => $this->size]
            : ['ok' => false, 'message' => 'Keine Verbindung zum Mailserver.', 'size' => null];
    }

    public function measure(Mail $mail): int
    {
        if ($this->measured !== null) {
            return $this->measured;
        }
        $n = strlen($mail->html) + strlen($mail->text);
        foreach ($mail->images as $img) {
            $n += (int) ceil(strlen($img['bytes']) / 3) * 4;
        }
        foreach ($mail->attachments as $file) {
            $n += (int) ceil((int) filesize($file['path']) / 3) * 4;
        }
        return $n;
    }

    public function send(Mail $mail): MailResult
    {
        $result = isset($this->results[$mail->to]) && $this->results[$mail->to] !== [] ? array_shift($this->results[$mail->to]) : MailResult::sent();
        if ($result->ok) {
            $this->sent[] = $mail;
        }
        return $result;
    }

    public function serverMaxSize(): ?int
    {
        return $this->size;
    }

    public function close(): void
    {
        $this->closed++;
    }

    /** @return list<string> Empfänger der versendeten Mails */
    public function recipients(): array
    {
        return array_map(static fn (Mail $m): string => $m->to, $this->sent);
    }
}
