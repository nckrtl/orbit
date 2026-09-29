<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskReviewDiffException;
use App\Domain\Tasks\TaskReviewPacket;
use App\Domain\Tasks\TaskReviewPacketBuilder;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnInstructions;
use App\Domain\Tasks\TaskTurnReceipt;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Infrastructure\Tasks\RemoteTaskReviewDiff;
use App\Infrastructure\Tasks\T3\HttpT3Dispatcher;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3DispatchException;
use App\Infrastructure\Tasks\T3\T3ModelSelection;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\FakeAgentDriver;

beforeEach(function (): void {
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
});

function t3_spawner_group(): Task
{
    $project = Project::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@example.test:orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 't3-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.110',
        'wireguard_ip' => '10.44.0.110',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-1',
        'checkout_path' => '/srv/orbit/apps/orbit/task-1',
        'branch' => 'task-1',
        'status' => 'source_resolved',
        'starting_commit' => str_repeat('b', 40),
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Wire T3',
        'brief' => 'Spawn reviewer and implementer.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'Models',
        'brief' => 'Store the records.',
        'status' => TaskStatus::Running,
    ]);

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

/**
 * @return array{TaskAgentSpawner, object, object}
 */
function t3_spawner_stack(?TaskWorkspaceMcp $mcp = null): array
{
    $dispatcher = new class implements T3Dispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public bool $fail = false;

        public ?string $adoptProjectId = null;

        public int $failTurnStartRemaining = 0;

        public function dispatch(Node $node, array $command): array
        {
            expect($command)->not->toHaveKey('command')
                ->and($node->wireguard_ip)->toBe('10.44.0.110');

            if ($this->fail) {
                throw new T3DispatchException;
            }

            if (is_string($this->adoptProjectId) && ($command['type'] ?? null) === 'project.create') {
                $this->commands[] = $command;

                throw new T3DispatchException(existingProjectId: $this->adoptProjectId);
            }

            if (($command['type'] ?? null) === 'thread.turn.start' && $this->failTurnStartRemaining > 0) {
                $this->failTurnStartRemaining--;
                $this->commands[] = $command;

                throw new T3DispatchException('T3 turn start failed.');
            }

            $this->commands[] = $command;
            $threadId = is_string($command['threadId'] ?? null) ? $command['threadId'] : 'generated-thread';

            return ['sequence' => count($this->commands), 'thread_id' => $threadId];
        }
    };

    return [new TaskAgentSpawner(test_t3_registry($dispatcher), app(TaskReviewPacketBuilder::class), $mcp ?? app(TaskWorkspaceMcp::class)), $dispatcher];
}

