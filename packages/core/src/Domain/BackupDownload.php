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
    public function forDelivery(?User $viewer, bool $encrypted = false): array
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
        if ($encrypted && !$this->app->backupEncryption()->configured()) {
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
}
