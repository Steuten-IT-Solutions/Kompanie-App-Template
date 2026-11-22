<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\I18n\I18n;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Eigene Namen je Sprache für Ämter und Gruppen der Ämter (Einrichten je Sprache): Texte des Servers in der Sprache der
 * Anfrage, gespeichert bleibt der deutsche Name; Löschen nimmt die Übersetzungen mit.
 */
final class OfficeTranslationTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $all = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => false, 'titles' => true, 'directory' => true, 'offices' => true];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, null, null, new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00')), $all);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
        $this->ok('PATCH', '/admin/settings', ['languages' => 'de,en']);
    }

    protected function tearDown(): void
    {
        I18n::setLocale(I18n::SOURCE_LOCALE);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    private function call(string $method, string $path, array $body = [], array $query = [], ?string $locale = null): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body, [], '', null, $locale));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function ok(string $method, string $path, array $body = [], array $query = [], ?string $locale = null): array
    {
        $res = $this->call($method, $path, $body, $query, $locale);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    /** @param list<array<string, string>> $items */
    private function translate(array $items): void
    {
        $this->ok('PUT', '/admin/translations', ['locale' => 'en', 'items' => $items]);
    }

    /** @return array<string, array<string, array<string, string>>> */
    private function english(): array
    {
        return (array) ($this->ok('GET', '/config')['name_translations']['en'] ?? []);
    }

    public function testServerTextsUseTheNameOfTheLanguage(): void
    {
        $group = (int) $this->ok('POST', '/office-groups', ['title' => 'Vorstand'])['id'];
        $office = (int) $this->ok('POST', '/offices', ['title' => 'Hauptmann', 'group_id' => $group])['id'];
        $this->ok('POST', "/offices/$office/terms", ['kind' => 'period', 'start_year' => 2020, 'holders' => [['user_id' => $this->m]]]);
        $this->translate([
            ['kind' => 'office', 'ref' => (string) $office, 'text' => 'Captain'],
            ['kind' => 'office_group', 'ref' => (string) $group, 'text' => 'Board'],
        ]);
        $this->assertSame('Captain', $this->english()['office'][(string) $office]['label']);
        $this->assertSame('Board', $this->english()['office_group'][(string) $group]['label']);
        $this->assertSame('Hauptmann', $this->ok('GET', '/offices')['offices'][0]['title'], 'gespeichert bleibt der deutsche Name');

        // Vorschau „Als ehemalig eintragen“
        $preview = $this->ok('GET', "/members/{$this->m}/former/offices", [], ['left_on' => '2026'], 'en');
        $this->assertSame('Captain', $preview['terms'][0]['office']);
        $preview = $this->ok('GET', "/members/{$this->m}/former/offices", [], ['left_on' => '2026'], 'de');
        $this->assertSame('Hauptmann', $preview['terms'][0]['office']);

        // Bericht (Tabelle der Amtszeiten)
        $report = $this->ok('GET', '/admin/report', [], ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'offices'], 'en');
        $this->assertSame('Captain', $report['sections'][0]['tables'][0]['rows'][0][0]);
        $report = $this->ok('GET', '/admin/report', [], ['from' => '2026-01-01', 'to' => '2026-12-31', 'sections' => 'offices']);
        $this->assertSame('Hauptmann', $report['sections'][0]['tables'][0]['rows'][0][0]);

        // Meldung zu Titeln, die ein Amt vergibt
        $title = (int) $this->ok('POST', '/titles', ['title' => 'Kapitän'])['id'];
        $this->ok('PATCH', "/offices/$office", ['title_id' => $title]);
        $res = $this->call('PUT', "/members/{$this->m}/titles", ['title_ids' => []], [], 'en');
        $this->assertSame(400, $res->status);
        $this->assertStringContainsString('Captain', (string) json_encode($res->data, JSON_UNESCAPED_UNICODE));
    }

    public function testDeletingRemovesTheTranslations(): void
    {
        $group = (int) $this->ok('POST', '/office-groups', ['title' => 'Vorstand'])['id'];
        $office = (int) $this->ok('POST', '/offices', ['title' => 'Hauptmann', 'group_id' => $group])['id'];
        $other = (int) $this->ok('POST', '/offices', ['title' => 'Fahne', 'group_id' => null])['id'];
        $this->translate([
            ['kind' => 'office', 'ref' => (string) $office, 'text' => 'Captain'],
            ['kind' => 'office', 'ref' => (string) $other, 'text' => 'Flag'],
            ['kind' => 'office_group', 'ref' => (string) $group, 'text' => 'Board'],
        ]);
        $this->ok('DELETE', "/offices/$office");
        $this->ok('DELETE', "/office-groups/$group");
        $this->assertSame(['en' => ['office' => [(string) $other => ['label' => 'Flag']]]], $this->app->translations()->repository()->all(['office', 'office_group']));
    }

    public function testOnlyOfficeManagersTranslate(): void
    {
        $office = (int) $this->ok('POST', '/offices', ['title' => 'Hauptmann', 'group_id' => null])['id'];
        $this->users->current = $this->m;
        $res = $this->call('PUT', '/admin/translations', ['locale' => 'en', 'items' => [['kind' => 'office', 'ref' => (string) $office, 'text' => 'Captain']]]);
        $this->assertSame(403, $res->status);
    }
}
