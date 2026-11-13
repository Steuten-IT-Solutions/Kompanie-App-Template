<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Http\ApiError;

/**
 * Sicherungen automatisch auf dem eigenen Server (Webspace der App) ablegen: im geschützten Ordner „sicherungen“, in dem
 * auch die Sicherungen vor Updates liegen (Update\UpdateService::backupBefore). Hilft gegen versehentliches Löschen oder
 * Ändern, nicht gegen den Verlust des Servers; dafür bleiben Herunterladen und Google Drive (BackupUploadService).
 *
 * Geprüft wird täglich (TASK), gesichert einmal pro Woche (EVERY_DAYS); es bleiben die letzten KEEP. Wie die Sicherungen
 * vor Updates unverschlüsselt (sie liegen neben der Datenbank) und mit zufälligem Teil im Namen, weil unter WordPress
 * auf nginx der .htaccess-Schutz des Ordners nicht greift.
 *
 * Außerdem die Liste aller Sicherungen in diesem Ordner (wöchentliche und vor Updates) zum Herunterladen
 * (BackupDownload), Wiederherstellen und Löschen in der App: Es gibt auf dem Webspace oft keinen anderen Zugang.
 */
final class ServerBackupService
{
    public const TASK = 'backup_server';
    public const INTERVAL = 86400;
    /** Abstand zwischen zwei Sicherungen in Tagen */
    public const EVERY_DAYS = 7;
    /** So viele wöchentliche Sicherungen bleiben auf dem Server (ältere löscht die App; die vor Updates zählen nicht mit). */
    public const KEEP = 4;
    /** Einstellung dieser Installation (nicht in Sicherungen, Backup::LOCAL_SETTINGS): {enabled: bool} */
    public const SETTING = 'backup_server';
    /** Zustand dieser Installation (nicht in Sicherungen): {last_ok: int, last_try: int, error: string} */
    public const STATE_SETTING = 'backup_server_state';
    /** Name einer wöchentlichen Sicherung: sicherung-<JJJJMMTT>-<HHMMSS>-<Zufall>.json */
    private const WEEKLY = '/^sicherung-(\d{8}-\d{6})-[0-9a-f]{32}\.json$/';
    /** Sicherung vor einem Update (UpdateService::backupBefore), auch ältere Namen ohne laufende Nummer */
    private const BEFORE_UPDATE = '/^vor-update-[0-9A-Za-z.\-]+\.json$/';
    /** Version im heutigen Namen: vor-update-<Nummer>-<Datum>-<Zeit>-<Version>-<Zufall>.json */
    private const BEFORE_UPDATE_VERSION = '/^vor-update-\d{6}-\d{8}-\d{6}-(.+)-[0-9a-f]{32}\.json$/';

    public function __construct(private readonly App $app)
    {
    }

    /** Gibt es hier eine Datensicherung? */
    public function available(): bool
    {
        return $this->app->backup !== null;
    }

    public function enabled(): bool
    {
        $c = $this->app->settings->get(self::SETTING);
        return is_array($c) && ($c['enabled'] ?? false) === true;
    }

    /** Soll die Aufgabe eingeplant sein? */
    public function active(): bool
    {
        return $this->enabled() && $this->available();
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $state = $this->state();
        return [
            'available' => $this->available(),
            'enabled' => $this->enabled(),
            'can_restore' => $this->app->backup?->canImport() ?? false,
            'last_ok' => $state['last_ok'] > 0 ? $state['last_ok'] : null,
            'last_try' => $state['last_try'] > 0 ? $state['last_try'] : null,
            'error' => $state['error'] !== '' ? $state['error'] : null,
            'every_days' => self::EVERY_DAYS,
            'keep' => self::KEEP,
            'files' => $this->available() ? $this->files() : [],
        ];
    }

    /** @return array<string, mixed> status() */
    public function setEnabled(bool $on): array
    {
        if ($on && !$this->available()) {
            throw new ApiError(404, 'not_available', 'Die Datensicherung gibt es in dieser Installation nicht.');
        }
        $this->app->settings->set(self::SETTING, ['enabled' => $on]);
        return $this->status();
    }

    /** Tägliche Prüfung: sichern, wenn die letzte Sicherung älter als EVERY_DAYS ist. Fehler gehen an App::runTask. */
    public function runTask(): void
    {
        if (!$this->active()) {
            return;
        }
        // Eine Stunde Spielraum, weil die Aufgabe nicht auf die Minute genau läuft.
        if ($this->state()['last_ok'] > $this->app->time()->timestamp() - self::EVERY_DAYS * 86400 + 3600) {
            return;
        }
        $this->create();
    }

