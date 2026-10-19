<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\CalendarService;
use KompanieApp\Core\Domain\GalleryService;
use KompanieApp\Core\Storage\Sql\PdoConnection;
use KompanieApp\Core\Tests\Support\CountingPdo;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\MemorySettings;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Aufgaben werden nur eingeplant, wenn sich etwas geändert hat – nicht bei jeder Anfrage. */
final class SchedulingTest extends TestCase
{
    public function testNormalRequestDoesNotTouchTheScheduler(): void
    {
        $pdo = new CountingPdo('sqlite::memory:');
        $settings = new MemorySettings();
        $app = TestApp::create(new PdoConnection($pdo, 'kompanie_'), new FakeUsers(), [], null, null, $settings);
        $this->assertSame('0.0.0', $settings->get(App::PLAN_SETTING), 'nach der Einrichtung eingeplant');
        $calls = $app->scheduler->calls;

        // Was jede Anfrage tut: upgrade() (Migrationen prüfen) – danach weder planen noch schreiben.
        $pdo->log = [];
        $app->upgrade();
        $app->upgrade();
        $this->assertSame($calls, $app->scheduler->calls, 'keine Aufgabenplanung in normalen Anfragen');
        $this->assertSame([], $pdo->writes(), 'keine Schreibzugriffe in normalen Anfragen');
    }

    public function testRescheduleAfterUpdateAndSettingsChanges(): void
    {
        $settings = new MemorySettings();
        $app = TestApp::create(TestApp::connection(), new FakeUsers(), [], null, null, $settings);
        $calls = $app->scheduler->calls;

        // Nach einem Update (andere Version als bei der letzten Planung): neu einplanen.
        $settings->set(App::PLAN_SETTING, '0.0.0-alt');
        $app->upgrade();
        $this->assertGreaterThan($calls, $app->scheduler->calls);
        $this->assertSame('0.0.0', $settings->get(App::PLAN_SETTING));

        // Einstellungen speichern plant ein bzw. aus.
        $this->assertArrayNotHasKey(CalendarService::TASK, $app->scheduler->scheduled);
        $app->settingsService()->update(['calendar_ics_url' => 'https://example.org/k.ics']);
        $this->assertArrayHasKey(CalendarService::TASK, $app->scheduler->scheduled);
        $app->features()->update(['calendar' => false]);
        $this->assertArrayNotHasKey(CalendarService::TASK, $app->scheduler->scheduled, 'Bereich aus: Aufgabe ausgeplant');
        $this->assertArrayNotHasKey(GalleryService::TASK, $app->scheduler->scheduled, 'Galerie nicht eingerichtet');
    }
}
