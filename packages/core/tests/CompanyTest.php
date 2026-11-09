<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\CompanyService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Storage\Backup;
use KompanieApp\Core\Storage\UserData;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Kompanieliste (Issue #280): Liste für alle mit view_companies, Pflege und Zuordnung mit manage_companies, Mitglieder
 * nur mit Bereich Mitgliederliste und view_directory, Logo, keine Verknüpfung mit Ämtern, Löschen der Person, Sicherung.
 */
final class CompanyTest extends TestCase
{
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><circle cx="5" cy="5" r="4"/></svg>';

    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;
    private int $guest;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('anna', Roles::MEMBER);
        $this->guest = $this->users->add('gast', Roles::GUEST);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    private function company(array $in): int
    {
        $res = $this->call('POST', '/companies', $in);
        $this->assertSame(201, $res->status, (string) json_encode($res->data));
        return $res->data['id'];
    }

    public function testCreateUpdateOrderDelete(): void
    {
        $a = $this->company([
            'name' => '  Erste   Kompanie ', 'short_name' => '1. Kp', 'founded_year' => 1862,
            'description' => '<p>Seit <strong>1862</strong><script>x</script><img src="bild:abc"></p>',
            'website_url' => 'https://erste.example/', 'app_url' => 'https://app.erste.example/',
        ]);
        $b = $this->company(['name' => 'Zweite']);
        $list = $this->call('GET', '/companies')->data;
        $this->assertSame([$a, $b], array_column($list, 'id'), 'neue ans Ende');
        $this->assertSame('Erste Kompanie', $list[0]['name']);
        $this->assertSame(1862, $list[0]['founded_year']);
        $this->assertSame('<p>Seit <strong>1862</strong></p>', $list[0]['description'], 'bereinigt, fremde Bilder entfernt');
        $this->assertTrue($list[0]['active']);
        $this->assertNull($list[0]['logo_url']);
        $this->assertSame([], $list[0]['member_ids'], 'Pflege sieht die Zuordnungen');

        $this->assertSame([$b, $a], array_column($this->call('PUT', '/companies/order', ['ids' => [$b, $a]])->data, 'id'));
        $res = $this->call('PATCH', "/companies/$a", ['active' => false, 'founded_year' => null, 'description' => '<p> </p>']);
        $this->assertSame([false, null, '', 'Erste Kompanie'], [$res->data['active'], $res->data['founded_year'], $res->data['description'], $res->data['name']], 'aufgelöst, Rest bleibt');
        $this->assertSame(200, $this->call('DELETE', "/companies/$b")->status);
        $this->assertSame([$a], array_column($this->call('GET', '/companies')->data, 'id'), 'ehemalige bleiben in der Liste');
        $this->assertSame(404, $this->call('PATCH', "/companies/$b", ['name' => 'x'])->status);
    }

    public function testRejectsInvalid(): void
    {
        foreach ([
            'ohne Namen' => ['name' => ' '],
            'Name fehlt' => ['short_name' => 'X'],
            'Name zu lang' => ['name' => str_repeat('x', CompanyService::NAME_MAX + 1)],
            'Kurzname zu lang' => ['name' => 'A', 'short_name' => str_repeat('x', CompanyService::SHORT_MAX + 1)],
            'Jahr zu früh' => ['name' => 'A', 'founded_year' => 1799],
            'Jahr in der Zukunft' => ['name' => 'A', 'founded_year' => 9999],
            'nur http' => ['name' => 'A', 'website_url' => 'http://example.org/'],
            'App nur https' => ['name' => 'A', 'app_url' => 'javascript:alert(1)'],
            'ohne Endung' => ['name' => 'A', 'website_url' => 'example'],
            'mit Zugangsdaten' => ['name' => 'A', 'app_url' => 'https://nutzer:geheim@example.org/'],
            'aktiv kein Wahrheitswert' => ['name' => 'A', 'active' => 'ja'],
        ] as $case => $body) {
            $this->assertSame(400, $this->call('POST', '/companies', $body)->status, $case);
        }
        $this->assertSame([], $this->call('GET', '/companies')->data);
    }

    public function testLinksWithoutScheme(): void
    {
        $res = $this->call('POST', '/companies', ['name' => 'A', 'website_url' => ' example.org ', 'app_url' => 'Example.org/app']);
        $this->assertSame(['https://example.org', 'https://Example.org/app'], [$res->data['website_url'], $res->data['app_url']]);
        $res = $this->call('PATCH', '/companies/' . $res->data['id'], ['app_url' => 'http://example.org/app']);
        $this->assertSame([400, '„App der Kompanie“ muss mit https:// beginnen (sichere Verbindung), nicht mit http://.'], [$res->status, $res->data['message'] ?? null]);
        $res = $this->call('POST', '/companies', ['name' => 'B', 'website_url' => 'example']);
        $this->assertSame('„Webseite“ ist keine gültige Webadresse. Beispiel: beispiel.de oder https://beispiel.de/seite', $res->data['message'] ?? null, 'Meldung nennt das Feld');
    }

    public function testRightsAndFeature(): void
    {
        $id = $this->company(['name' => 'Erste']);
        $this->users->current = $this->member;
        $this->assertSame(200, $this->call('GET', '/companies')->status);
        $this->assertArrayNotHasKey('member_ids', $this->call('GET', '/companies')->data[0], 'nur für die Pflege');
        $this->assertSame(403, $this->call('POST', '/companies', ['name' => 'X'])->status);
        $this->assertSame(403, $this->call('PUT', "/members/{$this->member}/companies", ['company_ids' => [$id]])->status);
        $this->users->current = null;
        $this->assertSame(['Erste'], array_column($this->call('GET', '/companies')->data, 'name'), 'ohne Anmeldung');
        $this->assertSame(401, $this->call('POST', '/companies', ['name' => 'X'])->status);

        $this->assertSame(TestApp::caps(['member', 'manage_companies']), $this->app->roles()->find('companies')['capabilities'] ?? null);
        $this->assertSame('Kompanien verwalten', $this->app->roles()->find('companies')['label'] ?? null);
        $pfleger = $this->users->add('pfleger', 'companies');
        $this->users->current = $pfleger;
        $this->assertSame(200, $this->call('PUT', "/members/{$this->member}/companies", ['company_ids' => [$id]])->status, 'Standardrolle ordnet zu');
        $this->assertSame(200, $this->call('GET', '/members')->status, 'und sieht dafür die Mitgliederliste der Verwaltung');

        $this->users->current = $this->admin;
        $this->app->features()->update(['companies' => false]);
        $this->assertSame(403, $this->call('GET', '/companies')->status, 'Bereich aus');
        $this->assertArrayNotHasKey('companies', $this->call('GET', '/directory')->data[0], 'auch nicht in der Mitgliederliste');
    }

    public function testAssignBothWaysAndVisibility(): void
    {
        $a = $this->company(['name' => 'Erste']);
        $b = $this->company(['name' => 'Zweite']);
        $res = $this->call('PUT', "/members/{$this->member}/companies", ['company_ids' => [$b, $a, $a]]);
        $this->assertSame([$a, $b], array_column($res->data['companies'], 'id'), 'mehrere, in der Reihenfolge der Liste, je einmal');
        $this->assertSame(400, $this->call('PUT', "/members/{$this->member}/companies", ['company_ids' => [999]])->status);
        $this->assertSame(400, $this->call('PUT', "/members/{$this->guest}/companies", ['company_ids' => [$a]])->status, 'nur Mitglieder');
        $this->assertSame(400, $this->call('PUT', "/companies/$a/members", ['user_ids' => [$this->member, $this->guest]])->status);
        $this->assertSame([$this->admin, $this->member], $this->call('PUT', "/companies/$a/members", ['user_ids' => [$this->member, $this->admin]])->data['member_ids']);
        $this->assertSame([$a, $b], array_column($this->call('GET', "/members/{$this->member}")->data['companies'], 'id'), 'Mitgliedsseite der Verwaltung');

        // Mitglieder der Kompanie und Kompanien in der Mitgliederliste: nur mit Mitgliederliste
        $this->users->current = $this->member;
        $list = array_column($this->call('GET', '/companies')->data, null, 'id');
        $this->assertSame(['Admin', 'Anna'], array_column($list[$a]['members'], 'name'));
        $this->assertSame(2, $list[$a]['member_count']);
        $dir = array_column($this->call('GET', '/directory')->data, null, 'id');
        $this->assertSame(['Erste', 'Zweite'], array_column($dir[$this->member]['companies'], 'name'));
        $this->assertArrayNotHasKey($this->guest, $dir, 'Gäste stehen nicht in der Mitgliederliste');

        $this->users->current = $this->admin;
        $this->app->roles()->update(Roles::GUEST, ['capabilities' => ['view_news', 'view_companies']]);
        $this->users->current = null;
        $anon = $this->call('GET', '/companies')->data[0];
        $this->assertArrayNotHasKey('members', $anon, 'ohne view_directory keine Namen');
        $this->assertArrayNotHasKey('member_count', $anon);

        // Gast mit view_directory sieht die Namen, wenn der Bereich Mitgliederliste an ist
        $this->users->current = $this->admin;
        $this->app->roles()->update(Roles::GUEST, ['capabilities' => ['view_news', 'view_companies', 'view_directory']]);
        $this->users->current = null;
        $list = array_column($this->call('GET', '/companies')->data, null, 'id');
        $this->assertSame(['Admin', 'Anna'], array_column($list[$a]['members'], 'name'), 'Gast mit view_directory');
        // Wie in der Mitgliederliste (Foto, Titel, Dienstgrad), aber ohne Geburtstag, Eintritt und Kompanien
        $keys = array_keys($list[$a]['members'][0]);
        foreach (['id', 'name', 'photo_url'] as $key) {
            $this->assertContains($key, $keys);
        }
        foreach (['birthday', 'join_year', 'join_date', 'companies', 'email', 'phone'] as $key) {
            $this->assertNotContains($key, $keys);
        }

        // Bereich Mitgliederliste aus: keine Mitglieder auf den Kompanieseiten, auch nicht mit view_directory
        $this->users->current = $this->admin;
        $this->app->features()->update(['directory' => false]);
        foreach ([null, $this->member, $this->admin] as $viewer) {
            $this->users->current = $viewer;
            $list = array_column($this->call('GET', '/companies')->data, null, 'id');
            $this->assertArrayNotHasKey('members', $list[$a], 'ohne Bereich Mitgliederliste keine Namen');
            $this->assertArrayNotHasKey('member_count', $list[$a]);
        }
        $this->assertSame([$this->admin, $this->member], $list[$a]['member_ids'], 'Zuordnung bleibt, Verwaltung sieht sie');
        $this->app->features()->update(['directory' => true]);

        // Personen löschen: Zuordnung weg
        $this->users->current = $this->admin;
        UserData::purge($this->app->db, $this->member);
        $this->assertSame([$this->admin], $this->app->companies()->repository()->memberIdsOf($a));
    }

    public function testOfficeGroupNotLinked(): void
    {
        // Ämter gehören zu Personen: Eine Gruppe der Ämter lässt sich nicht mehr mit einer Kompanie verknüpfen.
        $group = $this->call('POST', '/office-groups', ['title' => 'Vorstand Erste'])->data['id'];
        $id = $this->company(['name' => 'Erste', 'office_group_id' => $group]);
        $this->assertArrayNotHasKey('office_group', $this->call('GET', '/companies')->data[0]);
        $this->assertNull($this->app->companies()->repository()->find($id)['office_group_id'] ?? null);
        $this->assertSame(200, $this->call('DELETE', "/office-groups/$group")->status);
    }

    public function testLogoAndDelivery(): void
    {
        $id = $this->company(['name' => 'Erste']);
        $res = $this->call('POST', "/companies/$id/logo", ['file' => ['name' => 'logo.svg', 'data' => base64_encode(self::SVG)]]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame('svg', $res->data['logo_type']);
        $this->assertMatchesRegularExpression('#^https://example\.org/app/kompanielogo/' . $id . '\?v=[0-9a-f]{16}$#', (string) $res->data['logo_url']);
        $out = $this->app->companies()->logoForDelivery(null, $id, substr((string) $res->data['logo_url'], -16));
        $this->assertSame(200, $out['status']);
        $this->assertSame('public, max-age=31536000, immutable', $out['headers']['Cache-Control']);
        $this->assertStringNotContainsString('script', (string) ($out['body'] ?? ''));
        $this->assertSame(400, $this->call('POST', "/companies/$id/logo", ['file' => ['name' => 'a.gif', 'data' => base64_encode('GIF89a')]])->status);
        $this->assertNull($this->call('DELETE', "/companies/$id/logo")->data['logo_url']);
        $this->assertSame(404, $this->app->companies()->logoForDelivery(null, $id, null)['status']);
        $this->app->features()->update(['companies' => false]);
        $this->assertSame(404, $this->app->companies()->logoForDelivery(null, $id, null)['status'], 'Bereich aus');
    }

    /** Transparente Logos bleiben transparent: PNG mit Alphakanal, Ecke durchsichtig, Fläche deckend. */
    public function testPngLogoKeepsTransparency(): void
    {
        $id = $this->company(['name' => 'Erste']);
        $im = imagecreatetruecolor(1024, 512);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, (int) imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 256, 128, 767, 383, (int) imagecolorallocate($im, 20, 20, 220));
        ob_start();
        imagepng($im);
        $res = $this->call('POST', "/companies/$id/logo", ['file' => ['name' => 'logo.png', 'data' => base64_encode((string) ob_get_clean())]]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame('png', $res->data['logo_type']);
        $out = $this->app->companies()->logoForDelivery(null, $id, null);
        $this->assertSame('image/png', $out['headers']['Content-Type']);
        $stored = imagecreatefromstring((string) $out['body']);
        $this->assertNotFalse($stored);
        $this->assertSame([512, 256], [imagesx($stored), imagesy($stored)], 'auf 512 Pixel verkleinert');
        $this->assertSame(127, imagecolorsforindex($stored, imagecolorat($stored, 2, 2))['alpha'], 'Ecke durchsichtig, nicht weiß');
        $center = imagecolorsforindex($stored, imagecolorat($stored, 256, 128));
        $this->assertSame([0, 220], [$center['alpha'], $center['blue']], 'Fläche deckend');
    }

    public function testDescriptionImagesArePublicWithViewCompanies(): void
    {
        $png = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();
        $up = $this->call('POST', '/rich-images', ['file' => ['name' => 'bild.png', 'data' => base64_encode($bytes)]]);
        $this->assertSame(201, $up->status);
        $uid = $up->data['uid'];
        $d = fn (?int $user, string $u = ''): array => $this->app->richImages()->forDelivery($user === null ? null : $this->users->find($user), $u === '' ? $uid : $u);
        $this->assertSame(401, $d(null)['status'], 'Entwurf: ohne Anmeldung nicht');

        $id = $this->company(['name' => 'Erste', 'description' => '<p>Bild:</p><img data-image="' . $uid . '">']);
        $desc = $this->call('GET', '/companies')->data[0]['description'];
        $this->assertSame('<p>Bild:</p><img src="https://example.org/app/bild/' . $uid . '" data-image="' . $uid . '">', $desc, 'Bild gehört der Kompanie, mit Adresse');
        $anon = $d(null);
        $this->assertSame(200, $anon['status'], 'öffentlich: Gäste haben view_companies');
        $this->assertSame('public, max-age=86400', $anon['headers']['Cache-Control']);
        $this->assertSame(200, $d($this->member)['status']);

        // Brett-Bilder bleiben ohne Anmeldung gesperrt (Besitzer nicht öffentlich)
        $this->assertSame(401, $d(null, str_repeat('a', 32))['status']);

        // Gäste ohne Sehrecht: wie heute 401; mit Anmeldung und Recht weiter sichtbar
        $this->app->roles()->update(Roles::GUEST, ['capabilities' => ['view_news']]);
        $this->assertSame(401, $d(null)['status']);
        $this->assertSame(403, $d($this->guest)['status']);
        $this->assertSame(200, $d($this->member)['status']);
        $this->assertSame('private, max-age=86400', $d($this->member)['headers']['Cache-Control']);
        $this->app->roles()->update(Roles::GUEST, ['capabilities' => ['view_news', 'view_companies']]);
        $this->app->features()->update(['companies' => false]);
        $this->assertSame(401, $d(null)['status'], 'Bereich aus');
        $this->assertSame(403, $d($this->admin)['status']);
        $this->app->features()->update(['companies' => true]);

        // Bild aus der Beschreibung genommen: gelöscht; Kompanie gelöscht: ihre Bilder auch
        $this->call('PATCH', "/companies/$id", ['description' => '<p>ohne</p>']);
        $this->assertNull($this->app->richImages()->repository()->find($uid));
        $up2 = $this->call('POST', '/rich-images', ['file' => ['name' => 'bild.png', 'data' => base64_encode($bytes)]])->data['uid'];
        $this->call('PATCH', "/companies/$id", ['description' => '<img data-image="' . $up2 . '">']);
        $this->assertSame(200, $d(null, $up2)['status']);
        $this->call('DELETE', "/companies/$id");
        $this->assertNull($this->app->richImages()->repository()->find($up2));
    }

    public function testBackup(): void
    {
        $this->assertContains('companies', Backup::CORE_TABLES);
        $this->assertContains('member_companies', Backup::CORE_TABLES);
        $id = $this->company(['name' => 'Gesichert']);
        $this->call('PUT', "/members/{$this->member}/companies", ['company_ids' => [$id]]);
        $backup = new Backup($this->app->db);
        $tables = $backup->dump(['companies', 'member_companies']);
        $this->app->db->execute('DELETE FROM {member_companies}');
        $this->app->db->execute('DELETE FROM {companies}');
        $backup->restore($tables, ['companies', 'member_companies']);
        $this->assertSame([$this->member], $this->call('GET', '/companies')->data[0]['member_ids']);
    }
}
