<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Storage\BackupCrypto;
use KompanieApp\Core\Update\OffsiteBackup;

/**
 * Sicherungen automatisch in Google Drive ablegen, damit nach dem Verlust des Servers eine aktuelle Sicherung außerhalb
 * liegt (die Sicherungen vor Updates liegen auf demselben Server).
 *
 * Braucht eine Verbindung über „Mit Google verbinden“ mit der Berechtigung drive.file: Die App legt in „Meine Ablage“
 * des verbundenen Google-Kontos einen eigenen Ordner an, lädt einmal pro Woche eine Sicherung hoch und behält die
 * letzten KEEP. Geprüft wird täglich (TASK); scheitert das Hochladen, versucht sie es am nächsten Tag wieder.
 *
 * Vor jedem Update kommt die Sicherung vom Server zusätzlich in denselben Ordner (storeBeforeUpdate, die letzten
 * KEEP_BEFORE_UPDATE); scheitert das, fragt UpdateService nach, ob trotzdem aktualisiert werden soll.
 *
 * Nach Drive gehen Sicherungen nur verschlüsselt (BackupEncryptionService, Endung .json.enc): Ohne Passwort für
 * Sicherungen lässt sich das nicht einschalten, und eine früher eingeschaltete Sicherung pausiert, bis es eingerichtet ist.
 */
final class BackupUploadService implements OffsiteBackup
{
    public const TASK = 'backup_upload';
    public const INTERVAL = 86400;
    /** Abstand zwischen zwei Sicherungen in Tagen */
    public const EVERY_DAYS = 7;
    /** So viele Sicherungen bleiben im Ordner (ältere löscht die App). */
    public const KEEP = 8;
    /** So viele Sicherungen vor Updates bleiben im Ordner (wie auf dem Server). */
    public const KEEP_BEFORE_UPDATE = 5;
    /** Einstellung (gehört zu den Daten): {enabled: bool, folder_id: string} */
    public const SETTING = 'backup_drive';
    /** Zustand dieser Installation (nicht in Sicherungen): {last_ok: int, last_try: int, error: string} */
    public const STATE_SETTING = 'backup_drive_state';
    private const PREFIX = 'sicherung-';
    private const PREFIX_UPDATE = 'vor-update-';

    public function __construct(private readonly App $app)
    {
    }

    /** Gibt es hier eine Datensicherung und eine Verbindung über „Mit Google verbinden“? */
    public function available(): bool
    {
        return $this->app->backup !== null && $this->app->drive()->oauth() !== null;
    }

    public function enabled(): bool
    {
        return ($this->config()['enabled'] ?? false) === true;
    }

    /** Soll die Aufgabe eingeplant sein? Ohne Passwort für Sicherungen pausiert sie. */
    public function active(): bool
    {
        return $this->enabled() && $this->available() && $this->app->drive()->backupAccess() && $this->app->backupEncryption()->configured();
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $state = $this->state();
        $folder = (string) ($this->config()['folder_id'] ?? '');
        return [
            'available' => $this->available(),
            'access' => $this->app->drive()->backupAccess(),
            'enabled' => $this->enabled(),
            // Eingeschaltet, aber ohne Passwort für Sicherungen: pausiert
            'needs_passphrase' => !$this->app->backupEncryption()->configured(),
            'email' => $this->app->drive()->oauthEmail(),
            'folder_url' => $folder !== '' ? 'https://drive.google.com/drive/folders/' . rawurlencode($folder) : null,
            'last_ok' => $state['last_ok'] > 0 ? $state['last_ok'] : null,
            'last_try' => $state['last_try'] > 0 ? $state['last_try'] : null,
            'error' => $state['error'] !== '' ? $state['error'] : null,
            'every_days' => self::EVERY_DAYS,
            'keep' => self::KEEP,
        ];
    }

