<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Tests\Support\FakeHttpClient;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** Anmeldung per OAuth für KI-Assistenten (Plan „MCP-Server“, Schritt 3): Metadaten, Freigabe, Tokens, CIMD und DCR. */
final class OAuthTest extends TestCase
{
    private const API = 'https://example.org/api/v1/';
    private const CIMD = 'https://8.8.8.8/oauth/client.json';
    private const REDIRECT = 'https://client.example/callback';

    private FakeUsers $users;
    private FixedClock $clock;
    private FakeHttpClient $http;
    private App $app;
    private int $admin;
    private int $member;
    /** @var array<string, mixed> */
    private array $document;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-23 10:00:00', new \DateTimeZone('UTC')));
        $this->document = ['client_id' => self::CIMD, 'client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]];
        $this->http = new FakeHttpClient(fn (string $method, string $url): array => $url === self::CIMD
            ? ['status' => 200, 'body' => (string) json_encode($this->document)]
            : ['status' => 404, 'body' => '']);
        $this->app = TestApp::create(TestApp::connection(), $this->users, http: $this->http, clock: $this->clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
        $this->app->accessTokens()->setEnabled(true);
        $this->users->current = null;
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = [], ?string $bearer = null): Response
    {
        return $this->app->api()->handle(new Request('GET', $path, $query, [], [], '', null, null, $bearer));
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): Response
    {
        return $this->app->api()->handle(new Request('POST', $path, [], $body));
    }

    private static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @return array<string, string> */
    private function authorizeQuery(string $verifier, string $clientId = self::CIMD): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'scope' => 'read write',
            'resource' => self::API . 'mcp',
        ];
    }

    /** Anfrage stellen; gibt den Schlüssel für die Zustimmung zurück. */
    private function requestKey(string $verifier, string $clientId = self::CIMD): string
    {
        $res = $this->get('/oauth/authorize', $this->authorizeQuery($verifier, $clientId));
        $this->assertSame(302, $res->status, (string) json_encode($res->data));
        $this->assertMatchesRegularExpression('~^https://example\.org/app/#/ki-zugang/(kaq_[A-Za-z0-9_-]{43})$~', $res->headers['Location']);
        return substr($res->headers['Location'], strlen('https://example.org/app/#/ki-zugang/'));
    }

    /** @return array<string, string> Parameter der Rücksprung-Adresse */
    private function approve(string $key, int $userId, string $scope = 'write'): array
    {
        $this->users->current = $userId;
        $res = $this->post('/oauth/requests/' . $key, ['approve' => true, 'scope' => $scope]);
        $this->users->current = null;
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertStringStartsWith(self::REDIRECT . '?', $res->data['redirect']);
        parse_str((string) parse_url($res->data['redirect'], PHP_URL_QUERY), $params);
        return $params;
    }

    public function testMetadata(): void
    {
        $prm = $this->get('/oauth/protected-resource');
        $this->assertSame(200, $prm->status);
        $this->assertSame(['resource' => self::API . 'mcp', 'authorization_servers' => ['https://example.org/api/v1']], array_intersect_key($prm->data, ['resource' => 1, 'authorization_servers' => 1]));
        foreach (['/.well-known/oauth-authorization-server', '/.well-known/openid-configuration'] as $path) {
            $as = $this->get($path);
            $this->assertSame('https://example.org/api/v1', $as->data['issuer']);
            $this->assertSame(self::API . 'oauth/token', $as->data['token_endpoint']);
            $this->assertSame(['S256'], $as->data['code_challenge_methods_supported']);
            $this->assertTrue($as->data['client_id_metadata_document_supported']);
        }
        // Wurzel der Domain: Pfad des Ausstellers bzw. der Ressource hinten angehängt
        $this->assertSame('/.well-known/oauth-authorization-server', $this->app->oauth()->wellKnownRoute('/.well-known/oauth-authorization-server/api/v1'));
        $this->assertSame('/.well-known/openid-configuration', $this->app->oauth()->wellKnownRoute('/.well-known/openid-configuration/api/v1'));
        $this->assertSame('/.well-known/oauth-protected-resource', $this->app->oauth()->wellKnownRoute('/.well-known/oauth-protected-resource/api/v1/mcp'));
        $this->assertNull($this->app->oauth()->wellKnownRoute('/.well-known/oauth-authorization-server/anders'));

        // Der MCP-Endpunkt verweist auf die Metadaten
        $mcp = $this->app->api()->handle(new Request('POST', '/mcp', [], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
        $this->assertSame('Bearer resource_metadata="' . self::API . 'oauth/protected-resource"', $mcp->headers['WWW-Authenticate']);
        $bad = $this->app->api()->handle(new Request('POST', '/mcp', [], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], [], '', null, null, 'kat_x'));
        $this->assertSame('Bearer error="invalid_token", resource_metadata="' . self::API . 'oauth/protected-resource"', $bad->headers['WWW-Authenticate']);

        // Ausgeschaltet: nichts davon
        $this->users->current = $this->admin;
        $this->app->accessTokens()->setEnabled(false);
        $this->assertSame(404, $this->get('/oauth/protected-resource')->status);
        $this->assertSame(404, $this->get('/.well-known/oauth-authorization-server')->status);
        $this->assertSame('invalid_grant', $this->post('/oauth/token', ['grant_type' => 'refresh_token'])->data['error']);
    }

    public function testFullFlowWithMetadataDocument(): void
    {
        $verifier = str_repeat('v', 50);
        $key = $this->requestKey($verifier);

        // Zustimmung braucht eine Anmeldung; die App zeigt Programm und Umfang
        $this->assertSame(401, $this->get('/oauth/requests/' . $key)->status);
        $this->users->current = $this->member;
        $pending = $this->get('/oauth/requests/' . $key)->data;
        $this->assertSame(['Claude', '8.8.8.8', 'client.example', 'write'], [$pending['client'], $pending['client_host'], $pending['redirect_host'], $pending['scope']]);
        $this->assertSame(400, $this->post('/oauth/requests/' . $key, ['approve' => true, 'scope' => 'admin'])->status);

        $params = $this->approve($key, $this->member, 'read');
        $this->assertSame(['xyz', 'https://example.org/api/v1'], [$params['state'], $params['iss']]);
        $this->assertStringStartsWith('kac_', $params['code']);
        $this->users->current = $this->member;
        $this->assertSame(404, $this->post('/oauth/requests/' . $key, ['approve' => true, 'scope' => 'read'])->status, 'Anfrage nur einmal');
        $this->users->current = null;

        $exchange = ['grant_type' => 'authorization_code', 'code' => $params['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => self::CIMD, 'code_verifier' => $verifier, 'resource' => self::API . 'mcp'];
        $tokens = $this->post('/oauth/token', $exchange);
        $this->assertSame(200, $tokens->status, (string) json_encode($tokens->data));
        $this->assertSame(['Bearer', 3600, 'read'], [$tokens->data['token_type'], $tokens->data['expires_in'], $tokens->data['scope']]);
        $this->assertSame('no-store', $tokens->headers['Cache-Control']);
        $this->assertArrayNotHasKey('id', $tokens->data);
        $this->assertSame('invalid_grant', $this->post('/oauth/token', $exchange)->data['error'], 'Code nur einmal');

        // Das Token gilt am MCP-Endpunkt als die Person; der Zugang trägt den Namen des Programms
        $who = $this->app->api()->handle(new Request('POST', '/mcp', [], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'whoami']], [], '', null, null, $tokens->data['access_token']));
        $this->assertSame($this->member, $who->data['result']['structuredContent']['person']['id']);
        $this->assertSame('Claude', $this->app->accessTokens()->list($this->member)[0]['client']);

        // Erneuern nur mit demselben Programm
        $refresh = ['grant_type' => 'refresh_token', 'refresh_token' => $tokens->data['refresh_token'], 'client_id' => 'https://8.8.8.8/anders.json'];
        $this->assertSame('invalid_grant', $this->post('/oauth/token', $refresh)->data['error']);
        $refresh['client_id'] = self::CIMD;
        $renewed = $this->post('/oauth/token', $refresh);
        $this->assertSame(200, $renewed->status);
        $this->assertNotSame($tokens->data['refresh_token'], $renewed->data['refresh_token']);
    }

    public function testCodeChecks(): void
    {
        $verifier = str_repeat('a', 43);
        $params = $this->approve($this->requestKey($verifier), $this->member);
        $base = ['grant_type' => 'authorization_code', 'code' => $params['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => self::CIMD, 'code_verifier' => str_repeat('b', 43)];
        $this->assertSame('invalid_grant', $this->post('/oauth/token', $base)->data['error'], 'falscher code_verifier');
        // Ein Fehlversuch verbraucht den Code
        $this->assertSame('invalid_grant', $this->post('/oauth/token', ['code_verifier' => $verifier] + $base)->data['error']);

        $params = $this->approve($this->requestKey($verifier), $this->member);
        $this->clock->at = $this->clock->now()->modify('+61 seconds');
        $this->assertSame('invalid_grant', $this->post('/oauth/token', ['code' => $params['code'], 'code_verifier' => $verifier] + $base)->data['error'], 'abgelaufen');

        $params = $this->approve($this->requestKey($verifier), $this->member);
        $this->assertSame('invalid_grant', $this->post('/oauth/token', ['code' => $params['code'], 'code_verifier' => $verifier, 'redirect_uri' => 'https://client.example/anders'] + $base)->data['error']);
        $this->assertSame('unsupported_grant_type', $this->post('/oauth/token', ['grant_type' => 'password'])->data['error']);
    }

    public function testAuthorizeErrors(): void
    {
        $verifier = str_repeat('v', 50);
        $q = $this->authorizeQuery($verifier);
        $this->assertSame([400, 'invalid_client'], [($r = $this->get('/oauth/authorize', ['client_id' => 'https://8.8.8.8/fehlt.json'] + $q))->status, $r->data['code']]);
        $this->assertSame('invalid_redirect_uri', $this->get('/oauth/authorize', ['redirect_uri' => 'https://evil.example/cb'] + $q)->data['code'], 'nie an eine fremde Adresse');
        // Andere Fehler gehen an das Programm
        foreach ([['code_challenge_method' => 'plain'], ['response_type' => 'token'], ['resource' => 'https://other.example/mcp']] as $change) {
            $res = $this->get('/oauth/authorize', $change + $q);
            $this->assertSame(302, $res->status);
            $this->assertStringStartsWith(self::REDIRECT . '?error=', $res->headers['Location']);
            $this->assertStringContainsString('state=xyz', $res->headers['Location']);
        }
        // Ablehnen
        $key = $this->requestKey($verifier);
        $this->users->current = $this->member;
        $denied = $this->post('/oauth/requests/' . $key, ['approve' => false]);
        $this->assertStringContainsString('error=access_denied', $denied->data['redirect']);
        // Abgelaufene Anfrage
        $key = $this->requestKey($verifier);
        $this->clock->at = $this->clock->now()->modify('+601 seconds');
        $this->assertSame(404, $this->get('/oauth/requests/' . $key)->status);
    }

    public function testMetadataDocumentChecks(): void
    {
        $verifier = str_repeat('v', 50);
        // client_id im Dokument muss die Adresse sein
        $this->document['client_id'] = 'https://8.8.8.8/anders.json';
        $this->assertSame(400, $this->get('/oauth/authorize', $this->authorizeQuery($verifier))->status);
        // Keine Adressen im eigenen Netz
        $this->assertSame(400, $this->get('/oauth/authorize', $this->authorizeQuery($verifier, 'https://10.0.0.1/client.json'))->status);
        $this->assertSame(400, $this->get('/oauth/authorize', $this->authorizeQuery($verifier, 'http://8.8.8.8/client.json'))->status, 'nur https');
        $this->assertNotContains('https://10.0.0.1/client.json', array_column($this->http->calls, 'url'));

        // Einmal geladen, einen Tag lang nicht neu
        $this->document['client_id'] = self::CIMD;
        $this->requestKey($verifier);
        $this->requestKey($verifier);
        $this->assertSame(2, count(array_filter($this->http->calls, static fn (array $c): bool => $c['url'] === self::CIMD)), 'ein Fehlversuch, ein Laden');
    }

    public function testDynamicRegistration(): void
    {
        $this->assertSame('invalid_redirect_uri', $this->post('/oauth/register', ['redirect_uris' => ['http://client.example/cb']])->data['error']);
        $this->assertSame('invalid_redirect_uri', $this->post('/oauth/register', [])->data['error']);
        $reg = $this->post('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_basic']);
        $this->assertSame(201, $reg->status);
        $this->assertSame(['none', 'Claude'], [$reg->data['token_endpoint_auth_method'], $reg->data['client_name']]);
        $clientId = $reg->data['client_id'];
        $this->assertStringStartsWith('kcl_', $clientId);

        $verifier = str_repeat('d', 60);
        $params = $this->approve($this->requestKey($verifier, $clientId), $this->admin);
        $tokens = $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'code' => $params['code'], 'redirect_uri' => self::REDIRECT, 'client_id' => $clientId, 'code_verifier' => $verifier]);
        $this->assertSame(200, $tokens->status, (string) json_encode($tokens->data));
        $this->assertSame('write', $tokens->data['scope']);
    }
}
