<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\InventoryService;
use KompanieApp\Core\Domain\UserNames;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Inventar (Issue #6): Rechte, Prüfung, Orte, Umlagern, genau ein Verlaufseintrag je Änderung, beide Datenbanken. */
final class InventoryServiceTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private ?\KompanieApp\Core\Storage\Sql\Connection $db = null;
    private int $admin;
    private int $m;
    private int $cand;
    private int $guest;
    private int $keeper;

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            TestApp::dropTables($this->db);
        }
    }

    /** @param array<string, bool> $features */
    private function setUpApp(array $features = ['inventory' => true], string $driver = 'sqlite'): void
    {
        $this->users = new FakeUsers();
        $off = array_fill_keys(['events', 'calendar', 'news', 'gallery', 'points', 'penalties', 'ranks', 'titles', 'attendance', 'documents', 'board', 'polls', 'birthdays', 'inventory'], false);
        $this->db = TestApp::connection($driver);
        $clock = new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('Europe/Berlin')));
        $this->app = TestApp::create($this->db, $this->users, [], null, null, null, $clock, $features + $off);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->cand = $this->users->add('anwaerter', TestApp::candidateRole($this->app));
        $this->guest = $this->users->add('gast', Roles::GUEST);
        if ($features['inventory'] ?? false) {
            // Standardrolle des Bereichs: „Inventar verwalten“ (member + manage_inventory)
            $this->keeper = $this->users->add('zeugwart', 'inventory');
        }
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    private function as(?int $user): self
    {
        $this->users->current = $user;
        return $this;
    }

    /** @return array<string, mixed> */
    private function create(array $body): array
    {
        $res = $this->call('POST', '/inventory', $body);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data;
    }

    /** @return list<array<string, mixed>> */
    private function log(?int $item = null): array
    {
        $res = $this->call('GET', $item === null ? '/inventory/log' : "/inventory/$item/log");
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        return json_decode((string) json_encode($res->data), true);
    }

    /** @return array<int, array<string, mixed>> */
    private function items(): array
    {
        return array_column($this->call('GET', '/inventory')->data, null, 'id');
    }

    public function testRightsPerRole(): void
    {
        $this->setUpApp();
        $this->assertSame(['member', 'manage_inventory'], array_column($this->app->roles()->describe(), 'capabilities', 'key')['inventory'] ?? null);
        $this->assertSame(401, $this->as(null)->call('GET', '/inventory')->status);
        foreach ([$this->m => 'Mitglied', $this->cand => 'Anwärter', $this->guest => 'Gast'] as $who => $label) {
            $this->as($who);
            foreach ([['GET', '/inventory'], ['GET', '/inventory/locations'], ['GET', '/inventory/log'], ['POST', '/inventory'], ['PATCH', '/inventory/1'], ['DELETE', '/inventory/1'], ['GET', '/inventory/1/log'], ['POST', '/inventory/1/move']] as [$method, $path]) {
                $this->assertSame(403, $this->call($method, $path, ['title' => 'x', 'location' => 'y'])->status, "$label: $method $path");
            }
        }
        $item = $this->as($this->keeper)->create(['title' => 'Bierbank', 'quantity' => 10, 'location' => 'Lager']);
        $this->assertSame(['title' => 'Bierbank', 'quantity' => 10, 'unit' => null, 'location' => 'Lager', 'created_by_name' => 'Zeugwart'], array_intersect_key($item, array_flip(['title', 'quantity', 'unit', 'location', 'created_by_name'])));
        $this->assertSame(200, $this->as($this->admin)->call('PATCH', "/inventory/{$item['id']}", ['quantity' => 9])->status, 'Admin hat das Recht ohnehin');
        // Eigene Rolle mit dem Recht
        $role = $this->app->roles()->create(['label' => 'Kammer', 'capabilities' => ['member', 'manage_inventory']]);
        $this->assertSame(200, $this->as($this->users->add('kammer', $role['key']))->call('GET', '/inventory')->status);
    }

    public function testFeatureOff(): void
    {
        $this->setUpApp(['inventory' => false]);
        foreach ([['GET', '/inventory'], ['GET', '/inventory/locations'], ['GET', '/inventory/log'], ['POST', '/inventory'], ['PATCH', '/inventory/1'], ['DELETE', '/inventory/1'], ['GET', '/inventory/1/log'], ['POST', '/inventory/1/move']] as [$method, $path]) {
            $res = $this->call($method, $path);
            $this->assertSame([403, 'feature_disabled'], [$res->status, $res->data['code']], "$method $path");
        }
        $this->assertArrayNotHasKey('inventory', array_column($this->app->roles()->describe(), 'key', 'key'), 'Standardrolle erst beim Einschalten');
    }

    public function testValidation(): void
    {
        $this->setUpApp();
        $cases = [
            'ohne Bezeichnung' => ['quantity' => 1, 'location' => 'Lager'],
            'leere Bezeichnung' => ['title' => '  ', 'quantity' => 1, 'location' => 'Lager'],
            'ohne Ort' => ['title' => 'Zelt', 'quantity' => 1],
            'negative Menge' => ['title' => 'Zelt', 'quantity' => -1, 'location' => 'Lager'],
            'negative Menge als Text' => ['title' => 'Zelt', 'quantity' => '-0,5', 'location' => 'Lager'],
            'keine Zahl' => ['title' => 'Zelt', 'quantity' => 'viele', 'location' => 'Lager'],
            'vier Nachkommastellen' => ['title' => 'Zelt', 'quantity' => '1,2345', 'location' => 'Lager'],
            'zu groß' => ['title' => 'Zelt', 'quantity' => '1000000000', 'location' => 'Lager'],
            'Bezeichnung zu lang' => ['title' => str_repeat('x', 101), 'quantity' => 1, 'location' => 'Lager'],
            'Einheit zu lang' => ['title' => 'Zelt', 'quantity' => 1, 'unit' => str_repeat('x', 21), 'location' => 'Lager'],
            'Grund zu lang' => ['title' => 'Zelt', 'quantity' => 1, 'location' => 'Lager', 'reason' => str_repeat('x', 256)],
        ];
        foreach ($cases as $name => $body) {
            $this->assertSame(400, $this->call('POST', '/inventory', $body)->status, $name);
        }
        $this->assertSame([], $this->log(), 'nichts angelegt, nichts im Verlauf');

        $item = $this->create(['title' => ' Kabeltrommel ', 'quantity' => '2,5', 'unit' => 'Stück', 'location' => 'Halle', 'category' => 'Technik', 'note' => "Zeile 1\nZeile 2"]);
        $this->assertSame(['title' => 'Kabeltrommel', 'quantity' => 2.5, 'unit' => 'Stück', 'location' => 'Halle', 'category' => 'Technik', 'note' => "Zeile 1\nZeile 2"], array_intersect_key($item, array_flip(['title', 'quantity', 'unit', 'location', 'category', 'note'])));
        $this->assertSame(0, $this->create(['title' => 'Leer', 'location' => 'Halle'])['quantity'], 'ohne Menge: 0');
        $this->assertSame(1.125, $this->create(['title' => 'Draht', 'quantity' => 1.125, 'unit' => 'km', 'location' => 'Halle'])['quantity']);
        foreach (['title' => '', 'location' => ' ', 'quantity' => -3] as $field => $value) {
            $this->assertSame(400, $this->call('PATCH', "/inventory/{$item['id']}", [$field => $value])->status, "PATCH $field");
        }
        $this->assertSame(400, $this->call('PATCH', "/inventory/{$item['id']}", ['adjust' => -3])->status, 'unter 0');
        $this->assertSame(400, $this->call('PATCH', "/inventory/{$item['id']}", ['quantity' => 1, 'adjust' => 1])->status, 'beides');
        $this->assertSame(404, $this->call('PATCH', '/inventory/999', ['quantity' => 1])->status);
        $this->assertSame(404, $this->call('GET', '/inventory/999/log')->status);
        $this->assertSame(2.5, $this->items()[$item['id']]['quantity'], 'unverändert');
    }

    public function testUpdateAdjustDeleteAndOneLogEntryPerChange(): void
    {
        $this->setUpApp();
        $item = $this->as($this->keeper)->create(['title' => 'Zelt', 'quantity' => 3, 'location' => 'Lager', 'reason' => 'Inventur']);
        $id = $item['id'];
        $this->assertCount(1, $this->log($id));
        $this->assertSame(['action' => 'create', 'reason' => 'Inventur', 'title' => 'Zelt', 'location' => 'Lager', 'user_name' => 'Zeugwart'], array_intersect_key($this->log($id)[0], array_flip(['action', 'reason', 'title', 'location', 'user_name'])));

        $this->assertSame(4, $this->call('PATCH', "/inventory/$id", ['adjust' => 1])->data['quantity']);
        $this->assertSame(3.5, $this->call('PATCH', "/inventory/$id", ['adjust' => '-0,5'])->data['quantity']);
        $res = $this->call('PATCH', "/inventory/$id", ['quantity' => 2, 'note' => 'Plane gerissen', 'reason' => 'Sturmschaden']);
        $this->assertSame([2, 'Plane gerissen'], [$res->data['quantity'], $res->data['note']]);
        $log = $this->log($id);
        $this->assertCount(4, $log, 'je Änderung genau ein Eintrag');
        $this->assertSame(['action' => 'update', 'changes' => ['note' => ['old' => '', 'new' => 'Plane gerissen'], 'quantity' => ['old' => 3.5, 'new' => 2]], 'reason' => 'Sturmschaden'], array_intersect_key($log[0], array_flip(['action', 'reason', 'changes'])));
        $this->assertSame(['quantity' => ['old' => 4, 'new' => 3.5]], $log[1]['changes']);

        // Ohne Änderung kein Eintrag
        $this->call('PATCH', "/inventory/$id", ['quantity' => '2,000', 'title' => 'Zelt', 'reason' => 'nichts']);
        $this->assertCount(4, $this->log($id));

        // Löschen: weich, Verlauf bleibt lesbar; danach 404
        $this->assertSame(200, $this->call('DELETE', "/inventory/$id", ['reason' => 'ausgesondert'])->status);
        $this->assertArrayNotHasKey($id, $this->items());
        $log = $this->log($id);
        $this->assertCount(5, $log);
        $this->assertSame(['action' => 'delete', 'changes' => ['quantity' => ['old' => 2, 'new' => null]], 'reason' => 'ausgesondert'], array_intersect_key($log[0], array_flip(['action', 'reason', 'changes'])));
        $this->assertSame(404, $this->call('DELETE', "/inventory/$id")->status);
        $this->assertSame(404, $this->call('PATCH', "/inventory/$id", ['quantity' => 1])->status);
        $this->assertCount(5, $this->log(), 'Gesamtverlauf');

        // Konto gelöscht: Verlauf bleibt, Name „gelöschtes Konto“
        $this->as($this->admin)->call('DELETE', "/members/{$this->keeper}");
        $this->assertSame(UserNames::DELETED, $this->log($id)[0]['user_name']);
        $this->assertCount(5, $this->log($id));
    }

    public function testLocationsAreCaseInsensitiveSuggestions(): void
    {
        $this->setUpApp();
        $this->create(['title' => 'Zelt', 'quantity' => 1, 'location' => 'Lager Nord', 'unit' => 'Stück', 'category' => 'Zelte']);
        $b = $this->create(['title' => 'Tisch', 'quantity' => 1, 'location' => 'lager nord', 'unit' => 'stück', 'category' => 'zelte']);
        $this->assertSame(['Lager Nord', 'Stück', 'Zelte'], [$b['location'], $b['unit'], $b['category']], 'Schreibweise des bekannten Werts');
        $this->create(['title' => 'Grill', 'quantity' => 1, 'location' => 'Halle']);
        $this->assertSame(['Halle', 'Lager Nord'], $this->call('GET', '/inventory/locations')->data);
        $this->assertSame('Halle', $this->call('PATCH', "/inventory/{$b['id']}", ['location' => 'HALLE'])->data['location']);
    }

    public function testMovePartialMergeFullAndTooMuch(): void
    {
        $this->setUpApp();
        $src = $this->create(['title' => 'Bierbank', 'quantity' => 10, 'unit' => 'Stück', 'location' => 'Lager']);
        $id = $src['id'];

        // Zu viel → 400, nichts geändert, kein Verlauf
        $before = $this->log();
        $res = $this->call('POST', "/inventory/$id/move", ['quantity' => 11, 'location' => 'Halle']);
        $this->assertSame(400, $res->status);
        $this->assertSame(400, $this->call('POST', "/inventory/$id/move", ['quantity' => 0, 'location' => 'Halle'])->status, 'Menge 0');
        $this->assertSame(400, $this->call('POST', "/inventory/$id/move", ['quantity' => 1, 'location' => 'lager'])->status, 'derselbe Ort');
        $this->assertSame(400, $this->call('POST', "/inventory/$id/move", ['quantity' => 1])->status, 'ohne Ziel');
        $this->assertSame($before, $this->log());
        $this->assertCount(1, $this->items());

        // Teilmenge ohne Ziel → neuer Eintrag
        $res = $this->call('POST', "/inventory/$id/move", ['quantity' => 4, 'location' => 'Halle', 'reason' => 'Fest']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(6, $res->data['source']['quantity']);
        $new = $res->data['target'];
        $this->assertSame(['title' => 'Bierbank', 'quantity' => 4, 'unit' => 'Stück', 'location' => 'Halle'], array_intersect_key($new, array_flip(['title', 'quantity', 'unit', 'location'])));
        $this->assertNotSame($id, $new['id']);
        $move = ['from' => 'Lager', 'to' => 'Halle', 'quantity' => 4, 'source_id' => $id, 'target_id' => $new['id']];
        $this->assertSame([['action' => 'move', 'changes' => ['quantity' => ['old' => 10, 'new' => 6], 'move' => $move], 'reason' => 'Fest']], array_map(static fn (array $e): array => array_intersect_key($e, array_flip(['action', 'reason', 'changes'])), array_slice($this->log($id), 0, 1)));
        $this->assertSame([['action' => 'move', 'changes' => ['quantity' => ['old' => null, 'new' => 4], 'move' => $move], 'location' => 'Halle']], array_map(static fn (array $e): array => array_intersect_key($e, array_flip(['action', 'location', 'changes'])), $this->log($new['id'])));
        $this->assertCount(3, $this->log(), 'je betroffenem Eintrag genau einer');

        // Teilmenge mit Ziel (gleiche Bezeichnung und Einheit, andere Schreibweise) → zusammengeführt
        $res = $this->call('POST', "/inventory/$id/move", ['quantity' => '2,5', 'location' => 'HALLE']);
        $this->assertSame([3.5, $new['id'], 6.5, 'Halle'], [$res->data['source']['quantity'], $res->data['target']['id'], $res->data['target']['quantity'], $res->data['target']['location']]);
        $this->assertCount(5, $this->log());
        $this->assertSame(['old' => 4, 'new' => 6.5], $this->log($new['id'])[0]['changes']['quantity']);

        // Andere Einheit → nicht zusammengeführt
        $kg = $this->create(['title' => 'bierbank', 'quantity' => 1, 'unit' => 'kg', 'location' => 'Keller']);
        $res = $this->call('POST', "/inventory/{$kg['id']}/move", ['quantity' => 1, 'location' => 'Halle']);
        $this->assertNotSame($new['id'], $res->data['target']['id']);
        $this->assertSame($kg['id'], $res->data['target']['id'], 'alles ohne Ziel: nur der Ort ändert sich');
        $this->assertNull($res->data['source']);
        $this->assertSame(['location' => ['old' => 'Keller', 'new' => 'Halle'], 'move' => ['from' => 'Keller', 'to' => 'Halle', 'quantity' => 1, 'source_id' => $kg['id'], 'target_id' => $kg['id']]], $this->log($kg['id'])[0]['changes']);
        $this->assertCount(2, $this->log($kg['id']), 'Anlegen und ein Umlagern');

        // Alles mit Ziel → Quelle verschwindet, Verlauf bleibt
        $res = $this->call('POST', "/inventory/$id/move", ['quantity' => 3.5, 'location' => 'Halle']);
        $this->assertNull($res->data['source']);
        $this->assertSame([$new['id'], 10], [$res->data['target']['id'], $res->data['target']['quantity']]);
        $this->assertArrayNotHasKey($id, $this->items());
        $last = $this->log($id)[0];
        $this->assertSame(['old' => 3.5, 'new' => 0], $last['changes']['quantity']);
        $this->assertTrue($last['changes']['move']['source_removed']);
        $this->assertSame(404, $this->call('POST', "/inventory/$id/move", ['quantity' => 1, 'location' => 'Lager'])->status);
        $this->assertSame(['Halle'], $this->call('GET', '/inventory/locations')->data);
    }

    public function testLogPagingAndBackup(): void
    {
        $this->setUpApp();
        $item = $this->create(['title' => 'Fahne', 'quantity' => 1, 'location' => 'Schrank']);
        for ($i = 2; $i <= 5; $i++) {
            $this->call('PATCH', "/inventory/{$item['id']}", ['quantity' => $i]);
        }
        $page = $this->log();
        $this->assertCount(5, $page);
        $first = $this->call('GET', '/inventory/log', [], ['limit' => '2'])->data;
        $this->assertCount(2, $first);
        $next = $this->call('GET', '/inventory/log', [], ['limit' => '2', 'before' => (string) $first[1]['id']])->data;
        $this->assertSame([$page[2]['id'], $page[3]['id']], array_column($next, 'id'));
        $this->assertSame(400, $this->call('GET', '/inventory/log', [], ['limit' => '0'])->status);
        $this->assertContains('inventory_items', Backup::CORE_TABLES);
        $this->assertContains('inventory_log', Backup::CORE_TABLES);
    }

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return getenv('KOMPANIE_TEST_MYSQL') ? ['sqlite' => ['sqlite'], 'mysql' => ['mysql']] : ['sqlite' => ['sqlite']];
    }

    #[DataProvider('drivers')]
    public function testDecimalsAndMoveOnEveryDatabase(string $driver): void
    {
        $this->setUpApp(['inventory' => true], $driver);
        $a = $this->create(['title' => 'Seil', 'quantity' => '12,345', 'unit' => 'm', 'location' => 'A']);
        $this->assertSame(12.345, $this->items()[$a['id']]['quantity']);
        $res = $this->call('POST', "/inventory/{$a['id']}/move", ['quantity' => '0,345', 'location' => 'B']);
        $this->assertSame([12, 0.345], [$res->data['source']['quantity'], $res->data['target']['quantity']]);
        $this->assertSame(400, $this->call('POST', "/inventory/{$a['id']}/move", ['quantity' => '12,001', 'location' => 'B'])->status);
        $this->assertSame(12, $this->items()[$a['id']]['quantity'], 'keine Teiländerung');
        $res = $this->call('POST', "/inventory/{$a['id']}/move", ['quantity' => 12, 'location' => 'b']);
        $this->assertSame(12.345, $res->data['target']['quantity']);
        $this->assertSame(InventoryService::MAX_MILLI, InventoryService::quantity('999999999,999', 'x'));
        $this->assertCount(5, $this->log());
    }
}
