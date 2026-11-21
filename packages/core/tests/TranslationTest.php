<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Eigene Namen je weiterer Sprache (Plan „Mehrsprachigkeit“, Einrichten je Sprache): speichern, Rechte, /config. */
final class TranslationTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
    }

    protected function tearDown(): void
    {
        I18n::setLocale(I18n::SOURCE_LOCALE);
    }

    /** @param array<string, mixed> $body */
    private function call(?int $user, string $method, string $path, array $body = [], ?string $locale = null): Response
    {
        $this->users->current = $user;
        return $this->app->api()->handle(new Request($method, $path, [], $body, [], '', null, $locale));
    }

    /** @param list<array<string, string>> $items */
    private function save(?int $user, array $items, string $locale = 'en'): Response
    {
        return $this->call($user, 'PUT', '/admin/translations', ['locale' => $locale, 'items' => $items]);
    }

    public function testNamesPerLanguage(): void
    {
        $this->assertSame(400, $this->save($this->admin, [['kind' => 'nav', 'ref' => 'termine', 'text' => 'Dates']])->status, 'Englisch ist noch aus');
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $this->assertSame([], (array) $this->call(null, 'GET', '/config')->data['name_translations']);

        $this->assertSame(403, $this->save($this->m, [['kind' => 'nav', 'ref' => 'termine', 'text' => 'Dates']])->status);
        $this->assertSame(400, $this->save($this->admin, [['kind' => 'nav', 'ref' => 'termine', 'text' => 'Termine']], 'de')->status, 'Deutsch steht, wo es ist');
        $this->assertSame(400, $this->save($this->admin, [['kind' => 'gibt-es-nicht', 'ref' => 'x', 'text' => 'X']])->status);
        $this->assertSame(400, $this->save($this->admin, [['kind' => 'nav', 'ref' => 'termine', 'text' => str_repeat('x', 25)]])->status, 'zu lang');

        $res = $this->save($this->admin, [
            ['kind' => 'nav', 'ref' => 'termine', 'text' => '  Dates  '],
            ['kind' => 'role', 'ref' => Roles::MEMBER, 'text' => 'Fellow'],
        ]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['en' => ['nav' => ['termine' => ['label' => 'Dates']], 'role' => ['member' => ['label' => 'Fellow']]]], $res->data['translations']);

        $config = $this->call(null, 'GET', '/config')->data;
        $this->assertSame('Dates', $config['name_translations']['en']['nav']['termine']['label']);
        $roles = array_column($this->call(null, 'GET', '/config', [], 'en')->data['roles'], 'label', 'key');
        $this->assertSame('Fellow', $roles['member'], 'Rolle in der Sprache der Anfrage');
        $this->assertSame('Guest', $roles['guest'], 'ohne Eintrag die übersetzte Vorgabe');
        $roles = array_column($this->call(null, 'GET', '/config')->data['roles'], 'label', 'key');
        $this->assertSame('Mitglied', $roles['member'], 'auf Deutsch der deutsche Name');

        // Leerer Text löscht
        $res = $this->save($this->admin, [['kind' => 'role', 'ref' => Roles::MEMBER, 'text' => '']]);
        $this->assertSame(['en' => ['nav' => ['termine' => ['label' => 'Dates']]]], $res->data['translations']);
    }

    public function testOwnSectionNamesPerLanguage(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $nav = $this->call($this->admin, 'GET', '/admin/navigation')->data;
        $res = $this->call($this->admin, 'PUT', '/admin/navigation', ['navigation' => $nav['navigation'], 'names' => ['termine' => ['label' => 'Feste']]]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $config = $this->call(null, 'GET', '/config')->data;
        $this->assertSame('Feste', $config['section_labels']['events']);
        $this->assertSame('Feste', $config['section_labels_i18n']['en']['events'], 'ohne Eintrag bleibt der eigene deutsche Name');

        $this->save($this->admin, [['kind' => 'nav', 'ref' => 'termine', 'text' => 'Festivals']]);
        $config = $this->call(null, 'GET', '/config')->data;
        $this->assertSame('Feste', $config['section_labels']['events']);
        $this->assertSame('Festivals', $config['section_labels_i18n']['en']['events']);
        I18n::setLocale('en');
        $this->assertSame('Festivals', $this->app->config->sectionLabel('events'), 'auch in Meldungen des Servers');
        I18n::setLocale('de');
        $this->assertSame('Feste', $this->app->config->sectionLabel('events'));
    }
}
