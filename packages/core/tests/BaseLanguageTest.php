<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Config\KompanieConfig;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\I18n\I18n;

use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Basissprache der Kompanie (kompanie.json „language“, beim Anlegen gewählt): Inhalte und eigene Namen stehen in ihr,
 * jede andere Sprache ist eine Übersetzung. Hier eine Kompanie mit Englisch als Basis und Deutsch als weiterer Sprache.
 */
final class BaseLanguageTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $clock = new FixedClock(new \DateTimeImmutable('2026-11-06 12:00:00', new \DateTimeZone('UTC')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, config: ['name' => 'Rifle Company', 'language' => 'en'], clock: $clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
    }

    protected function tearDown(): void
    {
        I18n::setBase(I18n::SOURCE_LOCALE);
    }

    /** @param array<string, mixed> $body */
    private function call(?int $user, string $method, string $path, array $body = [], ?string $locale = null): Response
    {
        $this->users->current = $user;
        $res = $this->app->api()->handle(new Request($method, $path, [], $body, [], '', null, $locale));
        I18n::setLocale(I18n::base());
        return $res;
    }

    /** @return array<string, mixed> */
    private function data(Response $res): array
    {
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        return json_decode((string) json_encode($res->data), true);
    }

    public function testConfigReadsTheLanguage(): void
    {
        $this->assertSame('en', (new KompanieConfig(['language' => 'en']))->language());
        $this->assertSame('de', (new KompanieConfig([]))->language(), 'ohne Angabe Deutsch');
        $this->assertSame('de', (new KompanieConfig(['language' => 'fr']))->language(), 'Unbekanntes gilt nicht');
        $this->assertSame('The app of Rifle Company', (new KompanieConfig(['name' => 'Rifle Company', 'language' => 'en']))->description());
    }

    public function testBaseIsTheDefaultAndAlwaysOn(): void
    {
        $this->assertSame('en', I18n::base());
        $config = $this->data($this->call(null, 'GET', '/config'));
        $this->assertSame('en', $config['base_language']);
        $this->assertSame(['en'], $config['languages']);

        // Deutsch als weitere Sprache; die Basis bleibt dabei und vorne
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de']);
        $this->assertSame(['en', 'de'], $this->app->languages()->enabled());
        $this->assertSame('en', $this->app->languages()->forRecipient($this->member), 'ohne Wahl: Basissprache');
        $this->assertSame('en', $this->app->languages()->resolve(null, null));
        $this->assertSame('de', $this->app->languages()->resolve('de', null));

        // Meldungen in der Sprache der Anfrage, ohne Angabe in der Basissprache
        $bad = ['locale' => 'en', 'items' => []];
        $this->assertSame('Translations exist only for additional languages, not for the base language.', $this->call($this->admin, 'PUT', '/admin/translations', $bad)->data['message'] ?? null);
        $this->assertSame('Übersetzungen gibt es nur für weitere Sprachen, nicht für die Basissprache.', $this->call($this->admin, 'PUT', '/admin/translations', $bad, 'de')->data['message'] ?? null);
    }

    public function testNamesAreTranslatedIntoGerman(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'en,de']);
        $save = fn (string $locale, string $text): Response => $this->call($this->admin, 'PUT', '/admin/translations', ['locale' => $locale, 'items' => [['kind' => 'role', 'ref' => Roles::MEMBER, 'text' => $text]]]);
        $this->assertSame(400, $save('en', 'Fellow')->status, 'keine Übersetzung in die Basissprache');
        $this->assertSame(200, $save('de', 'Kamerad')->status);

        $roles = fn (?string $locale): array => array_column($this->data($this->call(null, 'GET', '/config', [], $locale))['roles'], 'label', 'key');
        $this->assertSame('Kamerad', $roles('de')[Roles::MEMBER]);
        $this->assertSame('Gast', $roles('de')[Roles::GUEST], 'Vorgabe ohne Eintrag: deutsch');
        $this->assertSame('Member', $roles(null)[Roles::MEMBER], 'Basissprache: Vorgabe auf Englisch');

        $overview = $this->data($this->call($this->admin, 'GET', '/admin/translations', [], 'de'));
        $this->assertSame('en', $overview['source_locale']);
    }

    public function testContentIsTranslatedIntoGerman(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'en,de']);
        $event = ['title' => 'Shooting fair', 'start_date' => '2026-12-01', 'location' => 'Marquee'];
        $this->assertSame(400, $this->call($this->admin, 'POST', '/events', $event + ['translations' => ['en' => ['title' => 'Fair']]])->status, 'nicht in die Basissprache');
        $this->data($this->call($this->admin, 'POST', '/events', $event + ['translations' => ['de' => ['title' => 'Schützenfest']]]));

        $de = $this->data($this->call(null, 'GET', '/events', [], 'de'))[0];
        $this->assertSame(['Schützenfest', 'Marquee', 'some'], [$de['title'], $de['location'], $de['untranslated']]);
        $en = $this->data($this->call(null, 'GET', '/events'))[0];
        $this->assertSame('Shooting fair', $en['title']);
        $this->assertArrayNotHasKey('untranslated', $en);
    }
}