it('spawns the group reviewer with its first review and a fresh implementer on the instance Node', function (): void {
    $group = t3_spawner_group();
    [$spawner, $dispatcher] = t3_spawner_stack();

    $reviewerId = $spawner->spawnReviewer($group->tasks->first());
    $implementerId = $spawner->spawnImplementer($group->tasks->first());

    expect($reviewerId)->not->toBeNull()
        ->and($implementerId)->not->toBeNull()
        ->and($reviewerId)->not->toBe($implementerId)
        ->and(array_column($dispatcher->commands, 'type'))->toBe([
            'project.create',
            'thread.create',
            'thread.turn.start',
            'project.create',
            'thread.create',
            'thread.turn.start',
        ]);

    $reviewerProject = $dispatcher->commands[0];
    $reviewerCreate = $dispatcher->commands[1];
    $implementerProject = $dispatcher->commands[3];
    $implementerCreate = $dispatcher->commands[4];
    $reviewerSelection = T3ModelSelection::forModel(TaskAgentDefaults::ReviewerModel, TaskAgentDefaults::ReviewerEffort);
    $implementerSelection = T3ModelSelection::forModel(TaskAgentDefaults::ImplementerModel, TaskAgentDefaults::ImplementerEffort);

    $task = $group->tasks->first();
    expect($reviewerCreate['title'])->toBe('Orbit task #'.$group->id.' · Review: '.$task->title)
        ->and($reviewerProject['defaultModelSelection'])->toBe($reviewerSelection)
        ->and($reviewerCreate['modelSelection'])->toBe($reviewerSelection)
        ->and($implementerProject['defaultModelSelection'])->toBe($implementerSelection)
        ->and($implementerCreate['modelSelection'])->toBe($implementerSelection)
        ->and($reviewerCreate['worktreePath'])->toBe('/srv/orbit/apps/orbit/task-1')
        ->and($reviewerCreate['branch'])->toBe('task-1')
        ->and($dispatcher->commands[2]['message'])->toMatchArray([
            'role' => 'user',
            'attachments' => [],
        ])
        ->and($dispatcher->commands[2]['message']['text'])->toContain('Do not re-run the Project task check or deliverable commands the handoff already passed.')
        ->and($dispatcher->commands[2]['message']['text'])->toContain('use your web and documentation tools to confirm that framework and library usage matches current documentation')
        ->and($dispatcher->commands[2]['message']['text'])->toContain('Review subtask #'.$task->id.': '.$task->title)
        ->and($dispatcher->commands[2]['message']['text'])->toContain('Group brief')
        ->and($dispatcher->commands[2]['message']['text'])->toContain($group->brief)
        ->and($dispatcher->commands[2]['modelSelection'])->toBe($reviewerSelection)
        ->and($dispatcher->commands[2]['runtimeMode'])->toBe('full-access')
        ->and($dispatcher->commands[2]['interactionMode'])->toBe('default')
        ->and($dispatcher->commands[2]['message']['text'])->toContain('The group started at '.str_repeat('b', 40).".\ngit diff --stat ".str_repeat('b', 40).'..HEAD')
        ->and($dispatcher->commands[5]['message']['text'])->toContain('Implement this subtask')
        ->and($dispatcher->commands[5]['message']['text'])->toContain('The group started at '.str_repeat('b', 40).".\ngit diff --stat ".str_repeat('b', 40).'..HEAD')
        ->and($dispatcher->commands[5]['message']['text'])->toContain('Follow this repository\'s task instructions.')
        ->and($dispatcher->commands[5]['modelSelection'])->toBe($implementerSelection)
        ->and($dispatcher->commands[5]['runtimeMode'])->toBe('full-access')
        ->and($dispatcher->commands[5]['interactionMode'])->toBe('default')
        ->and($implementerSelection['instanceId'])->toBe('codex')
        ->and($implementerSelection['options'])->toBe([['id' => 'reasoningEffort', 'value' => 'high']]);
});

it('posts T3 model options as id and value JSON objects', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.110:3773/api/orchestration/dispatch' => Http::response(['sequence' => 1]),
    ]);
    $group = t3_spawner_group();
    $threadId = (new TaskAgentSpawner(test_t3_registry(app(HttpT3Dispatcher::class)), app(TaskReviewPacketBuilder::class), app(TaskWorkspaceMcp::class)))->spawnReviewer($group->tasks->first());

    expect($threadId)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return is_array($payload)
            && ($payload['type'] ?? null) === 'project.create'
            && ($payload['defaultModelSelection']['options'] ?? null) === [
                ['id' => 'effort', 'value' => 'high'],
            ];
    });
    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return is_array($payload)
            && ($payload['type'] ?? null) === 'thread.create'
            && ($payload['modelSelection']['options'] ?? null) === [
                ['id' => 'effort', 'value' => 'high'],
            ];
    });
    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return is_array($payload)
            && ($payload['type'] ?? null) === 'thread.turn.start'
            && ($payload['runtimeMode'] ?? null) === 'full-access'
            && ($payload['interactionMode'] ?? null) === 'default'
            && ($payload['modelSelection']['options'] ?? null) === [
                ['id' => 'effort', 'value' => 'high'],
            ];
    });
});

