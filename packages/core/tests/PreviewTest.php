<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Capability;
use KompanieApp\Core\Auth\Preview;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * Vorschau „App als Rolle ansehen“ (Issue #63): nur für Admins, Rechte genau der Rolle ohne Zusatzrollen, @public wie
 * ohne Anmeldung, nur lesend (auf dem Server), Dateien mit den Rechten der Rolle, Wartungsmodus.
 */
final class PreviewTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $m;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $dir = sys_get_temp_dir() . '/kompanie-preview-' . bin2hex(random_bytes(4));
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], null, $dir);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->m = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    /** @param array<string, mixed> $query */
    private function call(string $method, string $path, array $body = [], ?string $preview = null, array $query = []): Response
    {
        return $this->app->api()->handle(new Request($method, $path, $query, $body, [], '', $preview));
    }

    public function testValueFromHeaderOrQuery(): void
    {
        $this->assertNull(Preview::valueFrom(null, null));
        $this->assertNull(Preview::valueFrom('  ', ''));
        $this->assertSame('member', Preview::valueFrom(' member ', 'guest'), 'der Header zählt zuerst');
        $this->assertSame('guest', Preview::valueFrom(null, 'guest'));
        $this->assertSame('@public', Preview::valueFrom('', '@public'));
        $this->assertNull(Preview::valueFrom(['member'], ['guest']), 'nur Zeichenketten');
        $this->assertSame(Preview::MAX_LENGTH, mb_strlen((string) Preview::valueFrom(str_repeat('a', 100))));
    }

    public function testRoleRightsWithoutExtraRolesSamePerson(): void
    {
        // Gespeicherte Zusatzrollen am Admin-Konto (z. B. aus der Zeit vor seiner Ernennung) dürfen nicht mitwirken.
        $this->app->roles()->setExtraRoles($this->admin, ['points']);
        $this->assertSame(['points'], $this->app->roles()->extraRoles($this->admin, Roles::MEMBER), 'ohne Vorschau-Kennzeichen wirkten sie');
        $preview = $this->app->preview();
        $preview->begin('member');
        $user = $this->app->authContext->currentUser();
        $this->assertNotNull($user);
        $this->assertSame([$this->admin, 'admin', Roles::MEMBER, true], [$user->id, $user->login, $user->role, $user->preview]);
        $this->assertFalse($user->isAdmin());
        $this->assertSame(TestApp::capabilities(['member']), $user->capabilities);
        $this->assertSame([], $this->app->roles()->extraRolesOf($user));
        $this->assertSame($this->admin, $preview->realUser()?->id);
        $this->assertSame($user, $this->app->authContext->currentUser(), 'einmal je Anfrage gebaut');

        $preview->begin('points');
        $caps = $this->app->authContext->currentUser()?->capabilities ?? [];
        $this->assertContains(Capability::ManagePoints, $caps);
        $this->assertNotContains(Capability::ManageSettings, $caps);

        $preview->begin(Preview::PUBLIC);
        $this->assertNull($this->app->authContext->currentUser());
        $this->assertTrue($preview->active());

        $preview->begin(null);
        $this->assertTrue($this->app->authContext->currentUser()?->isAdmin());
        $this->assertFalse($preview->requested());
    }

    public function testOnlyAdminsAndOnlyKnownRoles(): void
    {
        $preview = $this->app->preview();
        foreach (['admin', 'r99', 'gibtsnicht'] as $value) {
            $preview->begin($value);
            $this->assertNull($preview->role(), $value);
            $this->assertTrue($preview->requested(), 'angefragt: trotzdem nur lesend');
            $this->assertTrue($this->app->authContext->currentUser()?->isAdmin(), $value . ': ohne Vorschau');
        }
        $this->users->current = $this->m;
        $preview->begin('admin');
        $this->assertNull($preview->role());
        $this->assertSame(Roles::MEMBER, $this->app->authContext->currentUser()?->role, 'Nicht-Admins: eigene Rechte, nie mehr');
        $preview->begin(Preview::PUBLIC);
        $this->assertSame($this->m, $this->app->authContext->currentUser()?->id);
        $this->users->current = null;
        $preview->begin('member');
        $this->assertNull($this->app->authContext->currentUser(), 'ohne Anmeldung keine Vorschau');
        $preview->begin(null);
    }

    public function testReadOnlyOnServerExceptLogout(): void
    {
        foreach ([['POST', '/board', ['title' => 'Titel', 'text' => 'Hallo', 'push' => false]], ['PUT', '/me/birthday', ['month' => 1, 'day' => 2]], ['POST', '/me/push/test', []], ['POST', '/auth/login', ['username' => 'admin', 'password' => 'x']], ['PATCH', '/admin/settings', []], ['DELETE', "/members/{$this->m}", []]] as [$method, $path, $body]) {
            $res = $this->call($method, $path, $body, 'member');
            $this->assertSame([403, 'preview_readonly', 'In der Vorschau kannst du nichts ändern.'], [$res->status, $res->data['code'] ?? null, $res->data['message'] ?? null], "$method $path");
        }
        $this->assertSame(403, $this->call('POST', '/board', ['title' => 'Titel', 'text' => 'Hallo'], null, [Preview::QUERY => 'member'])->status, 'auch über den Parameter');
        $this->assertSame('preview_readonly', $this->call('POST', '/board', ['title' => 'Titel', 'text' => 'Hallo'], 'gibtsnicht')->data['code'] ?? null, 'auch ungültig');
        $this->assertSame(200, $this->call('POST', '/auth/logout', [], 'member')->status, 'Abmelden geht');
        $this->assertFalse($this->app->preview()->requested(), 'nach der Anfrage keine Vorschau mehr');
        $this->users->current = $this->admin;
        $this->assertSame(201, $this->call('POST', '/board', ['title' => 'Titel', 'text' => 'Hallo', 'push' => false])->status, 'ohne Vorschau wieder möglich');
    }

    public function testConfigReflectsPreview(): void
    {
        $d = $this->call('GET', '/config', [], 'member')->data;
        $this->assertSame(['role' => 'member', 'label' => $this->app->roles()->label('member')], $d['preview']);
        $this->assertSame(Roles::MEMBER, $d['user']['level']);
        $this->assertFalse($d['setupPending']);
        $this->assertNotSame('', $d['csrf']['token'], 'Token der echten Sitzung (zum Abmelden)');
        $d = $this->call('GET', '/config', [], null, [Preview::QUERY => Preview::PUBLIC])->data;
        $this->assertSame(['role' => '@public', 'label' => 'ohne Anmeldung'], $d['preview']);
        $this->assertNull($d['user']);
        $this->assertNull($this->call('GET', '/config')->data['preview']);
    }

    public function testMaintenanceShowsWhatTheRoleSees(): void
    {
        $this->assertSame(200, $this->call('POST', '/admin/maintenance', ['on' => true, 'reason' => 'Umbau'])->status);
        $this->assertSame(200, $this->call('GET', '/events')->status, 'Admin');
        $this->assertSame(503, $this->call('GET', '/events', [], 'member')->status, 'als Mitglied: Wartung');
        $this->assertSame(503, $this->call('GET', '/events', [], Preview::PUBLIC)->status);
        $d = $this->call('GET', '/config', [], 'member')->data;
        $this->assertTrue($d['maintenance']['on']);
        $this->assertSame('member', $d['preview']['role'], 'Vorschau bleibt erkennbar (Banner mit „Beenden“)');
    }

    public function testDocumentsAndBadgesWithRightsOfTheRole(): void
    {
        $doc = fn (string $title, string $visibility): array => $this->call('POST', '/documents', ['title' => $title, 'date' => '2026-10-01', 'visibility' => $visibility, 'file' => ['name' => 'a.pdf', 'data' => base64_encode(self::PDF)]])->data;
        $all = $doc('Satzung', 'members');
        $mgr = $doc('Kasse', 'managers');
        $this->assertStringNotContainsString('vorschau', $all['url']);

        $urls = array_column($this->call('GET', '/documents', [], 'member')->data['documents'], 'url', 'title');
        $this->assertSame(['Satzung'], array_keys($urls));
        $this->assertStringEndsWith('&vorschau=member', $urls['Satzung']);
        $this->assertStringEndsWith('&vorschau=%40public', (function (): string {
            $this->app->preview()->begin(Preview::PUBLIC);
            try {
                return $this->app->env->documentUrl(1, 'x');
            } finally {
                $this->app->preview()->begin(null);
            }
        })());

        // Auslieferung, wie die Adapter sie aufrufen: Vorschau aus der Adresse, dann der aktuelle Benutzer.
        $deliver = function (?string $preview, int $id): array {
            $this->app->preview()->begin($preview);
            try {
                return $this->app->documents()->forDelivery($this->app->authContext->currentUser(), $id);
            } finally {
                $this->app->preview()->begin(null);
            }
        };
        $this->assertSame(200, $deliver(null, $mgr['id'])['status'], 'Admin');
        $this->assertSame(404, $deliver('member', $mgr['id'])['status'], 'als Mitglied: eingeschränktes Dokument nicht');
        $ok = $deliver('member', $all['id']);
        $this->assertSame([200, 'no-store'], [$ok['status'], $ok['cache_control'] ?? null], 'nicht speichern');
        $this->assertSame(403, $deliver('guest', $all['id'])['status']);
        $this->assertSame(401, $deliver(Preview::PUBLIC, $all['id'])['status']);

        $rank = $this->call('POST', '/ranks', ['title' => 'Gefreiter'])->data['id'];
        $im = imagecreatetruecolor(32, 32);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        $this->assertSame(200, $this->call('POST', "/ranks/$rank/badge", ['file' => ['name' => 'a.png', 'data' => base64_encode($png)]])->status);
        $badge = function (?string $preview) use ($rank): array {
            $this->app->preview()->begin($preview);
            try {
                return $this->app->ranks()->badgeForDelivery($this->app->authContext->currentUser(), $rank, null, '128');
            } finally {
                $this->app->preview()->begin(null);
            }
        };
        $cand = TestApp::candidateRole($this->app);
        $this->assertSame([200, 'no-store'], [$badge($cand)['status'], $badge($cand)['headers']['Cache-Control']]);
        $this->assertNotSame('no-store', $badge(null)['headers']['Cache-Control']);
        // Mit der öffentlichen Mitgliederliste (Issue #112) sieht jeder die Abzeichen, sonst nur Mitglieder und Anwärter.
        $this->assertSame(200, $badge('guest')['status']);
        $this->assertSame(200, $badge(Preview::PUBLIC)['status']);
        $this->app->features()->update(['directory' => false]);
        $this->assertSame(403, $badge('guest')['status']);
        $this->assertSame(401, $badge(Preview::PUBLIC)['status']);
    }
}
