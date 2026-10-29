<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Storage\BackupCrypto;

/**
 * Passwort für Sicherungen (Storage\BackupCrypto): einrichten, prüfen, ändern, entfernen; Sicherungen verschlüsseln.
 *
 * Gespeichert wird nur der öffentliche Schlüssel (Einstellung BackupCrypto::SETTING, gehört zur Installation und kommt
 * nicht in Sicherungen). Das Passwort selbst kennt die App nicht: Wer es vergisst, kann verschlüsselte Sicherungen nicht
 * mehr wiederherstellen. Ändern betrifft nur neue Sicherungen; ältere brauchen weiter das alte Passwort.
 * Sicherungen in Google Drive gibt es nur mit Passwort (BackupUploadService).
 */
final class BackupEncryptionService
{
    /**
     * Fehlversuche „Passwort prüfen“ bzw. Wiederherstellen in 15 Minuten (je für die ganze Installation), danach 15
     * Minuten gesperrt. Nur Fehlversuche zählen, und jeweils getrennt: Prüfen sperrt kein Wiederherstellen im Notfall.
     */
    public const ATTEMPTS = 10;
    /** Eigene Zähler für „Prüfen“ und „Wiederherstellen“: Fehlversuche beim Prüfen sperren kein Wiederherstellen. */
    private const VERIFY = 'verify';
    private const RESTORE = 'restore';

    public function __construct(private readonly App $app)
    {
    }

    public function configured(): bool
    {
        return $this->key() !== null;
    }

    /**
     * Wurde das Passwort seit dem Einrichten einmal richtig geprüft („Passwort prüfen“)? Erst dann löscht Google Drive
     * die alten unverschlüsselten Sicherungen: Ein vertipptes Passwort ließe sonst keine lesbare Sicherung übrig.
     */
    public function verified(): bool
    {
        return ($this->key()['verified'] ?? false) === true;
    }

    /** @return array{available: bool, configured: bool, verified: bool, fingerprint: ?string, set_at: ?int, min_length: int} */
    public function status(): array
    {
        $key = $this->key();
        return [
            'available' => BackupCrypto::available(),
            'configured' => $key !== null,
            'verified' => ($key['verified'] ?? false) === true,
            'fingerprint' => $key !== null ? BackupCrypto::fingerprint($key['public_key']) : null,
            'set_at' => $key !== null && is_int($key['set_at'] ?? null) ? $key['set_at'] : null,
            'min_length' => BackupCrypto::MIN_LENGTH,
        ];
    }