it('shows the base failure kind and message to the reviewer', function (): void {
    $group = t3_spawner_group();
    $task = $group->tasks->first();
    $task->update(['deliverables' => [[
        'id' => 'layout-repro',
        'type' => 'command',
        'description' => 'The layout fails before the fix',
        'command' => 'vendor/bin/pest tests/Feature/HomeScreenTest.php',
        'directory' => 'apps/gateway',
        'fails_on_base' => true,
        'paths' => ['apps/gateway/tests/Feature/HomeScreenTest.php'],
    ]]]);
    TaskCheck::query()->create([
        'task_id' => $task->id,
        'kind' => TaskCheckKind::Handoff,
        'status' => TaskCheckStatus::Passed,
        'pid' => 1,
        'process_started' => 'Wed Sep 23 12:00:00 2026',
        'head_before' => str_repeat('a', 40),
        'tree_before' => str_repeat('b', 40),
        'deliverable_evidence' => [
            'diff' => [],
            'commands' => [
                'layout-repro' => [
                    'exit_code' => 0,
                    'output' => '',
                    'base_started' => true,
                    'base_exit_code' => 2,
                    'base_output' => 'Class "HomeScreen" not found',
                ],
            ],
        ],
        'started_at' => now(),
    ]);
    $reviewer = test_agent_thread($group, 'reviewer-existing');
    $reviewer->update(['task_id' => $task->id]);
    $group->reviewer_agent_thread_id = $reviewer->id;
    $group->save();
    [$spawner, $dispatcher] = t3_spawner_stack();

    $spawner->requestReview($task->fresh());

    expect($dispatcher->commands[0]['message']['text'])->toContain('`vendor/bin/pest tests/Feature/HomeScreenTest.php` in apps/gateway exited 2 on the start commit: Class "HomeScreen" not found')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('Do not re-run the Project task check or deliverable commands the handoff already passed.')
        ->and($dispatcher->commands[0]['message']['text'])->not->toContain('Group brief');
});

it('does not ask for pull request fields when reviewing a fixup on an open pull request', function (): void {
    $group = t3_spawner_group();
    $task = $group->tasks()->firstOrFail();
    $reviewer = test_agent_thread($group, 'reviewer-existing');
    $reviewer->update(['task_id' => $task->id]);
    $group->update([
        'pr_url' => 'https://github.com/acme/orbit/pull/42',
        'reviewer_agent_thread_id' => $reviewer->id,
    ]);
    [$spawner, $dispatcher] = t3_spawner_stack();

    $spawner->requestReview($task);

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toStartWith('Review subtask #'.$task->id)
        ->and($dispatcher->commands[0]['message']['text'])->toEndWith(TaskTurnInstructions::reviewer(final: false, threadId: $reviewer->id))
        ->and($dispatcher->commands[0]['message']['text'])->not->toContain('--pr-summary');
});

it('sends the review request to the stored reviewer thread', function (): void {
    $group = t3_spawner_group();
    $task = $group->tasks->firstOrFail();
    $reviewer = test_agent_thread($group, 'reviewer-existing');
    $reviewer->update(['task_id' => $task->id]);
    $group->reviewer_agent_thread_id = $reviewer->id;
    $group->save();
    [$spawner, $dispatcher] = t3_spawner_stack();

    $spawner->requestReview($task);

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('thread.turn.start')
        ->and($dispatcher->commands[0]['threadId'])->toBe('reviewer-existing')
        ->and($dispatcher->commands[0]['message']['text'])->toStartWith('Review subtask #'.$group->tasks->first()->id)
        ->and($dispatcher->commands[0]['message']['text'])->toEndWith(TaskTurnInstructions::reviewer(final: true, threadId: $reviewer->id))
        ->and($dispatcher->commands[0]['message']['text'])->toContain('The change list, summary and breaking list are yours to write: add a missing entry yourself instead of requesting changes.')
        ->and($dispatcher->commands[0]['message']['text'])->not->toContain('are the feature\'s contract.')
        ->and($dispatcher->commands[0]['message']['role'])->toBe('user')
        ->and($dispatcher->commands[0]['modelSelection'])->toBe(T3ModelSelection::forModel(TaskAgentDefaults::ReviewerModel, TaskAgentDefaults::ReviewerEffort))
        ->and($dispatcher->commands[0]['runtimeMode'])->toBe('full-access')
        ->and($dispatcher->commands[0]['interactionMode'])->toBe('default');
});

it('names a non-main project default branch in the opening review packet', function (): void {
    $group = t3_spawner_group();
    $group->project->update(['default_branch' => 'develop']);
    [$spawner, $dispatcher] = t3_spawner_stack();

    $spawner->spawnReviewer($group->tasks->first());

    expect($dispatcher->commands[2]['message']['text'])->not->toContain('feature\'s contract');
});

