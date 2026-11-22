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
 * News aus der App je Sprache (Plan „Mehrsprachigkeit“, PR 9c): Titel und Texte je weiterer Sprache in Liste und Beitrag,
 * Rückfall auf Deutsch mit Hinweis, Bearbeiten mit source, Löschen.
 */
final class NewsTranslationTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
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

    /** @return array<int|string, mixed> */
    private function data(Response $res): array
    {
        $this->assertLessThan(300, $res->status, (string) json_encode($res->data));
        return json_decode((string) json_encode($res->data), true);
    }

    public function testNewsPerLanguage(): void
    {
        $this->data($this->call($this->admin, 'PATCH', '/admin/settings', ['news_source' => 'app', 'languages' => 'de,en']));
        $id = (int) $this->data($this->call($this->admin, 'POST', '/news', ['title' => 'Schützenfest', 'blocks' => [
            ['id' => 'a1', 'type' => 'text', 'html' => '<p>Am Samstag feiern wir.</p>'],
        ]]))['id'];

        $item = $this->data($this->call($this->admin, 'GET', "/news/$id", [], 'en'));
        $this->assertSame('Schützenfest', $item['title']);
        $this->assertSame('all', $item['untranslated']);

        $saved = $this->data($this->call($this->admin, 'PATCH', "/news/$id", ['translations' => ['en' => ['title' => 'Shooting festival', 'blocks' => ['a1' => ['html' => '<p>We celebrate on Saturday.</p>']]]]]));
        $this->assertSame('Schützenfest', $saved['title'], 'Antwort zum Bearbeiten: deutsch');
        $this->assertSame('Shooting festival', $saved['translations']['en']['title']);

        $item = $this->data($this->call(null, 'GET', "/news/$id", [], 'en'));
        $this->assertSame('Shooting festival', $item['title']);
        $this->assertStringContainsString('We celebrate on Saturday.', $item['content']);
        $this->assertSame('<p>We celebrate on Saturday.</p>', $item['blocks'][0]['html']);
        $this->assertArrayNotHasKey('untranslated', $item);
        $list = $this->data($this->call(null, 'GET', '/news', [], 'en'));
        $this->assertSame('Shooting festival', $list[0]['title']);
        $this->assertSame('We celebrate on Saturday.', $list[0]['excerpt']);
        $this->assertSame('Schützenfest', $this->data($this->call(null, 'GET', '/news'))[0]['title']);

        $src = $this->data($this->call($this->admin, 'GET', "/news/$id", [], 'en', ['source' => '1']));
        $this->assertSame('Schützenfest', $src['title']);
        $this->assertSame('<p>Am Samstag feiern wir.</p>', $src['blocks'][0]['html']);
        $this->assertSame('Shooting festival', $this->app->newsService()->appSource()->titleIn((string) $id, 'en'));

        $this->assertSame(200, $this->call($this->admin, 'DELETE', "/news/$id")->status);
        $this->assertSame([], $this->app->translations()->repository()->forRef('news', (string) $id));
    }
}