    /** @return array<string, mixed> status() */
    public function setEnabled(bool $on): array
    {
        if ($on && !$this->available()) {
            throw ApiError::invalid('Dafür braucht die App eine Verbindung über „Mit Google verbinden“ (Einstellungen → Google-Zugang).');
        }
        if ($on && !$this->app->backupEncryption()->configured()) {
            throw ApiError::invalid('Nach Google Drive gehen Sicherungen nur verschlüsselt. Bitte zuerst unter Datensicherung ein Passwort für Sicherungen einrichten.');
        }
        if ($on && !$this->app->drive()->backupAccess()) {
            throw ApiError::invalid('Die Verbindung zu Google erlaubt noch nicht, Dateien anzulegen. Bitte unter Einstellungen → Google-Zugang die Verbindung trennen und neu mit Google verbinden; dabei das Häkchen für eigene Dateien in Google Drive setzen.');
        }
        $this->app->settings->set(self::SETTING, ['enabled' => $on] + $this->config());
        return $this->status();
    }

    /** Tägliche Prüfung: hochladen, wenn die letzte Sicherung älter als EVERY_DAYS ist. Fehler gehen an App::runTask. */
    public function runTask(): void
    {
        if (!$this->active()) {
            return;
        }
        // Eine Stunde Spielraum, weil die Aufgabe nicht auf die Minute genau läuft.
        if ($this->state()['last_ok'] > $this->app->time()->timestamp() - self::EVERY_DAYS * 86400 + 3600) {
            return;
        }
        $this->upload();
    }

    /**
     * Sicherung jetzt hochladen und alte löschen.
     *
     * @return array<string, mixed> status()
     * @throws \RuntimeException mit verständlicher Meldung (auch im Zustand vermerkt)
     */
    public function upload(): array
    {
        $backup = $this->app->backup;
        if ($backup === null || !$this->available() || !$this->app->drive()->backupAccess()) {
            throw ApiError::invalid('Sicherungen in Google Drive sind noch nicht eingerichtet.');
        }
        if (!$this->app->backupEncryption()->configured()) {
            throw ApiError::invalid('Nach Google Drive gehen Sicherungen nur verschlüsselt. Bitte zuerst ein Passwort für Sicherungen einrichten.');
        }
        $now = $this->app->time()->timestamp();
        $state = $this->state();
        $state['last_try'] = $now;
        $encrypted = null;
        try {
            // Erst Stück für Stück in eine temporäre Datei (verschlüsselt), dann in Stücken hochladen: Die Sicherung muss
            // nie ganz in den Arbeitsspeicher (Fotos, Dokumente und Anhänge machen sie schnell größer als memory_limit).
            $encrypted = $this->app->backupEncryption()->exportToTempFile($backup);
            $this->store($encrypted, self::PREFIX . $this->app->time()->format($now, 'Y-m-d-His') . BackupCrypto::EXTENSION, self::PREFIX, self::KEEP, $state);
        } catch (\Throwable $e) {
            $state['error'] = $e instanceof \JsonException ? 'Die Sicherung ließ sich nicht erstellen: ' . $e->getMessage() : $e->getMessage();
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($state['error'], 0, $e);
        } finally {
            $encrypted !== null && @unlink($encrypted);
            $this->app->settings->set(self::STATE_SETTING, $state);
        }
        return $this->status();
    }

    /**
     * Sicherung vor einem Update (schon auf dem Server geschrieben) hochladen. Zählt wie die wöchentliche: Sie ist eine
     * vollständige Sicherung außerhalb des Servers.
     */
    public function storeBeforeUpdate(string $path, string $version, ?callable $onProgress = null): void
    {
        if (!$this->active()) {
            throw new \RuntimeException('Sicherungen in Google Drive sind nicht eingeschaltet.');
        }
        $now = $this->app->time()->timestamp();
        $state = $this->state();
        $state['last_try'] = $now;
        $encrypted = null;
        try {
            // Die Sicherung auf dem Server bleibt unverschlüsselt (sie liegt neben der Datenbank); nach Drive nur verschlüsselt.
            $encrypted = $this->app->backupEncryption()->encryptFile($path);
            $name = self::PREFIX_UPDATE . $this->app->time()->format($now, 'Y-m-d-His') . '-' . $version . BackupCrypto::EXTENSION;
            $this->store($encrypted, $name, self::PREFIX_UPDATE, self::KEEP_BEFORE_UPDATE, $state, $onProgress);
        } catch (\RuntimeException $e) {
            $state['error'] = $e->getMessage();
            throw $e;
        } finally {
            $encrypted !== null && @unlink($encrypted);
            $this->app->settings->set(self::STATE_SETTING, $state);
        }
    }

