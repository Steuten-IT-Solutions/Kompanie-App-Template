<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Einrichtbare Navigation (Issue #277): speichern, prüfen, zurücksetzen, für alle in /config. */
final class NavigationTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    private function save(mixed $navigation): Response
    {
        return $this->call('PUT', '/admin/navigation', ['navigation' => $navigation]);
    }

    public function testDefaultIsNull(): void
    {
        $this->assertNull($this->call('GET', '/config')->data['navigation'], 'ohne Einstellung die Standardnavigation');
        $res = $this->call('GET', '/admin/navigation');
        $this->assertSame(200, $res->status);
        $this->assertNull($res->data['navigation']);
        $this->assertContains('termine', $res->data['targets']);
        $this->assertSame(5, $res->data['max_main']);
    }

    public function testSaveAndResetVisibleForEveryone(): void
    {
        $nav = [
            'items' => ['termine', ['label' => '  Über <b>uns</b> ', 'icon' => 'stern', 'items' => ['mitglieder', 'dokumente']], 'galerie'],
            'hidden' => ['punktekatalog'],
        ];
        $res = $this->save($nav);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $expected = ['items' => ['termine', ['label' => 'Über uns', 'icon' => 'stern', 'items' => ['mitglieder', 'dokumente']], 'galerie'], 'hidden' => ['punktekatalog']];
        $this->assertSame($expected, $res->data['navigation'], 'Name als Klartext');

        foreach ([$this->member, null] as $who) {
            $this->users->current = $who;
            $this->assertSame($expected, $this->call('GET', '/config')->data['navigation'], 'auch für Mitglieder und Gäste');
        }

        $this->users->current = $this->admin;
        $this->assertNull($this->save(null)->data['navigation'], 'null stellt die Standardnavigation wieder her');
        $this->assertNull($this->call('GET', '/config')->data['navigation']);
    }

    public function testOnlyAdmins(): void
    {
        $this->users->current = $this->member;
        $this->assertSame(403, $this->call('GET', '/admin/navigation')->status);
        $this->assertSame(403, $this->save(['items' => ['termine']])->status);
        $this->users->current = null;
        $this->assertSame(401, $this->save(['items' => ['termine']])->status);
    }

    public function testRejectsInvalid(): void
    {
        $menu = static fn (array $items, string $label = 'Mehr', string $icon = 'ordner'): array => ['label' => $label, 'icon' => $icon, 'items' => $items];
        $cases = [
            'ohne Angabe' => null,
            'keine Liste' => ['items' => 'termine'],
            'unbekannter Bereich' => ['items' => ['verwaltung']],
            'doppelt' => ['items' => ['termine', $menu(['termine'])]],
            'doppelt mit ausgeblendet' => ['items' => ['news'], 'hidden' => ['news']],
            'zu viele Hauptpunkte' => ['items' => ['termine', 'news', 'galerie', 'mitglieder', 'dokumente', 'brett']],
            'Untermenü ohne Namen' => ['items' => [$menu(['termine'], ' ')]],
            'Name zu lang' => ['items' => [$menu(['termine'], str_repeat('x', 25))]],
            'leeres Untermenü' => ['items' => [$menu([])]],
            'verschachtelt' => ['items' => [$menu([$menu(['termine'])])]],
            'unbekanntes Symbol' => ['items' => [$menu(['termine'], 'Mehr', 'x')]],
        ];
        foreach ($cases as $name => $nav) {
            $res = $name === 'ohne Angabe' ? $this->call('PUT', '/admin/navigation') : $this->save($nav);
            $this->assertSame(400, $res->status, $name);
        }
        $this->assertNull($this->call('GET', '/config')->data['navigation'], 'nichts gespeichert');
    }

    public function testUnknownStoredTargetsAreDropped(): void
    {
        // z. B. nach dem Zurückrollen auf eine Version, die einen neueren Bereich nicht kennt
        $this->app->settings->set('navigation', ['items' => ['termine', 'spaeter', ['label' => 'Mehr', 'icon' => '?', 'items' => ['spaeter', 'news']]], 'hidden' => ['spaeter']]);
        $this->assertSame(
            ['items' => ['termine', ['label' => 'Mehr', 'icon' => 'ordner', 'items' => ['news']]], 'hidden' => []],
            $this->call('GET', '/config')->data['navigation'],
        );
    }
}
