<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
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
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\FakeAgentDriver;

beforeEach(function (): void {
    test_bind_snapshot_driver();
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
});

function task_spawner_group(): Task
{
    $project = Project::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@example.test:orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'agent-node',
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
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Wire an agent',
        'brief' => 'Spawn reviewer and implementer.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Models',
        'brief' => 'Store the records.',
        'status' => TaskStatus::Running,
    ]);

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

/** @return array{TaskAgentSpawner, AgentCommandDispatcher} */
function task_spawner_stack(?TaskWorkspaceMcp $mcp = null): array
{
    $dispatcher = new class implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public bool $fail = false;

        public function dispatch(Node $node, array $command): array
        {
            expect($node->wireguard_ip)->toBe('10.44.0.110');
            if ($this->fail) {
                throw new AgentDriverException('Agent refused the turn.');
            }
            $this->commands[] = $command;

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };

    return [new TaskAgentSpawner(test_snapshot_registry($dispatcher), app(TaskReviewPacketBuilder::class), $mcp ?? app(TaskWorkspaceMcp::class)), $dispatcher];
}

it('spawns fresh role threads on the workspace Node with the configured model and opening prompt', function (): void {
    $group = task_spawner_group();
    [$spawner, $dispatcher] = task_spawner_stack();
    $task = $group->tasks->sole();
    $reviewerId = $spawner->spawnReviewer($task);
    $implementerId = $spawner->spawnImplementer($task);

    expect($reviewerId)->toBeInt()->and($implementerId)->toBeInt()->not->toBe($reviewerId)
        ->and(array_column($dispatcher->commands, 'type'))->toBe(['create', 'create']);
    $reviewer = AgentThread::query()->findOrFail($reviewerId);
    $implementer = AgentThread::query()->findOrFail($implementerId);
    expect($reviewer->driver)->toBe('pi')->and($implementer->driver)->toBe('pi')
        ->and($reviewer->node_id)->toBe($group->taskable->node_id)
        ->and($implementer->node_id)->toBe($reviewer->node_id)
        ->and($reviewer->role)->toBe('reviewer')->and($implementer->role)->toBe('implementer');
    expect($dispatcher->commands[0])->toMatchArray([
        'title' => 'Orbit task #'.$group->id.' · Review: '.$task->title,
        'model' => TaskAgentDefaults::ReviewerModel, 'effort' => TaskAgentDefaults::ReviewerEffort,
        'worktreePath' => '/srv/orbit/apps/orbit/task-1', 'branch' => 'task-1',
    ])->and($dispatcher->commands[0]['message']['text'])->toContain('Review subtask #'.$task->id, $group->brief, 'Do not re-run the Project task check')
        ->and($dispatcher->commands[1]['model'])->toBe(TaskAgentDefaults::ImplementerModel)
        ->and($dispatcher->commands[1]['effort'])->toBe(TaskAgentDefaults::ImplementerEffort)
        ->and($dispatcher->commands[1]['message']['text'])->toContain('Implement this subtask', 'The group started at '.str_repeat('b', 40), 'Follow this repository\'s task instructions.');
});

it('shows the base failure kind and message to the reviewer', function (): void {
    $group = task_spawner_group();
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
    [$spawner, $dispatcher] = task_spawner_stack();

    $spawner->requestReview($task->fresh());

    expect($dispatcher->commands[0]['message']['text'])->toContain('`vendor/bin/pest tests/Feature/HomeScreenTest.php` in apps/gateway exited 2 on the start commit: Class "HomeScreen" not found')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('Do not re-run the Project task check or deliverable commands the handoff already passed.')
        ->and($dispatcher->commands[0]['message']['text'])->not->toContain('Group brief');
});

it('does not ask for pull request fields when reviewing a fixup on an open pull request', function (): void {
    $group = task_spawner_group();
    $task = $group->tasks()->firstOrFail();
    $reviewer = test_agent_thread($group, 'reviewer-existing');
    $reviewer->update(['task_id' => $task->id]);
    $group->update([
        'pr_url' => 'https://github.com/acme/orbit/pull/42',
        'reviewer_agent_thread_id' => $reviewer->id,
    ]);
    [$spawner, $dispatcher] = task_spawner_stack();

    $spawner->requestReview($task);

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toStartWith('Review subtask #'.$task->id)
        ->and($dispatcher->commands[0]['message']['text'])->toEndWith(TaskTurnInstructions::reviewer(final: false, threadId: $reviewer->id))
        ->and($dispatcher->commands[0]['message']['text'])->not->toContain('--pr-summary');
});

it('sends the review request to the stored reviewer thread', function (): void {
    $group = task_spawner_group();
    $task = $group->tasks->firstOrFail();
    $reviewer = test_agent_thread($group, 'reviewer-existing');
    $reviewer->update(['task_id' => $task->id]);
    $group->reviewer_agent_thread_id = $reviewer->id;
    $group->save();
    [$spawner, $dispatcher] = task_spawner_stack();

    $spawner->requestReview($task);

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('send')
        ->and($dispatcher->commands[0]['threadId'])->toBe('reviewer-existing')
        ->and($dispatcher->commands[0]['message']['text'])->toStartWith('Review subtask #'.$group->tasks->first()->id)
        ->and($dispatcher->commands[0]['message']['text'])->toEndWith(TaskTurnInstructions::reviewer(final: true, threadId: $reviewer->id))
        ->and($dispatcher->commands[0]['message']['text'])->toContain('The change list, summary and breaking list are yours to write: add a missing entry yourself instead of requesting changes.')
        ->and($dispatcher->commands[0]['message']['text'])->not->toContain('are the feature\'s contract.')
        ->and($dispatcher->commands[0]['model'])->toBe(TaskAgentDefaults::ReviewerModel)
        ->and($dispatcher->commands[0]['effort'])->toBe(TaskAgentDefaults::ReviewerEffort);
});

