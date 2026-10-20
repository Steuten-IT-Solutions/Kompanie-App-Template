<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\DocumentService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Dokumente (Issue #2): Hochladen mit Prüfung, Sichtbarkeit, Fassungen, Kategorien, Auslieferung, Rechte. */
final class DocumentServiceTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private FakeUsers $users;
    private App $app;
    private string $dir;
    private int $admin;
    private int $m;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->dir = sys_get_temp_dir() . '/kompanie-doc-' . bin2hex(random_bytes(4));
        $all = ['events' => false, 'calendar' => false, 'news' => false, 'gallery' => false, 'points' => false, 'penalties' => false, 'ranks' => false, 'titles' => false, 'attendance' => false, 'documents' => true];
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, $this->dir, null, null, $all);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    /** @return array{name: string, data: string} */
    private static function file(string $name, string $bytes = self::PDF): array
    {
        return ['name' => $name, 'data' => base64_encode($bytes)];
    }

    /** @return array<string, mixed> */
    private function upload(string $title, array $extra = []): array
    {
        $res = $this->call('POST', '/documents', $extra + ['title' => $title, 'date' => '2026-10-01', 'file' => self::file('satzung.pdf')]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data;
    }

    private function files(): array
    {
        return glob($this->dir . '/private/dokumente/*') ?: [];
    }

    public function testUploadChecksTypeSignatureAndSize(): void
    {
        $doc = $this->upload('Satzung');
        $this->assertSame(['pdf', 'pdf', 'application/pdf', 'members'], [$doc['ext'], $doc['kind'], $doc['mime'], $doc['visibility']]);
        $this->assertSame(strlen(self::PDF), $doc['size']);
        $this->assertSame('https://example.org/app/dokument/' . $doc['id'] . '?v=' . $doc['version'], $doc['url']);
        $this->assertCount(1, $this->files());

        $bad = fn (array $body): int => $this->call('POST', '/documents', $body + ['title' => 'X', 'date' => '2026-10-01'])->status;
        $this->assertSame(400, $bad(['file' => self::file('bild.exe')]), 'Endung');
        $this->assertSame(400, $bad(['file' => self::file('bild.png')]), 'PDF als PNG umbenannt: Signatur passt nicht');
        $this->assertSame(400, $bad(['file' => ['name' => 'a.pdf', 'data' => 'kein base64!!']]));
        $this->assertSame(400, $bad([]), 'ohne Datei');
        $this->assertSame(400, $bad(['file' => self::file('a.pdf'), 'visibility' => 'alle']));
        $this->assertSame(400, $bad(['file' => self::file('a.pdf'), 'category_id' => 999]));
        $this->assertSame(400, $this->call('POST', '/documents', ['title' => 'X', 'date' => '1.10.2026', 'file' => self::file('a.pdf')])->status);
        $this->assertSame(400, $bad(['file' => self::file('gross.pdf', self::PDF . str_repeat('x', DocumentService::MAX_BYTES))]), 'über 20 MB');
        $this->assertCount(1, $this->files(), 'abgelehnte Dateien werden nicht gespeichert');

        $png = $this->call('POST', '/documents', ['title' => 'Logo', 'date' => '2026-10-01', 'file' => ['name' => 'Logo.PNG', 'data' => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1A\n...")]]);
        $this->assertSame(201, $png->status, 'Endung groß, als data:-Adresse');
        $this->assertSame('image', $png->data['kind']);
        $this->assertArrayHasKey('max_bytes', $this->call('GET', '/documents')->data);
    }

    public function testVisibilityAndDelivery(): void
    {
        $public = $this->upload('Satzung');
        $intern = $this->upload('Kassenbericht', ['visibility' => 'managers']);
        $guest = $this->users->add('gast', Roles::GUEST);
        $docs = $this->app->documents();

        $this->users->current = $this->m;
        $list = $this->call('GET', '/documents')->data;
        $this->assertSame(['Satzung'], array_column($list['documents'], 'title'), 'nur für alle Mitglieder');
        $this->assertArrayNotHasKey('max_bytes', $list);
        $this->assertSame(403, $this->call('POST', '/documents', ['title' => 'X', 'date' => '2026-10-01', 'file' => self::file('a.pdf')])->status);

        $member = $this->users->find($this->m);
        $res = $docs->forDelivery($member, $public['id']);
        $this->assertSame(200, $res['status']);
        $this->assertSame(self::PDF, file_get_contents((string) ($res['path'] ?? '')));
        $this->assertStringStartsWith('inline; filename="Satzung.pdf"', (string) ($res['disposition'] ?? ''));
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{32}"$/', (string) ($res['etag'] ?? ''), 'ETag für bedingte Abrufe (304)');
        // Issue #18: Die Adresse wechselt mit der Fassung, die aktuelle darf ein Jahr im Browser bleiben.
        $this->assertSame('private, no-cache', $res['cache_control'] ?? null, 'ohne Fassung: jedes Mal prüfen');
        $this->assertStringContainsString('v=' . $public['version'], $public['url']);
        $this->assertSame('private, max-age=31536000, immutable', $docs->forDelivery($member, $public['id'], $public['version'])['cache_control'] ?? null);
        $this->assertSame('private, no-cache', $docs->forDelivery($member, $public['id'], 'veraltet')['cache_control'] ?? null, 'alte Fassung: nicht lange speichern');
        $this->assertSame(404, $docs->forDelivery($member, $intern['id'])['status'], 'eingeschränkt: wie nicht vorhanden');
        $this->assertSame(401, $docs->forDelivery(null, $public['id'])['status']);
        $this->assertSame(403, $docs->forDelivery($this->users->find($guest), $public['id'])['status']);
        $this->assertSame(200, $docs->forDelivery($this->users->find($this->admin), $intern['id'])['status']);

        // Eigene Rolle mit „Dokumente verwalten“ sieht auch eingeschränkte.
        $editor = $this->users->add('redaktion', 'documents');
        $this->users->current = $editor;
        $this->assertCount(2, $this->call('GET', '/documents')->data['documents']);

        $this->app->features()->update(['documents' => false]);
        $this->assertSame(404, $docs->forDelivery($member, $public['id'])['status']);
        $this->assertSame(403, $this->call('GET', '/documents')->status);
    }

    public function testNewVersionEditAndDelete(): void
    {
        $doc = $this->upload('Protokoll');
        $old = $this->files();
        $res = $this->call('POST', '/documents/' . $doc['id'] . '/file', ['file' => self::file('protokoll.docx', "PK\x03\x04neu")]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertNotSame($doc['version'], $res->data['version']);
        $this->assertSame(['docx', 'file'], [$res->data['ext'], $res->data['kind']]);
        $this->assertCount(1, $this->files());
        $this->assertNotSame($old, $this->files(), 'alte Datei ersetzt');
        $this->assertStringStartsWith('attachment;', (string) ($this->app->documents()->forDelivery($this->users->find($this->m), $doc['id'])['disposition'] ?? ''));

        $res = $this->call('PATCH', '/documents/' . $doc['id'], ['title' => 'Protokoll März', 'date' => '2026-03-01', 'visibility' => 'managers']);
        $this->assertSame(['Protokoll März', '2026-03-01', 'managers'], [$res->data['title'], $res->data['date'], $res->data['visibility']]);
        $this->assertSame(400, $this->call('PATCH', '/documents/' . $doc['id'], ['title' => ''])->status);
        $this->assertSame(404, $this->call('PATCH', '/documents/999', ['title' => 'X'])->status);

        $this->assertSame(200, $this->call('DELETE', '/documents/' . $doc['id'])->status);
        $this->assertSame([], $this->files());
        $this->assertSame(404, $this->call('DELETE', '/documents/' . $doc['id'])->status);
    }

    public function testCategories(): void
    {
        $a = $this->call('POST', '/document-categories', ['title' => 'Protokolle']);
        $this->assertSame(201, $a->status);
        $b = $this->call('POST', '/document-categories', ['title' => 'Formulare'])->data;
        $this->assertSame(400, $this->call('POST', '/document-categories', ['title' => 'protokolle'])->status);
        $doc = $this->upload('Protokoll', ['category_id' => $a->data['id']]);
        $this->assertSame($a->data['id'], $doc['category_id']);

        $res = $this->call('PUT', '/document-categories/order', ['ids' => [$b['id'], $a->data['id']]]);
        $this->assertSame(['Formulare', 'Protokolle'], array_column($res->data, 'title'));
        $this->assertSame(400, $this->call('PUT', '/document-categories/order', ['ids' => [$b['id']]])->status);
        $this->assertSame('Sitzungen', $this->call('PATCH', '/document-categories/' . $a->data['id'], ['title' => 'Sitzungen'])->data['title']);

        // Löschen: Dokumente stehen danach ohne Kategorie da.
        $this->assertSame(200, $this->call('DELETE', '/document-categories/' . $a->data['id'])->status);
        $list = $this->call('GET', '/documents')->data;
        $this->assertSame(['Formulare'], array_column($list['categories'], 'title'));
        $this->assertNull($list['documents'][0]['category_id']);

        $this->users->current = $this->m;
        $this->assertSame(403, $this->call('POST', '/document-categories', ['title' => 'X'])->status);
    }

    public function testForeignFileNamesAreNeverTouched(): void
    {
        // Etwa aus einer veränderten Sicherung: Der Dateiname zeigt auf eine andere geschützte Datei.
        $doc = $this->upload('Satzung');
        $other = $this->app->files->path('galerie/intern.jpg', false);
        file_put_contents($other, 'geheim');
        $this->app->db->update('documents', ['file' => '../galerie/intern.jpg'], ['id' => $doc['id']]);
        $this->assertSame(404, $this->app->documents()->forDelivery($this->users->find($this->admin), $doc['id'])['status']);
        $this->assertSame(200, $this->call('DELETE', '/documents/' . $doc['id'])->status);
        $this->assertFileExists($other);
    }

    public function testMaxBytesFollowsServerLimits(): void
    {
        $this->assertLessThanOrEqual(DocumentService::MAX_BYTES, DocumentService::maxBytes());
        $this->assertGreaterThan(0, DocumentService::maxBytes());
    }
}
