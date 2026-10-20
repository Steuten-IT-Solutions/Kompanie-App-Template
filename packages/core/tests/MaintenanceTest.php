<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Push\PushService;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\NullScheduler;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Wartungsmodus (Issue #42): Sperre für alle außer Admins, Ausnahmen, Schalten mit Begründung, Aufgaben. */
final class MaintenanceTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $manager;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->app = TestApp::create(TestApp::connection(), $this->users);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->manager = $this->users->add('leitung', 'points');
        $this->member = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body));
    }

    public function testSwitchWithReasonAndLog(): void
    {
        $this->assertFalse($this->call('GET', '/config')->data['maintenance']['on']);
        $res = $this->call('POST', '/admin/maintenance', ['on' => true, 'reason' => "Umstellung auf die neue App,\nbis ca. 18 Uhr <b>"]);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame([true, "Umstellung auf die neue App,\nbis ca. 18 Uhr"], [$res->data['on'], $res->data['reason']], 'Klartext, Zeilenumbruch bleibt');
        $since = $res->data['since'];

        // Begründung während der Wartung ändern: seit bleibt.
        $res = $this->call('POST', '/admin/maintenance', ['on' => true, 'reason' => 'Dauert länger']);
        $this->assertSame(['Dauert länger', $since], [$res->data['reason'], $res->data['since']]);
        $this->assertSame(400, $this->call('POST', '/admin/maintenance', ['on' => true, 'reason' => str_repeat('x', 501)])->status);
        $this->assertSame(400, $this->call('POST', '/admin/maintenance', ['on' => 'ja'])->status);

        $res = $this->call('POST', '/admin/maintenance', ['on' => false]);
        $this->assertSame([false, ''], [$res->data['on'], $res->data['reason']]);
        $this->assertCount(3, $res->data['log']);
        $this->assertSame(['Admin', false], [$res->data['log'][0]['by_name'], $res->data['log'][0]['on']], 'neueste zuerst, mit Namen');

        $this->users->current = $this->manager;
        $this->assertSame(403, $this->call('POST', '/admin/maintenance', ['on' => true])->status, 'nur Admins schalten');
    }

    public function testOnlyAdminsPassWhileActive(): void
    {
        $this->call('POST', '/admin/maintenance', ['on' => true, 'reason' => 'Umstellung']);
        $this->assertSame(200, $this->call('GET', '/me')->status, 'Admin arbeitet normal');
        $this->assertSame(200, $this->call('GET', '/events')->status);

        foreach ([$this->manager, $this->member, null] as $who) {
            $this->users->current = $who;
            foreach ([['GET', '/events'], ['GET', '/me'], ['GET', '/gallery'], ['POST', '/points', ['user_ids' => [1], 'points' => 1, 'reason' => 'x']]] as $r) {
                $res = $this->call($r[0], $r[1], $r[2] ?? []);
                $this->assertSame(503, $res->status, $r[1]);
                $this->assertSame(['maintenance', 'Umstellung'], [$res->data['code'], $res->data['reason']]);
            }
            $config = $this->call('GET', '/config');
            $this->assertSame(200, $config->status, 'Konfiguration bleibt erreichbar');
            $this->assertSame(['on' => true, 'reason' => 'Umstellung'], array_intersect_key($config->data['maintenance'], ['on' => 1, 'reason' => 1]));
            $this->assertNotSame(503, $this->call('POST', '/auth/login', ['username' => 'x', 'password' => 'y'])->status, 'Anmeldung bleibt möglich');
        }
        $this->assertSame(503, $this->app->documents()->forDelivery($this->users->find($this->member), 1)['status']);

        $this->users->current = $this->admin;
        $this->call('POST', '/admin/maintenance', ['on' => false]);
        $this->users->current = $this->member;
        $this->assertSame(200, $this->call('GET', '/me')->status, 'danach wieder normal');
    }

    public function testBackgroundTasksPauseExceptUpdatesAndPushIsSentAfterwards(): void
    {
        $scheduler = new NullScheduler();
        $app = TestApp::create(TestApp::connection(), $this->users, scheduler: $scheduler);
        $admin = $this->users->find($this->admin);
        $app->maintenance()->set(true, '', $admin);
        $app->runTask('calendar_refresh');
        $app->runTask(PushService::TASK_SEND);
        $this->assertTrue($app->maintenance()->active());
        $this->assertTrue(\KompanieApp\Core\Domain\MaintenanceService::runsDuringMaintenance('update_check'));
        $this->assertFalse(\KompanieApp\Core\Domain\MaintenanceService::runsDuringMaintenance(PushService::TASK_SEND));
        $this->assertTrue($app->statusReporter()->payload()['maintenance']);
        $this->assertArrayNotHasKey('reason', $app->statusReporter()->payload());

        $app->maintenance()->set(false, null, $admin);
        $this->assertContains(PushService::TASK_SEND, $scheduler->soon, 'Warteschlange gleich nach dem Ende');
        $this->assertFalse($app->statusReporter()->payload()['maintenance']);
    }
}
