<?php

declare(strict_types=1);

namespace KompanieApp\Core\Tests;

use KompanieApp\Core\App;
use KompanieApp\Core\Auth\Roles;
use KompanieApp\Core\Domain\AccessTokenService;
use KompanieApp\Core\Http\Request;
use KompanieApp\Core\Http\Response;
use KompanieApp\Core\Mcp\McpServer;
use KompanieApp\Core\Mcp\Tools;
use KompanieApp\Core\Tests\Support\FakeUsers;
use KompanieApp\Core\Tests\Support\FixedClock;
use KompanieApp\Core\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

/** MCP-Endpunkt (Plan „MCP-Server“, Schritt 2): JSON-RPC, Versionen, Werkzeuge nach Rechten und Umfang. */
final class McpServerTest extends TestCase
{
    private FakeUsers $users;
    private App $app;
    private int $admin;
    private int $member;

    protected function setUp(): void
    {
        $this->users = new FakeUsers();
        $clock = new FixedClock(new \DateTimeImmutable('2026-11-23 10:00:00', new \DateTimeZone('UTC')));
        $this->app = TestApp::create(TestApp::connection(), $this->users, clock: $clock);
        $this->admin = $this->users->add('admin', Roles::ADMIN);
        $this->member = $this->users->add('mitglied', Roles::MEMBER);
        $this->users->current = $this->admin;
        $this->app->accessTokens()->setEnabled(true);
        $this->users->current = null;
    }

    private function token(int $userId, string $scope = AccessTokenService::WRITE): string
    {
        return $this->app->accessTokens()->issue($userId, 'Claude', $scope)['access_token'];
    }

