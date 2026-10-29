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
 * Mit $encrypted (Adresse mit ?verschluesselt=1) verschlüsselt mit dem Passwort für Sicherungen (BackupCrypto): Die
 * Sicherung geht erst in eine temporäre Datei, dann verschlüsselt Stück für Stück an den Browser.
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
        if ($encrypted) {
            // Erst ganz erzeugen, dann senden: Scheitert das Sichern, kommt ein Fehler statt einer abgeschnittenen Datei,
            // die der Browser als fertige Sicherung speichert. Große Sicherungen brauchen Zeit (wie beim Senden in den
            // Adaptern); bricht PHP trotzdem ab, löscht das Ende der Anfrage die Datei (exportToTempFile).
            // Nur verlängern, nie verkürzen (0 = ohne Grenze).
            $limit = (int) ini_get('max_execution_time');
            if ($limit > 0 && $limit < 300 && function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            try {
                $file = $this->app->backupEncryption()->exportToTempFile($backup);
            } catch (\Throwable $e) {
                // Der Browser zeigt bei einem Download nur „fehlgeschlagen“: der Grund steht im Fehlerprotokoll der App.
                $this->app->telemetry()->record(Code::ServerError, 'backup_download', 'Verschlüsselte Sicherung nicht erstellt: ' . $e->getMessage());
                return $deny(500);
            }
            return ['status' => 200, 'headers' => [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $name . '"',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
                'X-Robots-Tag' => 'noindex',
            ], 'write' => function ($out) use ($file, $now): void {
                try {
                    $in = fopen($file, 'rb');
                    if ($in === false) {
                        throw new \RuntimeException('Die verschlüsselte Sicherung ließ sich nicht lesen.');
                    }
                    try {
                        $copied = stream_copy_to_stream($in, $out);
                    } finally {
                        fclose($in);
                    }
                    $complete = $copied !== false && $copied === filesize($file);
                } finally {
                    @unlink($file);
                }
                // Nur eine ganz gesendete Sicherung zählt für die Ampel.
                if ($complete) {
                    $this->app->settings->set(StatusService::BACKUP_SETTING, $now);
                }
            }];
        }
        return ['status' => 200, 'headers' => [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ], 'write' => function ($out) use ($backup, $now): void {
            $backup->exportTo($out);
            // Erst nach dem Schreiben: Die Ampel zählt nur eine vollständig erzeugte Sicherung.
            $this->app->settings->set(StatusService::BACKUP_SETTING, $now);
        }];
    }
}
