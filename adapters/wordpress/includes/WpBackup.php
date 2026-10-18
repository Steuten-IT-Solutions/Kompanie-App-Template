<?php

declare(strict_types=1);

namespace KompanieApp\WordPress;

use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\GalleryRepository;
use KompanieApp\Core\Storage\Sql\Connection;
use KompanieApp\Core\Storage\Sql\Migrator;

/**
 * Datensicherung im WordPress-Plugin: Daten des Kerns und Einstellungen (Options mit Präfix).
 * Die Benutzer sind WordPress-Konten und gehören zur Sicherung von WordPress selbst; die Daten
 * verweisen auf deren IDs. Deshalb lässt sich eine Sicherung nur auf derselben Website
 * wiederherstellen (z. B. nach einem missglückten Update).
 */
final class WpBackup implements BackupProvider
{
    /** Gehören zur Installation, nicht zu den Daten. */
    private const SKIP_SETTINGS = [Migrator::SETTING, 'roles_version', 'update_state', 'update_last'];

    public function __construct(private readonly Connection $db, private readonly string $version, private readonly string $kompanie)
    {
    }

    /** @return array<string, mixed> */
    public function export(): array
    {
        $settings = [];
        foreach (self::settingNames() as $key) {
            $settings[$key] = get_option(WpSettings::PREFIX . $key);
        }
        return [
            'format' => Backup::FORMAT,
            'formatVersion' => Backup::FORMAT_VERSION,
            'created' => gmdate('c'),
            'variant' => 'wordpress',
            'version' => $this->version,
            'kompanie' => $this->kompanie,
            'site' => self::site(),
            'schema' => ['core' => (int) get_option(WpSettings::PREFIX . Migrator::SETTING, 0)],
            'tables' => (new Backup($this->db))->dump(Backup::CORE_TABLES),
            'settings' => $settings,
        ];
    }

    public function canImport(): bool
    {
        return true;
    }

    public function import(string $json): void
    {
        $data = Backup::parse($json);
        if (($data['variant'] ?? '') !== 'wordpress') {
            throw new \RuntimeException('Sicherungen der eigenständigen Version lassen sich (noch) nicht ins WordPress-Plugin übernehmen: Die Benutzer sind hier WordPress-Konten.');
        }
        if (($data['site'] ?? '') !== self::site()) {
            throw new \RuntimeException('Die Sicherung stammt von einer anderen Website. Die Daten verweisen auf deren WordPress-Benutzer und lassen sich hier nicht wiederherstellen.');
        }
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $this->db->transaction(function () use ($data, $settings): void {
            (new Backup($this->db))->restore($data['tables'], Backup::CORE_TABLES);
            // Bilddateien sind nicht in der Sicherung: alle neu aufbereiten.
            $this->db->execute('UPDATE {photos} SET status = ?', [GalleryRepository::PENDING]);
            $wp = new WpSettings();
            foreach ($settings as $key => $value) {
                if (is_string($key) && preg_match('/^[a-z0-9_]{1,100}$/', $key) && self::isData($key)) {
                    $wp->set($key, $value);
                }
            }
        });
    }

    /** @return string[] Namen der Einstellungen ohne Präfix */
    private static function settingNames(): array
    {
        global $wpdb;
        $names = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
            $wpdb->esc_like(WpSettings::PREFIX) . '%',
        ));
        $keys = array_map(static fn (string $n): string => substr($n, strlen(WpSettings::PREFIX)), $names);
        return array_values(array_filter($keys, static fn (string $k): bool => self::isData($k)));
    }

    /** Einstellung, die zu den Daten gehört (nicht zur Installation, kein Zähler von WpCache). */
    private static function isData(string $key): bool
    {
        return !in_array($key, self::SKIP_SETTINGS, true) && !str_starts_with($key, WpCache::COUNTER);
    }

    /** Website ohne Schema, damit ein Wechsel auf https die Sicherung nicht unbrauchbar macht. */
    private static function site(): string
    {
        return rtrim((string) preg_replace('#^https?://#i', '', home_url('/')), '/');
    }
}
