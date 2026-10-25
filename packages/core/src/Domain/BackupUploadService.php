<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;

/**
 * Sicherungen automatisch in Google Drive ablegen, damit nach dem Verlust des Servers eine aktuelle Sicherung außerhalb
 * liegt (die Sicherungen vor Updates liegen auf demselben Server).
 *
 * Braucht eine Verbindung über „Mit Google verbinden“ mit der Berechtigung drive.file: Die App legt in „Meine Ablage“
 * des verbundenen Google-Kontos einen eigenen Ordner an, lädt einmal pro Woche eine Sicherung hoch und behält die
 * letzten KEEP. Geprüft wird täglich (TASK); scheitert das Hochladen, versucht sie es am nächsten Tag wieder.
 */
final class BackupUploadService
{
    public const TASK = 'backup_upload';
    public const INTERVAL = 86400;
    /** Abstand zwischen zwei Sicherungen in Tagen */
    public const EVERY_DAYS = 7;
    /** So viele Sicherungen bleiben im Ordner (ältere löscht die App). */
    public const KEEP = 8;
    /** Einstellung (gehört zu den Daten): {enabled: bool, folder_id: string} */
    public const SETTING = 'backup_drive';
    /** Zustand dieser Installation (nicht in Sicherungen): {last_ok: int, last_try: int, error: string} */
    public const STATE_SETTING = 'backup_drive_state';
    private const PREFIX = 'sicherung-';

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

    /** Soll die Aufgabe eingeplant sein? */
    public function active(): bool
    {
        return $this->enabled() && $this->available() && $this->app->drive()->backupAccess();
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
        $now = $this->app->time()->timestamp();
        $state = $this->state();
        $state['last_try'] = $now;
        $tmp = $this->app->files->tempFile();
        try {
            // Erst Stück für Stück in eine temporäre Datei, dann in Stücken hochladen: Die Sicherung muss nie ganz in
            // den Arbeitsspeicher (Fotos, Dokumente und Anhänge machen sie schnell größer als memory_limit).
            $out = @fopen($tmp, 'wb');
            if ($out === false) {
                throw new \RuntimeException('Die Sicherung ließ sich nicht erstellen (temporäre Datei).');
            }
            try {
                $backup->exportTo($out);
            } finally {
                fclose($out);
            }
            $drive = $this->app->drive();
            $folder = $this->folder();
            $name = self::PREFIX . $this->app->time()->format($now, 'Y-m-d-His') . '.json';
            $drive->uploadFile($folder, $name, $tmp, 'application/json');
            $this->prune($folder);
            $state['last_ok'] = $now;
            $state['error'] = '';
        } catch (\Throwable $e) {
            $state['error'] = $e instanceof \JsonException ? 'Die Sicherung ließ sich nicht erstellen: ' . $e->getMessage() : $e->getMessage();
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($state['error'], 0, $e);
        } finally {
            @unlink($tmp);
            $this->app->settings->set(self::STATE_SETTING, $state);
        }
        return $this->status();
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

    /** Ältere Sicherungen über KEEP hinaus löschen (der Name enthält Datum und Uhrzeit). Fehler hier sind nicht schlimm. */
    private function prune(string $folder): void
    {
        try {
            $names = [];
            foreach ($this->app->drive()->listChildren($folder) as $f) {
                $name = (string) ($f['name'] ?? '');
                if (str_starts_with($name, self::PREFIX) && str_ends_with($name, '.json') && !DriveClient::isFolder($f)) {
                    $names[(string) $f['id']] = $name;
                }
            }
            arsort($names);
            foreach (array_slice(array_keys($names), self::KEEP) as $id) {
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