it('resolves the reviewer spawner through the container with the production diff reader', function (): void {
    app()->forgetInstance(TaskReviewDiff::class);
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);
    $resolved = app(AgentSpawner::class);
    $packets = (new ReflectionProperty(TaskAgentSpawner::class, 'packets'))->getValue($resolved);
    $diffs = $packets instanceof TaskReviewPacketBuilder
        ? (new ReflectionProperty(TaskReviewPacketBuilder::class, 'diffs'))->getValue($packets)
        : null;

    expect($resolved)->toBeInstanceOf(TaskAgentSpawner::class)
        ->and($diffs)->toBeInstanceOf(RemoteTaskReviewDiff::class);
});

it('uses the container diff reader when the reviewer spawner is resolved', function (): void {
    $diff = new class implements TaskReviewDiff
    {
        public function read(Instance $instance, string $startCommit): array
        {
            return [
                'files' => [['path' => 'wired.php', 'insertions' => 3, 'deletions' => 1]],
                'diff' => '+from the bound reader',
                'files_complete' => true,
                'diff_available' => true,
                'summary' => ['files' => 1, 'insertions' => 3, 'deletions' => 1],
            ];
        }
    };
    $driver = new FakeAgentDriver('t3');
    app()->instance(TaskReviewDiff::class, $diff);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);
    $group = t3_spawner_group();

    $id = app(AgentSpawner::class)->spawnReviewer($group->tasks->first());

    expect($id)->not->toBeNull()
        ->and($driver->calls[0]['prompt'])->toContain('+from the bound reader')
        ->and($driver->calls[0]['prompt'])->toContain('wired.php')
        ->and($driver->calls[0]['prompt'])->toContain('1 file changed, 3 insertions(+), 1 deletion(-)');
});

it('does not open a review when the bound diff reader fails', function (): void {
    app()->instance(TaskReviewDiff::class, new class implements TaskReviewDiff
    {
        public function read(Instance $instance, string $startCommit): array
        {
            throw new TaskReviewDiffException('The review diff could not be read.');
        }
    });
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);
    $group = t3_spawner_group();
    $dispatcher = new class implements T3Dispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            return ['sequence' => count($this->commands), 'thread_id' => 'should-not-start'];
        }
    };
    app()->instance(AgentDriverRegistry::class, test_t3_registry($dispatcher));

    expect(fn () => app(AgentSpawner::class)->spawnReviewer($group->tasks->first()))
        ->toThrow(TaskReviewDiffException::class)
        ->and($dispatcher->commands)->toBe([]);
});

it('deletes a reserved reviewer when creating the conversation throws', function (): void {
    app()->instance(TaskReviewDiff::class, new class implements TaskReviewDiff
    {
        public function read(Instance $instance, string $startCommit): array
        {
            return [
                'files' => [],
                'diff' => '',
                'files_complete' => true,
                'diff_available' => true,
                'summary' => ['files' => 0, 'insertions' => 0, 'deletions' => 0],
            ];
        }
    });
    $driver = new FakeAgentDriver('t3');
    $driver->failNextCreate = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);
    $group = t3_spawner_group();
    $task = $group->tasks->firstOrFail();

    expect(fn () => app(AgentSpawner::class)->spawnReviewer($task))
        ->toThrow(RuntimeException::class, 'serialization failure')
        ->and(AgentThread::query()->where('task_group_id', $group->id)->count())->toBe(0);

    $id = app(AgentSpawner::class)->spawnReviewer($task->fresh() ?? $task);

    expect($id)->toBeInt()
        ->and(AgentThread::query()->where('task_group_id', $group->id)->count())->toBe(1)
        ->and(AgentThread::query()->find($id)?->external_id)->not->toStartWith(TaskAgentSpawner::PendingPrefix)
        ->and(array_column($driver->calls, 'operation'))->toBe(['create', 'create']);
});