    /** @return array<string, mixed> status() */
    public function set(mixed $passphrase, mixed $confirm): array
    {
        if (!BackupCrypto::available()) {
            throw ApiError::invalid('Auf diesem Server fehlt PHP die Erweiterung sodium; bitte beim Hoster nachfragen.');
        }
        $passphrase = is_string($passphrase) ? $passphrase : '';
        if (mb_strlen($passphrase) < BackupCrypto::MIN_LENGTH) {
            throw ApiError::invalid('Das Passwort für Sicherungen braucht mindestens ' . BackupCrypto::MIN_LENGTH . ' Zeichen.');
        }
        if (!is_string($confirm) || !hash_equals($passphrase, $confirm)) {
            throw ApiError::invalid('Die beiden Passwörter sind nicht gleich.');
        }
        try {
            $key = BackupCrypto::newKey($passphrase);
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        $this->app->settings->set(BackupCrypto::SETTING, $key + ['set_at' => $this->app->time()->timestamp(), 'verified' => false]);
        $this->app->scheduleTasks();
        return $this->status();
    }

    /** Passwort entfernen: neue Sicherungen sind dann unverschlüsselt, Drive pausiert. @return array<string, mixed> */
    public function remove(): array
    {
        $this->app->settings->delete(BackupCrypto::SETTING);
        $this->app->scheduleTasks();
        return $this->status();
    }

    /** Passt das Passwort zum eingerichteten? (begrenzt: ATTEMPTS Fehlversuche in 15 Minuten) */
    public function verify(mixed $passphrase): bool
    {
        $key = $this->key() ?? throw ApiError::invalid('Es ist noch kein Passwort für Sicherungen eingerichtet.');
        $this->throttle(self::VERIFY);
        try {
            $ok = is_string($passphrase) && BackupCrypto::matches($key, $passphrase);
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        }
        if (!$ok) {
            $this->failed(self::VERIFY);
        } elseif (($key['verified'] ?? false) !== true) {
            $this->app->settings->set(BackupCrypto::SETTING, ['verified' => true] + (array) $this->app->settings->get(BackupCrypto::SETTING));
        }
        return $ok;
    }

    /**
     * Hochgeladene Sicherung (POST /admin/backup, backup_base64); ist sie verschlüsselt, mit dem Passwort entschlüsseln.
     * Der Klartext entsteht nur im Arbeitsspeicher (einmal), damit er nicht im /tmp des Servers landet.
     *
     * @throws ApiError 400 passphrase_required, wenn das Passwort fehlt; 400 bei falschem Passwort oder beschädigter
     *                  Datei; 429 nach zu vielen Fehlversuchen
     */
    public function readUpload(string $base64, mixed $passphrase): string
    {
        // Am Anfang erkennen, was es ist: Klartext nie in eine Datei (php://temp schreibt ab 2 MB ins /tmp des Servers).
        $head = (string) base64_decode(substr($base64, 0, 4 * (int) ceil(strlen(BackupCrypto::MAGIC) / 3)), false);
        if (!BackupCrypto::isEncrypted($head)) {
            $plain = base64_decode($base64, true);
            if ($plain === false) {
                throw ApiError::invalid('Die Datei ließ sich nicht lesen.');
            }
            return $plain; // unverschlüsselte Sicherung
        }
        if (!is_string($passphrase) || $passphrase === '') {
            throw new ApiError(400, 'passphrase_required', 'Die Sicherung ist verschlüsselt. Bitte das Passwort für Sicherungen eingeben.');
        }
        $this->throttle(self::RESTORE);
        // Der verschlüsselte Inhalt darf in eine temporäre Datei, der Klartext entsteht nur im Arbeitsspeicher.
        $in = fopen('php://temp', 'w+b');
        if ($in === false) {
            throw ApiError::invalid('Die Datei ließ sich nicht lesen.');
        }
        try {
            $filter = stream_filter_append($in, 'convert.base64-decode', STREAM_FILTER_WRITE);
            for ($pos = 0, $len = strlen($base64); $pos < $len; $pos += 1048576) {
                fwrite($in, substr($base64, $pos, 1048576));
            }
            if ($filter !== false) {
                stream_filter_remove($filter); // schreibt den Rest
            }
            rewind($in);
            return BackupCrypto::decrypt($in, $passphrase);
        } catch (\RuntimeException $e) {
            // Nur ein falsches Passwort zählt; eine beschädigte Datei oder zu wenig Speicher sperrt niemanden aus.
            if ($e->getCode() === BackupCrypto::WRONG_PASSPHRASE) {
                $this->failed(self::RESTORE);
            }
            throw $e instanceof ApiError ? $e : ApiError::invalid($e->getMessage());
        } finally {
            fclose($in);
        }
    }

    /**
     * Ganze Sicherung verschlüsselt in eine temporäre Datei: beim Schreiben verschlüsselt (BackupEncryptor), der
     * Klartext liegt nie als Datei vor. Der Aufrufer löscht die Datei.
     *
     * @return string Pfad der verschlüsselten Datei
     */
    public function exportToTempFile(BackupProvider $backup): string
    {
        return $this->writeEncrypted(static fn ($out) => $backup->exportTo($out));
    }

    /**
     * Datei (z. B. die Sicherung vor einem Update auf dem Server) verschlüsselt in eine temporäre Datei schreiben (zum
     * Hochladen); der Aufrufer löscht sie.
     *
     * @return string Pfad der verschlüsselten Datei
     */
    public function encryptFile(string $plainPath): string
    {
        $in = @fopen($plainPath, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Die Sicherung ließ sich nicht verschlüsseln (Datei nicht lesbar).');
        }
        try {
            return $this->writeEncrypted(static function ($out) use ($in): void {
                if (stream_copy_to_stream($in, $out) === false) {
                    throw new \RuntimeException('Die Sicherung ließ sich nicht verschlüsseln (Datei nicht lesbar).');
                }
            });
        } finally {
            fclose($in);
        }
    }

    /**
     * Temporäre Datei, in die $write verschlüsselt schreibt; geprüft, ob alles angekommen ist (Speicherplatz). Bricht PHP
     * ab (Zeitlimit, Abbruch eines Downloads), löscht das Ende der Anfrage die Datei.
     *
     * @param \Closure(resource): void $write
     */
    private function writeEncrypted(\Closure $write): string
    {
        $key = $this->key() ?? throw new \RuntimeException('Es ist kein Passwort für Sicherungen eingerichtet.');
        $file = $this->app->files->tempFile();
        register_shutdown_function(static fn () => is_file($file) && @unlink($file));
        try {
            $out = @fopen($file, 'wb');
            if ($out === false) {
                throw new \RuntimeException('Die Sicherung ließ sich nicht erstellen (temporäre Datei).');
            }
            try {
                $bytes = BackupCrypto::encryptTo($out, $key, $this->meta(), $write);
            } finally {
                fclose($out);
            }
            clearstatcache(true, $file);
            if (filesize($file) !== $bytes) {
                throw new \RuntimeException('Die verschlüsselte Sicherung ließ sich nicht vollständig schreiben (Speicherplatz?).');
            }
            return $file;
        } catch (\Throwable $e) {
            @unlink($file);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException('Die Sicherung ließ sich nicht erstellen: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array<string, scalar> unverschlüsselte Angaben im Kopf */
    private function meta(): array
    {
        return ['created' => gmdate('c', $this->app->time()->timestamp()), 'kompanie' => $this->app->config->name()];
    }

    /** @return array{public_key: string, salt: string, ops: int, mem: int, set_at?: int}|null */
    private function key(): ?array
    {
        $k = $this->app->settings->get(BackupCrypto::SETTING);
        if (!is_array($k) || !is_string($k['public_key'] ?? null) || !is_string($k['salt'] ?? null) || !is_numeric($k['ops'] ?? null) || !is_numeric($k['mem'] ?? null)) {
            return null;
        }
        // WordPress kann Zahlen als Zeichenkette zurückgeben.
        return ['public_key' => $k['public_key'], 'salt' => $k['salt'], 'ops' => (int) $k['ops'], 'mem' => (int) $k['mem']]
            + (is_numeric($k['set_at'] ?? null) ? ['set_at' => (int) $k['set_at']] : [])
            // Ältere Einstellungen ohne den Schalter gelten als geprüft (wie vor dieser Prüfung).
            + ['verified' => !array_key_exists('verified', $k) || $k['verified'] === true || $k['verified'] === '1' || $k['verified'] === 1];
    }

    private function throttle(string $kind): void
    {
        if ($this->app->cache->get('backup_passphrase_blocked_' . $kind) !== null) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.');
        }
    }

    /** Fehlversuch zählen; nach ATTEMPTS in 15 Minuten 15 Minuten sperren. */
    private function failed(string $kind): void
    {
        if ($this->app->cache->increment('backup_passphrase_' . $kind, 900) >= self::ATTEMPTS) {
            $this->app->cache->set('backup_passphrase_blocked_' . $kind, 1, 900);
        }
    }
}
