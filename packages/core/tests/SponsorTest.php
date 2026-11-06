<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\RankService;
use KompanieApp\Core\Domain\SponsorService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Sponsoren (Issue #279): Liste für alle, Pflege mit manage_sponsors, Logo (PNG/SVG), Reihenfolge, Auslieferung. */
final class SponsorTest extends TestCase
{
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10"><script>alert(1)</script><rect width="20" height="10" fill="#fc0"/></svg>';

    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users, features: ['sponsors' => true]);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    private function sponsor(string $name, string $url = ''): int
    {
        $res = $this->call('POST', '/sponsors', ['name' => $name, 'url' => $url]);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data['id'];
    }

    private static function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 0, 0, (int) ($w / 2), (int) ($h / 2), (int) imagecolorallocate($im, 20, 20, 220));
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    /** @return array{name: string, data: string} */
    private static function file(string $name, string $bytes): array
    {
        return ['name' => $name, 'data' => base64_encode($bytes)];
    }

    public function testCreateListUpdateDeleteInOrder(): void
    {
        $a = $this->sponsor('  Bäckerei   Muster ', 'https://bäckerei-muster.de/');
        $b = $this->sponsor('Autohaus');
        $res = $this->call('GET', '/sponsors');
        $this->assertSame(200, $res->status);
        $this->assertSame([
            ['id' => $a, 'name' => 'Bäckerei Muster', 'url' => 'https://bäckerei-muster.de/', 'logo_url' => null, 'logo_type' => null],
            ['id' => $b, 'name' => 'Autohaus', 'url' => '', 'logo_url' => null, 'logo_type' => null],
        ], $res->data, 'neue ans Ende, Name als eine Zeile');

        $this->assertSame([$b, $a], array_column($this->call('PUT', '/sponsors/order', ['ids' => [$b, $a]])->data, 'id'));
        $this->assertSame(400, $this->call('PUT', '/sponsors/order', ['ids' => [$b]])->status, 'alle angeben');

        $this->assertSame('https://autohaus.example/', $this->call('PATCH', "/sponsors/$b", ['url' => 'https://autohaus.example/'])->data['url']);
        $this->assertSame('Autohaus', $this->call('PATCH', "/sponsors/$b", ['url' => ''])->data['name'], 'nur der Link, Name bleibt');
        $this->assertSame(200, $this->call('DELETE', "/sponsors/$a")->status);
        $this->assertSame([$b], array_column($this->call('GET', '/sponsors')->data, 'id'));
        $this->assertSame(404, $this->call('PATCH', "/sponsors/$a", ['name' => 'x'])->status);
    }

    public function testRejectsInvalid(): void
    {
        foreach ([
            'ohne Namen' => ['name' => ' '],
            'Name zu lang' => ['name' => str_repeat('x', SponsorService::NAME_MAX + 1)],
            'nur http' => ['name' => 'A', 'url' => 'http://example.org/'],
            'kein Link' => ['name' => 'A', 'url' => 'javascript:alert(1)'],
            'Adresse ohne Schema' => ['name' => 'A', 'url' => 'example.org'],
        ] as $case => $body) {
            $this->assertSame(400, $this->call('POST', '/sponsors', $body)->status, $case);
        }
        $this->assertSame([], $this->call('GET', '/sponsors')->data);
    }

    public function testRightsAndFeature(): void
    {
        $id = $this->sponsor('Sparkasse');
        $this->users->current = $this->member;
        $this->assertSame(200, $this->call('GET', '/sponsors')->status, 'Mitglied sieht sie');
        $this->assertSame(403, $this->call('POST', '/sponsors', ['name' => 'X'])->status, 'pflegen nur mit manage_sponsors');
        $this->assertSame(403, $this->call('DELETE', "/sponsors/$id")->status);
        $this->users->current = null;
        $this->assertSame(['Sparkasse'], array_column($this->call('GET', '/sponsors')->data, 'name'), 'auch ohne Anmeldung (Gast)');
        $this->assertSame(401, $this->call('POST', '/sponsors', ['name' => 'X'])->status);

        // Standardrolle „Sponsoren verwalten“ beim Einschalten
        $this->assertSame(TestApp::caps(['member', 'manage_sponsors']), $this->app->roles()->find('sponsors')['capabilities'] ?? null);
        $this->assertSame('Sponsoren verwalten', $this->app->roles()->find('sponsors')['label'] ?? null);
        $pfleger = $this->users->add('pfleger', 'sponsors');
        $this->users->current = $pfleger;
        $this->assertSame(201, $this->call('POST', '/sponsors', ['name' => 'Neu'])->status);

        // Gast ohne view_sponsors sieht nichts
        $this->users->current = $this->admin;
        $this->app->roles()->update(Roles::GUEST, ['capabilities' => ['view_news']]);
        $this->users->current = null;
        $this->assertSame(401, $this->call('GET', '/sponsors')->status);

        $this->users->current = $this->admin;
        $this->app->features()->update(['sponsors' => false]);
        $this->assertSame(403, $this->call('GET', '/sponsors')->status, 'Bereich aus');
    }

    public function testLogoPngAndSvgWithDelivery(): void
    {
        $id = $this->sponsor('Brauerei', 'https://brauerei.example/');
        $res = $this->call('POST', "/sponsors/$id/logo", ['file' => self::file('logo.png', self::png(1200, 600))]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame('png', $res->data['logo_type']);
        $this->assertMatchesRegularExpression('#^https://example\.org/app/sponsorlogo/' . $id . '\?v=[0-9a-f]{16}$#', (string) $res->data['logo_url']);
        $version = (string) substr((string) $res->data['logo_url'], -16);

        $sponsors = $this->app->sponsors();
        $out = $sponsors->logoForDelivery(null, $id, $version);
        $this->assertSame(200, $out['status']);
        $this->assertSame('image/png', $out['headers']['Content-Type']);
        $this->assertSame('public, max-age=31536000, immutable', $out['headers']['Cache-Control'], 'Gast sieht sie: öffentlich');
        $info = getimagesizefromstring((string) ($out['body'] ?? ''));
        $this->assertSame([512, 256], [$info[0] ?? 0, $info[1] ?? 0], 'auf 512 Pixel verkleinert, Seitenverhältnis bleibt');
        $this->assertSame('public, no-cache', $sponsors->logoForDelivery(null, $id, 'alt')['headers']['Cache-Control'], 'veraltete Fassung');

        $small = $this->sponsor('Klein');
        $this->call('POST', "/sponsors/$small/logo", ['file' => self::file('k.png', self::png(40, 20))]);
        $info = getimagesizefromstring((string) ($sponsors->logoForDelivery(null, $small, null)['body'] ?? ''));
        $this->assertSame([40, 20], [$info[0] ?? 0, $info[1] ?? 0], 'nicht vergrößert');

        $res = $this->call('POST', "/sponsors/$id/logo", ['file' => self::file('logo.svg', self::SVG)]);
        $this->assertSame('svg', $res->data['logo_type'], 'ersetzt');
        $svg = $sponsors->logoForDelivery(null, $id, null);
        $this->assertSame('image/svg+xml', $svg['headers']['Content-Type']);
        $this->assertSame(RankService::SVG_CSP, $svg['headers']['Content-Security-Policy']);
        $this->assertStringNotContainsString('script', (string) ($svg['body'] ?? ''), 'bereinigt');

        $this->assertNull($this->call('DELETE', "/sponsors/$id/logo")->data['logo_url']);
        $this->assertSame(404, $sponsors->logoForDelivery(null, $id, null)['status']);
        $this->assertSame(404, $sponsors->logoForDelivery(null, 9999, null)['status']);

        // Ohne view_sponsors für Gast: angemeldet privat, ohne Anmeldung 401
        $this->app->roles()->update(Roles::GUEST, ['capabilities' => ['view_news']]);
        $member = $this->users->find($this->member);
        $this->assertNotNull($member);
        $this->assertTrue($member->can(Capability::ViewSponsors));
        $this->assertSame('private, no-cache', $sponsors->logoForDelivery($member, $small, null)['headers']['Cache-Control']);
        $this->assertSame(401, $sponsors->logoForDelivery(null, $small, null)['status']);
        $this->app->features()->update(['sponsors' => false]);
        $this->assertSame(404, $sponsors->logoForDelivery($member, $small, null)['status'], 'Bereich aus');
    }

    public function testRejectsBadLogos(): void
    {
        $id = $this->sponsor('A');
        $bad = fn (array $file): int => $this->call('POST', "/sponsors/$id/logo", ['file' => $file])->status;
        $this->assertSame(400, $bad(self::file('a.gif', 'GIF89a')), 'Format');
        $this->assertSame(400, $bad(self::file('a.png', "\x89PNG\r\n\x1A\nkaputt")), 'kaputtes PNG');
        $this->assertSame(400, $bad(self::file('a.jpg', self::png(10, 10))), 'Endung passt nicht');
        $this->assertSame(400, $bad(self::file('a.png', self::png(10, 10) . str_repeat("\0", SponsorService::LOGO_MAX_BYTES))), 'über 2 MB');
        $this->assertSame(400, $bad(self::file('a.svg', '<html>kein svg</html>')), 'kein SVG');
        $this->assertSame(400, $bad(['name' => 'a.png', 'data' => '']), 'leer');
        $this->assertNull($this->call('GET', '/sponsors')->data[0]['logo_url']);
    }

    public function testBackupContainsSponsorsWithLogo(): void
    {
        $this->assertContains('sponsors', Backup::CORE_TABLES);
        $id = $this->sponsor('Gesichert');
        $this->call('POST', "/sponsors/$id/logo", ['file' => self::file('logo.svg', self::SVG)]);
        $backup = new Backup($this->app->db);
        $tables = $backup->dump(['sponsors']);
        $this->app->db->execute('DELETE FROM {sponsors}');
        $backup->restore($tables, ['sponsors']);
        $this->assertSame(['Gesichert'], array_column($this->call('GET', '/sponsors')->data, 'name'));
        $this->assertSame(200, $this->app->sponsors()->logoForDelivery(null, $id, null)['status'], 'Logo zurück');
    }
}