it('does not start a replacement reviewer when the turn file cannot be written', function (): void {
    $group = t3_spawner_group();
    $task = $group->tasks->firstOrFail();
    $reviewer = test_agent_thread($group, 'reviewer-existing');
    $reviewer->update(['task_id' => $task->id]);
    $group->update(['reviewer_agent_thread_id' => $reviewer->id]);
    $driver = new FakeAgentDriver('t3');
    $driver->failNextSend = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskTurnReceipts::class, new class implements TaskTurnReceipts
    {
        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null): void
        {
            throw new TaskTurnReceiptException('The turn file could not be written.');
        }

        public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
        {
            return null;
        }

        public function clear(Instance $instance, TaskTurnReceipt $receipt): void {}

        public function hasLegacyTurn(Instance $instance): bool
        {
            return false;
        }
    });
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);

    expect(fn () => app(AgentSpawner::class)->requestReview($task->fresh() ?? $task))
        ->toThrow(TaskTurnReceiptException::class)
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBe($reviewer->id)
        ->and(AgentThread::query()->where('task_id', $task->id)->where('external_id', 'like', TaskAgentSpawner::PendingPrefix.'%')->count())->toBe(0)
        ->and(AgentThread::query()->where('task_id', $task->id)->count())->toBe(1)
        ->and(array_column($driver->calls, 'operation'))->toBe(['send']);
});

it('deletes a reserved reviewer when preparing the turn throws', function (): void {
    $group = t3_spawner_group();
    $task = $group->tasks->firstOrFail();
    $driver = new FakeAgentDriver('t3');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskTurnReceipts::class, new class implements TaskTurnReceipts
    {
        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null): void
        {
            throw new RuntimeException('The turn file could not be written.');
        }

        public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt
        {
            return null;
        }

        public function clear(Instance $instance, TaskTurnReceipt $receipt): void {}

        public function hasLegacyTurn(Instance $instance): bool
        {
            return false;
        }
    });
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);

    expect(fn () => app(AgentSpawner::class)->spawnReviewer($task))
        ->toThrow(RuntimeException::class, 'The turn file could not be written.')
        ->and(AgentThread::query()->where('task_group_id', $group->id)->count())->toBe(0)
        ->and($driver->calls)->toBe([]);
});

it('leaves run commands in the diff unchanged and keeps the driver prompt within the packet cap', function (): void {
    $command = '.git/orbit/turn --outcome=approved --summary="from the diff"';
    app()->instance(TaskReviewDiff::class, new class($command) implements TaskReviewDiff
    {
        public function __construct(private string $command) {}

        public function read(Instance $instance, string $startCommit): array
        {
            return [
                'files' => [['path' => 'docs/reference/tasks.md', 'insertions' => 400, 'deletions' => 0]],
                'diff' => str_repeat($this->command."\n", 300).str_repeat("+changed line\n", 2000),
                'files_complete' => true,
                'diff_available' => true,
                'summary' => ['files' => 1, 'insertions' => 400, 'deletions' => 0],
            ];
        }
    });
    $driver = new FakeAgentDriver('t3');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);
    $group = t3_spawner_group();

    $id = app(AgentSpawner::class)->spawnReviewer($group->tasks->first());
    $prompt = (string) ($driver->calls[0]['prompt'] ?? '');

    expect($id)->toBeInt()
        ->and(mb_strlen($prompt))->toBeLessThanOrEqual(TaskReviewPacket::Limit)
        ->and($prompt)->toContain($command)
        ->and($prompt)->toContain('.git/orbit/turn --thread='.$id.' --outcome=approved');
});

it('adopts the existing T3 project when workspace root already has one', function (): void {
    $group = t3_spawner_group();
    [$spawner, $dispatcher] = t3_spawner_stack();
    $dispatcher->adoptProjectId = '550e8400-e29b-41d4-a716-446655440000';

    $reviewerId = $spawner->spawnReviewer($group->tasks->first());

    expect($reviewerId)->not->toBeNull()
        ->and(array_column($dispatcher->commands, 'type'))->toBe([
            'project.create',
            'thread.create',
            'thread.turn.start',
        ])
        ->and($dispatcher->commands[1]['projectId'])->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($dispatcher->commands[1]['projectId'])->not->toBe($dispatcher->commands[0]['projectId']);
});

