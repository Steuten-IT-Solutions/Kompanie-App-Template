<?php

declare(strict_types=1);

namespace KompanieApp\Core\Domain;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Storage\BackupCrypto;
use KompanieApp\Core\Telemetry\Code;

/**
 * „Datensicherung herunterladen“ als Datei über Environment::backupUrl (<App>/sicherung): Die Sicherung wird Stück für
 * Stück an den Browser geschrieben (BackupProvider::exportTo), statt erst ganz im Arbeitsspeicher von Server und
 * Browser zu entstehen. Nur für Admins (manage_settings), nie in der Vorschau. GET /admin/backup bleibt für
 * Werkzeuge, die die Sicherung als JSON der API holen.
 *
 * Mit $encrypted (Adresse mit ?verschluesselt=1) verschlüsselt mit dem Passwort für Sicherungen (BackupCrypto), beim
 * Schreiben Stück für Stück.
 *
 * Mit $file (Adresse mit ?datei=<Name>) eine Sicherung, die schon auf dem Server liegt (ServerBackupService::files: die
 * wöchentlichen und die vor Updates), so wie sie dort liegt (unverschlüsselt).
 */
final class BackupDownload
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * Für die Auslieferung (beide Adapter gleich): Status, Header und bei 200 die Funktion, die die Sicherung in den
     * übergebenen Ausgabestrom schreibt.
     *
     * @return array{status: int, headers: array<string, string>, write?: \Closure(resource): void}
     */
    public function forDelivery(?User $viewer, bool $encrypted = false, ?string $file = null): array
    {
        $deny = static fn (int $status): array => ['status' => $status, 'headers' => ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']];
        $backup = $this->app->backup;
        if ($backup === null) {
            return $deny(404);
        }
        if ($viewer === null) {
            return $deny(401);
        }
        if (!$viewer->can(Capability::ManageSettings) || $viewer->preview) {
            return $deny(403);
        }
        if ($file !== null) {
            return $this->stored($file) ?? $deny(404);
        }
        // Verschlüsselt nur mit geprüftem Passwort: Ein vertipptes machte die Sicherung unbrauchbar (die Seite bietet es
        // vorher nicht an).
        if ($encrypted && (!$this->app->backupEncryption()->configured() || !$this->app->backupEncryption()->verified())) {
            return $deny(409);
        }
        $now = $this->app->time()->timestamp();
        $name = 'sicherung-' . $this->app->time()->format($now, 'Y-m-d') . ($encrypted ? BackupCrypto::EXTENSION : '.json');
        // Verschlüsselt ebenso Stück für Stück an den Browser (keine Zwischendatei, kein Warten vor dem ersten Byte, das ein
        // Proxy als Zeitüberschreitung abbricht). Bricht es ab, fehlt der Abschluss der Datei: Beim Wiederherstellen gilt
        // sie dann als unvollständig, nie als ganze Sicherung.
        return ['status' => 200, 'headers' => [
            'Content-Type' => $encrypted ? 'application/octet-stream' : 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ], 'write' => function ($out) use ($backup, $now, $encrypted): void {
            if ($encrypted) {
                try {
                    $this->app->backupEncryption()->exportEncryptedTo($backup, $out);
                } catch (\Throwable $e) {
                    // Der Browser zeigt bei einem Download nur „fehlgeschlagen“: der Grund steht im Fehlerprotokoll der App.
                    $this->app->telemetry()->record(Code::ServerError, 'backup_download', 'Verschlüsselte Sicherung nicht erstellt: ' . $e->getMessage());
                    throw $e;
                }
            } else {
                $backup->exportTo($out);
            }
            // Erst nach dem Schreiben: Die Ampel zählt nur eine vollständig erzeugte Sicherung.
            $this->app->settings->set(StatusService::BACKUP_SETTING, $now);
        }];
    }

    /**
     * Eine Sicherung vom Server (nur Namen aus der Liste), null wenn es sie nicht gibt.
     *
     * @return array{status: int, headers: array<string, string>, write: \Closure(resource): void}|null
     */
    private function stored(string $file): ?array
    {
        $path = $this->app->serverBackup()->path($file);
        if ($path === null) {
            return null;
        }
        // Stück für Stück wie die übrigen Sicherungen (die Adapter leeren dafür die Ausgabepuffer): Eine große Sicherung
        // soll nicht erst ganz im Arbeitsspeicher landen.
        return ['status' => 200, 'headers' => [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
            'Content-Length' => (string) (int) @filesize($path),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ], 'write' => static function ($out) use ($path): void {
            $in = @fopen($path, 'rb');
            if ($in === false) {
                throw new \RuntimeException('Die Sicherung ließ sich auf dem Server nicht lesen.');
            }
            try {
                stream_copy_to_stream($in, $out);
            } finally {
                fclose($in);
            }
        }];
    }
}
