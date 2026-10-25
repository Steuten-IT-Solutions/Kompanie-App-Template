<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Mail\Mail;
use KompanieApp\Core\Mail\PhpMailerMailer;

/**
 * Kompanieadresse und SMTP-Einstellungen (Issue #172), in beiden Varianten in der App unter Einstellungen → E-Mail.
 * Allgemein, nicht an das Schwarze Brett gebunden.
 *
 * - Absender ist die Kompanieadresse (mail_from) mit Absendername (mail_from_name, Vorschlag: Name der Kompanie);
 *   Antworten und Rückläufer gehen dorthin.
 * - SMTP: Server, Port, Verschlüsselung (starttls, ssl, none), Benutzer, Passwort. Das Passwort wird wie die übrigen
 *   Zugangsdaten in den Einstellungen gespeichert und nie an die App ausgeliefert (nur „gesetzt“). Zum Ändern schickt
 *   man den neuen Wert, zum Löschen einen leeren Text, ohne das Feld bleibt es.
 * - Versand in Portionen (mail_batch_size Mails je Lauf, 0 = so viele wie in einen Lauf passen; mail_batch_pause
 *   Minuten zwischen den Portionen, 0 = ohne Pause): Viele Hoster begrenzen Mails pro Stunde.
 * - „Verbindung prüfen“ zeigt das vom Server gemeldete Limit (SIZE); es wird in mail_server_size gemerkt und vor dem
 *   Versand gegen die fertige Mail geprüft (BoardMailService).
 */
final class MailService
{
    public const SECURE = ['starttls', 'ssl', 'none'];
    public const SIZE_SETTING = 'mail_server_size';

    public function __construct(private readonly App $app)
    {
    }

    /** @return array{host: string, port: int, secure: string, user: string, password: string, from: string, from_name: string} */
    public function config(): array
    {
        $s = $this->app->settings;
        $secure = (string) $s->get('smtp_secure', 'starttls');
        return [
            'host' => trim((string) $s->get('smtp_host', '')),
            'port' => (int) $s->get('smtp_port', 587),
            'secure' => in_array($secure, self::SECURE, true) ? $secure : 'starttls',
            'user' => (string) $s->get('smtp_user', ''),
            'password' => (string) $s->get('smtp_password', ''),
            'from' => trim((string) $s->get('mail_from', '')),
            'from_name' => trim((string) $s->get('mail_from_name', '')) ?: $this->app->config->name(),
        ];
    }

    /** Vollständig eingerichtet (Server und Absender) und PHPMailer vorhanden? */
    public function configured(): bool
    {
        $c = $this->config();
        return $c['host'] !== '' && $c['from'] !== '' && $c['port'] > 0 && $this->unavailable() === null;
    }

    /** Meldung, wenn der Mailversand auf diesem Server nicht geht (PHPMailer fehlt); sonst null. */
    public function unavailable(): ?string
    {
        // Ein von außen gesetzter Mailer (Tests) ist immer verfügbar.
        return $this->app->hasCustomMailer() ? null : PhpMailerMailer::unavailable();
    }

    /** @return array{batch_size: int, batch_pause: int} */
    public function batching(): array
    {
        return [
            'batch_size' => max(0, (int) $this->app->settings->get('mail_batch_size', 0)),
            'batch_pause' => max(0, (int) $this->app->settings->get('mail_batch_pause', 0)),
        ];
    }

