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
 * Namen von Dokumenten, ihren Kategorien und Alben je Sprache (Plan „Mehrsprachigkeit“, PR 9e): Fassung beim Anlegen
 * und Ändern, Lesen in der Sprache der App (fehlt sie, der deutsche Name), zum Bearbeiten deutsch mit translations,
 * Löschen räumt auf.
 */
final class DocumentAlbumTranslationTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private string $dir;
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kompanie-doc-tr-' . bin2hex(random_bytes(4));
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, $this->dir, null, null, ['documents' => true, 'gallery' => true]);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
    }

    protected function tearDown(): void
    {
        I18n::setLocale(I18n::SOURCE_LOCALE);
        exec('rm -rf ' . escapeshellarg($this->dir));
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

    public function testDocumentAndCategoryPerLanguage(): void
    {
        $file = ['name' => 'satzung.pdf', 'data' => base64_encode(self::PDF)];
        $this->assertSame(400, $this->call($this->admin, 'POST', '/document-categories', ['title' => 'Vorstand', 'translations' => ['en' => ['title' => 'Board']]])->status, 'Englisch noch aus');
        $this->call($this->admin, 'PATCH', '/admin/settings', ['languages' => 'de,en']);

        $cat = $this->data($this->call($this->admin, 'POST', '/document-categories', ['title' => 'Vorstand', 'translations' => ['en' => ['title' => 'Board']]], 'en'));
        $this->assertSame(['Vorstand', ['en' => ['title' => 'Board']]], [$cat['title'], $cat['translations']]);
        $doc = $this->data($this->call($this->admin, 'POST', '/documents', [
            'title' => 'Satzung', 'date' => '2026-10-01', 'category_id' => $cat['id'], 'file' => $file,
            'translations' => ['en' => ['title' => 'Statutes']],
        ], 'en'));
        $this->assertSame('Satzung', $doc['title'], 'Antwort zum Bearbeiten: deutsch');
        $this->assertSame(['en' => ['title' => 'Statutes']], $doc['translations']);
        $this->assertSame(400, $this->call($this->admin, 'PATCH', '/documents/' . $doc['id'], ['translations' => ['en' => ['title' => str_repeat('x', 192)]]])->status);

        $en = $this->data($this->call($this->member, 'GET', '/documents', [], 'en'));
        $this->assertSame(['Statutes', 'Board'], [$en['documents'][0]['title'], $en['categories'][0]['title']]);
        $this->assertArrayNotHasKey('translations', $en['documents'][0]);
        $de = $this->data($this->call($this->member, 'GET', '/documents'));
        $this->assertSame(['Satzung', 'Vorstand'], [$de['documents'][0]['title'], $de['categories'][0]['title']]);

        // Zum Bearbeiten (nur manage_documents): deutsch mit translations
        $src = $this->data($this->call($this->admin, 'GET', '/documents', [], 'en', ['source' => '1']));
        $this->assertSame(['Satzung', 'Statutes', 'Vorstand', 'Board'], [$src['documents'][0]['title'], $src['documents'][0]['translations']['en']['title'], $src['categories'][0]['title'], $src['categories'][0]['translations']['en']['title']]);
        $this->assertSame('Statutes', $this->data($this->call($this->member, 'GET', '/documents', [], 'en', ['source' => '1']))['documents'][0]['title']);

        // Nur die Fassung ändern: der deutsche Name bleibt; leer = deutsch
        $this->data($this->call($this->admin, 'PATCH', '/document-categories/' . $cat['id'], ['translations' => ['en' => ['title' => 'Committee']]]));
        $this->data($this->call($this->admin, 'PATCH', '/documents/' . $doc['id'], ['translations' => ['en' => ['title' => '']]]));
        $en = $this->data($this->call($this->member, 'GET', '/documents', [], 'en'));
        $this->assertSame(['Satzung', 'Committee'], [$en['documents'][0]['title'], $en['categories'][0]['title']]);
        $this->assertSame('Vorstand', $this->data($this->call($this->member, 'GET', '/documents'))['categories'][0]['title']);

        $this->assertSame(200, $this->call($this->admin, 'DELETE', '/document-categories/' . $cat['id'])->status);
        $this->assertSame([], $this->app->translations()->repository()->forRef('document_category', (string) $cat['id']));
        $this->data($this->call($this->admin, 'PATCH', '/documents/' . $doc['id'], ['translations' => ['en' => ['title' => 'Statutes']]]));
        $this->assertSame(200, $this->call($this->admin, 'DELETE', '/documents/' . $doc['id'])->status);
        $this->assertSame([], $this->app->translations()->repository()->forRef('document', (string) $doc['id']));
    }

    public function testAlbumPerLanguage(): void
    {
        $this->call($this->admin, 'PATCH', '/admin/settings', ['gallery_source' => 'server', 'gallery_store' => 'server', 'languages' => 'de,en']);
        $album = $this->data($this->call($this->admin, 'POST', '/admin/gallery/albums', ['title' => 'Schützenfest', 'year' => 2026, 'translations' => ['en' => ['title' => 'Shooting fair']]], 'en'));
        $id = (int) $album['id'];
        $this->assertSame(['Schützenfest', ['en' => ['title' => 'Shooting fair']]], [$album['title'], $album['translations']]);
        $this->assertSame(400, $this->call($this->admin, 'PATCH', "/admin/gallery/albums/$id", ['translations' => ['en' => ['title' => str_repeat('x', 121)]]])->status);

        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($img);
        $this->data($this->call($this->admin, 'POST', "/admin/gallery/albums/$id/photos", ['file' => ['name' => 'a.jpg', 'data' => base64_encode((string) ob_get_clean())]]));

        $years = $this->data($this->call(null, 'GET', '/gallery', [], 'en'))['years'];
        $this->assertSame('Shooting fair', $years[0]['albums'][0]['title']);
        $this->assertSame('Shooting fair', $this->data($this->call(null, 'GET', "/gallery/$id", [], 'en'))['title']);
        $this->assertSame('Schützenfest', $this->data($this->call(null, 'GET', "/gallery/$id"))['title']);
        $list = $this->data($this->call($this->admin, 'GET', '/admin/gallery/albums', [], 'en'))['albums'];
        $this->assertSame(['Schützenfest', 'Shooting fair'], [$list[0]['title'], $list[0]['translations']['en']['title']], 'Verwaltung: deutsch');

        // Umbenennen auf Deutsch lässt die Fassung stehen
        $this->data($this->call($this->admin, 'PATCH', "/admin/gallery/albums/$id", ['title' => 'Schützenfest 2026']));
        $this->assertSame('Shooting fair', $this->data($this->call(null, 'GET', "/gallery/$id", [], 'en'))['title']);

        $this->assertSame(200, $this->call($this->admin, 'DELETE', "/admin/gallery/albums/$id")->status);
        $this->assertSame([], $this->app->translations()->repository()->forRef('album', (string) $id));
    }
}
