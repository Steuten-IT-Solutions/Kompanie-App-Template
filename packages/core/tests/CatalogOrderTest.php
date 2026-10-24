<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Punktekatalog umsortieren (Issue #176): PUT /catalog/order, Prüfung, Rechte, Reihenfolge überall. */
final class CatalogOrderTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')));
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body));
    }

    /** @return array<string, int> Titel => ID */
    private function items(string ...$titles): array
    {
        $ids = [];
        foreach ($titles as $title) {
            $res = $this->call('POST', '/catalog', ['title' => $title, 'points' => 2]);
            $this->assertSame(201, $res->status, (string) json_encode($res->data));
            $ids[$title] = $res->data['id'];
        }
        return $ids;
    }

    public function testReorderAppliesEverywhereIncludingInactive(): void
    {
        // Neue Einträge kommen ans Ende, nicht alphabetisch.
        $c = $this->items('Schießen', 'Arbeitsdienst', 'Kompaniefest');
        $this->assertSame(['Schießen', 'Arbeitsdienst', 'Kompaniefest'], array_column($this->call('GET', '/catalog', [], ['all' => '1'])->data, 'title'));
        $this->call('PATCH', "/catalog/{$c['Arbeitsdienst']}", ['active' => false]);

        $res = $this->call('PUT', '/catalog/order', ['ids' => [$c['Kompaniefest'], $c['Arbeitsdienst'], $c['Schießen']]]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['Kompaniefest', 'Arbeitsdienst', 'Schießen'], array_column($res->data, 'title'), 'mit deaktivierten');
        $this->assertSame([10, 20, 30], array_column($res->data, 'sort'));
        $this->assertSame(['Kompaniefest', 'Arbeitsdienst', 'Schießen'], array_column($this->call('GET', '/catalog', [], ['all' => '1'])->data, 'title'), 'bleibt nach dem Neuladen');
        // Vorlagen beim Punkte vergeben: dieselbe Reihenfolge, nur aktive
        $this->users->current = $this->m;
        $this->assertSame(['Kompaniefest', 'Schießen'], array_column($this->call('GET', '/catalog')->data, 'title'));
        $this->users->current = $this->admin;
        // Ein neuer Eintrag kommt hinter die umsortierten.
        $this->items('Neu');
        $this->assertSame('Neu', array_column($this->call('GET', '/catalog', [], ['all' => '1'])->data, 'title')[3]);
    }

    public function testInvalidOrdersAndRights(): void
    {
        $c = $this->items('A', 'B');
        foreach ([
            'unvollständig' => [$c['A']],
            'doppelt' => [$c['A'], $c['A']],
            'fremde ID' => [$c['A'], $c['B'], 999],
            'keine Liste' => 'x',
            'keine Zahl' => [$c['A'], 'b'],
        ] as $why => $ids) {
            $this->assertSame(400, $this->call('PUT', '/catalog/order', ['ids' => $ids])->status, $why);
        }
        $this->assertSame(['A', 'B'], array_column($this->call('GET', '/catalog', [], ['all' => '1'])->data, 'title'), 'nichts geändert');

        $this->users->current = $this->m;
        $this->assertSame(403, $this->call('PUT', '/catalog/order', ['ids' => [$c['B'], $c['A']]])->status, 'ohne manage_points');
        $this->users->current = null;
        $this->assertSame(401, $this->call('PUT', '/catalog/order', ['ids' => [$c['B'], $c['A']]])->status);
        $this->users->current = $this->admin;
        $this->app->features()->update(['points' => false]);
        $this->assertSame(403, $this->call('PUT', '/catalog/order', ['ids' => [$c['B'], $c['A']]])->status, 'Bereich aus');
    }
}
