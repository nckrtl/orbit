<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Testing\TestResponse;

function extensions_mcp(mixed $test, string $endpoint, string $method, array $params = []): TestResponse
{
    return $test->postJson($endpoint, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
}

/** @return array<string, mixed> */
function extensions_mcp_message(TestResponse $response): array
{
    preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);

    return json_decode((string) end($matches[1]), true);
}

beforeEach(function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'extension-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.18',
        'wireguard_ip' => '10.44.0.18',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
});

it('disabled extension is invisible for tasks API and MCP tools', function (): void {
    $this->getJson('/api/v1/extensions')->assertOk()
        ->assertJsonPath('data.tasks', false)
        ->assertJsonPath('data.proxycli', false);
    $this->postJson('/api/v1/extensions/not-real/enable')->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    $this->getJson('/api/v1/tasks/status')->assertOk();
    $this->getJson('/api/v1/task-groups')->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');

    $tools = extensions_mcp($this, '/mcp', 'tools/list')->assertOk()->json('result.tools');
    expect(array_column($tools, 'name'))->not->toContain('tasks-list', 'tasks-create');
    $staleCall = extensions_mcp($this, '/mcp', 'tools/call', ['name' => 'tasks-list', 'arguments' => []])
        ->assertOk()->json('result');
    $staleError = json_decode($staleCall['content'][0]['text'], true);
    expect($staleCall['isError'])->toBeTrue()
        ->and($staleError['status'])->toBe(409)
        ->and($staleError['error']['code'])->toBe('extension.disabled');

    $search = extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'search_tools', 'arguments' => ['query' => 'tasks'],
    ])->assertOk()->json('result.content.0.text');
    expect($search)->not->toContain('tasks-list', 'tasks-create');
    $staleSearchExecution = extensions_mcp_message(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [['name' => 'tasks-list', 'arguments' => []]]],
    ]))['result'];
    expect($staleSearchExecution['isError'])->toBeTrue()
        ->and($staleSearchExecution['content'][0]['text'])->toContain('409', 'extension.disabled');

    $mixedBatch = extensions_mcp_message(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [
            ['name' => 'extension-list', 'arguments' => []],
            ['name' => 'tasks-list', 'arguments' => []],
        ]],
    ]))['result'];
    $mixedPayload = json_decode($mixedBatch['content'][0]['text'], true);
    expect($mixedBatch['isError'])->toBeTrue()
        ->and(array_column($mixedPayload['results'], 'name'))->toBe(['extension-list', 'tasks-list'])
        ->and($mixedPayload['results'][0]['isError'])->toBeFalse()
        ->and($mixedPayload['results'][1]['content'][0]['text'])->toContain('409', 'extension.disabled');

    $originalOutputLimit = config('mcp.tool_search.max_output_bytes');
    config()->set('mcp.tool_search.max_output_bytes', 256);
    $limitedBatch = extensions_mcp_message(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [
            ['name' => 'extension-list', 'arguments' => []],
            ['name' => 'tasks-list', 'arguments' => []],
        ]],
    ]))['result'];
    $limitedPayload = json_decode($limitedBatch['content'][0]['text'], true);
    expect($limitedBatch['isError'])->toBeTrue()
        ->and($limitedPayload['error']['kind'])->toBe('OutputLimitExceeded')
        ->and($limitedPayload['completedToolCalls'])->toBe(2)
        ->and($limitedPayload['attemptedToolCalls'])->toBe(2);
    config()->set('mcp.tool_search.max_output_bytes', $originalOutputLimit);

    $earlierErrorBatch = extensions_mcp_message(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [
            ['name' => 'not-a-tool', 'arguments' => []],
            ['name' => 'tasks-list', 'arguments' => []],
        ]],
    ]))['result'];
    $earlierErrorPayload = json_decode($earlierErrorBatch['content'][0]['text'], true);
    expect($earlierErrorBatch['isError'])->toBeTrue()
        ->and(array_column($earlierErrorPayload['results'], 'name'))->toBe(['not-a-tool'])
        ->and($earlierErrorPayload['results'][0]['content'][0]['text'])->toContain('not-a-tool');

    $this->postJson('/api/v1/extensions/tasks/enable')->assertOk()->assertJsonPath('data.enabled', true);
    $this->getJson('/api/v1/task-groups')->assertOk();
    expect(array_column(extensions_mcp($this, '/mcp', 'tools/list')->json('result.tools'), 'name'))->toContain('tasks-list');
    expect(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'search_tools', 'arguments' => ['query' => 'tasks list'],
    ])->json('result.content.0.text'))->toContain('tasks-list');
});

it('disabled extension is invisible for proxycli API and MCP tools', function (): void {
    $this->getJson('/api/v1/proxycli')->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    expect(array_column(extensions_mcp($this, '/mcp', 'tools/list')->assertOk()->json('result.tools'), 'name'))
        ->not->toContain('proxycli-status', 'proxycli-list');
    $staleCall = extensions_mcp($this, '/mcp', 'tools/call', ['name' => 'proxycli-status', 'arguments' => []])
        ->assertOk()->json('result');
    $staleError = json_decode($staleCall['content'][0]['text'], true);
    expect($staleCall['isError'])->toBeTrue()
        ->and($staleError['status'])->toBe(409)
        ->and($staleError['error']['code'])->toBe('extension.disabled');
    expect(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'search_tools', 'arguments' => ['query' => 'proxycli'],
    ])->assertOk()->json('result.content.0.text'))->not->toContain('proxycli-status');
    $staleSearchExecution = extensions_mcp_message(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [['name' => 'proxycli-status', 'arguments' => []]]],
    ]))['result'];
    expect($staleSearchExecution['isError'])->toBeTrue()
        ->and($staleSearchExecution['content'][0]['text'])->toContain('409', 'extension.disabled');

    $this->postJson('/api/v1/extensions/proxycli/enable')->assertOk();
    $this->getJson('/api/v1/proxycli')->assertOk();
    expect(array_column(extensions_mcp($this, '/mcp', 'tools/list')->json('result.tools'), 'name'))->toContain('proxycli-status');
    expect(extensions_mcp($this, '/mcp/search', 'tools/call', [
        'name' => 'search_tools', 'arguments' => ['query' => 'proxycli status'],
    ])->json('result.content.0.text'))->toContain('proxycli-status');
});
