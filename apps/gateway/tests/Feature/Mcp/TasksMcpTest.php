<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;
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

it('cancels a running or queued group through MCP and removes its shared Instance', function (TaskGroupStatus $status): void {
    app(TaskExtensionState::class)->enable();
    $node = Node::query()->create([
        'name' => 'tasks-mcp-instance-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.86',
        'wireguard_ip' => '10.44.0.86',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $this->appRecord->id,
        'node_id' => $node->id,
        'name' => 'task-mcp-cancel',
        'checkout_path' => '/srv/orbit/apps/mcp-demo/task-mcp-cancel',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $this->appRecord->id,
        'title' => 'MCP cancel',
        'brief' => 'Cancel a stuck group.',
        'status' => $status,
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    $cancelled = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-cancel',
        'arguments' => ['group' => $group->id],
    ]));
    $document = json_decode($cancelled['result']['content'][0]['text'], true);

    expect($cancelled['result']['isError'] ?? true)->toBeFalse()
        ->and($document['data']['status'])->toBe('cancelled')
        ->and($document['data']['taskable_id'])->toBeNull()
        ->and(AppInstance::query()->find($instance->id))->toBeNull();
})->with([
    'backlog' => TaskGroupStatus::Backlog,
    'todo' => TaskGroupStatus::Todo,
    'running' => TaskGroupStatus::Running,
]);

it('returns a structured MCP error for canceling a settling group and still completes it', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = TaskGroup::query()->create([
        'app_id' => $this->appRecord->id,
        'title' => 'MCP settle',
        'brief' => 'Complete after review.',
        'status' => TaskGroupStatus::Settling,
    ]);

    $cancelled = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-cancel',
        'arguments' => ['group' => $group->id],
    ]));
    $error = json_decode($cancelled['result']['content'][0]['text'], true);

    expect($cancelled['result']['isError'])->toBeTrue()
        ->and($error['status'])->toBe(409)
        ->and($error['error']['code'])->toBe('tasks.not_cancellable');

    $completed = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-complete',
        'arguments' => ['group' => $group->id],
    ]));
    $document = json_decode($completed['result']['content'][0]['text'], true);

    expect($completed['result']['isError'] ?? true)->toBeFalse()
        ->and($document['data']['status'])->toBe('completed');
});

it('returns a structured MCP error for canceling a completed group', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = TaskGroup::query()->create([
        'app_id' => $this->appRecord->id,
        'title' => 'MCP completed',
        'brief' => 'Already complete.',
        'status' => TaskGroupStatus::Completed,
    ]);

    $cancelled = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-cancel',
        'arguments' => ['group' => $group->id],
    ]));
    $error = json_decode($cancelled['result']['content'][0]['text'], true);

    expect($cancelled['result']['isError'])->toBeTrue()
        ->and($error['status'])->toBe(409)
        ->and($error['error']['code'])->toBe('tasks.not_cancellable');
});

it('prepares a backlog group and moves it to todo through MCP', function (): void {
    app(TaskExtensionState::class)->enable();
    $call = function (string $name, array $arguments): array {
        $message = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', ['name' => $name, 'arguments' => $arguments]));

        expect($message['result']['isError'] ?? true)->toBeFalse();

        return json_decode($message['result']['content'][0]['text'], true)['data'];
    };

    $tools = collect(tasks_mcp_message(tasks_mcp_call($this, 'tools/list'))['result']['tools'])->pluck('name');

    expect($tools)->toContain('tasks-update', 'tasks-subtask-create', 'tasks-subtask-update', 'tasks-subtask-destroy')
        ->and($tools)->not->toContain('tasks-add');

    $group = $call('tasks-create', ['app_id' => $this->appRecord->id, 'title' => 'MCP backlog', 'brief' => 'Prepare first.']);
    $first = $call('tasks-subtask-create', ['group' => $group['id'], 'title' => 'First', 'brief' => 'One.']);
    $second = $call('tasks-subtask-create', ['group' => $group['id'], 'title' => 'Second', 'brief' => 'Two.']);
    $moved = $call('tasks-subtask-update', ['group' => $group['id'], 'task' => $second['id'], 'position' => 1]);
    $destroyed = $call('tasks-subtask-destroy', ['group' => $group['id'], 'task' => $first['id']]);
    $ready = $call('tasks-update', ['group' => $group['id'], 'status' => 'todo']);

    expect($group['status'])->toBe('backlog')
        ->and($moved['position'])->toBe(1)
        ->and($destroyed['title'])->toBe('First')
        ->and($ready['status'])->toBe('todo')
        ->and(array_column($ready['tasks'], 'title'))->toBe(['Second']);
});
