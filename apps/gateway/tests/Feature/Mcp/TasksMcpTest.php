<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDefinition;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @param array<string, mixed> $params */
function tasks_mcp_call(mixed $test, string $method, array $params = [], string $endpoint = '/mcp'): TestResponse
{
    return $test->postJson($endpoint, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
}

/**
 * The Gateway JSON inside one execute_tools result.
 *
 * @param  array<string, mixed>  $message
 */
function tasks_execute_text(array $message): string
{
    $outer = json_decode((string) ($message['result']['content'][0]['text'] ?? ''));
    $text = $outer->results[0]->content[0]->text ?? '';

    return is_string($text) ? $text : '';
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
    $this->appRecord = Project::query()->create([
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
            'project_id' => $this->appRecord->id,
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
        'arguments' => ['project_id' => $this->appRecord->id],
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

describe('comment list type and limit filters', function (): void {
    it('returns the newest resolution under 64 KiB and keeps unfiltered comments newest first', function (): void {
        app(TaskExtensionState::class)->enable();
        $this->freezeTime();
        $group = Task::topLevel()->create([
            'project_id' => $this->appRecord->id,
            'title' => 'Long comment history',
            'brief' => 'Read only the latest resolution.',
            'status' => TaskGroupStatus::Backlog,
        ]);
        $task = $group->tasks()->create(['title' => 'Subtask', 'brief' => 'Many comments.', 'position' => 1]);
        $comments = [];
        for ($index = 0; $index < 40; $index++) {
            $comments[] = $task->comments()->create([
                'task_group_id' => $group->id,
                'type' => $index % 2 === 0 ? TaskCommentType::Resolution : TaskCommentType::AssistanceRequested,
                'body' => 'Comment '.$index.': '.str_repeat('x', 4096),
                'author' => 'operator',
                'posted_at' => now()->addSeconds($index),
            ]);
        }
        $arguments = ['group' => $group->id, 'task' => $task->id];
        $call = function (array $filters) use ($arguments): array {
            $message = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
                'name' => 'tasks-comment-list',
                'arguments' => [...$arguments, ...$filters],
            ]));
            expect($message['result']['isError'] ?? true)->toBeFalse();

            return $message;
        };

        $unfiltered = $call([]);
        $unfilteredText = $unfiltered['result']['content'][0]['text'];
        $unfilteredData = json_decode($unfilteredText, true)['data'];
        expect(strlen($unfilteredText))->toBeGreaterThan(65536);
        expect(array_column($unfilteredData, 'id'))->toBe(array_reverse(array_column($comments, 'id')));
        expect(array_column($unfilteredData, 'body'))->toBe(array_reverse(array_column($comments, 'body')));

        $filtered = $call(['type' => 'resolution', 'limit' => 1]);
        $filteredText = $filtered['result']['content'][0]['text'];
        $filteredData = json_decode($filteredText, true)['data'];
        expect(strlen($filteredText))->toBeLessThan(65536);
        expect($filteredData)->toHaveCount(1);
        expect($filteredData[0]['id'])->toBe($comments[38]->id);
        expect($filteredData[0]['body'])->toBe($comments[38]->body);
        expect($filteredData[0]['type'])->toBe('resolution');

        $typeOnly = json_decode($call(['type' => 'resolution'])['result']['content'][0]['text'], true)['data'];
        expect(array_column($typeOnly, 'id'))->toBe(array_reverse(array_column(array_filter($comments, static fn ($comment): bool => $comment->type === TaskCommentType::Resolution), 'id')));
        $limitOnly = json_decode($call(['limit' => 2])['result']['content'][0]['text'], true)['data'];
        expect(array_column($limitOnly, 'id'))->toBe([$comments[39]->id, $comments[38]->id]);
        $maximum = json_decode($call(['limit' => 100])['result']['content'][0]['text'], true)['data'];
        expect(array_column($maximum, 'id'))->toBe(array_column($unfilteredData, 'id'));
    });

    it('returns 422 validation.failed for invalid inputs', function (array $filters): void {
        app(TaskExtensionState::class)->enable();
        $group = Task::topLevel()->create([
            'project_id' => $this->appRecord->id,
            'title' => 'Invalid comment filters',
            'brief' => 'Reject invalid inputs.',
            'status' => TaskGroupStatus::Backlog,
        ]);
        $task = $group->tasks()->create(['title' => 'Subtask', 'brief' => 'Validate filters.', 'position' => 1]);

        $message = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
            'name' => 'tasks-comment-list',
            'arguments' => ['group' => $group->id, 'task' => $task->id, ...$filters],
        ]));
        $error = json_decode($message['result']['content'][0]['text'], true);

        expect($message['result']['isError'] ?? false)->toBeTrue();
        expect($error['status'])->toBe(422);
        expect($error['error']['code'])->toBe('validation.failed');
    })->with([
        'unknown type' => [['type' => 'bogus']],
        'zero limit' => [['limit' => 0]],
        'over maximum' => [['limit' => 101]],
        'non-integer limit' => [['limit' => 1.5]],
    ]);
});

