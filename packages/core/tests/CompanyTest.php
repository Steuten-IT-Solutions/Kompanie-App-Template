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
 * nur so weit wie in der Mitgliederliste, Logo, Verknüpfung mit Ämtern, Löschen der Person, Sicherung.
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
        $this->assertSame('<p>Seit <strong>1862</strong></p>', $list[0]['description'], 'bereinigt, ohne Bilder');
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
            'unbekannte Gruppe' => ['name' => 'A', 'office_group_id' => 999],
            'aktiv kein Wahrheitswert' => ['name' => 'A', 'active' => 'ja'],
        ] as $case => $body) {
            $this->assertSame(400, $this->call('POST', '/companies', $body)->status, $case);
        }
        $this->assertSame([], $this->call('GET', '/companies')->data);
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

        // Personen löschen: Zuordnung weg
        $this->users->current = $this->admin;
        UserData::purge($this->app->db, $this->member);
        $this->assertSame([$this->admin], $this->app->companies()->repository()->memberIdsOf($a));
    }

    public function testOfficeGroupLink(): void
    {
        $group = $this->call('POST', '/office-groups', ['title' => 'Vorstand Erste'])->data['id'];
        $id = $this->company(['name' => 'Erste', 'office_group_id' => $group]);
        $this->assertSame(['id' => $group, 'title' => 'Vorstand Erste', 'shown' => true], $this->call('GET', '/companies')->data[0]['office_group']);
        $this->assertSame(200, $this->call('DELETE', "/office-groups/$group")->status);
        $this->assertNull($this->call('GET', '/companies')->data[0]['office_group'], 'Gruppe gelöscht: Verknüpfung weg');
        $this->assertNull($this->app->companies()->repository()->find($id)['office_group_id'] ?? null);
        $this->assertNotNull($this->app->companies()->repository()->find($id));
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
