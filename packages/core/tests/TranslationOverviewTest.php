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

/** Alle Übersetzungen an einer Stelle (GET /admin/translations): Einträge, Basissprache, Vorgaben, Rechte. */
final class TranslationOverviewTest extends TestCase
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

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function call(?int $user, string $method, string $path, array $body = [], array $query = [], ?string $locale = null): Response
    {
        $this->users->current = $user;
        return $this->app->api()->handle(new Request($method, $path, $query, $body, [], '', null, $locale));
    }

    /**
     * Einträge einer Art: Kennung => Eintrag.
     *
     * @param array<string, mixed> $data
     * @return array<string, array<string, mixed>>
     */
    private static function group(array $data, string $kind): array
    {
        foreach ($data['groups'] as $g) {
            if ($g['kind'] === $kind) {
                return array_column($g['items'], null, 'ref');
            }
        }
        return [];
    }

    /** @param array<string, mixed> $data */
    private static function kinds(array $data): array
    {
        return array_column($data['groups'], 'kind');
    }

    public function testOverviewOfNames(): void
    {
        $this->assertSame(400, $this->call($this->admin, 'GET', '/admin/translations')->status, 'ohne weitere Sprache');
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $this->assertSame(403, $this->call($this->m, 'GET', '/admin/translations')->status);
        $this->assertSame(400, $this->call($this->admin, 'GET', '/admin/translations', [], ['locale' => 'de'])->status, 'Deutsch ist die Basissprache');

        $rank = $this->call($this->admin, 'POST', '/ranks', ['title' => 'Gefreiter'])->data['id'];
        $office = $this->call($this->admin, 'POST', '/offices', ['title' => 'Vorsitz'])->data['id'];
        $this->call($this->admin, 'PUT', '/admin/event-kinds', ['kinds' => ['Versammlung']]);
        $nav = $this->call($this->admin, 'GET', '/admin/navigation')->data;
        $r = $this->call($this->admin, 'PUT', '/admin/navigation', [
            'navigation' => ['items' => ['termine', ['label' => 'Mehr', 'icon' => 'ordner', 'items' => ['news']]], 'hidden' => []],
            'names' => ['galerie' => ['label' => 'Fotos']],
        ]);
        $this->assertSame(200, $r->status, (string) json_encode($r->data));
        $this->call($this->admin, 'PUT', '/admin/translations', ['locale' => 'en', 'items' => [
            ['kind' => 'rank', 'ref' => (string) $rank, 'text' => 'Private'],
            ['kind' => 'nav', 'ref' => 'galerie', 'text' => 'Photos'],
        ]]);

        // Antwort in der Sprache der App des Admins (hier Englisch): die Basissprache bleibt Deutsch
        $res = $this->call($this->admin, 'GET', '/admin/translations', [], [], 'en');
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame('en', $res->data['locale'], 'Vorgabe: die erste weitere Sprache');
        $this->assertSame('de', $res->data['source_locale']);
        $this->assertSame(['nav', 'role', 'rank', 'office', 'office_group', 'event_kind', 'menu'], self::kinds($res->data));

        $navItems = self::group($res->data, 'nav');
        $this->assertSame(['source' => 'Termine', 'text' => '', 'fallback' => 'Events'], array_intersect_key($navItems['termine'], array_flip(['source', 'text', 'fallback'])), 'ohne eigenen Namen die übersetzte Vorgabe');
        $this->assertSame(['source' => 'Fotos', 'text' => 'Photos', 'fallback' => 'Fotos'], array_intersect_key($navItems['galerie'], array_flip(['source', 'text', 'fallback'])));
        $this->assertSame(24, $navItems['galerie']['max']);
        $this->assertArrayHasKey('intern', $navItems);

        $this->assertSame('Private', self::group($res->data, 'rank')[(string) $rank]['text']);
        $this->assertSame('Gefreiter', self::group($res->data, 'rank')[(string) $rank]['source']);
        $this->assertSame('Vorsitz', self::group($res->data, 'office')[(string) $office]['source']);
        $this->assertSame(['Versammlung'], array_keys(self::group($res->data, 'event_kind')));
        $this->assertSame(['Mehr'], array_keys(self::group($res->data, 'menu')));
        $roles = self::group($res->data, 'role');
        $this->assertSame(['source' => 'Gast', 'fallback' => 'Guest'], array_intersect_key($roles['guest'], array_flip(['source', 'fallback'])));
    }

    public function testKindsOfAreasThatAreOff(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $this->app->features()->update(['ranks' => false, 'offices' => false, 'gallery' => false]);
        $res = $this->call($this->admin, 'GET', '/admin/translations');
        $this->assertSame(['nav', 'role', 'event_kind', 'menu'], self::kinds($res->data));
        $this->assertArrayNotHasKey('galerie', self::group($res->data, 'nav'));
        $this->assertArrayHasKey('termine', self::group($res->data, 'nav'));
    }
}