    /**
     * @param array<string, mixed>|list<mixed> $body
     * @param array<string, string> $headers
     */
    private function post(array $body, ?string $token, array $headers = []): Response
    {
        return $this->app->api()->handle(new Request('POST', '/mcp', [], $body, [], '', null, null, $token, $headers));
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $method, array $params, ?string $token, int $id = 1): Response
    {
        return $this->post(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], $token);
    }

    /** @param array<string, mixed> $args */
    private function callTool(string $token, string $name, array $args = []): array
    {
        $res = $this->rpc('tools/call', ['name' => $name, 'arguments' => $args], $token);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        $this->assertIsArray($res->data['result'] ?? null, (string) json_encode($res->data));
        return $res->data['result'];
    }

    /** @return list<string> */
    private function toolNames(string $token): array
    {
        $res = $this->rpc('tools/list', [], $token);
        $this->assertSame(200, $res->status, (string) json_encode($res->data));
        return array_column($res->data['result']['tools'], 'name');
    }

    public function testOffMeans404AndNoTokenMeans401(): void
    {
        $this->users->current = $this->admin;
        $this->app->accessTokens()->setEnabled(false);
        $this->users->current = null;
        $this->assertSame(404, $this->rpc('tools/list', [], null)->status);
        $this->assertSame(404, $this->rpc('tools/list', [], 'kat_falsch')->status);

        $this->app->accessTokens()->setEnabled(true);
        $res = $this->rpc('tools/list', [], null);
        $this->assertSame(401, $res->status);
        $this->assertSame('Bearer resource_metadata="https://example.org/api/v1/oauth/protected-resource"', $res->headers['WWW-Authenticate'] ?? null);
        $bad = $this->rpc('tools/list', [], 'kat_falsch');
        $this->assertSame(401, $bad->status);
        $this->assertSame('Bearer error="invalid_token", resource_metadata="https://example.org/api/v1/oauth/protected-resource"', $bad->headers['WWW-Authenticate'] ?? null);
        // Mit Cookie allein nicht (kein Zugang über die Anmeldung im Browser)
        $this->users->current = $this->admin;
        $this->assertSame(401, $this->rpc('tools/list', [], null)->status);
        // Nur POST
        $this->assertSame(405, $this->app->api()->handle(new Request('GET', '/mcp', [], [], [], '', null, null, $this->token($this->admin)))->status);
    }

    public function testLegacyInitializeAndModernDiscover(): void
    {
        $token = $this->token($this->member);
        $init = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']], $token);
        $this->assertSame(200, $init->status);
        $this->assertSame('2025-06-18', $init->data['result']['protocolVersion']);
        $this->assertSame('kompanie-app', $init->data['result']['serverInfo']['name']);
        $this->assertSame(['listChanged' => false], $init->data['result']['capabilities']['tools']);
        $this->assertStringContainsString('read and change', $init->data['result']['instructions']);
        // Unbekannte ältere Version: die neueste mit initialize
        $this->assertSame('2025-11-25', $this->rpc('initialize', ['protocolVersion' => '2024-11-05'], $token)->data['result']['protocolVersion']);
        $this->assertSame(202, $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $token)->status);
        $this->assertNull($this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $token)->data);
        $this->assertEquals(new \stdClass(), $this->rpc('ping', [], $token)->data['result']);

        $meta = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => McpServer::LATEST, 'io.modelcontextprotocol/clientCapabilities' => new \stdClass()]];
        $discover = $this->rpc('server/discover', $meta, $token, 7);
        $this->assertSame(7, $discover->data['id']);
        $this->assertSame('complete', $discover->data['result']['resultType']);
        $this->assertSame(McpServer::VERSIONS, $discover->data['result']['supportedVersions']);
        $this->assertSame('kompanie-app', $discover->data['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        $this->assertSame('complete', $this->rpc('tools/list', $meta, $token)->data['result']['resultType']);

        $old = $this->rpc('tools/list', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '1900-01-01']], $token);
        $this->assertSame(400, $old->status);
        $this->assertSame(McpServer::UNSUPPORTED_VERSION, $old->data['error']['code']);
        $this->assertSame(['supported' => McpServer::VERSIONS, 'requested' => '1900-01-01'], $old->data['error']['data']);

        $unknown = $this->rpc('resources/list', $meta, $token);
        $this->assertSame([404, McpServer::METHOD_NOT_FOUND], [$unknown->status, $unknown->data['error']['code']]);
        $this->assertSame(McpServer::METHOD_NOT_FOUND, $this->rpc('resources/list', [], $token)->data['error']['code']);
    }

    public function testMalformedRequestsAndHeaders(): void
    {
        $token = $this->token($this->member);
        $this->assertSame(McpServer::INVALID_REQUEST, $this->post([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']], $token)->data['error']['code'], 'kein Stapel');
        $this->assertSame(400, $this->post(['id' => 1, 'method' => 'ping'], $token)->status);
        $this->assertSame(McpServer::INVALID_PARAMS, $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => 'x'], $token)->data['error']['code']);

        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'whoami', 'arguments' => new \stdClass(), '_meta' => ['io.modelcontextprotocol/protocolVersion' => McpServer::LATEST]]];
        $this->assertSame(200, $this->post($body, $token, ['mcp-method' => 'tools/call', 'mcp-name' => 'whoami', 'mcp-protocol-version' => McpServer::LATEST])->status);
        $this->assertSame(200, $this->post($body, $token, ['mcp-name' => '=?base64?' . base64_encode('whoami') . '?='])->status);
        foreach ([['mcp-method' => 'tools/list'], ['mcp-name' => 'list_polls'], ['mcp-protocol-version' => '2025-11-25']] as $headers) {
            $res = $this->post($body, $token, $headers);
            $this->assertSame([400, McpServer::HEADER_MISMATCH], [$res->status, $res->data['error']['code']], (string) json_encode($headers));
        }
        // Origin: KI-Assistenten wie claude.ai dürfen eine eigene Herkunft mitschicken; ohne Token gilt trotzdem nichts
        $this->assertSame(200, $this->post($body, $token, ['origin' => 'https://claude.ai'])->status);
        $this->assertSame(200, $this->post($body, $token, ['origin' => 'https://example.org'])->status);
        $this->assertSame(401, $this->post($body, null, ['origin' => 'https://claude.ai'])->status);
    }

    public function testToolsFollowRightsAndScope(): void
    {
        $member = $this->token($this->member, AccessTokenService::READ);
        $names = $this->toolNames($member);
        $this->assertContains('whoami', $names);
        $this->assertNotContains('get_app_status', $names, 'nur Admins');
        foreach (Tools::all() as $tool) {
            if ($tool['write']) {
                $this->assertNotContains($tool['name'], $names, 'nur lesen: ' . $tool['name']);
            }
        }
        $admin = $this->token($this->admin);
        $all = $this->toolNames($admin);
        $this->assertContains('get_app_status', $all);
        $this->assertContains('create_poll', $all);
        $tools = $this->rpc('tools/list', [], $admin)->data['result']['tools'];
        $poll = $tools[array_search('create_poll', $all, true)];
        $this->assertFalse($poll['annotations']['readOnlyHint']);
        $this->assertSame('object', $poll['inputSchema']['type']);
        $this->assertTrue($tools[array_search('whoami', $all, true)]['annotations']['readOnlyHint']);

        // Werkzeug ohne Recht oder Umfang: unbekannt
        $res = $this->rpc('tools/call', ['name' => 'create_poll', 'arguments' => ['title' => 'Frage', 'type' => 'text']], $member);
        $this->assertSame(McpServer::INVALID_PARAMS, $res->data['error']['code']);
        $this->assertSame(McpServer::INVALID_PARAMS, $this->rpc('tools/call', ['name' => 'delete_everything'], $admin)->data['error']['code']);
    }

    public function testWhoamiAndArguments(): void
    {
        $token = $this->token($this->member, AccessTokenService::READ);
        $who = $this->callTool($token, 'whoami');
        $this->assertFalse($who['isError']);
        $this->assertSame($this->member, $who['structuredContent']['person']['id']);
        $this->assertSame('read', $who['structuredContent']['scope']);
        $this->assertSame($who['structuredContent'], json_decode($who['content'][0]['text'], true));

        $this->assertTrue($this->callTool($token, 'whoami', ['x' => 1])['isError'], 'unbekanntes Argument');
        $this->assertTrue($this->callTool($token, 'get_poll', [])['isError'], 'Pflichtargument fehlt');
        $this->assertTrue($this->callTool($token, 'get_poll', ['id' => 'eins'])['isError'], 'falscher Typ');
        $this->assertTrue($this->callTool($token, 'list_polls', ['limit' => 0])['isError'], 'außerhalb');
        $missing = $this->callTool($token, 'get_poll', ['id' => 999]);
        $this->assertTrue($missing['isError'], 'Fehler der Route als Werkzeugfehler');
        $this->assertNotSame('', $missing['content'][0]['text']);
    }

    public function testListsAreLimited(): void
    {
        $admin = $this->token($this->admin);
        for ($i = 1; $i <= 3; $i++) {
            $this->assertFalse($this->callTool($admin, 'create_poll', ['title' => 'Frage ' . $i, 'type' => 'text'])['isError']);
        }
        $list = $this->callTool($admin, 'list_polls', ['limit' => 2]);
        $this->assertCount(2, $list['structuredContent']['items']);
        $this->assertSame([3, true], [$list['structuredContent']['total'], $list['structuredContent']['truncated']]);
    }

    public function testWritesGoThroughTheRouteAndAreMarkedInTheChangeLog(): void
    {
        $admin = $this->token($this->admin);
        $created = $this->callTool($admin, 'create_poll', ['title' => 'Grillfest?', 'type' => 'single', 'options' => ['Ja', 'Nein']]);
        $this->assertFalse($created['isError'], (string) json_encode($created));
        $this->assertSame('Grillfest?', $created['structuredContent']['title']);

        // Nur lesen: die Route lehnt auch direkte Änderungen ab
        $read = $this->token($this->admin, AccessTokenService::READ);
        $this->assertSame(403, $this->app->api()->handle(new Request('POST', '/polls', [], ['title' => 'X', 'type' => 'text'], [], '', null, null, $read))->status);
        // Routen der Werkzeuge nehmen Tokens auch direkt an, andere weiter nicht
        $this->assertSame(200, $this->app->api()->handle(new Request('GET', '/polls', [], [], [], '', null, null, $read))->status);
        $this->assertSame(403, $this->app->api()->handle(new Request('GET', '/admin/changes', [], [], [], '', null, null, $read))->status);

        $this->users->current = $this->admin;
        $entries = $this->app->api()->handle(new Request('GET', '/admin/changes'))->data['entries'];
        $polls = array_values(array_filter($entries, static fn (array $e): bool => $e['label'] === 'Grillfest?'));
        $this->assertCount(1, $polls, (string) json_encode($entries));
        $this->assertSame(['Claude', $this->admin], [$polls[0]['via'], $polls[0]['actor_id']]);

        // In der App selbst: ohne via
        $this->app->api()->handle(new Request('POST', '/polls', [], ['title' => 'In der App', 'type' => 'text']));
        $entries = $this->app->api()->handle(new Request('GET', '/admin/changes'))->data['entries'];
        $app = array_values(array_filter($entries, static fn (array $e): bool => $e['label'] === 'In der App'));
        $this->assertSame('', $app[0]['via']);
    }

    public function testBoardMessagesNeverSendMail(): void
    {
        $tool = Tools::find('post_board_message');
        $this->assertNotNull($tool);
        [$method, $path, , $body] = Tools::request($tool, ['title' => 'Hallo', 'text' => 'Text', 'push' => false]);
        $this->assertSame(['POST', '/board', false], [$method, $path, $body['mail']]);
        $this->assertNull(McpServer::check($tool['input'], ['title' => 'Hallo', 'text' => 'Text']));
        $this->assertNotNull(McpServer::check($tool['input'], ['title' => 'Hallo', 'text' => 'Text', 'mail' => true]), 'mail gibt es nicht');
    }

    public function testEveryToolRouteExists(): void
    {
        foreach (Tools::all() as $tool) {
            $this->assertNotNull($this->app->api()->routeFor($tool['method'], $tool['path']), $tool['name']);
            $this->assertMatchesRegularExpression('/^[a-z_]{3,64}$/', $tool['name']);
        }
    }
}
