<?php

declare(strict_types=1);

namespace KompanieApp\Core\Mail;

use KompanieApp\Core\Port\Mailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Mailversand mit PHPMailer (LGPL-2.1) über SMTP (Issue #172). Die Klassen lädt der Adapter: in der eigenständigen
 * Version die mitgelieferte Kopie, in WordPress die von WordPress (wp-includes/PHPMailer, kein wp_mail()). Benötigt
 * wird PHPMailer ab MIN_VERSION (SMTP::getServerExt, Namensraum PHPMailer\PHPMailer).
 *
 * Die Höchstgröße des Servers liest serverMaxSize() aus der EHLO-Antwort (SIZE, RFC 1870; PHPMailer:
 * SMTP::getServerExt('SIZE') liefert die Zahl, 0 ohne Zahl, false ohne SIZE).
 */
final class PhpMailerMailer implements Mailer
{
    public const MIN_VERSION = '6.1.0';
    public const TIMEOUT = 15;

    private ?PHPMailer $mailer = null;
    private ?int $size = null;

    /**
     * @param array{host: string, port: int, secure: string, user: string, password: string, from: string, from_name: string} $config
     *   secure: starttls, ssl oder none
     */
    public function __construct(private readonly array $config)
    {
    }

    /** Sind die PHPMailer-Klassen in passender Version da? Sonst die Meldung, warum nicht. */
    public static function unavailable(): ?string
    {
        if (!class_exists(PHPMailer::class)) {
            return 'Der Mailversand ist auf diesem Server nicht verfügbar (PHPMailer fehlt).';
        }
        if (version_compare(PHPMailer::VERSION, self::MIN_VERSION, '<')) {
            return sprintf('Der Mailversand braucht PHPMailer ab Version %s, vorhanden ist %s.', self::MIN_VERSION, PHPMailer::VERSION);
        }
        return null;
    }

    public function check(): array
    {
        $this->close();
        try {
            $mailer = $this->connection();
            $ok = $mailer->smtpConnect();
        } catch (PHPMailerException $e) {
            $this->close();
            return ['ok' => false, 'message' => self::explain($e->getMessage()), 'size' => null];
        }
        if (!$ok) {
            $message = self::explain($mailer->ErrorInfo);
            $this->close();
            return ['ok' => false, 'message' => $message, 'size' => null];
        }
        $this->readSize();
        $size = $this->size;
        $this->close();
        return ['ok' => true, 'message' => 'Verbindung zum Mailserver hergestellt.', 'size' => $size];
    }

    public function measure(Mail $mail): int
    {
        return strlen($this->mime($mail));
    }

    /** Die fertige Mail (Kopf und Inhalt), wie sie an den Server ginge, ohne sie zu senden. */
    public function mime(Mail $mail): string
    {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP(); // Zeilenenden wie beim Versand (CRLF)
        try {
            $this->build($mailer, $mail);
            $mailer->preSend();
        } catch (PHPMailerException $e) {
            throw new \InvalidArgumentException(self::explain($e->getMessage()), 0, $e);
        }
        return $mailer->getSentMIMEMessage();
    }

    public function send(Mail $mail): MailResult
    {
        try {
            $mailer = $this->connection();
            if (!$mailer->getSMTPInstance()->connected()) {
                if (!$mailer->smtpConnect()) {
                    $error = self::explain($mailer->ErrorInfo);
                    $this->close();
                    return new MailResult(false, $error);
                }
                $this->readSize();
            }
            $this->build($mailer, $mail);
            if ($mail->hasHeaderRecipients()) {
                // Kopfzeilen mit den sichtbaren Empfängern erzeugen, zugestellt wird aber nur an $mail->to (smtpSend
                // nimmt die Empfänger erst in postSend aus to, cc und bcc).
                $mailer->preSend();
                $mailer->clearAllRecipients();
                $mailer->addBCC($mail->to, $mail->toName);
                $mailer->postSend();
            } else {
                $mailer->send();
            }
            return MailResult::sent();
        } catch (PHPMailerException $e) {
            $smtp = $this->mailer?->getSMTPInstance()->getError() ?? [];
            $code = isset($smtp['smtp_code']) && is_numeric($smtp['smtp_code']) ? (int) $smtp['smtp_code'] : null;
            $detail = trim((string) ($smtp['detail'] ?? ''));
            $message = self::explain($e->getMessage()) . ($detail !== '' ? ' (' . $detail . ')' : '');
            // Nach einem Fehler sauber neu verbinden (der Server kann die Sitzung beendet haben).
            $this->close();
            return new MailResult(false, $message, $code !== null && $code >= 500, $code);
        }
    }

    public function serverMaxSize(): ?int
    {
        return $this->size;
    }

    public function close(): void
    {
        if ($this->mailer !== null) {
            try {
                $this->mailer->smtpClose();
            } catch (\Throwable) {
            }
        }
        $this->mailer = null;
    }

    /** Wiederverwendete SMTP-Verbindung (SMTPKeepAlive) für mehrere Mails. */
    private function connection(): PHPMailer
    {
        if ($this->mailer === null) {
            $m = new PHPMailer(true);
            $m->isSMTP();
            $m->Host = $this->config['host'];
            $m->Port = $this->config['port'];
            $m->SMTPSecure = match ($this->config['secure']) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            // Ohne Verschlüsselung nicht heimlich doch STARTTLS versuchen (das entscheidet die Einstellung).
            $m->SMTPAutoTLS = $this->config['secure'] === 'starttls';
            $m->SMTPAuth = $this->config['user'] !== '';
            $m->Username = $this->config['user'];
            $m->Password = $this->config['password'];
            $m->Timeout = self::TIMEOUT;
            $m->SMTPKeepAlive = true;
            $this->mailer = $m;
        }
        return $this->mailer;
    }

    private function build(PHPMailer $m, Mail $mail): PHPMailer
    {
        $m->clearAllRecipients();
        $m->clearAttachments();
        $m->clearCustomHeaders();
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $m->XMailer = ' '; // keinen Hinweis auf die Software senden
        $m->setFrom($this->config['from'], $this->config['from_name'], true);
        if ($mail->hasHeaderRecipients()) {
            foreach ($mail->headerTo ?? [] as $r) {
                $m->addAddress($r['email'], $r['name']);
            }
            foreach ($mail->headerCc as $r) {
                $m->addCC($r['email'], $r['name']);
            }
            if (($mail->headerTo ?? []) === [] && $mail->headerCc === []) {
                $m->addBCC($mail->to, $mail->toName); // nur Bcc: To „undisclosed-recipients:;“
            }
        } else {
            $m->addAddress($mail->to, $mail->toName);
        }
        $m->isHTML(true);
        $m->Subject = $mail->subject;
        $m->Body = $mail->html;
        $m->AltBody = $mail->text;
        foreach ($mail->images as $img) {
            $m->addStringEmbeddedImage($img['bytes'], $img['cid'], $img['name'], PHPMailer::ENCODING_BASE64, $img['mime']);
        }
        foreach ($mail->attachments as $file) {
            $m->addAttachment($file['path'], $file['name'], PHPMailer::ENCODING_BASE64, $file['mime']);
        }
        return $m;
    }

    private function readSize(): void
    {
        $value = $this->mailer?->getSMTPInstance()->getServerExt('SIZE');
        $this->size = self::parseSize($value);
    }

    /** SIZE aus der EHLO-Antwort: Zahl > 0 ist ein Limit; 0, ohne Zahl oder fehlend heißt „keins gemeldet“. */
    public static function parseSize(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        return null;
    }

    /** PHPMailer-Meldungen um einen Hinweis ergänzen, was zu tun ist. */
    private static function explain(string $message): string
    {
        $message = trim($message);
        if (stripos($message, 'Could not authenticate') !== false) {
            return 'Anmeldung am Mailserver fehlgeschlagen: Benutzername oder Passwort prüfen.';
        }
        if (stripos($message, 'Failed to connect') !== false || stripos($message, 'Could not connect') !== false) {
            return 'Keine Verbindung zum Mailserver: Server, Port und Verschlüsselung prüfen. (' . $message . ')';
        }
        return $message === '' ? 'Unbekannter Fehler beim Mailversand.' : $message;
    }
}