    /**
     * Sicherung jetzt auf dem Server ablegen und ältere wöchentliche über KEEP hinaus löschen.
     *
     * @return array<string, mixed> status()
     * @throws \RuntimeException mit verständlicher Meldung (auch im Zustand vermerkt)
     */
    public function create(): array
    {
        $backup = $this->app->backup ?? throw new ApiError(404, 'not_available', 'Die Datensicherung gibt es in dieser Installation nicht.');
        $now = $this->app->time()->timestamp();
        $state = $this->state();
        $state['last_try'] = $now;
        $dir = $this->dir();
        $path = $dir . '/sicherung-' . gmdate('Ymd-His', $now) . '-' . bin2hex(random_bytes(16)) . '.json';
        // Erst unter vorläufigem Namen, damit nie eine halbe Sicherung in der Liste steht.
        $tmp = $path . '.tmp';
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            if (!is_file($dir . '/index.html')) {
                @file_put_contents($dir . '/index.html', '');
            }
            // Reste abgebrochener Läufe (z. B. Zeitlimit) entfernen.
            foreach (glob($dir . '/sicherung-*.json.tmp') ?: [] as $stale) {
                @unlink($stale);
            }
            $out = @fopen($tmp, 'wb');
            if ($out === false) {
                throw new \RuntimeException('Die Sicherung ließ sich auf dem Server nicht schreiben (Schreibrechte?).');
            }
            @chmod($tmp, 0600);
            try {
                // Stück für Stück (BackupProvider::exportTo): Mit Fotos, Dokumenten und Anhängen ist die Sicherung schnell
                // größer, als memory_limit erlaubt.
                $backup->exportTo($out);
                if (!fflush($out)) {
                    throw new \RuntimeException('Die Sicherung ließ sich auf dem Server nicht schreiben (Speicherplatz?).');
                }
            } finally {
                fclose($out);
            }
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('Die Sicherung ließ sich auf dem Server nicht schreiben (Speicherplatz?).');
            }
            $this->prune();
            $state['last_ok'] = $now;
            $state['error'] = '';
        } catch (\Throwable $e) {
            @unlink($tmp);
            $state['error'] = $e instanceof \JsonException ? 'Die Sicherung ließ sich nicht erstellen: ' . $e->getMessage() : $e->getMessage();
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($state['error'], 0, $e);
        } finally {
            $this->app->settings->set(self::STATE_SETTING, $state);
        }
        return $this->status();
    }

    /**
     * Alle Sicherungen im Ordner, neueste zuerst: wöchentliche (kind weekly) und vor Updates (kind update, mit Version).
     *
     * @return list<array{id: string, kind: string, created: int, size: int, version: string|null}>
     */
    public function files(): array
    {
        $out = [];
        foreach (scandir($this->dir()) ?: [] as $name) {
            $kind = $this->kind($name);
            $path = $this->dir() . '/' . $name;
            if ($kind === null || !is_file($path)) {
                continue;
            }
            $created = (int) @filemtime($path);
            if ($kind === 'weekly' && preg_match(self::WEEKLY, $name, $m) === 1) {
                $t = \DateTimeImmutable::createFromFormat('!Ymd-His', $m[1], new \DateTimeZone('UTC'));
                $created = $t !== false ? $t->getTimestamp() : $created;
            }
            $version = $kind === 'update' && preg_match(self::BEFORE_UPDATE_VERSION, $name, $v) === 1 ? $v[1] : null;
            $out[] = ['id' => $name, 'kind' => $kind, 'created' => $created, 'size' => (int) @filesize($path), 'version' => $version];
        }
        usort($out, static fn (array $a, array $b): int => [$b['created'], $b['id']] <=> [$a['created'], $a['id']]);
        return $out;
    }

    /**
     * Pfad einer Sicherung aus der Liste (Herunterladen, Wiederherstellen), sonst null: nur Namen aus files(), nie
     * andere Dateien oder Ordner.
     */
    public function path(string $id): ?string
    {
        if ($this->kind($id) === null) {
            return null;
        }
        $path = $this->dir() . '/' . $id;
        return is_file($path) ? $path : null;
    }

    /**
     * Alle Daten durch eine Sicherung auf dem Server ersetzen.
     *
     * @throws ApiError
     */
    public function restore(mixed $id, bool $confirm): void
    {
        $backup = $this->app->backup ?? throw new ApiError(404, 'not_available', 'Die Datensicherung gibt es in dieser Installation nicht.');
        if (!$backup->canImport()) {
            throw new ApiError(404, 'not_available', 'Eine Sicherung lässt sich hier nicht wiederherstellen.');
        }
        if (!$confirm) {
            throw ApiError::invalid('Bitte das Ersetzen aller Daten bestätigen.');
        }
        $path = is_string($id) ? $this->path($id) : null;
        if ($path === null) {
            throw ApiError::notFound('Diese Sicherung gibt es auf dem Server nicht (mehr).');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            throw ApiError::invalid('Die Sicherung ließ sich auf dem Server nicht lesen.');
        }
        try {
            $backup->import($json);
        } catch (\RuntimeException $e) {
            throw $e instanceof ApiError ? $e : ApiError::invalid($e->getMessage());
        }
    }

    /**
     * Eine Sicherung auf dem Server löschen.
     *
     * @return array<string, mixed> status()
     * @throws ApiError
     */
    public function delete(mixed $id): array
    {
        $path = is_string($id) ? $this->path($id) : null;
        if ($path === null) {
            throw ApiError::notFound('Diese Sicherung gibt es auf dem Server nicht (mehr).');
        }
        if (!@unlink($path)) {
            throw ApiError::invalid('Die Sicherung ließ sich auf dem Server nicht löschen (Schreibrechte?).');
        }
        return $this->status();
    }

    /** 'weekly', 'update' oder null (keine Sicherung aus diesem Ordner) */
    private function kind(string $name): ?string
    {
        if (preg_match(self::WEEKLY, $name) === 1) {
            return 'weekly';
        }
        return preg_match(self::BEFORE_UPDATE, $name) === 1 && !str_contains($name, '..') ? 'update' : null;
    }

    /** Ältere wöchentliche Sicherungen über KEEP hinaus löschen (der Name beginnt mit Datum und Uhrzeit). */
    private function prune(): void
    {
        $weekly = array_values(array_filter(scandir($this->dir()) ?: [], static fn (string $n): bool => preg_match(self::WEEKLY, $n) === 1));
        rsort($weekly);
        foreach (array_slice($weekly, self::KEEP) as $old) {
            @unlink($this->dir() . '/' . $old);
        }
    }

    /** Geschützter Ordner der Sicherungen (wie UpdateService::backupBefore); legt ihn an. */
    private function dir(): string
    {
        return dirname($this->app->files->path('sicherungen/x', false));
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