it('creates, shows, updates, and destroys a task definition through MCP', function (): void {
    app(TaskExtensionState::class)->enable();
    $definition = [
        'name' => 'build-feature',
        'title' => 'Maintain {app}',
        'brief' => 'Update {app}.',
        'parameters' => [
            ['name' => 'app', 'type' => 'text', 'required' => true],
        ],
        'status' => 'backlog',
        'schedule' => ['cron' => '0 3 * * 1', 'values' => ['app' => 'orbit']],
        'subtasks' => [
            [
                'key' => 'docs',
                'title' => 'Write the docs',
                'kind' => 'agent',
                'routes' => ['skipped' => 'complete'],
            ],
            [
                'key' => 'ship',
                'title' => 'Deploy',
                'kind' => 'action',
                'operation' => 'instance:deploy',
                'arguments' => ['instance' => 1],
            ],
        ],
    ];

    $created = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-definition-create',
        'arguments' => ['project' => $this->appRecord->id, ...$definition],
    ]));
    $createdDocument = json_decode($created['result']['content'][0]['text'], true);

    expect($created['result']['isError'] ?? true)->toBeFalse()
        ->and($createdDocument['data']['name'])->toBe('build-feature')
        ->and($createdDocument['data']['schedule']['values']['app'])->toBe('orbit')
        ->and($createdDocument['data']['subtasks'][0]['routes']['skipped'])->toBe('complete')
        ->and($createdDocument['data']['subtasks'][1]['arguments']['instance'])->toBe(1);

    $shown = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-definition-show',
        'arguments' => ['project' => $this->appRecord->id, 'name' => 'build-feature'],
    ]));
    $shownDocument = json_decode($shown['result']['content'][0]['text'], true);

    expect($shown['result']['isError'] ?? true)->toBeFalse()
        ->and($shownDocument['data']['title'])->toBe('Maintain {app}');

    $updated = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-definition-update',
        'arguments' => [
            'project' => $this->appRecord->id,
            'name' => 'build-feature',
            'title' => 'Build it faster',
            'schedule' => $definition['schedule'],
            'brief' => $definition['brief'],
            'parameters' => $definition['parameters'],
            'status' => 'backlog',
            'subtasks' => $definition['subtasks'],
        ],
    ]));
    $updatedDocument = json_decode($updated['result']['content'][0]['text'], true);

    expect($updated['result']['isError'] ?? true)->toBeFalse()
        ->and($updatedDocument['data']['title'])->toBe('Build it faster')
        ->and(TaskDefinition::query()->where('project_id', $this->appRecord->id)->value('title'))->toBe('Build it faster');

    $destroyed = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-definition-destroy',
        'arguments' => ['project' => $this->appRecord->id, 'name' => 'build-feature'],
    ]));

    expect($destroyed['result']['isError'] ?? true)->toBeFalse()
        ->and(TaskDefinition::query()->where('project_id', $this->appRecord->id)->count())->toBe(0);
});

