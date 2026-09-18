<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $names = [];
        $cursor = null;

        do {
            $response = mcp_call($this, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            $response->assertOk();
            $names = [...$names, ...array_column($response->json('result.tools'), 'name')];
            $cursor = $response->json('result.nextCursor');
        } while (is_string($cursor));

        $manifest = json_decode((string) file_get_contents(resource_path('mcp/tools.json')), true);

        expect($names)->toEqualCanonicalizing(array_column($manifest['tools'], 'name'))
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

    it('places path, query, and body inputs where the operation expects them', function (): void {
        $response = mcp_call($this, 'tools/call', ['name' => 'node-show', 'arguments' => ['node' => $this->gateway->id]]);

        $document = json_decode($response->json('result.content.0.text'), true);

        expect($response->json('result.isError'))->toBeFalse()
            ->and($document['data']['name'])->toBe('gateway');
    });

    it('returns the API error envelope when validation fails', function (): void {
        $response = mcp_call($this, 'tools/call', ['name' => 'app-create', 'arguments' => ['slug' => 'Not A Slug']]);

        $error = json_decode($response->json('result.content.0.text'), true);

        expect($response->json('result.isError'))->toBeTrue()
            ->and($error['status'])->toBe(422)
            ->and($error['error']['code'])->toBeString();
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
});
