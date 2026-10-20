<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests\Support;

use KompanieApp\Core\App;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Domain\Features;
use KompanieApp\Core\Infra\ArrayCache;
use KompanieApp\Core\Infra\GdImageResizer;
use KompanieApp\Core\Infra\LocalFileStorage;
use KompanieApp\Core\Infra\NullNewsSource;
use KompanieApp\Core\Port\NewsSource;
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
        foreach (['point_catalog', 'points', 'penalties', 'events', 'albums', 'photos', 'push_subscriptions', 'push_queue', 'ranks', 'member_ranks', 'rank_history', 'titles', 'member_titles', 'attendance_lists', 'attendance_entries', 'doc_categories', 'documents', 'board_messages'] as $t) {
            $db->execute('DROP TABLE IF EXISTS ' . $db->table($t));
        }
    }

    /**
     * @param array<string, mixed> $config Inhalt von kompanie.json
     * @param array<string, bool>|false|null $features Bereiche; null = alle an (wie nach der Ersteinrichtung), false = unverändert lassen
     */
    public static function create(
        Connection $db,
        FakeUsers $users = new FakeUsers(),
        array $config = [],
        ?FakeHttpClient $http = null,
        ?string $filesDir = null,
        ?MemorySettings $settings = null,
        ?FixedClock $clock = null,
        array|false|null $features = null,
        array $packageConfig = [],
        ?NullScheduler $scheduler = null,
        ?NewsSource $news = null,
    ): App {
        $filesDir ??= sys_get_temp_dir() . '/kompanie-test-' . bin2hex(random_bytes(4));
        $app = new App(
            db: $db,
            settings: $settings ?? new MemorySettings(),
            cache: new ArrayCache(),
            http: $http ?? new FakeHttpClient(),
            files: new LocalFileStorage($filesDir . '/public', 'https://example.org/media', $filesDir . '/private', ''),
            images: new GdImageResizer(),
            scheduler: $scheduler ?? new NullScheduler(),
            news: $news ?? new NullNewsSource(),
            clock: $clock ?? new FixedClock(),
            env: new FakeEnvironment(),
            authContext: $users,
            authenticator: $users,
            users: $users,
            config: new KompanieConfig($config),
            packageConfig: $packageConfig,
        );
        $users->capabilities = static fn (string $role): array => $app->roles()->capabilities($role);
        $app->upgrade();
        if ($features !== false) {
            $app->features()->update($features ?? array_fill_keys(Features::keys(), true));
        }
        return $app;
    }
}