it('keeps a nested empty object through a definition show and update', function (): void {
    app(TaskExtensionState::class)->enable();
    $shape = '{"options":{},"flags":[]}';
    $arguments = [
        'project' => $this->appRecord->id,
        'name' => 'keep-objects',
        'title' => 'Keep objects',
        'brief' => 'Nested objects stay objects.',
        'parameters' => [[
            'name' => 'tuning',
            'type' => 'text',
            'required' => false,
            'default' => ['options' => (object) [], 'flags' => []],
        ]],
        'status' => 'backlog',
        'subtasks' => [[
            'key' => 'ship',
            'title' => 'Ship it',
            'kind' => 'action',
            'operation' => 'instance:deploy',
            'arguments' => ['options' => (object) [], 'flags' => []],
        ]],
    ];

    $created = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-definition-create',
        'arguments' => $arguments,
    ]));
    $createdText = $created['result']['content'][0]['text'] ?? '';

    expect($created['result']['isError'] ?? true)->toBeFalse()
        ->and($createdText)->toContain('"default":'.$shape)
        ->and($createdText)->toContain('"arguments":'.$shape);

    $shown = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-definition-show',
        'arguments' => ['project' => $this->appRecord->id, 'name' => 'keep-objects'],
    ]));
    $shownText = $shown['result']['content'][0]['text'] ?? '';
    $document = json_decode($shownText);
    $replacement = $document->data;
    $replacement->project = $replacement->project_id;
    unset($replacement->project_id);

    $updated = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-definition-update',
        'arguments' => $replacement,
    ]));
    $updatedText = $updated['result']['content'][0]['text'] ?? '';
    $again = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-definition-show',
        'arguments' => ['project' => $this->appRecord->id, 'name' => 'keep-objects'],
    ]));

    expect($shown['result']['isError'] ?? true)->toBeFalse()
        ->and($shownText)->toContain('"default":'.$shape)
        ->and($shownText)->toContain('"arguments":'.$shape)
        ->and($updated['result']['isError'] ?? true)->toBeFalse()
        ->and($updatedText)->toContain('"default":'.$shape)
        ->and($updatedText)->toContain('"arguments":'.$shape)
        ->and($again['result']['content'][0]['text'] ?? '')->toContain('"default":'.$shape)
        ->and($again['result']['content'][0]['text'] ?? '')->toContain('"arguments":'.$shape);
});

it('keeps a nested empty object through execute_tools on the search endpoint', function (): void {
    app(TaskExtensionState::class)->enable();
    $shape = '{"options":{},"flags":[]}';
    $definition = [
        'project' => $this->appRecord->id,
        'name' => 'keep-objects',
        'title' => 'Keep objects',
        'brief' => 'Nested objects stay objects.',
        'parameters' => [[
            'name' => 'tuning',
            'type' => 'text',
            'required' => false,
            'default' => ['options' => (object) [], 'flags' => []],
        ]],
        'status' => 'backlog',
        'subtasks' => [[
            'key' => 'ship',
            'title' => 'Ship it',
            'kind' => 'action',
            'operation' => 'instance:deploy',
            'arguments' => ['options' => (object) [], 'flags' => []],
        ]],
    ];

    $created = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [['name' => 'tasks-definition-create', 'arguments' => $definition]]],
    ], '/mcp/search'));
    $createdText = tasks_execute_text($created);

    expect($created['result']['isError'] ?? true)->toBeFalse()
        ->and($createdText)->toContain('"default":'.$shape)
        ->and($createdText)->toContain('"arguments":'.$shape);

    $shown = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [[
            'name' => 'tasks-definition-show',
            'arguments' => ['project' => $this->appRecord->id, 'name' => 'keep-objects'],
        ]]],
    ], '/mcp/search'));
    $shownText = tasks_execute_text($shown);
    $document = json_decode($shownText);
    $replacement = $document->data;
    $replacement->project = $replacement->project_id;
    unset($replacement->project_id);

    $updated = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'execute_tools',
        'arguments' => ['calls' => [['name' => 'tasks-definition-update', 'arguments' => $replacement]]],
    ], '/mcp/search'));
    $updatedText = tasks_execute_text($updated);
    $again = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-definition-show',
        'arguments' => ['project' => $this->appRecord->id, 'name' => 'keep-objects'],
    ]));

    expect($shown['result']['isError'] ?? true)->toBeFalse()
        ->and($shownText)->toContain('"default":'.$shape)
        ->and($shownText)->toContain('"arguments":'.$shape)
        ->and($updated['result']['isError'] ?? true)->toBeFalse()
        ->and($updatedText)->toContain('"default":'.$shape)
        ->and($updatedText)->toContain('"arguments":'.$shape)
        ->and($again['result']['isError'] ?? true)->toBeFalse()
        ->and($again['result']['content'][0]['text'] ?? '')->toContain('"default":'.$shape)
        ->and($again['result']['content'][0]['text'] ?? '')->toContain('"arguments":'.$shape);
});

