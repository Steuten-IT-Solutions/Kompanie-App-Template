<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Domain\DriveClient;
use KompanieApp\Core\Domain\GoogleConnect;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Tests\Support\DriveMock;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** „Mit Google verbinden“ mit dem eigenen OAuth-Client der Installation (Google simuliert). */
final class GoogleConnectTest extends TestCase
{
    private const CLIENT = '123-abc.apps.googleusercontent.com';
    private const REDIRECT = 'https://example.org/app/google/callback';

    private DriveMock $google;
    private FakeUsers $users;
    private FixedClock $clock;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->google = new DriveMock();
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-10-20 12:00:00 UTC'));
        $this->app = TestApp::create(TestApp::connection(), $this->users, [], $this->google->http(), clock: $this->clock);
        $this->admin = $this->users->add('anna', 'admin');
        $this->member = $this->users->add('max', 'member');
        $this->users->current = $this->admin;
    }

    /** @return array{0: int, 1: mixed} */
    private function api(string $method, string $path, array $body = []): array
    {
        $res = $this->app->api()->handle(new Request($method, $path, [], $body));
        return [$res->status, json_decode((string) json_encode($res->data), true)];
    }

    /** @return array<string, string> Parameter der Adresse der Anmeldung bei Google */
    private static function params(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    /** Startet über die API und gibt das state zurück. */
    private function start(string $secret = 'GOCSPX-geheim'): string
    {
        [$s, $r] = $this->api('POST', '/admin/google/connect', ['client_id' => self::CLIENT, 'client_secret' => $secret]);
        $this->assertSame(200, $s, (string) json_encode($r));
        return self::params($r['url'])['state'];
    }

    public function testAuthorizeUrlHasAllParameters(): void
    {
        [$s, $r] = $this->api('POST', '/admin/google/connect', ['client_id' => ' ' . self::CLIENT . ' ', 'client_secret' => 'GOCSPX-geheim']);
        $this->assertSame(200, $s);
        $this->assertStringStartsWith(DriveClient::AUTH_URL . '?', $r['url']);
        $q = self::params($r['url']);
        $this->assertSame(self::CLIENT, $q['client_id']);
        $this->assertSame(self::REDIRECT, $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame(DriveClient::SCOPE . ' ' . DriveClient::CALENDAR_SCOPE, $q['scope']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('consent', $q['prompt']);
        $this->assertSame('true', $q['include_granted_scopes']);
        $this->assertMatchesRegularExpression('/^kompanie_app_app_[0-9a-f]{48}$/', $q['state']);
        $this->assertTrue(GoogleConnect::isOwnState($q['state']));
        $this->assertFalse(GoogleConnect::isOwnState('anderes_plugin_xyz'));
        $this->assertStringNotContainsString('geheim', $r['url'], 'Secret nie in der Adresse');
        // Leerzeichen als %20 (RFC 3986), nicht als +
        $this->assertStringContainsString('scope=' . rawurlencode(DriveClient::scopes()), $r['url']);

        // Gespeichert: nur der Client, noch keine Verbindung; das state nur als Hash.
        $settings = $this->app->settingsService()->get();
        $this->assertSame(self::CLIENT, $settings['google']['client_id']);
        $this->assertTrue($settings['google']['client_secret_set']);
        $this->assertFalse($settings['google']['connected']);
        $this->assertSame(self::REDIRECT, $settings['google']['redirect_uri']);
        $this->assertStringNotContainsString('geheim', (string) json_encode($settings));
        $this->assertStringNotContainsString($q['state'], (string) json_encode($this->app->settings->get(DriveClient::STATE_SETTING)));
        $this->assertNull($this->app->drive()->oauth());
    }

    public function testStartValidatesClientAndPermissions(): void
    {
        [$s, $r] = $this->api('POST', '/admin/google/connect', ['client_id' => 'falsch', 'client_secret' => 'x']);
        $this->assertSame([400, 'invalid'], [$s, $r['code']]);
        $this->assertStringContainsString('.apps.googleusercontent.com', $r['message']);
        [$s, $r] = $this->api('POST', '/admin/google/connect', ['client_id' => self::CLIENT, 'client_secret' => '']);
        $this->assertSame(400, $s, 'ohne Secret beim ersten Mal');
        $this->assertStringContainsString('Clientschlüssel', $r['message']);
        [$s] = $this->api('POST', '/admin/google/connect', ['client_id' => self::CLIENT, 'client_secret' => 'mit leerzeichen']);
        $this->assertSame(400, $s);

        $this->users->current = $this->member;
        $this->assertSame(403, $this->api('POST', '/admin/google/connect', ['client_id' => self::CLIENT, 'client_secret' => 'GOCSPX-x'])[0]);
        $this->assertSame(403, $this->api('POST', '/admin/google/disconnect')[0]);
        $this->users->current = null;
        $this->assertSame(401, $this->api('POST', '/admin/google/connect', ['client_id' => self::CLIENT, 'client_secret' => 'GOCSPX-x'])[0]);
        $this->assertSame([], (array) ($this->app->settings->get(DriveClient::OAUTH_SETTING) ?? []), 'nichts gespeichert');
    }

    public function testCallbackExchangesCodeAndStoresConnection(): void
    {
        $state = $this->start();
        $result = $this->app->googleConnect()->callback(['state' => $state, 'code' => 'CODE1', 'scope' => 'egal']);
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('app', $result['return']);
        $this->assertStringContainsString('kompanie@example.org', $result['message']);
        $this->assertSame(
            ['grant_type' => 'authorization_code', 'code' => 'CODE1', 'client_id' => self::CLIENT, 'client_secret' => 'GOCSPX-geheim', 'redirect_uri' => self::REDIRECT],
            $this->google->codeForm,
        );
        // Format wie bei der Übernahme: {client_id, client_secret, refresh_token, email, scope}
        $stored = $this->app->settings->get(DriveClient::OAUTH_SETTING);
        $this->assertSame([
            'client_id' => self::CLIENT,
            'client_secret' => 'GOCSPX-geheim',
            'refresh_token' => 'REFRESH1',
            'email' => 'kompanie@example.org',
            'scope' => DriveClient::SCOPE . ' ' . DriveClient::CALENDAR_SCOPE,
        ], $stored, 'gespeicherte Verbindung');
        $drive = $this->app->drive();
        $this->assertNotNull($drive->oauth());
        $this->assertTrue($drive->calendarAccess());
        $this->assertSame('TOKEN123', $drive->accessToken());
        $this->assertSame(1, $this->google->tokens, 'Zugriffstoken aus dem Austausch zwischengespeichert');

        $settings = $this->app->settingsService()->get();
        $this->assertTrue($settings['google']['connected'] && $settings['gallery']['oauth_connected']);
        $this->assertSame('kompanie@example.org', $settings['google']['email']);
        $this->assertTrue($settings['google']['calendar_access']);
        $this->assertTrue($settings['google']['last_result']['ok']);
        $this->assertSame($this->clock->now()->getTimestamp(), $settings['google']['last_result']['at']);
        $this->assertStringNotContainsString('REFRESH1', (string) json_encode($settings), 'Refresh-Token nie in der Antwort');

        // Ein state gilt nur einmal.
        $again = $this->app->googleConnect()->callback(['state' => $state, 'code' => 'CODE1']);
        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('abgelaufen oder ungültig', $again['message']);
        $this->assertNotNull($drive->oauth(), 'bestehende Verbindung bleibt');
    }

    public function testEmailFromIdTokenWhenPresent(): void
    {
        $http = $this->google->http();
        $inner = $http->handler;
        $payload = rtrim(strtr(base64_encode((string) json_encode(['email' => 'id@example.org'])), '+/', '-_'), '=');
        $http->handler = static function (string $method, string $url, array $headers, ?string $body) use ($inner, $payload): array {
            $res = $inner($method, $url, $headers, $body);
            if (str_starts_with($url, DriveClient::TOKEN_URI) && $res['status'] === 200) {
                $res['body'] = (string) json_encode(json_decode($res['body'], true) + ['id_token' => 'x.' . $payload . '.y']);
            }
            return $res;
        };
        $app = TestApp::create(TestApp::connection(), $this->users, [], $http, settings: null, clock: $this->clock);
        $app->drive()->saveClient(self::CLIENT, 'GOCSPX-geheim');
        $app->drive()->exchangeCode('CODE1', self::REDIRECT);
        $this->assertSame('id@example.org', $app->drive()->oauthEmail());
        $this->assertSame([], array_filter($http->calls, static fn (array $c): bool => str_starts_with($c['url'], DriveClient::ABOUT_URL)), 'kein Abruf von about');
    }

    public function testWrongExpiredOrForeignStateIsRejected(): void
    {
        $connect = $this->app->googleConnect();
        // Falsches state
        $this->start();
        $r = $connect->callback(['state' => 'kompanie_app_app_' . str_repeat('0', 48), 'code' => 'CODE1']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('abgelaufen oder ungültig', $r['message']);
        $this->assertNull($this->google->codeForm, 'kein Austausch ohne gültiges state');

        // Abgelaufen (nach 15 Minuten)
        $state = $this->start();
        $this->clock->at = $this->clock->now()->modify('+' . (DriveClient::STATE_TTL + 1) . ' seconds');
        $this->assertFalse($connect->callback(['state' => $state, 'code' => 'CODE1'])['ok']);
        $this->assertNull($this->google->codeForm);

        // Anderer Admin
        $state = $this->start();
        $other = $this->users->add('berta', 'admin');
        $this->users->current = $other;
        $this->assertFalse($connect->callback(['state' => $state, 'code' => 'CODE1'])['ok']);
        $this->users->current = $this->admin;
        $this->assertSame(1, count((array) $this->app->settings->get(DriveClient::STATE_SETTING)), 'ein anderer Admin verbraucht es nicht');

        // Andere Sitzung desselben Admins (z. B. anderes Gerät)
        $state = $this->start();
        $this->users->session = 'sitzung-2';
        $this->assertFalse($connect->callback(['state' => $state, 'code' => 'CODE1'])['ok']);
        $this->users->session = 'sitzung-1';

        // Mitglied bzw. ohne Anmeldung: Fehler, nichts gespeichert
        $state = $this->start();
        $this->app->settings->delete(GoogleConnect::RESULT_SETTING);
        $this->users->current = $this->member;
        try {
            $connect->callback(['state' => $state, 'code' => 'CODE1']);
            $this->fail('Mitglied durfte verbinden');
        } catch (\KompanieApp\Core\Http\ApiError $e) {
            $this->assertSame(403, $e->status);
        }
        $this->users->current = null;
        try {
            $connect->callback(['state' => $state, 'code' => 'CODE1']);
            $this->fail('Gast durfte verbinden');
        } catch (\KompanieApp\Core\Http\ApiError $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('als Admin anmelden', $e->getMessage());
        }
        $this->assertNull($connect->lastResult());
        $this->users->current = $this->admin;
        $this->assertTrue($connect->callback(['state' => $state, 'code' => 'CODE1'])['ok'], 'state bleibt für den richtigen Admin gültig');
    }

    public function testErrorsFromGoogle(): void
    {
        $connect = $this->app->googleConnect();
        $r = $connect->callback(['state' => $this->start(), 'error' => 'access_denied']);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('abgebrochen', $r['message']);
        $this->assertStringContainsString('abgebrochen', $connect->lastResult()['message'] ?? '');

        $this->assertStringContainsString('Google meldet einen Fehler: server_error', $connect->callback(['state' => $this->start(), 'error' => 'server_error'])['message']);
        $this->assertStringContainsString('fehlgeschlagen', $connect->callback(['state' => $this->start(), 'code' => 'FALSCH'])['message']);

        $this->google->issuedRefreshToken = '';
        $this->assertStringContainsString('kein Refresh-Token', $connect->callback(['state' => $this->start(), 'code' => 'CODE1'])['message']);
        $this->google->issuedRefreshToken = 'REFRESH1';

        $this->google->grantedScope = DriveClient::CALENDAR_SCOPE;
        $this->assertStringContainsString('Häkchen für Google Drive', $connect->callback(['state' => $this->start(), 'code' => 'CODE1'])['message']);

        $this->google->grantedScope = DriveClient::SCOPE . ' ' . DriveClient::CALENDAR_SCOPE;
        $this->google->codeError = 'invalid_client';
        $this->assertStringContainsString('Clientschlüssel prüfen', $connect->callback(['state' => $this->start(), 'code' => 'CODE1'])['message']);
        $this->google->codeError = 'redirect_uri_mismatch';
        $this->assertStringContainsString('Weiterleitungs-URI', $connect->callback(['state' => $this->start(), 'code' => 'CODE1'])['message']);
        $this->assertNull($this->app->drive()->oauth(), 'nach Fehlern nicht verbunden');
    }

    public function testOnlyDriveGrantedMeansNoCalendar(): void
    {
        $this->google->grantedScope = DriveClient::SCOPE;
        $this->app->settings->set('calendar_ids', 'kompanie@group.calendar.google.com');
        $r = $this->app->googleConnect()->callback(['state' => $this->start(), 'code' => 'CODE1']);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('Den Kalender darf die Verbindung nicht lesen', $r['message']);
        $this->assertFalse($this->app->drive()->calendarAccess());
        $this->assertSame(DriveClient::SCOPE, $this->app->drive()->oauth()['scope'] ?? null);
        $this->assertTrue($this->app->settingsService()->get()['calendar']['connection_needs_calendar_access']);
    }

    public function testDisconnectRevokesAndKeepsClient(): void
    {
        $this->app->googleConnect()->callback(['state' => $this->start(), 'code' => 'CODE1']);
        [$s, $settings] = $this->api('POST', '/admin/google/disconnect');
        $this->assertSame(200, $s);
        $this->assertSame(['REFRESH1'], $this->google->revoked);
        $this->assertFalse($settings['google']['connected']);
        $this->assertSame('', $settings['google']['email']);
        $this->assertSame(self::CLIENT, $settings['google']['client_id'], 'Client bleibt für eine neue Verbindung');
        $this->assertTrue($settings['google']['client_secret_set']);
        $this->assertNull($settings['google']['last_result']);
        $stored = $this->app->settings->get(DriveClient::OAUTH_SETTING);
        $this->assertSame(['', '', ''], [$stored['refresh_token'], $stored['scope'], $stored['email']]);
        try {
            $this->app->drive()->accessToken();
            $this->fail('Nach dem Trennen noch ein Token');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('kein Google-Zugang', $e->getMessage());
        }

        // Neu verbinden ohne das Secret noch einmal einzugeben
        [$s, $r] = $this->api('POST', '/admin/google/connect', ['client_id' => self::CLIENT, 'client_secret' => '']);
        $this->assertSame(200, $s);
        $this->assertTrue($this->app->googleConnect()->callback(['state' => self::params($r['url'])['state'], 'code' => 'CODE1'])['ok']);

        // Trennen über PATCH /admin/settings (ältere Fassungen der App) widerruft ebenso
        $this->google->revoked = [];
        $this->assertFalse($this->api('PATCH', '/admin/settings', ['drive_oauth' => ''])[1]['gallery']['oauth_connected']);
        $this->assertSame(['REFRESH1'], $this->google->revoked);

        // Ohne Verbindung: kein Widerruf, kein Fehler
        $this->google->revoked = [];
        $this->api('POST', '/admin/google/disconnect');
        $this->assertSame([], $this->google->revoked);
    }

    public function testRevokeFailureDoesNotBlockDisconnect(): void
    {
        $failing = TestApp::create(TestApp::connection(), $this->users, [], new \KompanieApp\Core\Tests\Support\FakeHttpClient(static function (): array {
            throw new \RuntimeException('Netz weg');
        }), settings: null, clock: $this->clock);
        $failing->drive()->saveOAuth(['client_id' => self::CLIENT, 'client_secret' => 'S', 'refresh_token' => 'R']);
        $failing->googleConnect()->disconnect();
        $this->assertNull($failing->drive()->oauth());
    }

    public function testChangingTheClientClearsConnectionAndScope(): void
    {
        $this->app->googleConnect()->callback(['state' => $this->start(), 'code' => 'CODE1']);
        $drive = $this->app->drive();
        // Gleicher Client, leeres Secret: Verbindung bleibt
        $drive->saveClient(self::CLIENT, '');
        $this->assertNotNull($drive->oauth());
        $this->assertTrue($drive->calendarAccess());
        // Anderes Secret: Verbindung und erlaubte Berechtigungen weg
        $drive->saveClient(self::CLIENT, 'GOCSPX-neu');
        $stored = $this->app->settings->get(DriveClient::OAUTH_SETTING);
        $this->assertSame(['', '', ''], [$stored['refresh_token'], $stored['scope'], $stored['email']]);
        $this->assertSame('GOCSPX-neu', $stored['client_secret']);
        $this->assertNull($drive->oauth());
        // Andere Client-ID ohne Secret: abgelehnt
        try {
            $drive->saveClient('999-xyz.apps.googleusercontent.com', '');
            $this->fail('Neue Client-ID ohne Secret angenommen');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Clientschlüssel', $e->getMessage());
        }
        // Übernommene Verbindung (saveOAuth) mit Scope: neuer Client leert den Scope
        $drive->saveOAuth(['client_id' => self::CLIENT, 'client_secret' => 'ALT', 'refresh_token' => 'R', 'scope' => DriveClient::scopes()]);
        $this->assertTrue($drive->calendarAccess());
        $drive->saveClient('999-xyz.apps.googleusercontent.com', 'GOCSPX-x');
        $this->assertSame('', $this->app->settings->get(DriveClient::OAUTH_SETTING)['scope']);
    }

    public function testTakenOverConnectionKeepsWorkingAndCanBeReconnected(): void
    {
        // Übernahme aus einer früheren App: gleiches Format
        $this->app->drive()->saveOAuth(['client_id' => self::CLIENT, 'client_secret' => 'GOCSPX-alt', 'refresh_token' => 'REFRESH1', 'email' => 'alt@example.org', 'scope' => DriveClient::SCOPE]);
        $settings = $this->app->settingsService()->get();
        $this->assertTrue($settings['google']['connected']);
        $this->assertSame([self::CLIENT, true, 'alt@example.org', false], [$settings['google']['client_id'], $settings['google']['client_secret_set'], $settings['google']['email'], $settings['google']['calendar_access']]);
        $this->assertSame('TOKEN123', $this->app->drive()->accessToken());
        // Neu verbinden mit gespeichertem Client, jetzt mit Kalender
        [$s, $r] = $this->api('POST', '/admin/google/connect', ['client_id' => self::CLIENT]);
        $this->assertSame(200, $s);
        $this->assertTrue($this->app->googleConnect()->callback(['state' => self::params($r['url'])['state'], 'code' => 'CODE1'])['ok']);
        $this->assertTrue($this->app->drive()->calendarAccess());
        $this->assertSame('GOCSPX-alt', $this->google->codeForm['client_secret'] ?? null);
    }

    public function testStateFromWordPressAdminReturnsThere(): void
    {
        $this->app->drive()->saveClient(self::CLIENT, 'GOCSPX-geheim');
        $url = $this->app->googleConnect()->start(self::CLIENT, '', GoogleConnect::RETURN_ADMIN);
        $state = self::params($url)['state'];
        $this->assertSame('admin', DriveClient::stateReturn($state));
        $this->assertSame('app', DriveClient::stateReturn('irgendwas'));
        $this->assertSame('admin', $this->app->googleConnect()->callback(['state' => $state, 'error' => 'access_denied'])['return']);
        $this->assertSame('https://example.org/app/?google=1#/einstellungen', $this->app->googleConnect()->appReturnUrl());
    }

    public function testStatesOfSeveralAdminsAndCleanup(): void
    {
        $drive = $this->app->drive();
        $drive->saveClient(self::CLIENT, 'GOCSPX-geheim');
        $a = $drive->createState(1, 'k1');
        $b = $drive->createState(2, 'k2');
        $this->assertTrue($drive->checkState($b, 2, 'k2'));
        $this->assertTrue($drive->checkState($a, 1, 'k1'));
        $this->assertNull($this->app->settings->get(DriveClient::STATE_SETTING), 'aufgeräumt');
        $drive->createState(1, 'k1');
        $this->clock->at = $this->clock->now()->modify('+1 hour');
        $drive->createState(2, 'k2');
        $this->assertSame([2], array_keys($this->app->settings->get(DriveClient::STATE_SETTING)), 'abgelaufene entfernt');
    }
}
