<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Auth\Level;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Domain\Input;
use KompanieApp\Core\Domain\Passwords;
use KompanieApp\Core\Domain\Text;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Storage\Sql\Migrations;
use KompanieApp\Core\Storage\Sql\Migrator;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class CoreTest extends TestCase
{
    public function testTextAndInput(): void
    {
        $this->assertSame('Hallo Welt', Text::line("  <b>Hallo</b>\n  Welt\x07 "));
        $this->assertSame("Zeile 1\nZeile 2", Text::multiline("<i>Zeile 1</i>  \r\n Zeile 2 "));
        $this->assertSame('', Text::line(['x']));
        $this->assertSame('abc', Text::line('abcdef', 3));
        $this->assertSame('Zu spät <10 min', Text::line('Zu spät <10 min'), 'einzelnes < bleibt');
        $this->assertSame('a<b und c', Text::line('a<b und c'));
        $this->assertSame('x y', Text::line('x <!-- k --> <b>y</b>'));
        $this->assertSame('alert(1)', Text::line('<<b>script>alert(1)<</b>/script>'), 'verschachtelte Tags');
        $this->assertSame('x', Text::line('<<<i>i>i>x'));
        $this->assertSame('alert(1)', Text::line("<\x01script>alert(1)</\x01script>"), 'Steuerzeichen im Tag');
        $this->assertSame('a', Text::line("a<\x0Bsvg onload=alert(1)>"));
        $deep = str_repeat('<', 20000) . str_repeat('b>', 20000) . 'x';
        $t = microtime(true);
        $this->assertStringNotContainsString('<', Text::line($deep));
        $this->assertLessThan(1.0, microtime(true) - $t, 'tief verschachtelte Tags ohne quadratische Laufzeit');
        $this->assertTrue(Input::isDate('2024-02-29'));
        $this->assertFalse(Input::isDate('2026-02-30'));
        $this->assertSame(5, Input::integer('5'));
        $this->assertSame(-3, Input::integer(-3));
        $this->assertNull(Input::integer(1.5));
        $this->assertNull(Input::integer('1e3'));
        $this->assertSame('https://a.de/x', Input::url('https://a.de/x', 'f'));
        $this->assertSame('https://bürger-verein.de/schützenfest', Input::url('https://bürger-verein.de/schützenfest', 'f'));
        $this->assertTrue(Input::bool('true'));
        $this->assertFalse(Input::bool('false'));
        $this->expectException(\KompanieApp\Core\Http\ApiError::class);
        Input::url('ftp://a.de', 'f');
    }

    public function testLimitsFitEveryDatabase(): void
    {
        foreach (['sqlite', getenv('KOMPANIE_TEST_MYSQL') ? 'mysql' : null] as $driver) {
            if ($driver === null) {
                continue;
            }
            $db = TestApp::connection($driver);
            try {
                $users = new FakeUsers();
                $app = TestApp::create($db, $users);
                $users->current = $users->add('admin', Level::Admin);
                $res = $app->api()->handle(new Request('POST', '/catalog', [], ['title' => 'x', 'points' => 1, 'sort' => 10000000000]));
                $this->assertSame(201, $res->status, $driver);
                $this->assertSame(1000000, $res->data['sort']);
                $res = $app->api()->handle(new Request('POST', '/events', [], ['title' => 'x', 'start_date' => '2031-01-01', 'description' => str_repeat('ä', 40000), 'url' => 'https://example.org/schützenfest']));
                $this->assertSame(201, $res->status, $driver);
                $this->assertSame(10000, mb_strlen($res->data['description']));
                $res = $app->api()->handle(new Request('PATCH', '/events/' . $res->data['id'], [], ['location' => 'Halle']));
                $this->assertSame(200, $res->status, 'Termin mit Umlaut-Link bleibt änderbar');
                $res = $app->api()->handle(new Request('POST', '/points', [], ['user_ids' => [$users->add('m', Level::Member)], 'points' => 1, 'reason' => 'Zu spät <10 min']));
                $this->assertSame('Zu spät <10 min', $res->data[0]['reason']);
            } finally {
                TestApp::dropTables($db);
            }
        }
    }

    public function testPasswords(): void
    {
        $this->assertSame('max.mueller', Passwords::loginBase('Max Müller'));
        $this->assertSame('jose.garcia', Passwords::loginBase('José García'));
        $this->assertSame('mitglied', Passwords::loginBase('!!!'));
        $this->assertTrue(Passwords::validLogin('a.b_c-d@e'));
        $this->assertFalse(Passwords::validLogin('mit leer'));
        $pw = Passwords::readable(12);
        $this->assertSame(12, strlen($pw));
        $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $pw);
    }

    public function testConfigDefaultsAndLabels(): void
    {
        $c = new KompanieConfig(['name' => 'Musterkompanie', 'labels' => ['roles' => ['manager' => 'Spieß'], 'sections' => ['penalties' => 'Kasse']], 'features' => ['penalties' => false]]);
        $this->assertSame('Musterkompanie', $c->shortName());
        $this->assertSame('Spieß', $c->roleLabel('manager'));
        $this->assertSame('Verwalter', $c->roleLabel('organizer'));
        $this->assertSame('Mitglied', $c->roleLabel('member'));
        $this->assertSame('Kasse', $c->sectionLabel('penalties'));
        $this->assertFalse($c->legacyFeature('penalties'));
        $this->assertTrue($c->legacyFeature('gallery'));
        $this->assertSame('Kalender', $c->sectionLabel('calendar', 'Kalender'));
        $this->assertSame('Kompanie-App', (new KompanieConfig([]))->name());
        $this->assertSame('#2f5d8a', (new KompanieConfig(['colors' => ['primary' => 'rot']]))->primaryColor());
    }

    public function testDisabledFeatureHasNoRoutes(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users, features: ['penalties' => false, 'events' => false, 'points' => true, 'gallery' => true]);
        $users->current = $users->add('admin', Level::Admin);
        foreach ([['GET', '/me/penalties'], ['POST', '/penalties'], ['GET', '/events']] as [$m, $p]) {
            $res = $app->api()->handle(new Request($m, $p));
            $this->assertSame(403, $res->status, "$m $p");
            $this->assertSame('feature_disabled', $res->data['code']);
        }
        $this->assertSame(200, $app->api()->handle(new Request('GET', '/me/points'))->status);
        $config = $app->api()->handle(new Request('GET', '/config'))->data;
        $this->assertFalse($config['features']['penalties']);
        $this->assertFalse($config['features']['gallery'], 'Galerie ohne Einrichtung aus');
        $this->assertSame(405, $app->api()->handle(new Request('PUT', '/events'))->status);
    }

    public function testLoginLogoutAndAdmins(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $a = $users->add('chef', Level::Admin, 'richtig123');
        $b = $users->add('zweiter', Level::Admin);

        $res = $app->api()->handle(new Request('POST', '/auth/login', [], ['username' => 'chef', 'password' => 'falsch']));
        $this->assertSame(400, $res->status);
        $this->assertNull($users->current);
        $res = $app->api()->handle(new Request('POST', '/auth/login', [], ['username' => 'chef', 'password' => 'richtig123', 'remember' => 'false']));
        $this->assertSame(200, $res->status);
        $this->assertSame($a, $res->data['user']['id'], 'Antwort enthält die neue Konfiguration');
        $this->assertNotSame('', $res->data['csrf']['token']);

        $res = $app->api()->handle(new Request('PATCH', "/members/$b/role", [], ['level' => 'member']));
        $this->assertSame(200, $res->status, 'Zweiter Admin kann herabgestuft werden');
        $this->assertSame(1, $users->countAdmins());

        $res = $app->api()->handle(new Request('POST', '/auth/logout'));
        $this->assertSame(200, $res->status);
        $this->assertNull($users->current);
        $this->assertSame(401, $app->api()->handle(new Request('POST', '/auth/logout'))->status);
    }

    public function testPasswordChangeNotifiesAuthenticator(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $users->add('m', Level::Member, 'altes-passwort');
        $res = $app->api()->handle(new Request('POST', '/me/password', [], ['current_password' => 'altes-passwort', 'new_password' => ' mit-leer ']));
        $this->assertSame(400, $res->status, 'Leerzeichen am Rand');
        $res = $app->api()->handle(new Request('POST', '/me/password', [], ['current_password' => 'altes-passwort', 'new_password' => 'neues-passwort']));
        $this->assertSame(200, $res->status);
        $this->assertSame(1, $users->passwordChanges, 'Andere Sitzungen werden beendet');
    }

    public function testPasswordChangeAttemptsAreLimited(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $id = $users->add('m', Level::Member);
        $users->setPassword($id, 'richtig-123');
        $try = static fn (string $cur) => $app->api()->handle(new Request('POST', '/me/password', [], ['current_password' => $cur, 'new_password' => 'ganz-neu-456']))->status;
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(400, $try('falsch'));
        }
        $this->assertSame(429, $try('richtig-123'), 'nach 5 Fehlversuchen gesperrt, auch mit dem richtigen Passwort');
        $this->assertTrue($users->verifyPassword($id, 'richtig-123'));
    }

    public function testMigratorIsIdempotent(): void
    {
        $db = TestApp::connection();
        $settings = new MemorySettings();
        $m = new Migrator($db, $settings);
        $this->assertTrue($m->pending());
        $this->assertSame([1, 2], $m->migrate());
        $this->assertSame([], $m->migrate());
        $this->assertSame(Migrations::latest(), $m->current());
        $this->assertFalse($m->pending());
        // Bestehende Tabellen bleiben erhalten (IF NOT EXISTS), auch wenn die Version fehlt.
        $db->insert('events', ['title' => 'x', 'start_date' => '2030-01-01', 'location' => '', 'description' => '', 'url' => '', 'created_by' => 1, 'created_at' => '2030-01-01 00:00:00', 'updated_at' => '2030-01-01 00:00:00']);
        (new Migrator($db, new MemorySettings()))->migrate();
        $this->assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM {events}'));
        $this->assertContains('attempts', \KompanieApp\Core\Storage\Sql\Schema::columns($db, 'photos'), 'Spalte aus Migration 2 genau einmal');
        TestApp::dropTables($db);
    }
}
