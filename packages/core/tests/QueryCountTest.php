<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\Features;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Push\WebPushCrypto;
use KompanieApp\Core\Storage\Sql\PdoConnection;
use KompanieApp\Core\Tests\Support\CountingPdo;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Keine Abfragen je Mitglied (Issue #18, Muster aus der ersten Umsetzung vermeiden): Listen und Sammelbuchungen brauchen
 * mit 40 Mitgliedern nicht mehr Abfragen als mit 10. Gezählt werden SQL-Abfragen und Zugriffe auf das
 * Benutzerverzeichnis (bei den Adaptern ebenfalls Datenbankabfragen: PdoUsers bzw. WordPress-Benutzer).
 */
final class QueryCountTest extends TestCase
{
    private const LISTS = [
        'GET /members', 'GET /members?all=1', 'GET /members/{m}', 'GET /promotions', 'GET /ranks?all=1', 'GET /titles',
        'GET /attendance', 'GET /attendance/{list}', 'GET /birthdays', 'GET /inventory', 'GET /inventory/log',
        'GET /polls', 'GET /polls/{poll}', 'GET /board', 'GET /directory', 'GET /offices', 'GET /me', 'GET /me/rank', 'GET /me/points', 'GET /me/penalties',
    ];

    public function testListsDoNotGrowWithMembers(): void
    {
        $small = $this->measure(10);
        $large = $this->measure(40);
        foreach (self::LISTS as $label) {
            $this->assertSame($small[$label]['total'], $large[$label]['total'], "$label: gleich viele Abfragen mit 10 und 40 Mitgliedern");
            $this->assertLessThanOrEqual(15, $large[$label]['total'], "$label: feste, kleine Zahl von Abfragen");
        }
    }

    public function testBulkBookingsWriteOncePerPersonAndReadOnce(): void
    {
        $small = $this->measure(10);
        $large = $this->measure(40);
        foreach (['POST /points' => 'points', 'POST /penalties' => 'penalties'] as $label => $table) {
            // Je Person genau ein INSERT (in einer Transaktion); alles andere fest, auch die Benachrichtigungen.
            $this->assertSame(10, $small[$label]['inserts'][$table] ?? 0, $label);
            $this->assertSame(40, $large[$label]['inserts'][$table] ?? 0, $label);
            $this->assertSame(1, $large[$label]['inserts']['push_queue'] ?? 0, "$label: Warteschlange mit einem gesammelten INSERT");
            $this->assertSame($small[$label]['total'] - 10, $large[$label]['total'] - 40, "$label: sonst keine Abfrage je Person");
        }
        $this->assertSame($small['PUT /attendance']['total'], $large['PUT /attendance']['total'], 'Anwesenheit ändern: gesammelt geschrieben');
    }

    public function testMeDoesNotReadPointEntriesForTheRank(): void
    {
        $counts = $this->measure(5);
        $me = implode("\n", $counts['GET /me']['log']);
        $this->assertSame(1, substr_count($me, 'FROM kompanie_points'), '/me: Punkte einmal (Summe), nicht noch einmal für den Dienstgrad');
        $this->assertStringContainsString('member_ranks', $me, 'Dienstgrad steht trotzdem in /me');
    }

    /**
     * @return array<string, array{total: int, inserts: array<string, int>, log: list<string>}>
     */
    private function measure(int $n): array
    {
        $pdo = new CountingPdo('sqlite::memory:');
        $users = new FakeUsers();
        $http = new FakeHttpClient(static fn () => ['status' => 201, 'body' => '']);
        $app = TestApp::create(new PdoConnection($pdo, 'kompanie_'), $users, [], $http, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), array_fill_keys(Features::keys(), true) + ['calendar' => false, 'gallery' => false, 'news' => false]);
        $call = static function (string $method, string $path, array $body = [], array $query = []) use ($app): array {
            $res = $app->api()->handle(new Request($method, $path, $query, $body));
            self::assertLessThan(400, $res->status, "$method $path: " . json_encode($res->data));
            return $res->data;
        };
        $admin = $users->add('admin', Roles::ADMIN);
        $users->current = $admin;
        $ranks = [];
        foreach (['Rekrut' => null, 'Gefreiter' => 5, 'Unteroffizier' => 20] as $title => $min) {
            $ranks[] = $call('POST', '/ranks', ['title' => $title, 'min_points' => $min])['id'];
        }
        $titles = [$call('POST', '/titles', ['title' => 'Ehrenmitglied'])['id'], $call('POST', '/titles', ['title' => 'Vorsitz'])['id']];
        // Ämter (Issue #112): eine Amtszeit je Mitglied, mit einem Konto und einem freien Namen
        $group = $call('POST', '/office-groups', ['title' => 'Verein'])['id'];
        $office = $call('POST', '/offices', ['title' => 'Kassenprüfer', 'group_id' => $group])['id'];
        $members = [];
        for ($i = 0; $i < $n; $i++) {
            $members[] = $users->add('m' . $i, Roles::MEMBER);
        }
        $poll = $call('POST', '/polls', ['title' => 'Grillfest?', 'type' => 'single', 'options' => ['Ja', 'Nein'], 'anonymous' => false, 'results' => 'after_vote']);
        foreach ($members as $i => $id) {
            $users->current = $admin;
            $call('POST', '/points', ['user_ids' => [$id], 'points' => 5 + $i, 'reason' => 'Fest']);
            $call('POST', '/penalties', ['user_ids' => [$id], 'amount_cents' => 200, 'reason' => 'Zu spät']);
            $call('PUT', "/members/$id/rank", ['rank_id' => $ranks[$i % 2], 'reason' => 'x']);
            $call('PUT', "/members/$id/titles", ['title_ids' => [$titles[$i % 2]]]);
            // Zusatzrollen (Issue #66): für alle Benutzer einer Anfrage eine Abfrage
            $call('PATCH', "/members/$id/role", ['extra_roles' => $i % 2 === 0 ? ['events'] : ['events', 'documents']]);
            $call('PUT', "/members/$id/birthday", ['month' => 1 + $i % 12, 'day' => 1 + $i % 28]);
            $call('POST', '/inventory', ['title' => "Gegenstand $i", 'quantity' => 2, 'location' => 'Lager']);
            $call('POST', '/board', ['text' => "Hallo $i"]);
            $call('POST', "/offices/$office/terms", ['start_year' => 1950 + $i, 'end_year' => 1951 + $i, 'holders' => [['user_id' => $id], ['name' => "Früher $i"]]]);
            $users->current = $id;
            $call('PUT', "/polls/{$poll['id']}/vote", ['option_ids' => [$poll['options'][0]['id']]]);
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $this->assertNotFalse($key);
            $app->push()->subscribe($users->find($id), 'https://fcm.googleapis.com/fcm/send/d' . $i, ['p256dh' => WebPushCrypto::b64uEncode(WebPushCrypto::rawPublic($key)), 'auth' => WebPushCrypto::b64uEncode(random_bytes(16))], null, null, '');
        }
        $users->current = $admin;
        $entries = [];
        foreach ($members as $i => $id) {
            $entries[$id] = $i % 3 === 0 ? 'absent' : 'present';
        }
        $list = $call('PUT', '/attendance', ['source' => 'free', 'title' => 'Übung', 'date' => '2026-10-19', 'entries' => $entries]);

        $requests = [];
        foreach (self::LISTS as $label) {
            [$method, $path] = explode(' ', $label, 2);
            $requests[$label] = [$method, $path, []];
        }
        $requests['POST /points'] = ['POST', '/points', ['user_ids' => $members, 'points' => 1, 'reason' => 'Alle']];
        $requests['POST /penalties'] = ['POST', '/penalties', ['user_ids' => $members, 'amount_cents' => 100, 'reason' => 'Alle']];
        $requests['PUT /attendance'] = ['PUT', '/attendance', ['id' => $list['id'], 'entries' => array_map(static fn (string $s): string => $s === 'absent' ? 'excused' : 'absent', $entries)]];

        $out = [];
        foreach ($requests as $label => [$method, $path, $body]) {
            $path = strtr($path, ['{m}' => (string) $members[0], '{list}' => (string) $list['id'], '{poll}' => (string) $poll['id']]);
            [$path, $q] = explode('?', $path, 2) + [1 => ''];
            parse_str($q, $query);
            $users->current = $path === '/me' || str_starts_with($path, '/me/') ? $members[0] : $admin;
            $pdo->log = [];
            $users->lookups = 0;
            $call($method, $path, $body, $query);
            $inserts = [];
            foreach ($pdo->log as $sql) {
                if (preg_match('/^INSERT INTO kompanie_(\w+)/', $sql, $m)) {
                    $inserts[$m[1]] = ($inserts[$m[1]] ?? 0) + 1;
                }
            }
            $out[$label] = ['total' => count($pdo->log) + $users->lookups, 'inserts' => $inserts, 'log' => $pdo->log];
        }
        return $out;
    }
}
