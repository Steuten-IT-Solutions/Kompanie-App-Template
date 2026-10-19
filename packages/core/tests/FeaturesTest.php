<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\Auth\Level;
use KompanieApp\Core\Domain\Features;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Bereiche ein- und ausschalten (Issue #7). */
final class FeaturesTest extends TestCase
{
    public function testNewInstallationStartsWithoutFeatures(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users, features: false);
        $this->assertSame(array_fill_keys(Features::keys(), false), $app->features()->all());
        $this->assertSame(array_fill_keys(Features::keys(), false), $app->api()->config()['features']);

        $res = $app->api()->handle(new Request('GET', '/events'));
        $this->assertSame(403, $res->status);
        $this->assertSame('feature_disabled', $res->data['code']);
    }

    public function testExistingInstallationKeepsFeaturesFromOldKompanieJson(): void
    {
        $db = TestApp::connection();
        $settings = new MemorySettings();
        // Stand vor dieser Version: Datenbank eingerichtet, aber noch keine Bereiche in den Einstellungen.
        TestApp::create($db, settings: $settings, features: false);
        $settings->delete(Features::SETTING);

        $app = TestApp::create($db, config: ['features' => ['penalties' => false]], settings: $settings, features: false);
        $all = $app->features()->all();
        $this->assertFalse($all['penalties'], 'ausgeschaltet bleibt aus');
        $this->assertTrue($all['points'], 'ohne Angabe galt früher „an“');
        $this->assertTrue($all['gallery']);

        // Nur einmalig: Danach gilt allein die Einstellung, auch wenn kompanie.json anderes sagt.
        $app->features()->update(['penalties' => true, 'points' => false]);
        $again = TestApp::create($db, config: ['features' => ['penalties' => false]], settings: $settings, features: false);
        $this->assertTrue($again->features()->enabled('penalties'));
        $this->assertFalse($again->features()->enabled('points'));
    }

    public function testOnlyAdminsManageFeatures(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users, features: ['points' => true]);
        $h = static fn (string $m, array $body = []) => $app->api()->handle(new Request($m, '/admin/features', [], $body));

        $this->assertSame(401, $h('GET')->status);
        foreach ([Level::Manager, Level::Member, Level::Guest] as $level) {
            $users->current = $users->add('u-' . $level->value, $level);
            $this->assertSame(403, $h('GET')->status, $level->value);
            $this->assertSame(403, $h('PUT', ['news' => true])->status, $level->value);
        }

        $users->current = $users->add('admin', Level::Admin);
        $list = $h('GET')->data;
        $this->assertSame(Features::keys(), array_column($list, 'key'));
        $byKey = array_column($list, null, 'key');
        $this->assertTrue($byKey['points']['enabled']);
        $this->assertFalse($byKey['news']['enabled']);
        $this->assertNull($byKey['points']['configured'], 'Punkte brauchen keine Einrichtung');
        $this->assertFalse($byKey['calendar']['configured'], 'Kalender ohne Adresse');
        $this->assertSame('kalender', $byKey['calendar']['setup']);

        $res = $h('PUT', ['news' => true, 'points' => false]);
        $this->assertSame(200, $res->status);
        $byKey = array_column($res->data, null, 'key');
        $this->assertTrue($byKey['news']['enabled']);
        $this->assertFalse($byKey['points']['enabled']);
        $this->assertFalse($byKey['events']['enabled'], 'nicht genannte Bereiche bleiben');

        $this->assertSame(400, $h('PUT', ['inventar' => true])->status, 'unbekannter Bereich');
        $this->assertSame(400, $h('PUT', ['news' => 'ja'])->status, 'kein Wahrheitswert');
        $this->assertTrue($app->features()->enabled('news'), 'ungültige Anfrage ändert nichts');
    }

    public function testDisabledFeatureKeepsItsData(): void
    {
        $users = new FakeUsers();
        $app = TestApp::create(TestApp::connection(), $users);
        $users->current = $users->add('admin', Level::Admin);
        $created = $app->api()->handle(new Request('POST', '/events', [], ['title' => 'Schützenfest', 'start_date' => '2099-07-01']));
        $this->assertSame(201, $created->status);

        $app->features()->update(['events' => false]);
        $this->assertSame(403, $app->api()->handle(new Request('GET', '/events'))->status);
        $this->assertFalse($app->api()->config()['features']['events']);

        $app->features()->update(['events' => true]);
        $events = $app->api()->handle(new Request('GET', '/events'))->data;
        $this->assertSame(['Schützenfest'], array_column($events, 'title'));
    }

    public function testBackgroundTasksOfDisabledFeaturesDoNotRun(): void
    {
        $account = (string) json_encode(['client_email' => 'galerie@example.iam.gserviceaccount.com', 'private_key' => 'x']);
        $settings = new MemorySettings([GalleryService::ROOT_SETTING => 'ABC', 'drive_service_account' => $account]);
        $app = TestApp::create(TestApp::connection(), settings: $settings);
        $this->assertTrue($app->gallery()->isConfigured());
        $this->assertArrayHasKey(GalleryService::TASK, $app->scheduler->scheduled);
        $app->features()->update(['gallery' => false]);
        $this->assertArrayNotHasKey(GalleryService::TASK, $app->scheduler->scheduled);
    }
}
