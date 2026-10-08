<?php

declare(strict_types=1);

use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Streaming\DeploymentStreamConnection;
use App\Http\Streaming\NativeDeploymentStreamConnection;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\FakeRouteRemovalProjector;
use Tests\Support\Orb220DeploymentApiFixture;

/** @param array<string, mixed> $params */
function mcp_call(mixed $test, string $method, array $params = [], string $endpoint = '/mcp'): TestResponse
{
    return $test->postJson($endpoint, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
}

/**
 * The JSON-RPC message of a reply, whether it arrived as one JSON document or as a server-sent event stream.
 *
 * @return array<string, mixed>
 */
function mcp_message(TestResponse $response): array
{
    if (! $response->baseResponse instanceof StreamedResponse) {
        return $response->json();
    }

    preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);

    return json_decode((string) end($matches[1]), true);
}

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

describe('POST /mcp', function (): void {
    it('answers the MCP handshake with the server identity and operating instructions', function (): void {
        $response = mcp_call($this, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ]);

        $response->assertOk();

        expect($response->json('result.serverInfo.name'))->toBe('Orbit Gateway')
            ->and($response->json('result.capabilities.tools'))->not->toBeNull()
            ->and($response->json('result.instructions'))->toContain('WireGuard');
    });

    it('lists one tool for every manifest entry', function (): void {
        $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();
        $this->postJson('/api/v1/extensions/proxycli/enable')->assertOk();
        $names = [];
        $cursor = null;

        do {
            $response = mcp_call($this, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            $response->assertOk();
            $names = [...$names, ...array_column($response->json('result.tools'), 'name')];
            $cursor = $response->json('result.nextCursor');
        } while (is_string($cursor));

        $manifest = json_decode((string) file_get_contents(resource_path('mcp/tools.json')), true);

        expect($response->json('result.nextCursor'))->toBeNull('The catalogue must fit in one page.')
            ->and($names)->toEqualCanonicalizing(array_column($manifest['tools'], 'name'))
            ->and($names)->toContain('node-list', 'instance-deploy', 'process-restart');
    });

    it('runs a read tool as the calling peer and returns the API document', function (): void {
        $response = mcp_call($this, 'tools/call', ['name' => 'node-list', 'arguments' => (object) []]);

        $response->assertOk();
        expect($response->json('result.isError'))->toBeFalse();

        $document = json_decode($response->json('result.content.0.text'), true);

        expect(array_column($document['data'], 'name'))->toContain('gateway')
            ->and($document['meta']['request_id'])->toBeString();
    });

    it('places a DELETE path parameter from the tool arguments onto the Route', function (): void {
        app()->instance(RouteRemovalProjector::class, new FakeRouteRemovalProjector);
        $project = Project::query()->create([
            'name' => 'MCP routes',
            'slug' => 'mcp-routes',
            'repository_url' => 'https://example.test/mcp-routes.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);
        $node = Node::query()->create([
            'name' => 'mcp-route-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => 'mcp.test',
            'public_ssh_host' => '192.0.2.40',
            'wireguard_ip' => '10.44.0.40',
            'user' => 'orbit',
        ]);
        $routeId = Route::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'domain' => 'mcp-destroy.example.test',
            'provenance' => 'explicit',
            'publication' => 'private',
            'status' => 'pending',
        ])->id;

        $response = mcp_call($this, 'tools/call', [
            'name' => 'route-destroy',
            'arguments' => ['route' => $routeId],
        ]);
        $document = json_decode($response->json('result.content.0.text'), true);

        expect($response->json('result.isError'))->toBeFalse()
            ->and($document['data']['id'])->toBe($routeId)
            ->and(Route::query()->whereKey($routeId)->exists())->toBeFalse();
    });

    it('places path, query, and body inputs where the operation expects them', function (): void {
        $response = mcp_call($this, 'tools/call', ['name' => 'node-show', 'arguments' => ['node' => $this->gateway->id]]);

        $document = json_decode($response->json('result.content.0.text'), true);

        expect($response->json('result.isError'))->toBeFalse()
            ->and($document['data']['name'])->toBe('gateway');
    });

    it('returns the API error envelope when validation fails', function (): void {
        $response = mcp_call($this, 'tools/call', ['name' => 'project-create', 'arguments' => ['slug' => 'Not A Slug', 'type' => 'laravel-app']]);

        $error = json_decode($response->json('result.content.0.text'), true);

        expect($response->json('result.isError'))->toBeTrue()
            ->and($error['status'])->toBe(422)
            ->and($error['error']['code'])->toBeString();
    });

    it('returns a streamed deploy as JSON-RPC instead of flushing it onto the MCP reply', function (): void {
        $fixture = Orb220DeploymentApiFixture::create();
        // The production connection echoes and flushes each event line, as it does for the CLI.
        $this->app->instance(DeploymentStreamConnection::class, new NativeDeploymentStreamConnection);
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip]);

        ob_start();

        try {
            $response = mcp_call($this, 'tools/call', ['name' => 'instance-deploy', 'arguments' => ['instance' => $fixture->instance->id]]);
        } finally {
            $leaked = (string) ob_get_clean();
        }

        $events = json_decode($response->json('result.content.0.text'), true)['events'] ?? [];

        expect($leaked)->toBe('', 'Output sent before the MCP reply makes PHP send text/html headers.')
            ->and($response->headers->get('Content-Type'))->toBe('application/json')
            ->and($response->json('result.isError'))->toBeFalse()
            ->and(array_column($events, 'type'))->toContain('phase', 'output')
            ->and(end($events))->toMatchArray(['type' => 'result', 'status' => 'succeeded']);
    });

    it('refuses a caller that is not an active WireGuard peer', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        mcp_call($this, 'tools/list')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    });

    it('enforces directed node access inside the tool call', function (): void {
        $peer = Node::query()->create([
            'name' => 'peer',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.3',
            'wireguard_ip' => '10.44.0.3',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip]);

        $response = mcp_call($this, 'tools/call', ['name' => 'node-list', 'arguments' => (object) []]);
        $error = json_decode($response->json('result.content.0.text'), true);

        expect($response->json('result.isError'))->toBeTrue()
            ->and($error['status'])->toBe(403);
    });
});

