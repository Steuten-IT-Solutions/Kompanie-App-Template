<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\ListService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Listen (Issue #121): anlegen, speichern, kopieren, löschen, „Mitglieder aktualisieren“, Datenspalten nur mit Bereich
 * und Recht der ansehenden Person, gelöschte Konten und Rechte.
 */
final class ListServiceTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $anna;
    private int $bernd;
    private int $cand;
    private int $guest;
    /** Konto nur mit Mitgliederbereich und manage_lists */
    private int $lister;

    /** @param array<string, bool> $features */
    private function setUpApp(array $features = []): void
    {
        $this->users = new FakeUsers();
        $all = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => true, 'penalties' => true, 'ranks' => true, 'titles' => true, 'birthdays' => true, 'directory' => true, 'lists' => true];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-22 12:00:00')), $features + $all);
        $this->admin = $this->users->create('Ada', 'Zander', 'admin', 'admin@example.org', 'geheim123', Roles::ADMIN);
        $this->bernd = $this->users->create('Bernd', 'Özdemir', 'bernd', 'bernd@example.org', 'geheim123');
        $this->anna = $this->users->create('Anna', 'Müller', 'anna', 'anna@example.org', 'geheim123');
        $this->cand = $this->users->create('Carl', 'Anwärter', 'carl', 'carl@example.org', 'geheim123', Roles::CANDIDATE);
        $this->guest = $this->users->create('Gina', 'Gast', 'gina', 'gina@example.org', 'geheim123', Roles::GUEST);
        $this->lister = $this->users->create('Lena', 'Listig', 'lena', 'lena@example.org', 'geheim123');
        $lister = $this->lister;
        $this->users->capabilities = static fn (string $level, int $id): array => $id === $lister
            ? [...TestApp::capabilities(['member']), Capability::ManageLists]
            : Roles::templateCapabilities($level);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = [], array $query = []): array
    {
        $res = $this->call($method, $path, $body, $query);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    /** @return array<string, mixed> */
    private function as(?int $user, string $method, string $path, array $body = []): array
    {
        $this->users->current = $user;
        try {
            return $this->ok($method, $path, $body);
        } finally {
            $this->users->current = $this->admin;
        }
    }

    /**
     * Zeilen als kurze Bezeichnungen: Name des Mitglieds, „(gelöscht)“ oder „frei:<Name>“.
     *
     * @param array<string, mixed> $list
     * @return list<string>
     */
    private static function rows(array $list): array
    {
        return array_map(static fn (array $r): string => $r['kind'] === 'free' ? 'frei:' . $r['name'] : ($r['deleted'] ? '(gelöscht)' : $r['name']), $list['rows']);
    }

    /**
     * Zeilen zum Speichern, wie die App sie schickt.
     *
     * @param array<string, mixed> $list
     * @return list<array<string, mixed>>
     */
    private static function rowsIn(array $list): array
    {
        return array_map(static fn (array $r): array => $r['kind'] === 'free' ? ['kind' => 'free', 'name' => $r['name']] : ['kind' => 'member', 'user_id' => $r['user_id']], $list['rows']);
    }

    /**
     * @param array<string, mixed> $list
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function save(array $list, array $changes): array
    {
        $in = ['title' => $list['title'], 'subtitle' => $list['subtitle'], 'date' => $list['date'], 'orientation' => $list['orientation'], 'columns' => $list['columns'], 'rows' => self::rowsIn($list)];
        return $this->ok('PUT', '/lists/' . $list['id'], $changes + $in);
    }

    public function testCreateSaveCopyDelete(): void
    {
        $this->setUpApp();
        $list = $this->ok('POST', '/lists', ['title' => '  Ausflug   Xanten ']);
        $this->assertSame('Ausflug Xanten', $list['title']);
        $this->assertSame([null, null, 'portrait'], [$list['subtitle'], $list['date'], $list['orientation']]);
        $this->assertSame([], $list['rows'], 'neue Liste beginnt leer');
        $this->assertSame(
            [['field', 'last_name', 'Name', 'normal', false], ['field', 'first_name', '', 'normal', false], ['blank', null, 'Unterschrift', 'wide', false]],
            array_map(static fn (array $c): array => [$c['kind'], $c['field'], $c['label'], $c['width'], $c['checkbox']], $list['columns']),
        );
        $this->assertSame('Ada Zander', $list['created_by_name']);

        $saved = $this->save($list, [
            'subtitle' => 'Bitte bis Freitag',
            'date' => '2026-11-07',
            'orientation' => 'landscape',
            'columns' => [
                ['kind' => 'field', 'field' => 'name', 'label' => 'Wer'],
                ['kind' => 'blank', 'label' => 'Bezahlt', 'width' => 'narrow', 'checkbox' => true],
                ['kind' => 'field', 'field' => 'rank', 'checkbox' => true],
            ],
            'rows' => [['kind' => 'free', 'name' => 'Gast vom Nachbarverein'], ['kind' => 'free'], ['kind' => 'free', 'name' => '<b>x</b>']],
        ]);
        $this->assertSame(['Bitte bis Freitag', '2026-11-07', 'landscape'], [$saved['subtitle'], $saved['date'], $saved['orientation']]);
        $this->assertSame([true, false], [$saved['columns'][1]['checkbox'], $saved['columns'][2]['checkbox']], 'Kästchen nur in leeren Spalten');
        $this->assertSame(['frei:Gast vom Nachbarverein', 'frei:', 'frei:x'], self::rows($saved));

        $overview = $this->ok('GET', '/lists');
        $this->assertSame([[$list['id'], 'Ausflug Xanten', 3, 'Ada Zander']], array_map(static fn (array $l): array => [$l['id'], $l['title'], $l['rows'], $l['updated_by_name']], $overview));

        $copy = $this->ok('POST', "/lists/{$list['id']}/copy");
        $this->assertSame('Ausflug Xanten (Kopie)', $copy['title']);
        $this->assertSame(self::rows($saved), self::rows($copy));
        $this->assertSame($saved['columns'], $copy['columns']);
        $this->assertSame(['Ausflug Xanten (Kopie)', 'Ausflug Xanten'], array_column($this->ok('GET', '/lists'), 'title'), 'zuletzt geänderte zuerst');

        $this->assertSame(['deleted' => true], $this->ok('DELETE', "/lists/{$list['id']}"));
        $this->assertSame(404, $this->call('GET', "/lists/{$list['id']}")->status);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {list_rows} WHERE list_id = ?', [$list['id']]));
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {list_columns} WHERE list_id = ?', [$list['id']]));
        foreach (['lists', 'list_columns', 'list_rows'] as $table) {
            $this->assertContains($table, Backup::CORE_TABLES);
        }
    }

    public function testValidation(): void
    {
        $this->setUpApp();
        $list = $this->ok('POST', '/lists', ['title' => 'Liste']);
        $id = $list['id'];
        $base = ['title' => 'Liste', 'columns' => $list['columns'], 'rows' => []];
        $bad = [
            ['title' => ' '],
            ['date' => '2026-02-30'],
            ['orientation' => 'quer'],
            ['columns' => []],
            ['columns' => array_fill(0, 21, ['kind' => 'blank'])],
            ['columns' => [['kind' => 'field', 'field' => 'password']]],
            ['columns' => [['kind' => 'blank', 'width' => 'riesig']]],
            ['columns' => [['kind' => 'formula']]],
            ['rows' => array_fill(0, 501, ['kind' => 'free'])],
            ['rows' => [['kind' => 'header']]],
            ['rows' => [['kind' => 'member', 'user_id' => $this->anna], ['kind' => 'member', 'user_id' => $this->anna]]],
            // Mitglieder kommen nur über „Mitglieder aktualisieren“; Gäste, Anwärter und unbekannte Konten gar nicht.
            ['rows' => [['kind' => 'member', 'user_id' => $this->guest]]],
            ['rows' => [['kind' => 'member', 'user_id' => $this->cand]]],
            ['rows' => [['kind' => 'member', 'user_id' => 999]]],
            ['rows' => [['kind' => 'member', 'user_id' => 'x']]],
        ];
        foreach ($bad as $i => $change) {
            $this->assertSame(400, $this->call('PUT', "/lists/$id", $change + $base)->status, 'Fall ' . $i);
        }
        $this->assertSame(400, $this->call('POST', '/lists', [])->status);
        $long = $this->ok('PUT', "/lists/$id", ['title' => str_repeat('a', 200), 'subtitle' => str_repeat('b', 300), 'columns' => [['kind' => 'blank', 'label' => str_repeat('c', 99)]], 'rows' => [['kind' => 'free', 'name' => str_repeat('d', 150)]]] + $base);
        $this->assertSame([120, 200, 60, 100], [mb_strlen($long['title']), mb_strlen($long['subtitle']), mb_strlen($long['columns'][0]['label']), mb_strlen($long['rows'][0]['name'])]);
        $this->assertSame(404, $this->call('PUT', '/lists/999', $base)->status);
        $this->assertSame(404, $this->call('POST', '/lists/999/sync-members')->status);
    }

    public function testSyncMembers(): void
    {
        $this->setUpApp();
        $list = $this->ok('POST', '/lists', ['title' => 'Unterschriften', 'rows' => [['kind' => 'free', 'name' => 'Gast'], ['kind' => 'free']]]);
        $id = $list['id'];

        // Übersicht ohne Änderung; Mitglieder nach Nachname (Umlaute wie Grundbuchstabe), ohne Gäste und Anwärter
        $preview = $this->ok('POST', "/lists/$id/sync-members");
        $this->assertFalse($preview['applied']);
        $this->assertNull($preview['list']);
        $this->assertSame(['Lena Listig', 'Anna Müller', 'Bernd Özdemir', 'Ada Zander'], array_column($preview['added'], 'name'));
        $this->assertSame([], $preview['removed']);
        $this->assertSame(['frei:Gast', 'frei:'], self::rows($this->ok('GET', "/lists/$id")));

        // Ohne Mitgliedszeilen kommen sie an den Anfang, freie Zeilen bleiben.
        $done = $this->ok('POST', "/lists/$id/sync-members", ['apply' => true]);
        $this->assertTrue($done['applied']);
        $this->assertSame(['Lena Listig', 'Anna Müller', 'Bernd Özdemir', 'Ada Zander', 'frei:Gast', 'frei:'], self::rows($done['list']));

        // Zweiter Aufruf ändert nichts.
        $again = $this->ok('POST', "/lists/$id/sync-members", ['apply' => true]);
        $this->assertSame([[], [], false], [$again['added'], $again['removed'], $again['applied']]);

        // Umsortieren und eine Mitgliedszeile von Hand löschen; ein neues Mitglied, eins wird Gast, eins wird gelöscht.
        $list = $this->ok('GET', "/lists/$id");
        $rows = self::rowsIn($list);
        $rows = [$rows[4], $rows[3], $rows[1], $rows[0], $rows[5]]; // Gast, Zander, Müller, Listig, frei; ohne Özdemir
        $list = $this->save($list, ['rows' => $rows]);
        $this->assertSame(['frei:Gast', 'Ada Zander', 'Anna Müller', 'Lena Listig', 'frei:'], self::rows($list));
        $neu = $this->users->create('Nora', 'Neumann', 'nora', 'nora@example.org', 'geheim123');
        $this->users->setRole($this->anna, Roles::GUEST);
        $this->assertSame(200, $this->call('DELETE', "/members/{$this->lister}")->status);
        $this->assertSame(['frei:Gast', 'Ada Zander', 'Anna Müller', '(gelöscht)', 'frei:'], self::rows($this->ok('GET', "/lists/$id")));
        $this->assertNull($this->app->db->scalar('SELECT user_id FROM {list_rows} WHERE list_id = ? AND sort = 40', [$id]), 'Verknüpfung gelöst');

        $preview = $this->ok('POST', "/lists/$id/sync-members");
        $this->assertSame([[$neu, 'Nora Neumann'], [$this->bernd, 'Bernd Özdemir']], array_map(static fn (array $a): array => [$a['user_id'], $a['name']], $preview['added']));
        $this->assertSame([[$this->anna, 'Anna Müller'], [null, null]], array_map(static fn (array $r): array => [$r['user_id'], $r['name']], $preview['removed']));
        $done = $this->ok('POST', "/lists/$id/sync-members", ['apply' => true]);
        // Neumann und Özdemir kommen vor die erste Mitgliedszeile, die nach Namen später kommt (Zander).
        $this->assertSame(['frei:Gast', 'Nora Neumann', 'Bernd Özdemir', 'Ada Zander', 'frei:'], self::rows($done['list']));
    }

    public function testSortLikeTheApp(): void
    {
        $this->setUpApp();
        $this->users->create('Cem', 'Çelik', 'cem', 'cem@example.org', 'geheim123');
        $this->users->create('Max', 'Muster10', 'm10', 'm10@example.org', 'geheim123');
        $this->users->create('Max', 'Muster2', 'm2', 'm2@example.org', 'geheim123');
        $this->users->create('Kasse', '', 'kasse', 'kasse@example.org', 'geheim123');
        $id = $this->ok('POST', '/lists', ['title' => 'Alle'])['id'];
        // Akzente und Umlaute wie Grundbuchstaben, Zahlen nach Wert, ohne Nachnamen zählt der Vorname (wie memberSort.ts)
        $this->assertSame(
            ['Cem Çelik', 'Kasse', 'Lena Listig', 'Anna Müller', 'Max Muster2', 'Max Muster10', 'Bernd Özdemir', 'Ada Zander'],
            array_column($this->ok('POST', "/lists/$id/sync-members")['added'], 'name'),
        );
    }

    public function testConflictInsteadOfOverwriting(): void
    {
        $this->setUpApp();
        $list = $this->ok('POST', '/lists', ['title' => 'Liste']);
        // Geladen um 11 Uhr; um 12 Uhr aktualisiert jemand anderes die Liste.
        $this->app->db->execute("UPDATE {lists} SET updated_at = '2026-10-22 11:00:00'");
        $loaded = $this->ok('GET', "/lists/{$list['id']}")['updated_at'];
        $this->as($this->lister, 'POST', "/lists/{$list['id']}/sync-members", ['apply' => true]);
        $res = $this->call('PUT', "/lists/{$list['id']}", ['title' => 'Alt', 'columns' => $list['columns'], 'rows' => [], 'updated_at' => $loaded]);
        $this->assertSame([409, 'conflict'], [$res->status, $res->data['code'] ?? null]);
        $this->assertStringStartsWith('Lena Listig hat die Liste inzwischen geändert.', (string) ($res->data['message'] ?? ''));
        $this->assertNotSame([], $this->ok('GET', "/lists/{$list['id']}")['rows'], 'nichts überschrieben');
        $current = $this->ok('GET', "/lists/{$list['id']}");
        $this->assertSame('Neu', $this->ok('PUT', "/lists/{$list['id']}", ['title' => 'Neu', 'columns' => $current['columns'], 'rows' => [], 'updated_at' => $current['updated_at']])['title']);
    }

    public function testFieldsFollowFeaturesAndRights(): void
    {
        $this->setUpApp();
        $rank = (int) $this->ok('POST', '/ranks', ['title' => 'Gefreiter', 'min_points' => 0])['id'];
        $this->ok('PUT', "/members/{$this->anna}/rank", ['rank_id' => $rank]);
        $title = (int) $this->ok('POST', '/titles', ['title' => 'Ehrenmitglied'])['id'];
        $this->ok('PUT', "/members/{$this->anna}/titles", ['title_ids' => [$title]]);
        $this->ok('POST', '/points', ['user_ids' => [$this->anna], 'points' => 5, 'reason' => 'Fest']);
        $this->ok('POST', '/penalties', ['user_ids' => [$this->anna], 'amount_cents' => 250, 'reason' => 'Zu spät']);
        $this->ok('PUT', "/members/{$this->anna}/birthday", ['month' => 3, 'day' => 9, 'year' => 1990]);
        $this->ok('PUT', "/members/{$this->bernd}/birthday", ['month' => 4, 'day' => 1]);
        $this->users->current = $this->bernd;
        $this->ok('PUT', '/me/birthday', ['month' => 4, 'day' => 1, 'visible' => false]);
        $this->users->current = $this->admin;
        // Zusatzrolle (Issue #66): steht hinter der Grundrolle
        $this->ok('PATCH', "/members/{$this->anna}/role", ['extra_roles' => ['points']]);

        $columns = array_map(static fn (string $f): array => ['kind' => 'field', 'field' => $f], ['nr', 'name', 'first_name', 'last_name', 'role', 'rank', 'titles', 'birthday', 'points', 'penalties']);
        $list = $this->ok('POST', '/lists', ['title' => 'Alles', 'columns' => $columns]);
        $list = $this->ok('POST', "/lists/{$list['id']}/sync-members", ['apply' => true])['list'];
        $byId = array_column($list['rows'], null, 'user_id');
        $this->assertEquals((object) [
            'name' => 'Anna Müller', 'first_name' => 'Anna', 'last_name' => 'Müller', 'role' => 'Mitglied, Punkte verwalten', 'rank' => 'Gefreiter',
            'titles' => ['Ehrenmitglied'], 'birthday' => '09.03.', 'points' => 5, 'penalties' => 250,
        ], $byId[$this->anna]['values']);
        $this->assertNull($byId[$this->bernd]['values']->birthday, 'nicht freigegeben');
        $this->assertSame([], $byId[$this->bernd]['values']->titles);
        $this->assertSame(['Anna', 'Müller'], [$byId[$this->anna]['first_name'], $byId[$this->anna]['last_name']]);
        $this->assertSame(array_fill(0, 10, true), array_column($list['columns'], 'available'));

        // Nur manage_lists: keine Rollen, Punkte und Strafen; Dienstgrad und Titel stehen in der öffentlichen Mitgliederliste.
        $this->users->current = $this->lister;
        $mine = $this->ok('GET', "/lists/{$list['id']}");
        $this->assertSame(
            ['nr' => true, 'name' => true, 'first_name' => true, 'last_name' => true, 'role' => false, 'rank' => true, 'titles' => true, 'birthday' => true]
            + array_fill_keys(ListService::PERSONAL_FIELDS, false) + ['points' => false, 'penalties' => false],
            array_column($mine['fields'], 'available', 'key'),
        );
        $fields = array_column($mine['fields'], 'available', 'key');
        $this->assertSame(array_intersect_key($fields, array_column($mine['columns'], 'available', 'field')), array_column($mine['columns'], 'available', 'field'));
        $values = array_column($mine['rows'], null, 'user_id')[$this->anna]['values'];
        $this->assertEquals((object) ['name' => 'Anna Müller', 'first_name' => 'Anna', 'last_name' => 'Müller', 'rank' => 'Gefreiter', 'titles' => ['Ehrenmitglied'], 'birthday' => '09.03.'], $values);

        // Ohne öffentliche Mitgliederliste: Dienstgrad und Titel nur für die, die sie pflegen. Bereich aus: Spalte leer.
        $this->app->features()->update(['directory' => false, 'birthdays' => false]);
        $available = array_column($this->ok('GET', "/lists/{$list['id']}")['fields'], 'available', 'key');
        $this->assertSame([false, false, false], [$available['rank'], $available['titles'], $available['birthday']]);
        $this->users->current = $this->admin;
        $available = array_column($this->ok('GET', "/lists/{$list['id']}")['fields'], 'available', 'key');
        $this->assertSame([true, true, false], [$available['rank'], $available['titles'], $available['birthday']]);
    }

    /** Adresse (ganz und in Teilen), Handynummer und E-Mail: nur mit view_personal, mit einer festen Zahl von Abfragen. */
    public function testPersonalFields(): void
    {
        $this->setUpApp();
        $this->ok('PUT', "/members/{$this->anna}/personal", ['phone' => '+49 171 1234567', 'address' => ['street' => 'Musterstraße', 'house_number' => '1', 'extra' => 'c/o Muster', 'postal_code' => '12345', 'city' => 'Musterstadt', 'country' => 'DE']]);
        $this->ok('PUT', "/members/{$this->bernd}/personal", ['address' => ['street' => 'Hauptplatz', 'house_number' => '2', 'postal_code' => '1010', 'city' => 'Wien', 'country' => 'AT']]);
        $columns = array_map(static fn (string $f): array => ['kind' => 'field', 'field' => $f], ['name', ...ListService::PERSONAL_FIELDS]);
        $list = $this->ok('POST', '/lists', ['title' => 'Adressen', 'columns' => $columns]);
        $list = $this->ok('POST', "/lists/{$list['id']}/sync-members", ['apply' => true])['list'];
        $byId = array_column($list['rows'], null, 'user_id');
        $this->assertEquals((object) [
            'name' => 'Anna Müller', 'address' => 'Musterstraße 1, c/o Muster, 12345 Musterstadt', 'street' => 'Musterstraße 1', 'postal_code' => '12345',
            'city' => 'Musterstadt', 'country' => 'Deutschland', 'phone' => '+49 171 1234567', 'email' => 'anna@example.org',
        ], $byId[$this->anna]['values']);
        $this->assertSame(['Hauptplatz 2, 1010 Wien, Österreich', 'Österreich', null], [$byId[$this->bernd]['values']->address, $byId[$this->bernd]['values']->country, $byId[$this->bernd]['values']->phone], 'Land außerhalb Deutschlands auch in der ganzen Adresse');
        $this->assertNull($byId[$this->lister]['values']->address, 'ohne Adresse');

        // Nur manage_lists, ohne view_personal: Spalten bleiben, Werte nicht.
        $this->users->current = $this->lister;
        $mine = $this->ok('GET', "/lists/{$list['id']}");
        $this->assertSame([true, ...array_fill(0, count(ListService::PERSONAL_FIELDS), false)], array_column($mine['columns'], 'available'));
        $this->assertEquals((object) ['name' => 'Anna Müller'], array_column($mine['rows'], null, 'user_id')[$this->anna]['values']);
    }

    public function testRightsAndFeature(): void
    {
        $this->setUpApp();
        $id = $this->as($this->lister, 'POST', '/lists', ['title' => 'Meine'])['id'];
        $this->as($this->lister, 'POST', "/lists/$id/sync-members", ['apply' => true]);
        foreach ([$this->anna, $this->cand, $this->guest, null] as $user) {
            $this->users->current = $user;
            foreach ([['GET', '/lists'], ['POST', '/lists'], ['GET', "/lists/$id"], ['PUT', "/lists/$id"], ['DELETE', "/lists/$id"], ['POST', "/lists/$id/copy"], ['POST', "/lists/$id/sync-members"]] as [$method, $path]) {
                $this->assertSame($user === null ? 401 : 403, $this->call($method, $path, ['title' => 'x'])->status, "$method $path");
            }
        }
        $this->users->current = $this->admin;
        $this->app->features()->update(['lists' => false]);
        $res = $this->call('GET', '/lists');
        $this->assertSame([403, 'feature_disabled'], [$res->status, $res->data['code'] ?? null]);
    }
}
