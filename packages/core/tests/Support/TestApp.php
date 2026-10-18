<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\App;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Infra\ArrayCache;
use KompanieApp\Core\Infra\GdImageResizer;
use KompanieApp\Core\Infra\LocalFileStorage;
use KompanieApp\Core\Infra\NullNewsSource;
use KompanieApp\Core\Storage\Sql\Connection;
use KompanieApp\Core\Storage\Sql\PdoConnection;

/** Baut eine App für Tests: SQLite im Speicher oder MySQL über KOMPANIE_TEST_MYSQL. */
final class TestApp
{
    public static function connection(string $driver = 'sqlite'): Connection
    {
        if ($driver === 'mysql') {
            // Format: host;datenbank;benutzer;passwort
            [$host, $db, $user, $pass] = explode(';', (string) getenv('KOMPANIE_TEST_MYSQL')) + ['', '', '', ''];
            $conn = PdoConnection::mysql($host, $db, $user, $pass, 'kt' . bin2hex(random_bytes(3)) . '_');
            return $conn;
        }
        return new PdoConnection(new \PDO('sqlite::memory:'), 'kompanie_');
    }

    /** Entfernt die Tabellen einer MySQL-Testverbindung wieder. */
    public static function dropTables(Connection $db): void
    {
        if ($db->driver() !== 'mysql') {
            return;
        }
        foreach (['point_catalog', 'points', 'penalties', 'events', 'albums', 'photos'] as $t) {
            $db->execute('DROP TABLE IF EXISTS ' . $db->table($t));
        }
    }

    /**
     * @param array<string, mixed> $config Inhalt von kompanie.json
     */
    public static function create(
        Connection $db,
        FakeUsers $users = new FakeUsers(),
        array $config = [],
        ?FakeHttpClient $http = null,
        ?string $filesDir = null,
        ?MemorySettings $settings = null,
        ?FixedClock $clock = null,
    ): App {
        $filesDir ??= sys_get_temp_dir() . '/kompanie-test-' . bin2hex(random_bytes(4));
        $app = new App(
            db: $db,
            settings: $settings ?? new MemorySettings(),
            cache: new ArrayCache(),
            http: $http ?? new FakeHttpClient(),
            files: new LocalFileStorage($filesDir . '/public', 'https://example.org/media', $filesDir . '/private', ''),
            images: new GdImageResizer(),
            scheduler: new NullScheduler(),
            news: new NullNewsSource(),
            clock: $clock ?? new FixedClock(),
            env: new FakeEnvironment(),
            authContext: $users,
            authenticator: $users,
            users: $users,
            config: new KompanieConfig($config),
        );
        $app->upgrade();
        return $app;
    }
}
