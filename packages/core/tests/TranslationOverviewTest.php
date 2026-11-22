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
        $this->assertSame(['nav', 'role', 'rank', 'office', 'office_group', 'event_kind', 'menu'], array_slice(self::kinds($res->data), 0, 7));

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
        $this->app->features()->update(['ranks' => false, 'offices' => false, 'gallery' => false, 'events' => false, 'polls' => false, 'documents' => false, 'pages' => false, 'board' => false]);
        $res = $this->call($this->admin, 'GET', '/admin/translations');
        $this->assertSame(['nav', 'role', 'event_kind', 'menu'], self::kinds($res->data));
        $this->assertArrayNotHasKey('galerie', self::group($res->data, 'nav'));
        $this->assertArrayHasKey('termine', self::group($res->data, 'nav'));
    }

    public function testContentWithSimpleFields(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en', 'calendar_source' => 'app']);
        $future = $this->call($this->admin, 'POST', '/events', ['title' => 'Schützenfest', 'start_date' => '2099-06-01', 'location' => 'Festplatz'])->data['id'];
        $this->call($this->admin, 'POST', '/events', ['title' => 'Altes Fest', 'start_date' => '2000-06-01'])->data['id'];
        $entry = $this->call($this->admin, 'POST', '/calendar/entries', ['title' => 'Arbeitsdienst', 'start_date' => '2099-07-01', 'description' => 'Mit Handschuhen'])->data['id'];
        $poll = $this->call($this->admin, 'POST', '/polls', ['title' => 'Grillfest?', 'type' => 'single', 'options' => ['Ja', 'Nein']])->data['id'];
        $category = $this->call($this->admin, 'POST', '/document-categories', ['title' => 'Protokolle'])->data['id'];
        // Fassung aus einem Editor erscheint hier
        $this->call($this->admin, 'PATCH', "/polls/{$poll}", ['translations' => ['en' => ['title' => 'Barbecue?', 'options' => ['Yes', '']]]]);

        $res = $this->call($this->admin, 'GET', '/admin/translations', [], [], 'en');
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertContains('event', self::kinds($res->data));
        $events = self::itemsOf($res->data, 'event');
        $refs = array_unique(array_column($events, 'ref'));
        $this->assertSame([(string) $future], array_values($refs), 'nur kommende Termine');
        $fields = array_column(array_filter($events, static fn (array $i): bool => $i['ref'] === (string) $future), null, 'field');
        $this->assertSame(['title', 'location'], array_keys($fields), 'leere Felder (Beschreibung) fehlen');
        $this->assertSame('Location', $fields['location']['label'], 'Feldname in der Sprache der Anfrage');
        $this->assertSame('Schützenfest · 2099-06-01', $fields['title']['context']);
        $this->assertFalse($fields['title']['multiline']);

        $calendar = self::itemsOf($res->data, 'calendar_entry');
        $this->assertSame(['title', 'description'], array_column($calendar, 'field'));
        $this->assertTrue($calendar[1]['multiline']);
        $this->assertSame((string) $entry, $calendar[0]['ref']);

        $polls = array_column(self::itemsOf($res->data, 'poll'), null, 'field');
        $this->assertSame(['title', 'option:0', 'option:1'], array_keys($polls));
        $this->assertSame('Barbecue?', $polls['title']['text']);
        $this->assertSame('Yes', $polls['option:0']['text']);
        $this->assertSame('', $polls['option:1']['text']);
        $this->assertSame('Answer 2', $polls['option:1']['label']);

        $this->assertSame('Protokolle', self::itemsOf($res->data, 'document_category')[0]['source']);
        $this->assertSame((string) $category, self::itemsOf($res->data, 'document_category')[0]['ref']);
        $this->assertNotContains('news', self::kinds($res->data), 'News werden beim Schreiben übersetzt');
    }

    public function testPagesAndBoardWithState(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $page = $this->call($this->admin, 'POST', '/pages', ['title' => 'Anfahrt', 'audience' => 'public', 'blocks' => [
            ['id' => 'a1', 'type' => 'text', 'html' => '<p>Über die Brücke</p>'],
            ['id' => 'a2', 'type' => 'notice', 'title' => 'Hinweis', 'text' => 'Parken am Markt', 'tone' => 'info'],
            ['id' => 'a3', 'type' => 'divider', 'style' => 'line'],
        ]])->data['id'];
        $this->call($this->admin, 'PATCH', "/pages/{$page}", ['translations' => ['en' => ['title' => 'Directions', 'blocks' => ['a1' => ['html' => '<p>Over the bridge</p>']]]]]);
        $message = $this->call($this->admin, 'POST', '/board', ['title' => 'Training fällt aus', 'blocks' => [['id' => 'b1', 'type' => 'text', 'html' => '<p>Heute nicht</p>']]])->data['id'];

        $res = $this->call($this->admin, 'GET', '/admin/translations');
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $pages = self::itemsOf($res->data, 'page');
        $this->assertCount(1, $pages);
        $this->assertSame(['ref' => (string) $page, 'field' => 'content', 'source' => 'Anfahrt', 'text' => 'Directions', 'total' => 4, 'done' => 2], array_intersect_key($pages[0], array_flip(['ref', 'field', 'source', 'text', 'total', 'done'])), 'Titel, Text, Hinweis (Titel + Text); Trennlinie zählt nicht');
        $board = self::itemsOf($res->data, 'board');
        $this->assertSame([(string) $message, '', 2, 0], [$board[0]['ref'], $board[0]['text'], $board[0]['total'], $board[0]['done']]);

        // Bereich aus: keine Gruppe
        $this->app->features()->update(['pages' => false]);
        $this->assertNotContains('page', self::kinds($this->call($this->admin, 'GET', '/admin/translations')->data));
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function itemsOf(array $data, string $kind): array
    {
        foreach ($data['groups'] as $g) {
            if ($g['kind'] === $kind) {
                return $g['items'];
            }
        }
        return [];
    }
}