    /**
     * Datei in den Ordner hochladen, ältere mit demselben Anfang über $keep hinaus löschen.
     *
     * @param array{last_ok: int, last_try: int, error: string} $state
     * @param (callable(int, int): void)|null $onProgress übertragene Bytes, Größe
     */
    private function store(string $path, string $name, string $prefix, int $keep, array &$state, ?callable $onProgress = null): void
    {
        $folder = $this->folder();
        $this->app->drive()->uploadFile($folder, $name, $path, 'application/octet-stream', DriveClient::UPLOAD_CHUNK, $onProgress);
        $this->prune($folder, $prefix, $keep);
        $state['last_ok'] = $state['last_try'];
        $state['error'] = '';
    }

    /** Ordner der Sicherungen; legt ihn (neu) an, wenn es ihn nicht gibt (z. B. gelöscht oder anderes Google-Konto). */
    private function folder(): string
    {
        $drive = $this->app->drive();
        $config = $this->config();
        $id = (string) ($config['folder_id'] ?? '');
        if ($id === '' || !$drive->folderExists($id)) {
            $id = $drive->createFolder('Sicherungen ' . $this->app->config->name());
            $this->app->settings->set(self::SETTING, ['folder_id' => $id] + $config);
        }
        return $id;
    }

    /**
     * Ältere Sicherungen über $keep hinaus löschen (der Name enthält Datum und Uhrzeit; verschlüsselte und frühere
     * unverschlüsselte zählen zusammen, so verschwinden die unverschlüsselten nach und nach). Fehler hier sind nicht schlimm.
     */
    private function prune(string $folder, string $prefix, int $keep): void
    {
        try {
            $names = [];
            foreach ($this->app->drive()->listChildren($folder) as $f) {
                $name = (string) ($f['name'] ?? '');
                if (str_starts_with($name, $prefix) && (str_ends_with($name, '.json') || str_ends_with($name, BackupCrypto::EXTENSION)) && !DriveClient::isFolder($f)) {
                    $names[(string) $f['id']] = $name;
                }
            }
            arsort($names);
            foreach (array_slice(array_keys($names), $keep) as $id) {
                $this->app->drive()->deleteFile((string) $id);
            }
        } catch (\RuntimeException $e) {
            error_log('Kompanie-App: alte Sicherungen in Google Drive nicht gelöscht: ' . $e->getMessage());
        }
    }

    /** @return array{enabled?: bool, folder_id?: string} */
    private function config(): array
    {
        $c = $this->app->settings->get(self::SETTING);
        if (!is_array($c)) {
            return [];
        }
        $out = [];
        if (is_bool($c['enabled'] ?? null)) {
            $out['enabled'] = $c['enabled'];
        }
        if (is_string($c['folder_id'] ?? null)) {
            $out['folder_id'] = $c['folder_id'];
        }
        return $out;
    }

    /** @return array{last_ok: int, last_try: int, error: string} */
    private function state(): array
    {
        $s = $this->app->settings->get(self::STATE_SETTING);
        $s = is_array($s) ? $s : [];
        return [
            'last_ok' => is_int($s['last_ok'] ?? null) ? $s['last_ok'] : 0,
            'last_try' => is_int($s['last_try'] ?? null) ? $s['last_try'] : 0,
            'error' => is_string($s['error'] ?? null) ? $s['error'] : '',
        ];
    }
}