it('names a non-main project default branch in the opening review packet', function (): void {
    $group = task_spawner_group();
    $group->project->update(['default_branch' => 'develop']);
    [$spawner, $dispatcher] = task_spawner_stack();

    $spawner->spawnReviewer($group->tasks->first());

    expect($dispatcher->commands[0]['message']['text'])->not->toContain('feature\'s contract');
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
    $driver = new FakeAgentDriver('pi');
    app()->instance(TaskReviewDiff::class, $diff);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);
    $group = task_spawner_group();

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
    $group = task_spawner_group();
    $dispatcher = new class implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            return ['sequence' => count($this->commands), 'thread_id' => 'should-not-start'];
        }
    };
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry($dispatcher));

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
    $driver = new FakeAgentDriver('pi');
    $driver->failNextCreate = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);
    $group = task_spawner_group();
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
    $group = task_spawner_group();
    $task = $group->tasks->firstOrFail();
    $reviewer = test_agent_thread($group, 'reviewer-existing');
    $reviewer->update(['task_id' => $task->id]);
    $group->update(['reviewer_agent_thread_id' => $reviewer->id]);
    $driver = new FakeAgentDriver('pi');
    $driver->failNextSend = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskTurnReceipts::class, new class implements TaskTurnReceipts
    {
        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?string $context = null): void
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
    $group = task_spawner_group();
    $task = $group->tasks->firstOrFail();
    $driver = new FakeAgentDriver('pi');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskTurnReceipts::class, new class implements TaskTurnReceipts
    {
        public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?string $context = null): void
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
    $driver = new FakeAgentDriver('pi');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->forgetInstance(AgentSpawner::class);
    app()->forgetInstance(TaskReviewPacketBuilder::class);
    $group = task_spawner_group();

    $id = app(AgentSpawner::class)->spawnReviewer($group->tasks->first());
    $prompt = (string) ($driver->calls[0]['prompt'] ?? '');

    expect($id)->toBeInt()
        ->and(mb_strlen($prompt))->toBeLessThanOrEqual(TaskReviewPacket::Limit)
        ->and($prompt)->toContain($command)
        ->and($prompt)->toContain('.git/orbit/turn --thread='.$id.' --outcome=approved');
});

it('writes the search endpoint file before it starts a reviewer', function (): void {
    $group = task_spawner_group();
    $mcp = new class implements TaskWorkspaceMcp
    {
        public int $missing = 0;

        public function installWhenMissing(Instance $instance): bool
        {
            $this->missing++;

            return true;
        }
    };
    [$spawner, $dispatcher] = task_spawner_stack($mcp);

    expect($spawner->spawnReviewer($group->tasks->first()))->not->toBeNull()
        ->and($mcp->missing)->toBe(1)
        ->and($dispatcher->commands)->not->toBe([]);
});

it('does not start a reviewer when the search endpoint file cannot be written', function (): void {
    $group = task_spawner_group();
    $mcp = new class implements TaskWorkspaceMcp
    {
        public function installWhenMissing(Instance $instance): bool
        {
            return false;
        }
    };
    [$spawner, $dispatcher] = task_spawner_stack($mcp);

    expect($spawner->spawnReviewer($group->tasks->first()))->toBeNull()
        ->and($dispatcher->commands)->toBe([])
        ->and(AgentThread::query()->where('task_group_id', $group->id)->count())->toBe(0);
});

it('returns null when the driver refuses the spawn', function (): void {
    $group = task_spawner_group();
    [$spawner, $dispatcher] = task_spawner_stack();
    $dispatcher->fail = true;

    expect($spawner->spawnReviewer($group->tasks->first()))->toBeNull()
        ->and($spawner->spawnImplementer($group->tasks->first()))->toBeNull();
});

it('reuses a subtask reviewer instead of spawning again', function (): void {
    $group = task_spawner_group();
    $task = $group->tasks->firstOrFail();
    $kept = test_agent_thread($group, 'kept-reviewer');
    $kept->update(['task_id' => $task->id]);
    $task->update(['implementer_agent_thread_id' => test_agent_thread($group, 'kept-implementer', $task)->id]);
    [$spawner, $dispatcher] = task_spawner_stack();

    expect($spawner->spawnReviewer($task->fresh()))->toBe($kept->id)
        ->and($spawner->spawnImplementer($task->fresh(['parent.taskable'])))
        ->toBe($task->fresh()->implementer_agent_thread_id)
        ->and($dispatcher->commands)->toBe([]);
});

it('keeps persisted role links after workspace removal', function (): void {
    $group = task_spawner_group();
    [$spawner] = task_spawner_stack();
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

it('lists the deliverables for the implementer and names the review deliverables the approval must confirm', function (): void {
    $group = task_spawner_group();
    $task = $group->tasks->first();
    $task->update(['deliverables' => [
        ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/tasks.md', 'change' => 'modified'],
        ['id' => 'export-test', 'type' => 'command', 'description' => 'Test the export', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway'],
        ['id' => 'web-tests', 'type' => 'command', 'description' => 'The web tests pass', 'command' => 'bun test', 'directory' => 'apps/web'],
        ['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask'],
    ]]);
    [$spawner, $dispatcher] = task_spawner_stack();

    $spawner->spawnReviewer($task->refresh());
    $spawner->spawnImplementer($task);
    $review = $dispatcher->commands[0]['message']['text'];
    $implement = $dispatcher->commands[1]['message']['text'];
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
