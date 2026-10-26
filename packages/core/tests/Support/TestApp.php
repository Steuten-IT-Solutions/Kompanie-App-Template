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
use KompanieApp\Core\Port\BackupProvider;
use KompanieApp\Core\Port\Mailer;
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
        foreach (['point_catalog', 'points', 'penalties', 'events', 'albums', 'photos', 'push_subscriptions', 'push_queue', 'ranks', 'member_ranks', 'rank_history', 'titles', 'member_titles', 'member_roles', 'attendance_lists', 'attendance_entries', 'doc_categories', 'documents', 'board_messages', 'polls', 'poll_options', 'poll_votes', 'poll_answers', 'birthdays', 'join_years', 'inventory_items', 'inventory_log', 'bug_reports', 'member_photos', 'office_groups', 'offices', 'office_terms', 'office_holders', 'former_members', 'lists', 'list_columns', 'list_rows', 'board_message_revisions', 'rich_images', 'user_signatures', 'user_settings', 'board_mails', 'activity', 'activity_last', 'activity_seen', 'activity_daily', 'member_contacts', 'mailings', 'mailing_recipients', 'attachments'] as $t) {
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
        ?BackupProvider $backup = null,
        ?Mailer $mailer = null,
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
            backup: $backup,
            packageConfig: $packageConfig,
            customMailer: $mailer,
        );
        $users->capabilities = static fn (string $role, int $id): array => $app->roles()->capabilitiesFor($id, $role);
        $app->upgrade();
        if ($features !== false) {
            $app->features()->update($features ?? array_fill_keys(Features::keys(), true));
        }
        return $app;
    }

    /**
     * Rechte, wie sie vor dem Aufteilen gemeint waren (member schloss das Sehen ein, Roles::expandLegacy), in der
     * Reihenfolge, in der sie gespeichert werden.
     *
     * @param string[] $legacy
     * @return string[]
     */
    public static function caps(array $legacy): array
    {
        $all = \KompanieApp\Core\Auth\Roles::expandLegacy($legacy);
        return array_values(array_filter(
            array_map(static fn (\KompanieApp\Core\Auth\Capability $c): string => $c->value, \KompanieApp\Core\Auth\Roles::GRANTABLE),
            static fn (string $v): bool => in_array($v, $all, true),
        ));
    }

    /**
     * Die Fähigkeiten zu caps().
     *
     * @param string[] $legacy
     * @return \KompanieApp\Core\Auth\Capability[]
     */
    public static function capabilities(array $legacy): array
    {
        return array_map(static fn (string $v): \KompanieApp\Core\Auth\Capability => \KompanieApp\Core\Auth\Capability::from($v), self::caps($legacy));
    }

    /**
     * Eigene Rolle „Anwärter“, wie ein Admin sie einrichtet (Issue #23): nur der interne Kalender, beim Anlegen von Konten
     * wählbar, zum Mitglied zu machen. Gibt ihren Schlüssel zurück (beim zweiten Aufruf denselben).
     */
    public static function candidateRole(App $app): string
    {
        foreach ($app->roles()->all() as $key => $r) {
            if ($r['label'] === 'Anwärter') {
                return $key;
            }
        }
        return $app->roles()->create(['label' => 'Anwärter', 'capabilities' => \KompanieApp\Core\Auth\Roles::CANDIDATE_DEFAULTS, 'new_accounts' => true, 'promotable' => true])['key'];
    }
}
