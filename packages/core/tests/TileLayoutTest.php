<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Anordnung der Kacheln: persönlich speichern je Seite und Spaltenzahl, prüfen, zurücksetzen, /config, je Konto; Vorgabe für alle. */
final class TileLayoutTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;
    private int $guest;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->guest = $this->users->add('gast', Roles::GUEST);
    }

    /** @param array<string, mixed> $body */
    private function call(?int $user, string $method, string $path, array $body = [], array $query = []): Response
    {
        $this->users->current = $user;
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    /** @return array<string, mixed> */
    private function layouts(int $user): array
    {
        $res = $this->call($user, 'GET', '/config');
        $this->assertSame(200, $res->status);
        return json_decode((string) json_encode($res->data['tile_layouts']), true);
    }

    public function testSavePerPageAndColumnsAndReset(): void
    {
        $this->assertSame([], $this->layouts($this->m), 'ohne Anordnung leer');
        $tiles = [['id' => 'termine', 'col' => 2, 'row' => 3, 'span' => 1], ['id' => 'mitglieder', 'col' => 1, 'row' => 1, 'span' => 2]];
        $res = $this->call($this->m, 'PUT', '/me/tiles', ['page' => 'intern', 'cols' => 2, 'tiles' => $tiles]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $sorted = [$tiles[1], $tiles[0]];
        $this->assertSame(['page' => 'intern', 'layouts' => ['2' => $sorted]], json_decode((string) json_encode($res->data), true), 'nach Zeile und Spalte');
        $wide = [['id' => 'mitglieder', 'col' => 3, 'row' => 1, 'span' => 2]];
        $this->assertSame(200, $this->call($this->m, 'PUT', '/me/tiles', ['page' => 'intern', 'cols' => 4, 'tiles' => $wide])->status);
        $this->assertSame(200, $this->call($this->m, 'PUT', '/me/tiles', ['page' => 'einstellungen/betrieb', 'cols' => 3, 'tiles' => []])->status);
        $this->assertSame([
            'einstellungen/betrieb' => ['3' => []],
            'intern' => ['2' => $sorted, '4' => $wide],
        ], $this->layouts($this->m), 'andere Spaltenzahl kommt dazu');
        $this->assertSame([], $this->layouts($this->admin), 'je Konto');

        $res = $this->call($this->m, 'DELETE', '/me/tiles', [], ['page' => 'intern']);
        $this->assertSame(200, $res->status);
        $this->assertSame(['einstellungen/betrieb' => ['3' => []]], $this->layouts($this->m), 'Zurücksetzen: alle Spaltenzahlen der Seite');
    }

    public function testDefaultsForEveryoneSetByAdmin(): void
    {
        $config = fn (?int $user): array => json_decode((string) json_encode($this->call($user, 'GET', '/config')->data['tile_defaults']), true);
        $this->assertSame([], $config(null), 'ohne Vorgabe leer');
        $tiles = [['id' => 'mitglieder', 'col' => 1, 'row' => 1, 'span' => 2], ['id' => 'termine', 'col' => 2, 'row' => 2, 'span' => 1]];
        $body = ['page' => 'verwaltung', 'cols' => 2, 'tiles' => $tiles];
        $this->assertSame(403, $this->call($this->m, 'PUT', '/admin/tiles', $body)->status, 'nur wer Einstellungen ändern darf');
        $res = $this->call($this->admin, 'PUT', '/admin/tiles', $body);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['page' => 'verwaltung', 'layouts' => ['2' => $tiles]], json_decode((string) json_encode($res->data), true));
        $this->assertSame(200, $this->call($this->admin, 'PUT', '/admin/tiles', ['page' => 'intern', 'cols' => 3, 'tiles' => []])->status);
        $expected = ['intern' => ['3' => []], 'verwaltung' => ['2' => $tiles]];
        $this->assertSame($expected, $config($this->m), 'gilt für alle, auch ohne Anmeldung');
        $this->assertSame($expected, $config(null));
        $this->assertSame([], $this->layouts($this->admin), 'eigene Anordnung des Admins bleibt leer');
        $this->assertSame($expected, json_decode((string) json_encode($this->call($this->admin, 'GET', '/admin/tiles')->data['defaults']), true));
        $this->assertSame(400, $this->call($this->admin, 'PUT', '/admin/tiles', ['page' => 'verwaltung', 'cols' => 2, 'tiles' => [$tiles[0], $tiles[0]]])->status);

        $this->assertSame(403, $this->call($this->m, 'DELETE', '/admin/tiles', [], ['page' => 'verwaltung'])->status);
        $this->assertSame(200, $this->call($this->admin, 'DELETE', '/admin/tiles', [], ['page' => 'verwaltung'])->status);
        $this->assertSame(['intern' => ['3' => []]], $config($this->m));
        $this->assertSame(200, $this->call($this->admin, 'DELETE', '/admin/tiles', [], ['page' => 'intern'])->status);
        $this->assertSame([], $config($this->m));
    }

    public function testGuestAccountMayArrangeButNotAnonymous(): void
    {
        $body = ['page' => 'menue/2', 'cols' => 2, 'tiles' => [['id' => 'galerie', 'col' => 1, 'row' => 1, 'span' => 1]]];
        $this->assertSame(200, $this->call($this->guest, 'PUT', '/me/tiles', $body)->status, 'Konto mit Grundrolle Gast');
        $this->assertSame(401, $this->call(null, 'PUT', '/me/tiles', $body)->status);
        $this->assertNull($this->call(null, 'GET', '/config')->data['tile_layouts'], 'ohne Anmeldung');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        $t = ['id' => 'termine', 'col' => 1, 'row' => 1, 'span' => 1];
        yield 'unbekannte Seite' => [['page' => 'meins', 'cols' => 2, 'tiles' => [$t]]];
        yield 'Seite fehlt' => [['cols' => 2, 'tiles' => [$t]]];
        yield 'Spaltenzahl 5' => [['page' => 'intern', 'cols' => 5, 'tiles' => [$t]]];
        yield 'Spaltenzahl als Text' => [['page' => 'intern', 'cols' => '2', 'tiles' => [$t]]];
        yield 'Kacheln fehlen' => [['page' => 'intern', 'cols' => 2]];
        yield 'zu breit' => [['page' => 'intern', 'cols' => 2, 'tiles' => [['span' => 2, 'col' => 2] + $t]]];
        yield 'Spalte 0' => [['page' => 'intern', 'cols' => 2, 'tiles' => [['col' => 0] + $t]]];
        yield 'Zeile 0' => [['page' => 'intern', 'cols' => 2, 'tiles' => [['row' => 0] + $t]]];
        yield 'Zeile zu weit' => [['page' => 'intern', 'cols' => 2, 'tiles' => [['row' => 201] + $t]]];
        yield 'Kennung' => [['page' => 'intern', 'cols' => 2, 'tiles' => [['id' => '<b>'] + $t]]];
        yield 'doppelt' => [['page' => 'intern', 'cols' => 2, 'tiles' => [$t, ['row' => 2] + $t]]];
        yield 'übereinander' => [['page' => 'intern', 'cols' => 3, 'tiles' => [['span' => 2] + $t, ['id' => 'galerie', 'col' => 2] + $t]]];
        yield 'zu viele' => [['page' => 'intern', 'cols' => 2, 'tiles' => array_map(static fn (int $i): array => ['id' => "k$i", 'col' => 1, 'row' => $i, 'span' => 1], range(1, 101))]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalid')]
    public function testValidation(array $body): void
    {
        $this->assertSame(400, $this->call($this->m, 'PUT', '/me/tiles', $body)->status);
        $this->assertSame([], $this->layouts($this->m));
    }

    public function testResetNeedsKnownPage(): void
    {
        $this->assertSame(400, $this->call($this->m, 'DELETE', '/me/tiles', [], ['page' => 'x'])->status);
        $this->assertSame(400, $this->call($this->m, 'DELETE', '/me/tiles')->status);
    }

    public function testDamagedValueIsIgnored(): void
    {
        $this->app->db->insert('user_settings', ['user_id' => $this->m, 'name' => 'tiles:intern', 'value' => '{"2":[{"id":"a","col":2,"row":1,"span":2}],"3":[]}']);
        $this->app->db->insert('user_settings', ['user_id' => $this->m, 'name' => 'tiles:kaputt', 'value' => '{"2":[]}']);
        $this->assertSame(['intern' => ['3' => []]], $this->layouts($this->m));
    }

    public function testPurgedWithAccount(): void
    {
        $this->call($this->m, 'PUT', '/me/tiles', ['page' => 'verwaltung', 'cols' => 4, 'tiles' => []]);
        $this->assertSame(1, (int) $this->app->db->scalar('SELECT COUNT(*) FROM {user_settings} WHERE user_id = ?', [$this->m]));
        $this->assertArrayHasKey('user_settings', \KompanieApp\Core\Storage\UserData::tables());
    }
}
