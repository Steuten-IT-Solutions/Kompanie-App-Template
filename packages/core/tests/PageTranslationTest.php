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
 * Eigene Seiten je Sprache (Plan „Mehrsprachigkeit“, PR 9b): Titel und Texte der Blöcke je weiterer Sprache, Rückfall auf
 * Deutsch mit Hinweis, Bearbeiten mit source, Prüfungen, Löschen.
 */
final class PageTranslationTest extends TestCase
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
        $this->member = $this->users->add('anna', Roles::MEMBER);
    }

    protected function tearDown(): void
    {
        I18n::setLocale(I18n::SOURCE_LOCALE);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function call(?int $user, string $method, string $path, array $body = [], ?string $locale = null, array $query = []): Response
    {
        $this->users->current = $user;
        $res = $this->app->api()->handle(new Request($method, $path, $query, $body, [], '', null, $locale));
        I18n::setLocale(I18n::SOURCE_LOCALE);
        return $res;
    }

    /** @return array<string, mixed> */
    private function data(Response $res): array
    {
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        return json_decode((string) json_encode($res->data), true);
    }

    public function testPagePerLanguage(): void
    {
        $page = $this->data($this->call($this->admin, 'POST', '/pages', ['title' => 'Anfahrt', 'audience' => 'public', 'blocks' => [
            ['id' => 'a1', 'type' => 'text', 'html' => '<p>Über die Brücke</p>'],
            ['id' => 'a2', 'type' => 'notice', 'title' => 'Hinweis', 'text' => 'Parken am Markt', 'tone' => 'info'],
            ['id' => 'a3', 'type' => 'facts', 'title' => '', 'items' => [['value' => '1848', 'label' => 'gegründet']]],
            ['id' => 'a4', 'type' => 'divider', 'style' => 'line'],
        ]]));
        $id = $page['id'];

        // Ohne eingeschaltetes Englisch gibt es keine Fassung
        $this->assertSame(400, $this->call($this->admin, 'PATCH', "/pages/$id", ['translations' => ['en' => ['title' => 'Directions']]])->status);
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);
        $this->assertSame(400, $this->call($this->admin, 'PATCH', "/pages/$id", ['translations' => ['de' => ['title' => 'X']]])->status, 'Deutsch steht, wo es ist');
        $this->assertSame(403, $this->call($this->member, 'PATCH', "/pages/$id", ['translations' => ['en' => ['title' => 'Directions']]])->status);

        // Noch nichts übersetzt: auf Englisch die deutsche Fassung mit Hinweis
        $en = $this->data($this->call(null, 'GET', "/pages/$id", [], 'en'));
        $this->assertSame('Anfahrt', $en['title']);
        $this->assertSame('all', $en['untranslated']);
        $this->assertArrayNotHasKey('untranslated', $this->data($this->call(null, 'GET', "/pages/$id")), 'auf Deutsch kein Hinweis');
        $this->assertSame(['en'], $this->data($this->call($this->admin, 'GET', '/pages'))['pages'][0]['untranslated_locales']);

        // Titel und ein Teil der Blöcke: unbekannte Kennungen und nicht übersetzbare Felder fallen weg
        $saved = $this->data($this->call($this->admin, 'PATCH', "/pages/$id", ['translations' => ['en' => [
            'title' => 'Directions',
            'blocks' => ['a1' => ['html' => '<p>Over the bridge</p><script>x</script>'], 'a2' => ['title' => 'Note', 'tone' => 'warning'], 'zz' => ['html' => '<p>?</p>']],
        ]]]));
        $this->assertSame('Anfahrt', $saved['title'], 'Antwort zum Bearbeiten: deutsch');
        $this->assertSame('Directions', $saved['translations']['en']['title']);
        $this->assertSame(['a1', 'a2'], array_keys($saved['translations']['en']['blocks']));
        $this->assertSame(['title' => 'Note'], $saved['translations']['en']['blocks']['a2']);
        $this->assertStringNotContainsString('script', $saved['translations']['en']['blocks']['a1']['html']);

        $en = $this->data($this->call(null, 'GET', "/pages/$id", [], 'en'));
        $this->assertSame('Directions', $en['title']);
        $this->assertSame('some', $en['untranslated'], 'Text des Hinweises und die Zahl fehlen noch');
        $byId = array_column($en['blocks'], null, 'id');
        $this->assertSame('<p>Over the bridge</p>', $byId['a1']['html']);
        $this->assertSame('Note', $byId['a2']['title']);
        $this->assertSame('Parken am Markt', $byId['a2']['text'], 'fehlt: deutsch');
        $this->assertSame('info', $byId['a2']['tone'], 'Einstellungen gelten für alle Sprachen');
        $this->assertSame('Directions', $this->data($this->call(null, 'GET', '/pages', [], 'en'))['pages'][0]['title']);
        $this->assertSame('Anfahrt', $this->data($this->call(null, 'GET', '/pages'))['pages'][0]['title']);

        // Zum Bearbeiten (source): deutsche Fassung auch, wenn die App auf Englisch läuft
        $src = $this->data($this->call($this->admin, 'GET', "/pages/$id", [], 'en', ['source' => '1']));
        $this->assertSame('Anfahrt', $src['title']);
        $this->assertSame('Directions', $src['translations']['en']['title']);
        $this->assertArrayNotHasKey('translations', $this->data($this->call($this->member, 'GET', "/pages/$id", [], null, ['source' => '1'])), 'nur für die Pflege');

        // Rest übersetzen; Liste mit anderer Länge gilt nicht
        $this->data($this->call($this->admin, 'PATCH', "/pages/$id", ['translations' => ['en' => ['blocks' => [
            'a1' => ['html' => '<p>Over the bridge</p>'], 'a2' => ['title' => 'Note', 'text' => 'Park at the market'],
            'a3' => ['items' => [['value' => '1848', 'label' => 'founded']]],
        ]]]]));
        $en = $this->data($this->call(null, 'GET', "/pages/$id", [], 'en'));
        $this->assertArrayNotHasKey('untranslated', $en);
        $this->assertSame([], $this->data($this->call($this->admin, 'GET', '/pages'))['pages'][0]['untranslated_locales']);
        $this->assertSame(400, $this->call($this->admin, 'PATCH', "/pages/$id", ['translations' => ['en' => ['blocks' => ['a2' => ['text' => str_repeat('x', 1001)]]]]])->status, 'Längen wie auf Deutsch');

        // Deutsch ändern: Blöcke mit gleicher Kennung behalten ihre Fassung; eine neue Zahl macht die Liste ungültig
        $this->data($this->call($this->admin, 'PATCH', "/pages/$id", ['blocks' => [
            ['id' => 'a1', 'type' => 'text', 'html' => '<p>Über die neue Brücke</p>'],
            ['id' => 'a3', 'type' => 'facts', 'title' => '', 'items' => [['value' => '1848', 'label' => 'gegründet'], ['value' => '250', 'label' => 'Mitglieder']]],
        ]]));
        $en = $this->data($this->call(null, 'GET', "/pages/$id", [], 'en'));
        $byId = array_column($en['blocks'], null, 'id');
        $this->assertSame('<p>Over the bridge</p>', $byId['a1']['html']);
        $this->assertSame('gegründet', $byId['a3']['items'][0]['label']);
        $this->assertSame('some', $en['untranslated']);

        // Leerer Titel löscht die Übersetzung; Löschen der Seite löscht alle Fassungen
        $this->data($this->call($this->admin, 'PATCH', "/pages/$id", ['translations' => ['en' => ['title' => '']]]));
        $this->assertSame('Anfahrt', $this->data($this->call(null, 'GET', "/pages/$id", [], 'en'))['title']);
        $this->assertSame(200, $this->call($this->admin, 'DELETE', "/pages/$id")->status);
        $this->assertSame([], $this->app->translations()->repository()->forRef('page', (string) $id));
    }
}