    /** Zuletzt gemeldetes Limit des Servers (SIZE) oder null. */
    public function serverMaxSize(): ?int
    {
        $v = $this->app->settings->get(self::SIZE_SETTING);
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    /** Limit merken (null: keins gemeldet bzw. nicht erreichbar, gespeichert als 0); nur schreiben, wenn es sich ändert. */
    public function rememberServerSize(?int $size): void
    {
        $stored = $this->app->settings->get(self::SIZE_SETTING);
        if ($stored === null || (int) $stored !== ($size ?? 0)) {
            $this->app->settings->set(self::SIZE_SETTING, $size ?? 0);
        }
    }

    /** Limit vergessen (anderer Server): beim nächsten Mal neu lesen. */
    public function forgetServerSize(): void
    {
        $this->app->settings->delete(self::SIZE_SETTING);
    }

    /** @return array<string, mixed> für Einstellungen → E-Mail (ohne Passwort) */
    public function describe(): array
    {
        $c = $this->config();
        return [
            'from' => $c['from'],
            'from_name' => (string) $this->app->settings->get('mail_from_name', ''),
            'from_name_default' => $this->app->config->name(),
            'host' => $c['host'],
            'port' => $c['port'],
            'secure' => $c['secure'],
            'user' => $c['user'],
            'password_set' => $c['password'] !== '',
            'configured' => $this->configured(),
            'unavailable' => $this->unavailable(),
            'server_max_size' => $this->serverMaxSize(),
            'pending' => $this->app->boardMail()->pendingCount(),
        ] + $this->batching();
    }

    /**
     * Übernimmt nur die mitgeschickten Felder; erst alles prüfen, dann speichern.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed> wie describe()
     */
    public function update(array $in): array
    {
        $set = [];
        if (array_key_exists('from', $in)) {
            $from = is_string($in['from']) ? trim($in['from']) : '';
            if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                throw ApiError::invalid('Bitte eine gültige Kompanieadresse eingeben (z. B. info@beispiel.de).');
            }
            $set['mail_from'] = $from;
        }
        if (array_key_exists('from_name', $in)) {
            $set['mail_from_name'] = Text::line($in['from_name']);
        }
        if (array_key_exists('host', $in)) {
            $host = is_string($in['host']) ? trim($in['host']) : '';
            if ($host !== '' && preg_match('/^[A-Za-z0-9.-]+$|^\[[0-9A-Fa-f:.]+\]$/', $host) !== 1) {
                throw ApiError::invalid('Bitte den Namen des Mailservers ohne „https://“ eingeben (z. B. smtp.beispiel.de).');
            }
            $set['smtp_host'] = $host;
        }
        if (array_key_exists('port', $in)) {
            $port = Input::integer($in['port']);
            if ($port === null || $port < 1 || $port > 65535) {
                throw ApiError::invalid('Der Port muss eine Zahl zwischen 1 und 65535 sein (meist 587 oder 465).');
            }
            $set['smtp_port'] = $port;
        }
        if (array_key_exists('secure', $in)) {
            if (!in_array($in['secure'], self::SECURE, true)) {
                throw ApiError::invalid('Ungültige Verschlüsselung.');
            }
            $set['smtp_secure'] = $in['secure'];
        }
        if (array_key_exists('user', $in)) {
            $set['smtp_user'] = is_string($in['user']) ? trim($in['user']) : '';
        }
        if (array_key_exists('password', $in)) {
            if (!is_string($in['password'])) {
                throw ApiError::invalid('Ungültiges Passwort.');
            }
            $set['smtp_password'] = $in['password'];
        }
        foreach (['batch_size' => 'mail_batch_size', 'batch_pause' => 'mail_batch_pause'] as $key => $setting) {
            if (array_key_exists($key, $in)) {
                $n = Input::integer($in[$key]);
                if ($n === null || $n < 0) {
                    throw ApiError::invalid($key === 'batch_size' ? 'Die Portionsgröße muss 0 (alle) oder größer sein.' : 'Die Pause muss 0 (ohne Pause) oder mehr Minuten sein.');
                }
                $set[$setting] = $n;
            }
        }
        foreach ($set as $name => $value) {
            $this->app->settings->set($name, $value);
        }
        if (array_intersect(array_keys($set), ['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_password']) !== []) {
            $this->forgetServerSize(); // anderer Server: Limit neu lesen
        }
        $this->app->scheduleTasks();
        return $this->describe();
    }

    /**
     * „Verbindung prüfen“: verbinden, verschlüsseln, anmelden; mit dem gemeldeten Limit.
     *
     * @return array{ok: bool, message: string, size: int|null}
     */
    public function check(): array
    {
        $this->requireConfigured();
        $result = $this->app->mailer()->check();
        if ($result['ok']) {
            $this->rememberServerSize($result['size']);
        }
        return $result;
    }

    /**
     * „Testmail senden“ an eine Adresse (Standard: die eigene).
     *
     * @return array{sent: bool, message: string}
     */
    public function sendTest(User $actor, mixed $to): array
    {
        $this->requireConfigured();
        $address = is_string($to) && trim($to) !== '' ? trim($to) : (string) ($this->app->users->profile($actor->id)['email'] ?? '');
        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiError::invalid('Bitte eine gültige Adresse für die Testmail eingeben.');
        }
        $name = $this->app->config->name();
        $text = "Diese Testmail kommt von der App „{$name}“. Der Mailversand ist richtig eingerichtet.";
        $mailer = $this->app->mailer();
        $result = $mailer->send(new Mail($address, '', 'Testmail: ' . $name, '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>', $text));
        $this->rememberServerSize($mailer->serverMaxSize());
        $mailer->close();
        if (!$result->ok) {
            return ['sent' => false, 'message' => 'Die Testmail konnte nicht gesendet werden: ' . $result->error];
        }
        return ['sent' => true, 'message' => 'Testmail an ' . $address . ' gesendet.'];
    }

    private function requireConfigured(): void
    {
        $unavailable = $this->unavailable();
        if ($unavailable !== null) {
            throw ApiError::invalid($unavailable);
        }
        if (!$this->configured()) {
            throw ApiError::invalid('Bitte zuerst Kompanieadresse und Mailserver eintragen.');
        }
    }
}
