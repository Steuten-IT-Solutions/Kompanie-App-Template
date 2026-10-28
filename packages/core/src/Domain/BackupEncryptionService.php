<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;
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
    /** Versuche „Passwort prüfen“ bzw. Wiederherstellen in 15 Minuten */
    public const ATTEMPTS = 10;

    public function __construct(private readonly App $app)
    {
    }

    public function configured(): bool
    {
        return $this->key() !== null;
    }

    /** @return array{available: bool, configured: bool, fingerprint: ?string, set_at: ?int, min_length: int} */
    public function status(): array
    {
        $key = $this->key();
        return [
            'available' => BackupCrypto::available(),
            'configured' => $key !== null,
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
        $this->app->settings->set(BackupCrypto::SETTING, $key + ['set_at' => $this->app->time()->timestamp()]);
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

    /** Passt das Passwort zum eingerichteten? (begrenzt: ATTEMPTS in 15 Minuten) */
    public function verify(mixed $passphrase): bool
    {
        $key = $this->key() ?? throw ApiError::invalid('Es ist noch kein Passwort für Sicherungen eingerichtet.');
        $this->throttle();
        try {
            return is_string($passphrase) && BackupCrypto::matches($key, $passphrase);
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        }
    }

    /**
     * Hochgeladene Sicherung: verschlüsselt → mit dem Passwort entschlüsseln, sonst unverändert (ältere Sicherungen).
     *
     * @throws ApiError 400 passphrase_required, wenn das Passwort fehlt; 400 bei falschem Passwort
     */
    public function decryptUpload(string $data, mixed $passphrase): string
    {
        if (!BackupCrypto::isEncrypted($data)) {
            return $data;
        }
        if (!is_string($passphrase) || $passphrase === '') {
            throw new ApiError(400, 'passphrase_required', 'Die Sicherung ist verschlüsselt. Bitte das Passwort für Sicherungen eingeben.');
        }
        $this->throttle();
        try {
            return BackupCrypto::decryptToString($data, $passphrase);
        } catch (\RuntimeException $e) {
            throw ApiError::invalid($e->getMessage());
        }
    }

    /**
     * Strom $in verschlüsselt nach $out schreiben (mit dem eingerichteten Schlüssel).
     *
     * @param resource $in
     * @param resource $out
     */
    public function encryptStream($in, $out): void
    {
        $key = $this->key() ?? throw new \RuntimeException('Es ist kein Passwort für Sicherungen eingerichtet.');
        BackupCrypto::encryptStream($in, $out, $key, [
            'created' => gmdate('c', $this->app->time()->timestamp()),
            'kompanie' => $this->app->config->name(),
        ]);
    }

    /**
     * Datei verschlüsselt in eine temporäre Datei schreiben (zum Hochladen); der Aufrufer löscht sie.
     *
     * @return string Pfad der verschlüsselten Datei
     */
    public function encryptFile(string $plainPath): string
    {
        $tmp = $this->app->files->tempFile();
        $in = @fopen($plainPath, 'rb');
        $out = @fopen($tmp, 'wb');
        try {
            if ($in === false || $out === false) {
                throw new \RuntimeException('Die Sicherung ließ sich nicht verschlüsseln (temporäre Datei).');
            }
            $this->encryptStream($in, $out);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        } finally {
            is_resource($in) && fclose($in);
            is_resource($out) && fclose($out);
        }
        return $tmp;
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
            + (is_numeric($k['set_at'] ?? null) ? ['set_at' => (int) $k['set_at']] : []);
    }

    private function throttle(): void
    {
        if ($this->app->cache->increment('backup_passphrase', 900) > self::ATTEMPTS) {
            throw new ApiError(429, 'too_many_attempts', 'Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.');
        }
    }
}