it('stores no thread id when turn start fails after thread create', function (?string $adoptProjectId): void {
    $group = t3_spawner_group();
    [$spawner, $dispatcher] = t3_spawner_stack();
    $dispatcher->adoptProjectId = $adoptProjectId;
    $dispatcher->failTurnStartRemaining = 2;

    Log::shouldReceive('error')->with('Agent conversation creation failed.', Mockery::any())->once();
    Log::shouldReceive('error')
        ->once()
        ->with('T3 thread.turn.start failed after the thread was created.', Mockery::on(function (array $context): bool {
            expect($context['thread_id'])->toBeString()->not->toBe('')
                ->and($context['exception'])->toBe('T3 turn start failed.');

            return true;
        }));

    $reviewerId = $spawner->spawnReviewer($group->tasks->first());

    expect($reviewerId)->toBeNull()
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBeNull()
        ->and(AgentThread::query()->where('task_group_id', $group->id)->count())->toBe(0)
        ->and(array_column($dispatcher->commands, 'type'))->toBe([
            'project.create',
            'thread.create',
            'thread.turn.start',
            'thread.turn.start',
        ]);

    if (is_string($adoptProjectId)) {
        expect($dispatcher->commands[1]['projectId'])->toBe($adoptProjectId)
            ->and($dispatcher->commands[1]['projectId'])->not->toBe($dispatcher->commands[0]['projectId']);
    }
})->with([
    'fresh project' => [null],
    'adopted project' => ['550e8400-e29b-41d4-a716-446655440000'],
]);

it('writes the search endpoint file before it starts a reviewer', function (): void {
    $group = t3_spawner_group();
    $mcp = new class implements TaskWorkspaceMcp
    {
        public int $missing = 0;

        public function installWhenMissing(Instance $instance): bool
        {
            $this->missing++;

            return true;
        }
    };
    [$spawner, $dispatcher] = t3_spawner_stack($mcp);

    expect($spawner->spawnReviewer($group->tasks->first()))->not->toBeNull()
        ->and($mcp->missing)->toBe(1)
        ->and($dispatcher->commands)->not->toBe([]);
});

it('does not start a reviewer when the search endpoint file cannot be written', function (): void {
    $group = t3_spawner_group();
    $mcp = new class implements TaskWorkspaceMcp
    {
        public function installWhenMissing(Instance $instance): bool
        {
            return false;
        }
    };
    [$spawner, $dispatcher] = t3_spawner_stack($mcp);

    expect($spawner->spawnReviewer($group->tasks->first()))->toBeNull()
        ->and($dispatcher->commands)->toBe([])
        ->and(AgentThread::query()->where('task_group_id', $group->id)->count())->toBe(0);
});

it('returns null when T3 refuses the spawn', function (): void {
    $group = t3_spawner_group();
    [$spawner, $dispatcher] = t3_spawner_stack();
    $dispatcher->fail = true;

    expect($spawner->spawnReviewer($group->tasks->first()))->toBeNull()
        ->and($spawner->spawnImplementer($group->tasks->first()))->toBeNull();
});

it('reuses a subtask reviewer instead of spawning again', function (): void {
    $group = t3_spawner_group();
    $task = $group->tasks->firstOrFail();
    $kept = test_agent_thread($group, 'kept-reviewer');
    $kept->update(['task_id' => $task->id]);
    $task->update(['implementer_agent_thread_id' => test_agent_thread($group, 'kept-implementer', $task)->id]);
    [$spawner, $dispatcher] = t3_spawner_stack();

    expect($spawner->spawnReviewer($task->fresh()))->toBe($kept->id)
        ->and($spawner->spawnImplementer($task->fresh(['parent.taskable'])))
        ->toBe($task->fresh()->implementer_agent_thread_id)
        ->and($dispatcher->commands)->toBe([]);
});

