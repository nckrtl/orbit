<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\App as OrbitApp;
use App\Models\Node;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @param array<string, mixed> $params */
function tasks_mcp_call(mixed $test, string $method, array $params = []): TestResponse
{
    return $test->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
}

/**
 * @return array<string, mixed>
 */
function tasks_mcp_message(TestResponse $response): array
{
    if (! $response->baseResponse instanceof StreamedResponse) {
        return $response->json();
    }

    preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);

    return json_decode((string) end($matches[1]), true);
}

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'tasks-mcp-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.85',
        'wireguard_ip' => '10.44.0.85',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->appRecord = OrbitApp::query()->create([
        'name' => 'MCP demo',
        'slug' => 'mcp-demo',
        'repository_url' => 'git@example.test:mcp-demo.git',
        'default_branch' => 'main',
    ]);
});

it('creates and lists a task group through MCP after the extension is enabled', function (): void {
    app(TaskExtensionState::class)->enable();

    $created = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-create',
        'arguments' => [
            'app_id' => $this->appRecord->id,
            'title' => 'MCP create',
            'brief' => 'Create through the generated tool.',
            'tasks' => [
                ['title' => 'First', 'brief' => 'One subtask'],
            ],
        ],
    ]));

    expect($created['result']['isError'] ?? true)->toBeFalse();

    $document = json_decode($created['result']['content'][0]['text'], true);

    expect($document['data']['title'])->toBe('MCP create')
        ->and($document['data']['tasks'][0]['title'])->toBe('First');

    $listed = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-list',
        'arguments' => ['app_id' => $this->appRecord->id],
    ]));
    $listDocument = json_decode($listed['result']['content'][0]['text'], true);

    expect($listed['result']['isError'] ?? true)->toBeFalse()
        ->and($listDocument['data'][0]['id'])->toBe($document['data']['id']);

    $shown = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-show',
        'arguments' => ['group' => $document['data']['id']],
    ]));
    $showDocument = json_decode($shown['result']['content'][0]['text'], true);

    expect($shown['result']['isError'] ?? true)->toBeFalse()
        ->and($showDocument['data']['id'])->toBe($document['data']['id'])
        ->and($showDocument['data']['brief'])->toBe('Create through the generated tool.');
});

it('returns tasks.disabled when MCP create runs before enable', function (): void {
    $created = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-create',
        'arguments' => [
            'app_id' => $this->appRecord->id,
            'title' => 'Too soon',
            'brief' => 'Must refuse.',
        ],
    ]));
    $error = json_decode($created['result']['content'][0]['text'], true);

    expect($created['result']['isError'])->toBeTrue()
        ->and($error['status'])->toBe(409)
        ->and($error['error']['code'])->toBe('tasks.disabled');
});
