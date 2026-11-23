<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\AccessTokenService;
use KompanieApp\Core\Http\ApiError;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Http\Route;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Zugänge für KI-Assistenten (Plan „MCP-Server“, Schritt 1): Tokens ausstellen, prüfen, erneuern, widerrufen. */
final class AccessTokenTest extends TestCase
{
    private FakeUsers $users;
    private FixedClock $clock;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-23 10:00:00', new \DateTimeZone('UTC')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
    }

    private function call(string $method, string $path, array $body = [], ?string $bearer = null, ?string $preview = null): Response
    {
        return $this->app->api()->handle(new Request($method, $path, [], $body, [], '', $preview, null, $bearer));
    }

    private function enable(): void
    {
        $this->users->current = $this->admin;
        $this->assertSame(['enabled' => true], $this->call('PUT', '/admin/ai-access', ['enabled' => true])->data);
    }

    /** @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, scope: string, id: int} */
    private function issue(int $userId, string $scope = AccessTokenService::WRITE): array
    {
        return $this->app->accessTokens()->issue($userId, 'Claude', $scope);
    }

    public function testOffByDefault(): void
    {
        $this->assertFalse($this->call('GET', '/admin/ai-access')->data['enabled']);
        try {
            $this->issue($this->member);
            $this->fail('ohne Schalter kein Zugang');
        } catch (ApiError $e) {
            $this->assertSame('ai_access_disabled', $e->errorCode);
        }
        $this->assertSame(400, $this->call('PUT', '/admin/ai-access', ['enabled' => 'ja'])->status);
        $this->users->current = $this->member;
        $this->assertSame(403, $this->call('PUT', '/admin/ai-access', ['enabled' => true])->status, 'nur Admins schalten');
        $this->assertSame(403, $this->call('GET', '/admin/ai-access')->status);
    }

    public function testTokenActsAsThePersonOnTokenRoutesOnly(): void
    {
        $this->enable();
        $grant = $this->issue($this->member);
        $this->assertSame(['Bearer', 3600, 'write'], [$grant['token_type'], $grant['expires_in'], $grant['scope']]);
        $this->assertStringStartsWith('kat_', $grant['access_token']);
        $this->assertStringStartsWith('kar_', $grant['refresh_token']);

        // Ohne Anmeldung per Cookie
        $this->users->current = null;
        $me = $this->call('GET', '/me', [], $grant['access_token']);
        $this->assertSame(200, $me->status, (string) json_encode($me->data));
        $this->assertSame($this->member, $me->data['id']);

        // Mit Anmeldung eines anderen Kontos im selben Aufruf: Das Token zählt.
        $this->users->current = $this->admin;
        $this->assertSame($this->member, $this->call('GET', '/me', [], $grant['access_token'])->data['id']);
        // Vorschau gilt mit Token nicht (sonst wäre @public ohne Anmeldung)
        $this->assertSame(200, $this->call('GET', '/me', [], $grant['access_token'], '@public')->status);

        // Routen ohne Freigabe für Tokens (weder Route::$token noch ein MCP-Werkzeug) lehnen ab, auch lesende.
        $res = $this->call('GET', '/members', [], $grant['access_token']);
        $this->assertSame([403, 'token_not_allowed'], [$res->status, $res->data['code']]);
        $this->assertSame(403, $this->call('GET', '/admin/ai-access', [], $grant['access_token'])->status);

        // Danach gilt wieder die Anmeldung per Cookie.
        $this->assertSame($this->admin, $this->call('GET', '/me')->data['id']);
        $this->assertNotNull($this->app->accessTokens()->list($this->member)[0]['last_used_at']);
    }

    public function testInvalidExpiredAndSwitchedOff(): void
    {
        $this->enable();
        $grant = $this->issue($this->member);
        $this->users->current = null;
        foreach (['kat_falsch', 'irgendwas', $grant['refresh_token']] as $token) {
            $res = $this->call('GET', '/me', [], $token);
            $this->assertSame([401, 'invalid_token'], [$res->status, $res->data['code']], $token);
            $this->assertSame('Bearer error="invalid_token"', $res->headers['WWW-Authenticate']);
        }

        $this->app->accessTokens()->setEnabled(false);
        $this->assertSame(401, $this->call('GET', '/me', [], $grant['access_token'])->status, 'Schalter aus: kein Zugang gilt');
        $this->app->accessTokens()->setEnabled(true);
        $this->assertSame(200, $this->call('GET', '/me', [], $grant['access_token'])->status, 'wieder an: Zugang gilt wieder');

        $this->clock->at = $this->clock->now()->modify('+3601 seconds');
        $this->assertSame(401, $this->call('GET', '/me', [], $grant['access_token'])->status, 'nach einer Stunde abgelaufen');
    }

    public function testRefreshRotatesAndExpires(): void
    {
        $this->enable();
        $grant = $this->issue($this->member);
        $this->clock->at = $this->clock->now()->modify('+2 hours');
        $next = $this->app->accessTokens()->refresh($grant['refresh_token']);
        $this->assertSame($grant['id'], $next['id'], 'derselbe Zugang');
        $this->users->current = null;
        $this->assertSame(200, $this->call('GET', '/me', [], $next['access_token'])->status);
        foreach ([$grant['refresh_token'], $next['access_token']] as $old) {
            try {
                $this->app->accessTokens()->refresh($old);
                $this->fail('altes Token zum Erneuern gilt nicht mehr');
            } catch (ApiError $e) {
                $this->assertSame('invalid_grant', $e->errorCode);
            }
        }

        // 30 Tage ohne Erneuern: neu verbinden
        $this->clock->at = $this->clock->now()->modify('+31 days');
        try {
            $this->app->accessTokens()->refresh($next['refresh_token']);
            $this->fail('nach 30 Tagen abgelaufen');
        } catch (ApiError $e) {
            $this->assertSame('invalid_grant', $e->errorCode);
        }
        $this->assertTrue($this->app->accessTokens()->list($this->member)[0]['expired']);
    }

    public function testReadScopeOnlyReads(): void
    {
        $this->enable();
        $read = $this->issue($this->member, AccessTokenService::READ);
        $write = $this->issue($this->member);
        $route = new Route('POST', '/test-token', Route::LOGIN, fn () => ['ok' => true], token: true);
        $this->users->current = null;
        $res = $this->app->api()->dispatch($route, new Request('POST', '/test-token', [], [], [], '', null, null, $read['access_token']));
        $this->assertSame([403, 'token_readonly'], [$res->status, $res->data['code']]);
        $res = $this->app->api()->dispatch($route, new Request('POST', '/test-token', [], [], [], '', null, null, $write['access_token']));
        $this->assertSame(200, $res->status);
        $this->assertSame(200, $this->call('GET', '/me', [], $read['access_token'])->status, 'lesen geht');
        try {
            $this->issue($this->member, 'admin');
            $this->fail('unbekannter Umfang');
        } catch (ApiError $e) {
            $this->assertSame(400, $e->status);
        }
    }

    public function testListAndRevoke(): void
    {
        $this->enable();
        $mine = $this->issue($this->member);
        $other = $this->issue($this->admin);

        $this->users->current = $this->member;
        $list = $this->call('GET', '/me/ai-access')->data;
        $this->assertTrue($list['enabled']);
        $this->assertSame([[$mine['id'], 'Claude', 'write']], array_map(static fn (array $t): array => [$t['id'], $t['client'], $t['scope']], $list['tokens']), 'nur eigene');
        $this->assertArrayNotHasKey('user_name', $list['tokens'][0]);
        $this->assertSame(404, $this->call('DELETE', '/me/ai-access/' . $other['id'])->status, 'fremde nicht');
        $this->assertSame(200, $this->call('DELETE', '/me/ai-access/' . $mine['id'])->status);
        $this->assertSame([], $this->call('GET', '/me/ai-access')->data['tokens']);
        $this->users->current = null;
        $this->assertSame(401, $this->call('GET', '/me', [], $mine['access_token'])->status, 'widerrufen');

        // Admins sehen alle mit Namen und widerrufen.
        $this->users->current = $this->admin;
        $all = $this->call('GET', '/admin/ai-access')->data['tokens'];
        $this->assertSame([[$other['id'], $this->admin]], array_map(static fn (array $t): array => [$t['id'], $t['user_id']], $all));
        $this->assertNotSame('', $all[0]['user_name']);
        $this->assertSame(200, $this->call('DELETE', '/admin/ai-access/' . $other['id'])->status);
        $this->assertSame(404, $this->call('DELETE', '/admin/ai-access/' . $other['id'])->status);
    }

    public function testNewPasswordEndsAccess(): void
    {
        $this->enable();
        $own = $this->issue($this->member);
        $this->users->current = $this->member;
        $res = $this->call('POST', '/me/password', ['current_password' => 'geheim123', 'new_password' => 'NeuesPasswort-2026']);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertSame([], $this->app->accessTokens()->list($this->member), 'eigenes neues Passwort');

        $again = $this->issue($this->member);
        $this->users->current = $this->admin;
        $this->assertSame(200, $this->call('POST', '/members/' . $this->member . '/password')->status);
        $this->assertSame([], $this->app->accessTokens()->list($this->member), 'Startpasswort');
        $this->users->current = null;
        foreach ([$own, $again] as $grant) {
            $this->assertSame(401, $this->call('GET', '/me', [], $grant['access_token'])->status);
        }
    }

    public function testDeletedAccountHasNoAccess(): void
    {
        $this->enable();
        $grant = $this->issue($this->member);
        $this->users->removeAccount($this->member);
        $this->users->current = null;
        $this->assertSame(401, $this->call('GET', '/me', [], $grant['access_token'])->status);
    }

    public function testBearerHeader(): void
    {
        $this->assertSame('kat_abc', Request::bearerFrom('Bearer kat_abc'));
        $this->assertSame('kat_abc', Request::bearerFrom('bearer   kat_abc '));
        $this->assertNull(Request::bearerFrom('Basic YWRtaW46Z2VoZWlt'));
        $this->assertNull(Request::bearerFrom('Bearer'));
        $this->assertNull(Request::bearerFrom(''));
        $this->assertNull(Request::bearerFrom(null));
    }
}