it('keeps persisted role links after workspace removal', function (): void {
    $group = t3_spawner_group();
    [$spawner] = t3_spawner_stack();
    $reviewer = $spawner->spawnReviewer($group->tasks->first());
    $implementer = $spawner->spawnImplementer($group->tasks->firstOrFail());
    $group->taskable->delete();
    $links = AgentThread::query()->where('task_group_id', $group->id)->orderBy('id')->get();
    expect($reviewer)->not->toBeNull()
        ->and($implementer)->not->toBeNull()
        ->and($links)->toHaveCount(2)
        ->and($links[0]->id)->toBe($reviewer)
        ->and($links[0]->task_id)->toBe($group->tasks->firstOrFail()->id)
        ->and($links[0]->model)->toBe(TaskAgentDefaults::ReviewerModel)
        ->and($links[0]->effort)->toBe(TaskAgentDefaults::ReviewerEffort)
        ->and($links[1]->id)->toBe($implementer)
        ->and($links[1]->task_id)->toBe($group->tasks->firstOrFail()->id)
        ->and($links[1]->model)->toBe(TaskAgentDefaults::ImplementerModel)
        ->and($links[1]->effort)->toBe(TaskAgentDefaults::ImplementerEffort)
        ->and($links[1]->node_id)->not->toBeNull();
});

it('imports legacy thread links using the instance morph alias', function (string $scenario): void {
    $default = DB::getDefaultConnection();
    config()->set('database.connections.agent_migration', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('agent_migration');
    try {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), static fn (string $path): bool => ! str_contains($path, 'create_agent_threads_from_task_agent_sessions')
            // The per-role split depends on the column this legacy import creates.
            && ! str_contains($path, 'split_task_group_agent_driver_by_role')
            // The token split alters agent_threads, which this legacy import creates.
            && ! str_contains($path, 'add_token_metrics_to_agent_threads')
            // The Pi resume points at agent_threads, which this legacy import creates.
            && ! str_contains($path, 'add_pi_restart_resume_to_tasks')
            // Thread archiving alters agent_threads, which this legacy import creates.
            && ! str_contains($path, 'add_thread_archiving_to_agent_threads')
            // Archive backoff alters agent_threads, which this legacy import creates.
            && ! str_contains($path, 'add_archive_backoff_to_agent_threads')
            && ! str_contains($path, 'merge_task_groups_into_tasks')));
        Artisan::call('migrate', ['--database' => 'agent_migration', '--path' => $paths, '--realpath' => true, '--force' => true]);
        $projectId = DB::table('projects')->insertGetId(['name' => 'legacy', 'slug' => 'legacy', 'code' => 'LEG', 'repository_url' => 'git@example.test:legacy.git', 'repository_identity' => 'example.test/legacy']);
        $nodeId = DB::table('nodes')->insertGetId(['name' => 'legacy-node', 'public_ssh_host' => '10.44.0.110', 'status' => 'active', 'platform' => 'linux']);
        $instanceId = DB::table('instances')->insertGetId(['project_id' => $projectId, 'node_id' => $nodeId, 'name' => 'task', 'checkout_path' => '/srv/legacy', 'status' => 'source_resolved']);
        $groupId = DB::table('task_groups')->insertGetId([
            'project_id' => $projectId, 'title' => 'Legacy', 'brief' => 'Legacy links',
            'taskable_type' => 'instance', 'taskable_id' => $instanceId,
            'reviewer_thread_id' => 'legacy-review', 'reviewer_model' => 'claude-opus-5', 'implementer_model' => 'gpt-5.6-luna',
        ]);
        $taskId = DB::table('tasks')->insertGetId(['task_group_id' => $groupId, 'position' => 1, 'title' => 'Legacy task', 'brief' => 'Legacy', 'implementer_thread_id' => 'legacy-implement']);
        if ($scenario !== 'pointers') {
            DB::table('task_agent_sessions')->insert([
                'id' => 42, 'task_group_id' => $groupId, 'node_id' => $nodeId, 'task_id' => null,
                'role' => $scenario === 'conflict' ? 'implementer' : 'reviewer', 'thread_id' => 'legacy-review',
                'model' => 'claude-opus-5', 'effort' => 'high',
            ]);
        }
        $migration = require glob(database_path('migrations/*create_agent_threads_from_task_agent_sessions.php'))[0];
        if ($scenario === 'conflict') {
            expect(fn () => run_legacy_schema_migration($migration, 'up'))->toThrow(RuntimeException::class, 'ownership is ambiguous');
            expect(Schema::hasTable('task_agent_sessions'))->toBeTrue()
                ->and(Schema::hasTable('agent_threads'))->toBeFalse();
            DB::table('task_agent_sessions')->where('id', 42)->update(['role' => 'reviewer']);
        }
        run_legacy_schema_migration($migration, 'up');
        $links = AgentThread::query()->orderBy('id')->get();
        expect($links)->toHaveCount(2)
            ->and($links[0]->node_id)->toBe($nodeId)
            ->and($links[0]->id)->toBe($scenario === 'pointers' ? 1 : 42)
            ->and($links[0]->effort)->toBe('high')
            ->and($links[1]->effort)->toBe('low')
            ->and($links[0]->model)->toBe('claude-opus-5')
            ->and($links[0]->driver)->toBe('t3')
            ->and($links[0]->runtime_key)->toBe('node:'.$nodeId)
            ->and($links[1]->task_id)->toBe($taskId)
            ->and($links[1]->external_id)->toBe('legacy-implement')
            ->and(DB::table('task_groups')->where('id', $groupId)->value('reviewer_agent_thread_id'))->toBe($links[0]->id)
            ->and(DB::table('tasks')->where('id', $taskId)->value('implementer_agent_thread_id'))->toBe($links[1]->id);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('agent_migration');
    }
})->with(['persisted', 'pointers', 'conflict']);

