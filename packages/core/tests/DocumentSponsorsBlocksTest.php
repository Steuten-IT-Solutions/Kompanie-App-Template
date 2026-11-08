<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Blöcke „Dokument“ (document) und „Sponsoren“ (sponsors): nur mit den Rechten des jeweiligen Bereichs. */
final class DocumentSponsorsBlocksTest extends TestCase
{
    private string $dir;
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;
    private int $guest;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kompanie-blocks-' . bin2hex(random_bytes(4));
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, $this->dir, null, null, ['pages' => true, 'board' => true, 'documents' => true, 'sponsors' => true]);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
        $this->guest = $this->users->add('gast', Roles::GUEST);
        $this->users->current = $this->admin;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function call(string $method, string $path, array $body = [], ?int $as = -1): Response
    {
        if ($as !== -1) {
            $this->users->current = $as;
        }
        $res = $this->app->api()->handle(new Request($method, $path, [], $body));
        $this->users->current = $this->admin;
        return $res;
    }

    /** @return array<string, mixed> */
    private function ok(string $method, string $path, array $body = [], ?int $as = -1): array
    {
        $res = $this->call($method, $path, $body, $as);
        $this->assertLessThan(300, $res->status, $method . ' ' . $path . ': ' . json_encode($res->data));
        return (array) $res->data;
    }

    public function testDocument(): void
    {
        $file = ['name' => 'satzung.pdf', 'data' => base64_encode("%PDF-1.4\n%%EOF")];
        $doc = $this->ok('POST', '/documents', ['title' => 'Satzung', 'date' => '2026-10-01', 'file' => $file]);
        $intern = $this->ok('POST', '/documents', ['title' => 'Kassenbericht', 'date' => '2026-10-02', 'visibility' => 'managers', 'file' => $file]);
        $page = $this->ok('POST', '/pages', ['title' => 'D', 'audience' => 'public', 'blocks' => [
            ['type' => 'text', 'html' => '<p>x</p>'],
            ['type' => 'document', 'document_id' => $doc['id']],
            ['type' => 'document', 'document_id' => $intern['id']],
        ]]);
        $this->assertSame(['id' => $doc['id'], 'title' => 'Satzung', 'ext' => 'pdf', 'kind' => 'pdf', 'size' => 14, 'date' => '2026-10-01', 'url' => $doc['url']], $page['blocks'][1]['document']);
        $member = $this->ok('GET', "/pages/{$page['id']}", [], $this->member)['blocks'];
        $this->assertSame(['text', 'document'], array_column($member, 'type'), 'Dokument nur für die Pflege fehlt für Mitglieder');
        $this->assertSame('Satzung', $member[1]['document']['title']);
        $this->assertSame(['text'], array_column($this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'], 'type'), 'ohne Anmeldung keine Dokumente');
        $this->assertSame('Dokument: Satzung (PDF) – ' . $doc['url'], $this->app->blocks()->plain([['type' => 'document', 'document_id' => $doc['id']]], true, $this->users->find($this->member)));
        $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'document', 'document_id' => 99999]]])->status);
        $this->ok('DELETE', "/documents/{$doc['id']}");
        $this->assertSame(['text'], array_column($this->ok('GET', "/pages/{$page['id']}", [], $this->member)['blocks'], 'type'));
        $this->assertNull($this->ok('GET', "/pages/{$page['id']}")['blocks'][1]['document'], 'die Pflege sieht den Block mit document null');
    }

    public function testSponsors(): void
    {
        $page = $this->ok('POST', '/pages', ['title' => 'S', 'audience' => 'public', 'blocks' => [['type' => 'text', 'html' => '<p>x</p>'], ['type' => 'sponsors', 'title' => 'Mit Dank an']]]);
        $this->assertSame(['text'], array_column($this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'], 'type'), 'ohne Sponsoren fällt der Block weg');
        $this->assertSame([], $this->ok('GET', "/pages/{$page['id']}")['blocks'][1]['sponsors']);
        $a = $this->ok('POST', '/sponsors', ['name' => 'Bäckerei Korn', 'url' => 'https://example.org/korn']);
        $this->ok('POST', '/sponsors', ['name' => 'Getränke Rhein', 'url' => '']);
        $guest = $this->ok('GET', "/pages/{$page['id']}", [], null)['blocks'];
        $this->assertSame('Mit Dank an', $guest[1]['title']);
        $this->assertSame(['Bäckerei Korn', 'Getränke Rhein'], array_column($guest[1]['sponsors'], 'name'));
        $this->assertSame($a['id'], $guest[1]['sponsors'][0]['id']);
        $this->assertSame("Mit Dank an\nBäckerei Korn – https://example.org/korn\nGetränke Rhein", $this->app->blocks()->plain([['type' => 'sponsors', 'title' => 'Mit Dank an']], true, null));
        $this->assertSame(400, $this->call('POST', '/pages', ['title' => 'X', 'blocks' => [['type' => 'sponsors', 'title' => str_repeat('x', 101)]]])->status);
        // Bereich aus: für Leser weg
        $this->ok('PUT', '/admin/features', ['sponsors' => false]);
        $this->assertSame(['text'], array_column($this->ok('GET', "/pages/{$page['id']}", [], $this->member)['blocks'], 'type'));
    }
}