describe('/mcp sessions', function (): void {
    beforeEach(function (): void {
        $this->initialize = fn (): TestResponse => mcp_call($this, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ]);
    });

    it('declares tool list changes and names the tool list in the session id', function (): void {
        $response = ($this->initialize)();

        expect($response->json('result.capabilities.tools.listChanged'))->toBeTrue()
            ->and($response->headers->get('Mcp-Session-Id'))->toMatch('/\A[0-9a-f]{16}\.[0-9a-f]{32}\z/');

        $session = $response->headers->get('Mcp-Session-Id');

        $this->withHeader('Mcp-Session-Id', $session);
        $listed = mcp_call($this, 'tools/list');

        $listed->assertOk();
        expect($listed->json('result.tools'))->not->toBeEmpty()
            ->and($listed->headers->has('Mcp-Session-Id'))->toBeFalse();
    });

    it('ends a session opened under an older tool list so the client lists the tools again', function (): void {
        $session = ($this->initialize)()->headers->get('Mcp-Session-Id');
        $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();
        $this->withHeader('Mcp-Session-Id', $session);

        $response = mcp_call($this, 'tools/call', ['name' => 'node-list', 'arguments' => (object) []]);

        $response->assertNotFound()
            ->assertExactJson(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Session not found']]);
        expect(Activity::query()->where('command', 'node:list')->exists())->toBeFalse();

        $this->flushHeaders();
        $renewed = ($this->initialize)()->headers->get('Mcp-Session-Id');
        $this->withHeader('Mcp-Session-Id', $renewed);
        $names = array_column(mcp_call($this, 'tools/list')->json('result.tools'), 'name');

        expect($renewed)->not->toBe($session)
            ->and($names)->toContain('tasks-create');
    });

    it('keeps serving a client that has no session id', function (): void {
        mcp_call($this, 'tools/list')->assertOk();
    });

    it('offers only the initialize handshake to a client that probes with server/discover', function (): void {
        $response = $this->withHeaders(['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'server/discover'])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ]],
        ]);

        $response->assertOk();
        expect($response->json('result.supportedVersions'))->not->toBeEmpty()->not->toContain('2026-07-28')
            ->and($response->json('result.capabilities.tools.listChanged'))->toBeTrue();
    });
});

describe('POST /mcp/search', function (): void {
    it('offers the catalogue through search_tools and execute_tools', function (): void {
        $tools = mcp_message(mcp_call($this, 'tools/list', [], '/mcp/search'));

        expect(array_column($tools['result']['tools'], 'name'))->toEqualCanonicalizing(['search_tools', 'execute_tools']);

        $found = mcp_message(mcp_call($this, 'tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'node list']], '/mcp/search'));

        expect($found['result']['content'][0]['text'])->toContain('node-list');

        $executed = mcp_message(mcp_call($this, 'tools/call', [
            'name' => 'execute_tools',
            'arguments' => ['calls' => [['name' => 'node-list', 'arguments' => (object) []]]],
        ], '/mcp/search'));

        expect($executed['result']['isError'])->toBeFalse()
            ->and($executed['result']['content'][0]['text'])->toContain('gateway');
    });

    it('bounds a whole execute_tools batch by one command deadline', function (): void {
        $now = 1_000.0;
        $deadline = new CommandDeadline(static function () use (&$now): float {
            return $now;
        });
        $this->app->instance(CommandDeadline::class, $deadline);
        // Each tool call is a nested API request. The first one uses up the batch's 550 seconds of forward work.
        Event::listen(RequestHandled::class, static function () use (&$now): void {
            $now += 551.0;
        });

        $executed = mcp_message(mcp_call($this, 'tools/call', [
            'name' => 'execute_tools',
            'arguments' => ['calls' => [
                ['name' => 'node-list', 'arguments' => (object) []],
                ['name' => 'node-list', 'arguments' => (object) []],
            ]],
        ], '/mcp/search'));
        $results = json_decode($executed['result']['content'][0]['text'], true)['results'];
        $late = json_decode($results[1]['content'][0]['text'], true);

        expect($executed['result']['isError'])->toBeTrue()
            ->and($results[0]['isError'] ?? false)->toBeFalse()
            ->and($results[1]['isError'])->toBeTrue()
            ->and($late['status'])->toBe(504)
            ->and($late['error']['code'])->toBe('command.deadline_exceeded')
            ->and(Activity::query()->where('command', 'node:list')->where('error_code', 'command.deadline_exceeded')->exists())->toBeTrue()
            // The deadline ends with the MCP request.
            ->and($deadline->cap(9_999.0))->toBe(9_999.0);
    });
});
