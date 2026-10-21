<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Auth\User;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Ämter (Issue #112): Gruppen, Amtszeiten mit Jahren und mehreren Inhabern, Reihenfolge im Zeitstrahl, öffentliche
 * Ansicht, Rechte, Verknüpfung mit Titeln, gelöschte Konten und Import.
 */
final class OfficeServiceTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;
    private int $guest;

    /** @param array<string, bool> $features */
    private function setUpApp(array $features = []): void
    {
        $this->users = new FakeUsers();
        $all = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => false, 'titles' => true, 'directory' => true, 'offices' => true];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), $features + $all);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->guest = $this->users->add('gast', Roles::GUEST);
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

    private function office(string $title, ?int $group = null): int
    {
        return (int) $this->ok('POST', '/offices', ['title' => $title, 'group_id' => $group])['id'];
    }

    /** @param list<array<string, mixed>> $holders */
    private function term(int $office, int $start, ?int $end, array $holders, string $kind = 'period', string $note = ''): int
    {
        return (int) $this->ok('POST', "/offices/$office/terms", ['kind' => $kind, 'start_year' => $start, 'end_year' => $end, 'note' => $note, 'holders' => $holders])['id'];
    }

    /** @return array<string, mixed> Amt aus der öffentlichen Übersicht */
    private function overviewOffice(int $id, ?int $viewer): array
    {
        $this->users->current = $viewer;
        $res = $this->call('GET', '/offices');
        $this->users->current = $this->admin;
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        return array_column($res->data['offices'], null, 'id')[$id];
    }

    public function testAllCasesFromTheWebsiteAndOrder(): void
    {
        $this->setUpApp();
        $club = (int) $this->ok('POST', '/office-groups', ['title' => 'Verein'])['id'];
        $umbrella = (int) $this->ok('POST', '/office-groups', ['title' => 'Dachverband'])['id'];
        $chair = $this->office('Vorsitzende', $club);
        $flag = $this->office('Fahne', $club);
        $honor = $this->office('Ehrenmitglieder', $club);
        $ministers = $this->office('Minister', $umbrella);
        $honorUmbrella = $this->office('Ehrenmitglieder', $umbrella);
        $free = $this->office('Ohne Gruppe');

        // Zeitraum mit Ende, laufend, dieselbe Person mit Lücke
        $this->term($chair, 1973, 1976, [['name' => 'Anton Alt']]);
        $this->term($chair, 1979, 1980, [['name' => 'Anton Alt']]);
        $this->term($chair, 2020, null, [['user_id' => $this->m]]);
        $this->term($chair, 1977, 1978, [['name' => 'Berta Bauer']]);
        // mehrere gleichzeitig mit verschiedenem Beginn
        $this->term($flag, 2009, 2023, [['name' => 'Carl Clemens']]);
        $this->term($flag, 2006, null, [['name' => 'Dora Dorn']]);
        $this->term($flag, 2006, null, [['name' => 'Emil Eck']]);
        $this->term($flag, 2025, null, [['name' => 'Frida Fink']]);
        // einzelnes Jahr mit Zusatz
        $this->term($honor, 2008, null, [['name' => 'Gustav Gans']], 'point', 'Ehrenvorsitz');
        // mehrere Personen in einer Amtszeit, Zusatz
        $this->term($ministers, 2022, 2024, [['name' => 'Hans Hof'], ['user_id' => $this->m]], 'period', 'Unter Ida Igel');
        $this->term($honorUmbrella, 2025, null, [['name' => 'Gustav Gans']], 'point');

        $res = $this->call('GET', '/offices');
        $this->assertSame(200, $res->status);
        $this->assertSame([['id' => $club, 'title' => 'Verein'], ['id' => $umbrella, 'title' => 'Dachverband']], $res->data['groups']);
        $this->assertSame([$chair, $flag, $honor, $ministers, $honorUmbrella, $free], array_column($res->data['offices'], 'id'), 'nach Gruppen, ohne Gruppe zuletzt');

        $chairTerms = $this->overviewOffice($chair, null)['terms'];
        $this->assertSame([[2020, null, true], [1979, 1980, false], [1977, 1978, false], [1973, 1976, false]], array_map(static fn (array $t): array => [$t['start_year'], $t['end_year'], $t['current']], $chairTerms), 'laufende zuerst, dann nach Beginn absteigend');
        $flagTerms = array_map(static fn (array $t): string => $t['holders'][0]['name'], $this->overviewOffice($flag, null)['terms']);
        $this->assertSame(['Frida Fink', 'Dora Dorn', 'Emil Eck', 'Carl Clemens'], $flagTerms, 'gleicher Beginn in der Reihenfolge der Erfassung');

        $point = $this->overviewOffice($honor, null)['terms'][0];
        $this->assertSame(['point', 2008, null, 'Ehrenvorsitz', false], [$point['kind'], $point['start_year'], $point['end_year'], $point['note'], $point['current']]);
        $pair = $this->overviewOffice($ministers, null)['terms'][0];
        $this->assertSame(['Hans Hof', 'Mitglied'], array_column($pair['holders'], 'name'));
        $this->assertSame('Unter Ida Igel', $pair['note']);
    }

    public function testValidation(): void
    {
        $this->setUpApp();
        $o = $this->office('Kassierer');
        $bad = [
            ['start_year' => 1700, 'holders' => [['name' => 'A']]],
            ['start_year' => 2050, 'holders' => [['name' => 'A']]],
            ['start_year' => 2000, 'end_year' => 1999, 'holders' => [['name' => 'A']]],
            ['kind' => 'point', 'start_year' => 2000, 'end_year' => 2001, 'holders' => [['name' => 'A']]],
            ['kind' => 'sonstwas', 'start_year' => 2000, 'holders' => [['name' => 'A']]],
            ['start_year' => 2000, 'holders' => []],
            ['start_year' => 2000, 'holders' => [['name' => ' ']]],
            ['start_year' => 2000, 'holders' => [['user_id' => 999]]],
            ['start_year' => 2000, 'holders' => [['name' => 'A'], ['name' => 'a']]],
            ['start_year' => 2000, 'note' => str_repeat('x', 101), 'holders' => [['name' => 'A']]],
        ];
        foreach ($bad as $i => $body) {
            $this->assertSame(400, $this->call('POST', "/offices/$o/terms", $body)->status, "Fall $i");
        }
        $this->assertSame(404, $this->call('POST', '/offices/999/terms', ['start_year' => 2000, 'holders' => [['name' => 'A']]])->status);
        $this->assertSame(400, $this->call('POST', '/offices', ['title' => 'kassierer'])->status, 'doppelt in derselben Gruppe');
        $g = (int) $this->ok('POST', '/office-groups', ['title' => 'Dachverband'])['id'];
        $this->office('Kassierer', $g); // in einer anderen Gruppe erlaubt
        $this->assertSame(400, $this->call('POST', '/office-groups', ['title' => 'dachverband'])->status);
        $this->assertSame(400, $this->call('POST', '/offices', ['title' => 'X', 'group_id' => 999])->status);
        $this->assertSame(400, $this->call('DELETE', "/office-groups/$g")->status, 'nur leere Gruppen');

        // Ändern: fehlende Felder bleiben
        $t = $this->term($o, 2000, null, [['name' => 'Anna']]);
        $res = $this->ok('PATCH', "/office-terms/$t", ['end_year' => 2004]);
        $this->assertSame([2000, 2004, false, ['Anna']], [$res['start_year'], $res['end_year'], $res['current'], array_column($res['holders'], 'name')]);
        $this->assertSame(200, $this->call('DELETE', "/office-terms/$t")->status);
        $this->assertSame([], $this->overviewOffice($o, null)['terms']);
    }

    public function testOrderOfGroupsAndOffices(): void
    {
        $this->setUpApp();
        $a = (int) $this->ok('POST', '/office-groups', ['title' => 'A'])['id'];
        $b = (int) $this->ok('POST', '/office-groups', ['title' => 'B'])['id'];
        $x = $this->office('X', $a);
        $y = $this->office('Y', $b);
        $z = $this->office('Z', $a);
        $this->assertSame(['B', 'A'], array_column($this->ok('PUT', '/office-groups/order', ['ids' => [$b, $a]]), 'title'));
        $this->assertSame([$y, $x, $z], array_column($this->ok('GET', '/offices')['offices'], 'id'));
        $this->assertSame([$y, $z, $x], array_column($this->ok('PUT', '/offices/order', ['ids' => [$z, $y, $x]]), 'id'), 'innerhalb der Gruppe in der neuen Reihenfolge');
        $this->assertSame(400, $this->call('PUT', '/offices/order', ['ids' => [$z]])->status);
        $this->ok('PATCH', "/offices/$x", ['group_id' => null]);
        $this->assertSame([$y, $z, $x], array_column($this->ok('GET', '/offices')['offices'], 'id'));
        $this->assertSame(200, $this->call('DELETE', "/offices/$y")->status);
        $this->assertSame(200, $this->call('DELETE', "/office-groups/$b")->status);
    }

    public function testPublicReadOnlyWithRights(): void
    {
        $this->setUpApp();
        $o = $this->office('Kassierer');
        $this->term($o, 2020, null, [['user_id' => $this->m]]);
        $this->term($o, 2010, 2019, [['user_id' => $this->guest]]);

        // Öffentlich: Konto und Foto nur für Personen in der Mitgliederliste, Gäste nur mit Namen.
        $holders = array_map(static fn (array $t): array => $t['holders'][0], $this->overviewOffice($o, null)['terms']);
        $this->assertSame(['name' => 'Mitglied', 'user_id' => $this->m, 'photo_url' => null], $holders[0]);
        $this->assertSame(['name' => 'Gast'], $holders[1]);
        $this->assertArrayNotHasKey('title_id', $this->overviewOffice($o, null), 'Verknüpfung nur für die Pflege');
        // Wer Ämter verwaltet, sieht alle Konten.
        $this->assertSame($this->guest, $this->overviewOffice($o, $this->admin)['terms'][1]['holders'][0]['user_id']);
        // Ohne Mitgliederliste keine Konten mehr öffentlich.
        $this->app->features()->update(['directory' => false]);
        $this->assertSame(['name' => 'Mitglied'], $this->overviewOffice($o, $this->guest)['terms'][0]['holders'][0]);
        $this->assertSame(['name' => 'Mitglied', 'user_id' => $this->m], $this->overviewOffice($o, $this->m)['terms'][0]['holders'][0], 'das eigene Konto immer');

        // Pflege nur mit manage_offices
        $this->users->current = $this->m;
        $this->assertSame(403, $this->call('POST', '/offices', ['title' => 'X'])->status);
        $this->assertSame(403, $this->call('POST', "/offices/$o/terms", ['start_year' => 2000, 'holders' => [['name' => 'A']]])->status);
        $this->users->current = null;
        $this->assertSame(401, $this->call('POST', '/office-groups', ['title' => 'X'])->status);
        $role = (string) array_column($this->callAs($this->admin, 'GET', '/admin/roles')->data, 'key', 'label')['Ämter verwalten'];
        $keeper = $this->users->add('chronist', $role);
        $this->users->current = $keeper;
        $this->assertSame(201, $this->call('POST', '/offices', ['title' => 'Schriftführer'])->status, 'Standardrolle „Ämter verwalten“');

        $this->users->current = $this->admin;
        $this->app->features()->update(['offices' => false]);
        $this->users->current = null;
        $res = $this->call('GET', '/offices');
        $this->assertSame([403, 'feature_disabled'], [$res->status, $res->data['code'] ?? null]);
    }

    private function callAs(int $user, string $method, string $path): Response
    {
        $before = $this->users->current;
        $this->users->current = $user;
        try {
            return $this->call($method, $path);
        } finally {
            $this->users->current = $before;
        }
    }

    public function testLinkedTitleFollowsCurrentTerms(): void
    {
        $this->setUpApp();
        $title = (int) $this->ok('POST', '/titles', ['title' => 'Vorsitz'])['id'];
        $other = $this->users->add('zweiter', Roles::MEMBER);
        $o = $this->office('Vorsitzende');
        $old = $this->term($o, 2000, 2019, [['user_id' => $other]]);
        $cur = $this->term($o, 2020, null, [['user_id' => $this->m], ['user_id' => $this->guest]]);
        $this->ok('PUT', "/members/$other/titles", ['title_ids' => [$title]]);

        // Vorschau vor dem Verknüpfen: wer ihn bekommt, wer ihn verliert (der Gast nicht: nur Mitglieder)
        $preview = $this->ok('GET', "/offices/$o/title-preview", [], ['title_id' => (string) $title]);
        $this->assertSame(['add' => [['name' => 'Mitglied', 'title' => 'Vorsitz']], 'remove' => [['name' => 'Zweiter', 'title' => 'Vorsitz']]], $preview);
        $this->assertSame([], $this->app->titles()->of($this->m), 'die Vorschau ändert nichts');

        $res = $this->ok('PATCH', "/offices/$o", ['title_id' => $title]);
        $this->assertSame($title, $res['title_id']);
        $this->assertSame(['Vorsitz'], array_column($this->app->titles()->of($this->m), 'title'));
        $this->assertSame([], $this->app->titles()->of($other));
        $this->assertSame([], $this->app->titles()->of($this->guest));
        $this->assertSame(['id' => $o, 'title' => 'Vorsitzende'], array_column($this->ok('GET', '/titles'), 'office', 'id')[$title]);

        // Von Hand nicht mehr zu vergeben oder zu entziehen
        $this->assertSame(400, $this->call('PUT', "/members/$other/titles", ['title_ids' => [$title]])->status);
        $this->assertSame(400, $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => []])->status);
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => [$title]])->status, 'unverändert geht');
        // Ein Titel gehört zu höchstens einem Amt
        $second = $this->office('Stellvertretung');
        $this->assertSame(400, $this->call('PATCH', "/offices/$second", ['title_id' => $title])->status);

        // Amtszeit endet: Titel weg; neue laufende: Titel dazu
        $this->ok('PATCH', "/office-terms/$cur", ['end_year' => 2026]);
        $this->assertSame([], $this->app->titles()->of($this->m));
        $this->ok('PATCH', "/office-terms/$old", ['end_year' => null]);
        $this->assertSame(['Vorsitz'], array_column($this->app->titles()->of($other), 'title'));
        // Zweite laufende Amtszeit derselben Person: Ende der einen nimmt den Titel nicht
        $again = $this->term($o, 2026, null, [['user_id' => $other]]);
        $this->ok('PATCH', "/office-terms/$old", ['end_year' => 2025]);
        $this->assertSame(['Vorsitz'], array_column($this->app->titles()->of($other), 'title'));
        $this->assertSame(200, $this->call('DELETE', "/office-terms/$again")->status);
        $this->assertSame([], $this->app->titles()->of($other));
        // Ein einzelnes Jahr vergibt keinen Titel
        $this->term($o, 2026, null, [['user_id' => $other]], 'point');
        $this->assertSame([], $this->app->titles()->of($other));

        // Verknüpfung lösen: Titel bleiben, wie sie sind, und sind wieder von Hand zu vergeben
        $this->term($o, 2026, null, [['user_id' => $this->m]]);
        $this->ok('PATCH', "/offices/$o", ['title_id' => null]);
        $this->assertSame(['Vorsitz'], array_column($this->app->titles()->of($this->m), 'title'), 'Lösen entzieht nichts');
        $this->assertSame(200, $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => []])->status);
        // Amt löschen nimmt den verknüpften Titel
        $this->ok('PATCH', "/offices/$o", ['title_id' => $title]);
        $this->assertSame(['Vorsitz'], array_column($this->app->titles()->of($this->m), 'title'));
        $this->assertSame(200, $this->call('DELETE', "/offices/$o")->status);
        $this->assertSame([], $this->app->titles()->of($this->m));
        // Titel löschen löst die Verknüpfung
        $o2 = $this->office('Kasse');
        $t2 = (int) $this->ok('POST', '/titles', ['title' => 'Kasse'])['id'];
        $this->ok('PATCH', "/offices/$o2", ['title_id' => $t2]);
        $this->assertSame(200, $this->call('DELETE', "/titles/$t2")->status);
        $this->assertNull($this->overviewOffice($o2, $this->admin)['title_id']);
    }

    public function testLinkedTitleSendsNotification(): void
    {
        $this->setUpApp();
        $user = $this->users->find($this->m);
        $this->assertInstanceOf(User::class, $user);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $keys = ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode(random_bytes(16))];
        $this->app->push()->subscribe($user, 'https://fcm.googleapis.com/fcm/send/amt', $keys, ['titles'], null, '');
        $queued = fn (): int => $this->app->push()->repository()->counts()['queued'];
        $title = (int) $this->ok('POST', '/titles', ['title' => 'Vorsitz'])['id'];
        $o = $this->office('Vorsitzende');
        $this->ok('PATCH', "/offices/$o", ['title_id' => $title]);
        $this->term($o, 2020, null, [['user_id' => $this->m]]);
        $this->assertSame(1, $queued());
        $this->term($o, 2021, null, [['name' => 'Jemand']]);
        $this->assertSame(1, $queued(), 'unverändert: keine Nachricht');
    }

    public function testDeletedAccountKeepsTermsWithName(): void
    {
        $this->setUpApp();
        $title = (int) $this->ok('POST', '/titles', ['title' => 'Vorsitz'])['id'];
        $o = $this->office('Vorsitzende');
        $this->ok('PATCH', "/offices/$o", ['title_id' => $title]);
        $this->term($o, 2020, null, [['user_id' => $this->m]]);
        $this->assertSame(200, $this->call('DELETE', "/members/{$this->m}")->status);
        $holder = $this->overviewOffice($o, $this->admin)['terms'][0]['holders'][0];
        $this->assertSame(['name' => 'Mitglied'], $holder, 'Name bleibt, Konto nicht');
        $row = $this->app->db->selectOne('SELECT user_id, name FROM {office_holders}');
        $this->assertTrue(is_array($row) && array_key_exists('user_id', $row));
        $this->assertSame([null, 'Mitglied'], [$row['user_id'], $row['name']]);
        $this->assertSame(0, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {member_titles}'));
        foreach (['office_groups', 'offices', 'office_terms', 'office_holders'] as $table) {
            $this->assertContains($table, Backup::CORE_TABLES);
        }
    }

    public function testImport(): void
    {
        $this->setUpApp();
        $existing = (int) $this->ok('POST', '/office-groups', ['title' => 'Verein'])['id'];
        $csv = "gruppe;amt;beginn;ende;art;personen;zusatz\n"
            . "Verein;Vorsitzende;2020;heute;;Mitglied;\n"
            . "Verein;Vorsitzende;1973;1976;;Anton Alt;\n"
            . "Dachverband;Minister;2022;2024;;Hans Hof & Mitglied;Unter Ida Igel\n"
            . "Dachverband;Ehrenmitglieder;2025;;;Gustav Gans;\n"
            . "Verein;Ehrenmitglieder;2008;;zeitpunkt;Gustav Gans;Ehrenvorsitz\n"
            . ";Ohne Gruppe;1990;1995;zeitraum;Berta Bauer;\n";

        $preview = $this->ok('POST', '/offices/import', ['csv' => $csv]);
        $this->assertFalse($preview['applied']);
        $this->assertSame([1, 5, 6], [$preview['groups_new'], $preview['offices_new'], $preview['terms_new']]);
        $this->assertSame([2, 3, 4, 5, 6, 7], array_column($preview['rows'], 'line'));
        $this->assertSame([['name' => 'Mitglied', 'user_id' => $this->m]], $preview['rows'][0]['holders'], 'eindeutiger Name: mit dem Konto verknüpft');
        $this->assertSame(['period', null, 'point', 'point', 'period'], [$preview['rows'][0]['kind'], $preview['rows'][0]['end_year'], $preview['rows'][3]['kind'], $preview['rows'][4]['kind'], $preview['rows'][5]['kind']]);
        $this->assertSame([], $this->ok('GET', '/offices')['offices'], 'Vorschau ändert nichts');

        $done = $this->ok('POST', '/offices/import', ['csv' => $csv, 'apply' => true]);
        $this->assertTrue($done['applied']);
        $all = $this->ok('GET', '/offices');
        $this->assertSame(['Verein', 'Dachverband'], array_column($all['groups'], 'title'));
        $this->assertSame($existing, $all['groups'][0]['id'], 'vorhandene Gruppe wiederverwendet');
        $this->assertCount(5, $all['offices']);
        $this->assertSame(6, array_sum(array_map(static fn (array $o): int => count($o['terms']), $all['offices'])));

        // Noch einmal: alles schon da
        $again = $this->ok('POST', '/offices/import', ['csv' => $csv]);
        $this->assertSame([0, 0, 0], [$again['groups_new'], $again['offices_new'], $again['terms_new']]);
        $this->assertSame([true], array_values(array_unique(array_column($again['rows'], 'duplicate'))));

        // Fehler: Vorschau nennt die Zeile, Übernehmen übernimmt nichts
        $broken = "Verein;Kasse;2000;1990;;A;\nVerein;Kasse;abc;;;B;\nVerein;Kasse;2001;;;C;\n";
        $p = $this->ok('POST', '/offices/import', ['csv' => $broken]);
        $this->assertArrayHasKey('error', $p['rows'][0]);
        $this->assertArrayHasKey('error', $p['rows'][1]);
        $this->assertArrayNotHasKey('error', $p['rows'][2]);
        $res = $this->call('POST', '/offices/import', ['csv' => $broken, 'apply' => true]);
        $this->assertSame(400, $res->status);
        $this->assertStringStartsWith('Zeile 1: ', (string) $res->data['message']);
        $this->assertCount(5, $this->ok('GET', '/offices')['offices']);
        $this->assertSame(400, $this->call('POST', '/offices/import', ['csv' => ''])->status);
        // Ohne Verknüpfen: nur Namen
        $plain = $this->ok('POST', '/offices/import', ['csv' => "X;Y;2000;;;Mitglied;\n", 'link' => false]);
        $this->assertSame([['name' => 'Mitglied', 'user_id' => null]], $plain['rows'][0]['holders']);
    }
}
