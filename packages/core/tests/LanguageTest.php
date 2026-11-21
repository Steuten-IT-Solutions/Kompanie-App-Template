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

/**
 * Sprachen (Plan „Mehrsprachigkeit“): eingeschaltete Sprachen (Admin), Wahl am Konto, Sprache je Anfrage aus Header,
 * Konto oder Vorgabe, /config.
 */
final class LanguageTest extends TestCase
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

    public function testOnlyGermanUntilTheAdminSwitchesOnMore(): void
    {
        $config = $this->call(null, 'GET', '/config')->data;
        $this->assertSame(['de'], $config['languages']);
        $this->assertSame(['enabled' => ['de'], 'available' => ['de', 'en']], $this->call($this->admin, 'GET', '/admin/settings')->data['languages']);
        $this->assertSame(400, $this->call($this->m, 'PUT', '/me/locale', ['locale' => 'en'])->status, 'Englisch ist noch aus');

        $this->assertSame(403, $this->call($this->m, 'PATCH', '/admin/settings', ['languages' => 'de,en'])->status);
        $res = $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'en']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame(['de', 'en'], $res->data['languages']['enabled'], 'Deutsch bleibt immer an, zuerst');
        $this->assertSame(['de', 'en'], $this->call(null, 'GET', '/config')->data['languages']);
        $this->assertSame(400, $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,fr'])->status, 'unbekannte Sprache');
        $this->assertSame(['de'], $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de'])->data['languages']['enabled'], 'wieder aus');
    }

    public function testChoiceOnTheAccount(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $this->assertNull($this->call($this->m, 'GET', '/config')->data['user']['locale'], 'ohne Wahl null');
        $this->assertSame(401, $this->call(null, 'PUT', '/me/locale', ['locale' => 'en'])->status, 'nur angemeldet');
        $this->assertSame(400, $this->call($this->m, 'PUT', '/me/locale', ['locale' => 'fr'])->status);
        $this->assertSame(['locale' => 'en'], $this->call($this->m, 'PUT', '/me/locale', ['locale' => 'en'])->data);
        $this->assertSame('en', $this->call($this->m, 'GET', '/config')->data['user']['locale']);
        $this->assertNull($this->call($this->admin, 'GET', '/config')->data['user']['locale'], 'je Konto');
        $this->assertSame('en', $this->app->languages()->forRecipient($this->m));
        $this->assertSame('de', $this->app->languages()->forRecipient($this->admin));
        $this->assertSame(['locale' => null], $this->call($this->m, 'PUT', '/me/locale', ['locale' => null])->data, 'Wahl löschen');
        $this->assertNull($this->app->languages()->ofUser($this->m));
    }

    public function testLanguageOfARequest(): void
    {
        $languages = $this->app->languages();
        $member = $this->users->find($this->m);
        $this->assertSame('de', $languages->resolve('en', $member), 'Englisch aus: Deutsch, auch mit Header');
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $this->assertSame('en', $languages->resolve('en', null), 'Header der App');
        $this->assertSame('de', $languages->resolve(null, $member), 'ohne Header und ohne Wahl: Deutsch');
        $this->call($this->m, 'PUT', '/me/locale', ['locale' => 'en']);
        $this->assertSame('en', $languages->resolve(null, $member), 'ohne Header: Wahl am Konto');
        $this->assertSame('de', $languages->resolve('de', $member), 'Header geht vor');
        $this->assertSame('en', $languages->resolve('fr', $member), 'Unbekanntes zählt nicht');
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de']);
        $this->assertSame('de', $languages->forRecipient($this->m), 'ausgeschaltet: Deutsch für Mails und Push');
    }

    public function testMessagesFollowTheRequestAndTheLocaleIsRestored(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $res = $this->call($this->m, 'PUT', '/me/locale', ['locale' => 'fr'], 'en');
        $this->assertSame(400, $res->status);
        $this->assertSame('Unknown language.', $res->data['message']);
        $this->assertSame('de', I18n::locale(), 'nach der Anfrage wieder die vorige Sprache');
        $this->assertSame('Unbekannte Sprache.', $this->call($this->m, 'PUT', '/me/locale', ['locale' => 'fr'])->data['message']);
    }
}