it('describes definition routes, arguments, and schedule values as objects', function (): void {
    app(TaskExtensionState::class)->enable();
    $tools = collect(tasks_mcp_message(tasks_mcp_call($this, 'tools/list'))['result']['tools']);

    foreach (['tasks-definition-create', 'tasks-definition-update'] as $name) {
        $schema = $tools->firstWhere('name', $name)['inputSchema'];
        $subtasks = $schema['properties']['subtasks']['items']['properties'];

        expect($subtasks['routes'])->toBe(['type' => 'object', 'additionalProperties' => ['type' => 'string']])
            ->and($subtasks['arguments']['type'])->toBe('object')
            ->and($schema['properties']['schedule']['properties']['values']['type'])->toBe('object')
            ->and($schema['properties']['schedule']['type'])->toBe(['object', 'null']);
    }
});

it('hides the tasks MCP tools before the extension is enabled', function (): void {
    $names = array_column(tasks_mcp_message(tasks_mcp_call($this, 'tools/list'))['result']['tools'], 'name');

    expect($names)->not->toContain('tasks-create');
    $this->postJson('/api/v1/task-groups', [
        'project_id' => $this->appRecord->id,
        'title' => 'Too soon',
        'brief' => 'Must refuse.',
    ])->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
});

it('cancels a running or queued group through MCP and removes its shared Instance', function (TaskGroupStatus $status): void {
    app(TaskExtensionState::class)->enable();
    bind_task_node_reachability();
    $node = Node::query()->create([
        'name' => 'tasks-mcp-instance-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.86',
        'wireguard_ip' => '10.44.0.86',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $this->appRecord->id,
        'node_id' => $node->id,
        'name' => 'task-mcp-cancel',
        'checkout_path' => '/srv/orbit/apps/mcp-demo/task-mcp-cancel',
        'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $this->appRecord->id,
        'title' => 'MCP cancel',
        'brief' => 'Cancel a stuck group.',
        'status' => $status,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    app()->instance(InstanceRemover::class, new class implements InstanceRemover
    {
        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $instance->delete();

            return new InstanceRemoval;
        }
    });

    $cancelled = tasks_mcp_message(tasks_mcp_call($this, 'tools/call', [
        'name' => 'tasks-cancel',
        'arguments' => ['group' => $group->id],
    ]));
    $document = json_decode($cancelled['result']['content'][0]['text'], true);

    expect($cancelled['result']['isError'] ?? true)->toBeFalse()
        ->and($document['data']['status'])->toBe('cancelled')
        ->and($document['data']['taskable_id'])->toBeNull()
        ->and(Instance::query()->find($instance->id))->toBeNull();
})->with([
    'backlog' => TaskGroupStatus::Backlog,
    'todo' => TaskGroupStatus::Todo,
    'running' => TaskGroupStatus::Running,
]);

it('returns a structured MCP error for canceling a settling group with a pull request and still completes it', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = Task::topLevel()->create([
        'project_id' => $this->appRecord->id,
        'title' => 'MCP settle',
        'brief' => 'Complete after review.',
        'status' => TaskGroupStatus::Settling,
        'pr_url' => 'https://github.com/nckrtl/orbit/pull/7',
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
    $group = Task::topLevel()->create([
        'project_id' => $this->appRecord->id,
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

    $group = $call('tasks-create', ['project_id' => $this->appRecord->id, 'title' => 'MCP backlog', 'brief' => 'Prepare first.']);
    $first = $call('tasks-subtask-create', ['group' => $group['id'], 'title' => 'First', 'brief' => 'One.']);
    $second = $call('tasks-subtask-create', ['group' => $group['id'], 'title' => 'Second', 'brief' => 'Two.', 'deliverables' => [
        ['id' => 'second-page', 'type' => 'file', 'description' => 'Document the second step', 'path' => 'docs/second.md', 'change' => 'created'],
    ]]);
    $moved = $call('tasks-subtask-update', ['group' => $group['id'], 'task' => $second['id'], 'position' => 1]);
    $destroyed = $call('tasks-subtask-destroy', ['group' => $group['id'], 'task' => $first['id']]);
    $ready = $call('tasks-update', ['group' => $group['id'], 'status' => 'todo']);

    expect($group['status'])->toBe('backlog')
        ->and($moved['position'])->toBe(1)
        ->and($destroyed['title'])->toBe('First')
        ->and($ready['status'])->toBe('todo')
        ->and(array_column($ready['tasks'], 'title'))->toBe(['Second'])
        ->and($ready['tasks'][0]['deliverables'])->toBe([
            ['id' => 'second-page', 'type' => 'file', 'description' => 'Document the second step', 'path' => 'docs/second.md', 'change' => 'created'],
        ]);
});
