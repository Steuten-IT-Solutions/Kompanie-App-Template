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
        $this->assertArrayNotHasKey('max_main', $res->data, 'keine Grenze für Hauptpunkte (Issue #362)');
        $this->assertEquals(new \stdClass(), $res->data['names'], 'leer als Objekt');
        $this->assertEquals(new \stdClass(), $res->data['start']);
        $this->assertTrue($res->data['show_labels']);
        $this->assertEquals(new \stdClass(), $this->call('GET', '/config')->data['section_labels']);
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

    public function testAnyNumberOfMainItems(): void
    {
        $all = ['termine', 'news', 'galerie', 'kompanien', 'sponsoren', 'mitglieder', 'dokumente', 'brett', 'dienstgrade', 'punktekatalog'];
        $res = $this->save(['items' => $all]);
        $this->assertSame(200, $res->status, 'zehn Hauptpunkte gehen (Issue #362)');
        $this->assertSame($all, $res->data['navigation']['items']);
    }

    public function testNamesIconsStartAndLabels(): void
    {
        $res = $this->call('PUT', '/admin/navigation', [
            'navigation' => null,
            'names' => [
                'galerie' => ['label' => '  Fotos ', 'icon' => 'kamera'],
                'termine' => ['label' => 'Kalender'],
                'punktekatalog' => ['label' => 'Ordensliste'],
                'intern' => ['label' => 'Vorstand', 'icon' => 'fahne'],
                'news' => ['label' => '', 'icon' => ''],
            ],
            'start' => ['guest' => 'galerie', 'member' => 'meins'],
            'show_labels' => false,
        ]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(
            ['galerie' => ['label' => 'Fotos', 'icon' => 'kamera'], 'termine' => ['label' => 'Kalender'], 'punktekatalog' => ['label' => 'Ordensliste'], 'intern' => ['label' => 'Vorstand', 'icon' => 'fahne']],
            $res->data['names'],
            'leere Angaben fallen weg',
        );
        $this->assertSame(['guest' => 'galerie', 'member' => 'meins'], $res->data['start']);
        $this->assertFalse($res->data['show_labels']);

        // Der Name gilt überall, wo der Kern den Bereich nennt (KompanieConfig::sectionLabel).
        $this->assertSame('Fotos', $this->app->config->sectionLabel('gallery'));
        $this->assertSame('Kalender', $this->app->config->sectionLabel('events', 'Termine'));
        $this->assertSame('Ordensliste', $this->app->config->sectionLabel('catalog'));
        $this->assertSame('Vorstand', $this->app->config->sectionLabel('intern'));
        $this->assertSame('News', $this->app->config->sectionLabel('news'), 'ohne eigenen Namen der Standard');

        foreach ([$this->member, null] as $who) {
            $this->users->current = $who;
            $config = $this->call('GET', '/config')->data;
            $this->assertSame('Fotos', $config['section_labels']['gallery'] ?? null);
            $this->assertSame('Ordensliste', $config['section_labels']['catalog'] ?? null);
            $this->assertSame('kamera', $config['navigation_names']['galerie']['icon'] ?? null);
            $this->assertSame(['guest' => 'galerie', 'member' => 'meins'], $config['navigation_start']);
            $this->assertFalse($config['navigation_labels']);
        }

        // Standardnavigation wiederherstellen setzt alles zurück (so schickt es die App).
        $this->users->current = $this->admin;
        $res = $this->call('PUT', '/admin/navigation', ['navigation' => null, 'names' => [], 'start' => [], 'show_labels' => true]);
        $this->assertEquals(new \stdClass(), $res->data['names']);
        $this->assertEquals(new \stdClass(), $res->data['start']);
        $this->assertTrue($res->data['show_labels']);
        $this->assertSame('Galerie', $this->app->config->sectionLabel('gallery'));
        $this->assertSame('Punktekatalog', $this->app->config->sectionLabel('catalog'));
    }

    public function testMembersNameFollowsTheShownSection(): void
    {
        $this->call('PUT', '/admin/navigation', ['navigation' => null, 'names' => ['mitglieder' => ['label' => 'Unsere Leute']]]);
        $this->assertSame(['directory' => 'Unsere Leute'], $this->app->navigation()->sectionOverrides(true, true));
        $this->assertSame(['offices' => 'Unsere Leute'], $this->app->navigation()->sectionOverrides(false, true), 'nur Ämter: der Tab heißt wie die Ämter');
    }

    public function testRejectsInvalidNamesAndStart(): void
    {
        $cases = [
            'Name zu lang' => ['names' => ['galerie' => ['label' => str_repeat('x', 25)]]],
            'unbekannter Bereich' => ['names' => ['verwaltung' => ['label' => 'X']]],
            'unbekanntes Symbol' => ['names' => ['galerie' => ['icon' => 'unbekannt']]],
            'Namen als Liste' => ['names' => [['label' => 'X']]],
            'Startseite unbekannt' => ['start' => ['member' => 'verwaltung']],
            'Meins für Gäste' => ['start' => ['guest' => 'meins']],
            'Startseite ausgeblendet' => ['navigation' => ['items' => ['termine'], 'hidden' => ['news']], 'start' => ['guest' => 'news']],
            'Untermenü fehlt' => ['navigation' => ['items' => ['termine']], 'start' => ['guest' => 'menue/1']],
            'Intern mit eigener Navigation' => ['navigation' => ['items' => ['termine']], 'start' => ['guest' => 'intern']],
            'Schalter kein Wahrheitswert' => ['show_labels' => 'nein'],
        ];
        foreach ($cases as $name => $body) {
            $res = $this->call('PUT', '/admin/navigation', $body + ['navigation' => null]);
            $this->assertSame(400, $res->status, $name);
        }
        $this->assertSame([], $this->app->navigation()->names(), 'nichts gespeichert');

        $ok = $this->call('PUT', '/admin/navigation', [
            'navigation' => ['items' => ['termine', ['label' => 'Mehr', 'icon' => 'ordner', 'items' => ['news']]]],
            'start' => ['guest' => 'menue/1', 'member' => 'news'],
        ]);
        $this->assertSame(200, $ok->status, 'Untermenü und Bereich darin gehen');
    }

    public function testDeletedPageLosesNameAndStart(): void
    {
        $this->app->settings->set('navigation_names', ['seiten/7' => ['label' => 'Anfahrt'], 'galerie' => ['label' => 'Fotos']]);
        $this->app->settings->set('navigation_start', ['guest' => 'seiten/7', 'member' => 'galerie']);
        $this->app->navigation()->removeTarget('seiten/7');
        $this->assertSame(['galerie' => ['label' => 'Fotos']], $this->app->navigation()->names());
        $this->assertSame(['member' => 'galerie'], $this->app->navigation()->start());
    }
}
