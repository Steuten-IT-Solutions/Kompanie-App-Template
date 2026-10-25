<?php

declare(strict_types=1);

namespace KompanieApp\Standalone;

use KompanieApp\Core\App;
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\Core\Storage\Sql\Migrator;

/**
 * Datensicherung der eigenständigen Version: Daten des Kerns mit den Dateien der Dokumente und Abzeichen
 * (Backup::dumpFiles), Benutzer (mit Passwort-Hashes) und Einstellungen. Sitzungen, Zwischenspeicher und
 * Anmeldeversuche gehören nicht dazu; nach dem Wiederherstellen melden sich alle neu an.
 */
final class StandaloneBackup implements BackupProvider
{
    public const TABLES = [...Backup::CORE_TABLES, 'users'];
    /** Gehören zur Installation, nicht zu den Daten. */
    private const SKIP_SETTINGS = [...Backup::LOCAL_SETTINGS, Migrations::SETTING, PseudoScheduler::NEXT_SETTING, Kernel::CRON_RUNS_SETTING, Kernel::CRON_SPAWN_SETTING];

    public function __construct(private readonly Kernel $kernel)
    {
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        $app = $this->kernel->app();
        $tables = (new Backup($this->kernel->db()))->dump(self::TABLES);
        return [...$this->head(), 'tables' => $tables, 'files' => Backup::dumpFiles($app->files, $tables), ...$this->tail()];
    }

    public function exportTo($out): void
    {
        (new Backup($this->kernel->db()))->write($out, self::TABLES, $this->kernel->app()->files, $this->head(), $this->tail());
    }

    /** @return array<string, mixed> Felder vor tables */
    private function head(): array
    {
        $app = $this->kernel->app();
        return [
            'format' => Backup::FORMAT,
            'formatVersion' => Backup::FORMAT_VERSION,
            'created' => gmdate('c'),
            'variant' => 'standalone',
            'version' => $this->kernel->version(),
            'kompanie' => $app->config->name(),
            'schema' => [
                'core' => (int) $app->settings->get(Migrator::SETTING, 0),
                'standalone' => (int) $app->settings->get(Migrations::SETTING, 0),
            ],
        ];
    }

    /** @return array<string, mixed> Felder nach files */
    private function tail(): array
    {
        $settings = [];
        foreach ($this->kernel->db()->select('SELECT name, value FROM {settings} ORDER BY name') as $r) {
            if (!in_array($r['name'], self::SKIP_SETTINGS, true)) {
                $settings[(string) $r['name']] = json_decode((string) $r['value'], true);
            }
        }
        return ['settings' => $settings];
    }

    public function canImport(): bool
    {
        return true;
    }

    /** Ersetzt alle Daten durch die Sicherung. */
    public function import(string $json): void
    {
        $data = Backup::parse($json);
        if (($data['variant'] ?? '') !== 'standalone') {
            throw new \RuntimeException('Sicherungen aus dem WordPress-Plugin lassen sich (noch) nicht in die eigenständige Version übernehmen: Die Benutzer sind dort WordPress-Konten.');
        }
        if (empty($data['tables']['users']) || !in_array('admin', array_column($data['tables']['users'], 'level'), true)) {
            throw new \RuntimeException('Die Sicherung enthält keinen Admin; danach könnte sich niemand mehr anmelden.');
        }
        $files = Backup::parseFiles($data['files'] ?? null);
        $db = $this->kernel->db();
        $db->transaction(function () use ($db, $data): void {
            (new Backup($db))->restore($data['tables'], self::TABLES);
            foreach ((array) ($data['settings'] ?? []) as $name => $value) {
                if (is_string($name) && !in_array($name, self::SKIP_SETTINGS, true)) {
                    $db->execute('DELETE FROM {settings} WHERE name = ?', [$name]);
                    $db->insert('settings', ['name' => $name, 'value' => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                }
            }
            // Bilddateien sind nicht in der Sicherung: alle neu aufbereiten.
            $db->execute('UPDATE {photos} SET status = ?', [GalleryRepository::PENDING]);
            $db->execute('DELETE FROM {sessions}');
            $db->execute('DELETE FROM {cache}');
            // Andere Bereiche oder eine andere Galerie: beim nächsten Aufruf neu einplanen (App::upgrade).
            $db->execute('DELETE FROM {settings} WHERE name = ?', [App::PLAN_SETTING]);
        });
        Backup::restoreFiles($this->kernel->app()->files, $files);
    }
}