it('uses high effort for a reviewer follow-up when a legacy effort is absent', function (): void {
    $group = t3_spawner_group();
    [$spawner, $dispatcher] = t3_spawner_stack();
    $id = $spawner->spawnReviewer($group->tasks->first());
    $thread = AgentThread::query()->findOrFail($id);
    $thread->update(['effort' => null]);

    test_t3_registry(dispatcher: $dispatcher)->get('t3')->send($thread, 'Please review.');

    $command = $dispatcher->commands[array_key_last($dispatcher->commands)];
    expect($command['type'])->toBe('thread.turn.start')
        ->and($command['modelSelection']['options'])->toBe([['id' => 'effort', 'value' => 'high']]);
});

it('lists the deliverables for the implementer and names the review deliverables the approval must confirm', function (): void {
    $group = t3_spawner_group();
    $task = $group->tasks->first();
    $task->update(['deliverables' => [
        ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/tasks.md', 'change' => 'modified'],
        ['id' => 'export-test', 'type' => 'command', 'description' => 'Test the export', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway'],
        ['id' => 'web-tests', 'type' => 'command', 'description' => 'The web tests pass', 'command' => 'bun test', 'directory' => 'apps/web'],
        ['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask'],
    ]]);
    [$spawner, $dispatcher] = t3_spawner_stack();

    $spawner->spawnReviewer($task->refresh());
    $spawner->spawnImplementer($task);
    $review = $dispatcher->commands[2]['message']['text'];
    $implement = $dispatcher->commands[5]['message']['text'];
    $list = "Deliverables. Orbit checks each one before the review:\n"
        ."- reference-page (file: docs/reference/tasks.md, modified): Document the export\n"
        ."- export-test (command: `vendor/bin/pest tests/Feature/ExportTest.php` in apps/gateway): Test the export\n"
        ."- web-tests (command: `bun test` in apps/web): The web tests pass\n"
        .'- error-copy (review: confirmed by the reviewer): Errors name the subtask';

    expect($implement)->toContain($list)
        ->and($implement)->toContain('Add --deliverable=ID=evidence for each deliverable of this subtask (reference-page, export-test, web-tests, error-copy)')
        ->and($review)->toContain('- reference-page (file: docs/reference/tasks.md, modified): Document the export')
        ->and($review)->toContain('- export-test (command: `vendor/bin/pest tests/Feature/ExportTest.php` in apps/gateway): Test the export')
        ->and($review)->toContain('- web-tests (command: `bun test` in apps/web): The web tests pass')
        ->and($review)->toContain('- error-copy (review: confirmed by the reviewer): Errors name the subtask')
        ->and($review)->toContain('The approval must confirm each review deliverable (error-copy) with --deliverable=ID=evidence');
});
