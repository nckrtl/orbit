<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskRunInstructions;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Tasks\T3\HttpT3Dispatcher;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3DispatchException;
use App\Infrastructure\Tasks\T3\T3ModelSelection;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

function t3_spawner_group(): TaskGroup
{
    $app = OrbitApp::query()->create([
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
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-1',
        'checkout_path' => '/srv/orbit/apps/orbit/task-1',
        'branch' => 'task-1',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
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

    return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
}

/**
 * @return array{TaskAgentSpawner, object, object}
 */
function t3_spawner_stack(): array
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

    return [new TaskAgentSpawner(test_t3_registry($dispatcher)), $dispatcher];
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

    expect($reviewerCreate['title'])->toStartWith('Orbit task #'.$group->id.' · Reviewer:')
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
        ->and($dispatcher->commands[2]['message']['text'])->toContain('You are the reviewer for this feature group.')
        ->and($dispatcher->commands[2]['message']['text'])->toContain('use your web and documentation tools to confirm that framework and library usage matches current documentation')
        ->and($dispatcher->commands[2]['message']['text'])->toContain('Review subtask #'.$group->tasks->first()->id)
        ->and($dispatcher->commands[2]['message']['text'])->toContain('The ADRs and documentation that this branch changes against `origin/main` are the feature\'s contract. Review each subtask against them.')
        ->and($dispatcher->commands[2]['modelSelection'])->toBe($reviewerSelection)
        ->and($dispatcher->commands[2]['runtimeMode'])->toBe('full-access')
        ->and($dispatcher->commands[2]['interactionMode'])->toBe('default')
        ->and($dispatcher->commands[5]['message']['text'])->toContain('Implement this subtask')
        ->and($dispatcher->commands[5]['message']['text'])->toContain('The ADRs and documentation that this branch changes against `origin/main` are the feature\'s contract. Build to them.')
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
    $threadId = (new TaskAgentSpawner(test_t3_registry(app(HttpT3Dispatcher::class))))->spawnReviewer($group->tasks->first());

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
        'type' => 'test',
        'description' => 'The layout fails before the fix',
        'project' => 'apps/gateway',
        'file' => 'tests/Feature/HomeScreenTest.php',
        'name' => 'home screen layout',
        'fails_on_base' => true,
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
            'tests' => [
                'layout-repro' => [
                    'exit_code' => 0,
                    'cases' => [['name' => 'it keeps the home screen layout', 'status' => 'passed']],
                    'base_placed' => true,
                    'base_exit_code' => 2,
                    'base_cases' => [[
                        'name' => 'it breaks the home screen layout',
                        'status' => 'failed',
                        'kind' => 'error',
                        'message' => 'Class "HomeScreen" not found',
                    ]],
                ],
            ],
            'commands' => [],
        ],
        'started_at' => now(),
    ]);
    $group->reviewer_agent_thread_id = test_agent_thread($group, 'reviewer-existing')->id;
    $group->save();
    [$spawner, $dispatcher] = t3_spawner_stack();

    $spawner->requestReview($task->fresh());

    expect($dispatcher->commands[0]['message']['text'])->toContain('Base run on the start commit. An error, such as a missing class, is not an assertion failure.')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('- layout-repro: "it breaks the home screen layout" failed on the start commit with an error: Class "HomeScreen" not found');
});

it('sends the review request to the stored reviewer thread', function (): void {
    $group = t3_spawner_group();
    $group->reviewer_agent_thread_id = test_agent_thread($group, 'reviewer-existing')->id;
    $group->save();
    [$spawner, $dispatcher] = t3_spawner_stack();

    $spawner->requestReview($group->tasks->first());

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('thread.turn.start')
        ->and($dispatcher->commands[0]['threadId'])->toBe('reviewer-existing')
        ->and($dispatcher->commands[0]['message']['text'])->toStartWith('Review subtask #'.$group->tasks->first()->id)
        ->and($dispatcher->commands[0]['message']['text'])->toEndWith(TaskRunInstructions::reviewer(final: true))
        ->and($dispatcher->commands[0]['message']['role'])->toBe('user')
        ->and($dispatcher->commands[0]['modelSelection'])->toBe(T3ModelSelection::forModel(TaskAgentDefaults::ReviewerModel, TaskAgentDefaults::ReviewerEffort))
        ->and($dispatcher->commands[0]['runtimeMode'])->toBe('full-access')
        ->and($dispatcher->commands[0]['interactionMode'])->toBe('default');
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

it('returns null when T3 refuses the spawn', function (): void {
    $group = t3_spawner_group();
    [$spawner, $dispatcher] = t3_spawner_stack();
    $dispatcher->fail = true;

    expect($spawner->spawnReviewer($group->tasks->first()))->toBeNull()
        ->and($spawner->spawnImplementer($group->tasks->first()))->toBeNull();
});

it('reuses persisted thread ids instead of spawning again', function (): void {
    $group = t3_spawner_group();
    $group->reviewer_agent_thread_id = test_agent_thread($group, 'kept-reviewer')->id;
    $group->save();
    $group->tasks->first()->update(['implementer_agent_thread_id' => test_agent_thread($group, 'kept-implementer', $group->tasks->firstOrFail())->id]);
    [$spawner, $dispatcher] = t3_spawner_stack();

    expect($spawner->spawnReviewer($group->tasks->first()))->toBe($group->reviewer_agent_thread_id)
        ->and($spawner->spawnImplementer($group->tasks->first()->fresh(['taskGroup.taskable']) ?? $group->tasks->first()))
        ->toBe($group->tasks->firstOrFail()->implementer_agent_thread_id)
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
        ->and($links[0]->task_id)->toBeNull()
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
            && ! str_contains($path, 'split_task_group_agent_driver_by_role')));
        Artisan::call('migrate', ['--database' => 'agent_migration', '--path' => $paths, '--realpath' => true, '--force' => true]);
        $appId = DB::table('apps')->insertGetId(['name' => 'legacy', 'slug' => 'legacy', 'code' => 'LEG', 'repository_url' => 'git@example.test:legacy.git', 'repository_identity' => 'example.test/legacy']);
        $nodeId = DB::table('nodes')->insertGetId(['name' => 'legacy-node', 'public_ssh_host' => '10.44.0.110', 'status' => 'active', 'platform' => 'linux']);
        $instanceId = DB::table('app_instances')->insertGetId(['app_id' => $appId, 'node_id' => $nodeId, 'name' => 'task', 'checkout_path' => '/srv/legacy', 'status' => 'source_resolved']);
        $groupId = DB::table('task_groups')->insertGetId([
            'app_id' => $appId, 'title' => 'Legacy', 'brief' => 'Legacy links',
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
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'ownership is ambiguous');
            expect(Schema::hasTable('task_agent_sessions'))->toBeTrue()
                ->and(Schema::hasTable('agent_threads'))->toBeFalse();
            DB::table('task_agent_sessions')->where('id', 42)->update(['role' => 'reviewer']);
        }
        $migration->up();
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

it('starts the planner as the group reviewer thread with the planning brief', function (): void {
    $group = t3_spawner_group();
    $group->update(['status' => TaskGroupStatus::Backlog, 'plan' => true]);
    [$spawner, $dispatcher] = t3_spawner_stack();

    $threadId = $spawner->spawnPlanner($group->refresh());
    $text = $dispatcher->commands[2]['message']['text'];

    expect($threadId)->not->toBeNull()
        ->and(AgentThread::query()->findOrFail($threadId)->role)->toBe('reviewer')
        ->and($dispatcher->commands[1]['title'])->toBe('Orbit task #'.$group->id.' · Planner: Wire T3')
        ->and($dispatcher->commands[1]['modelSelection'])->toBe(T3ModelSelection::forModel(TaskAgentDefaults::ReviewerModel, TaskAgentDefaults::ReviewerEffort))
        ->and($text)->toStartWith('You are the planner for this Orbit task group.')
        ->and($text)->toContain('Orbit task group #'.$group->id.' for Project orbit (app_id '.$group->app_id.')')
        ->and($text)->toContain('on the branch task-'.$group->id.' and leave them uncommitted')
        ->and($text)->toContain('tasks-subtask-create, tasks-subtask-update, and tasks-subtask-destroy')
        ->and($text)->toContain('move the group to Todo with tasks-update and status todo')
        ->and($text)->toContain('Give every subtask at least one deliverable and at most five in its deliverables list, and turn each explicit item of its brief into one.')
        ->and($text)->toContain('Split the feature with the creating-tasks skill (.agents/skills/creating-tasks/SKILL.md)')
        ->and($text)->toContain('refuses to move the group to Todo while a subtask has none');
});

it('lists the deliverables for the implementer and names the review deliverables the approval must confirm', function (): void {
    $group = t3_spawner_group();
    $task = $group->tasks->first();
    $task->update(['deliverables' => [
        ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/tasks.md', 'change' => 'modified'],
        ['id' => 'export-test', 'type' => 'test', 'description' => 'Test the export', 'project' => 'apps/gateway', 'file' => 'tests/Feature/ExportTest.php', 'name' => 'exports every subtask'],
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
        ."- export-test (test: Pest test \"exports every subtask\" in apps/gateway/tests/Feature/ExportTest.php): Test the export\n"
        ."- web-tests (command: `bun test` in apps/web): The web tests pass\n"
        .'- error-copy (review: confirmed by the reviewer): Errors name the subtask';

    expect($implement)->toContain($list)
        ->and($implement)->toContain('Add --deliverable=ID=evidence for each deliverable of this subtask (reference-page, export-test, web-tests, error-copy)')
        ->and($review)->toContain($list)
        ->and($review)->toContain('The approval must confirm each review deliverable (error-copy) with --deliverable=ID=evidence');
});

it('tells a planner thread it has become the reviewer with the first review request only', function (): void {
    $group = t3_spawner_group();
    $group->update(['plan' => true, 'reviewer_agent_thread_id' => test_agent_thread($group, 'planner-thread')->id]);
    [$spawner, $dispatcher] = t3_spawner_stack();
    $task = $group->refresh()->tasks->first();

    $spawner->requestReview($task);
    $task->update(['review_attempt' => 1, 'review_notified_attempt' => 1]);
    $spawner->requestReview($task->refresh());

    expect($dispatcher->commands[0]['threadId'])->toBe('planner-thread')
        ->and($dispatcher->commands[0]['message']['text'])->toStartWith('The plan is in Todo and Orbit has started the implementers. From now on you are the reviewer of this group, not its planner.')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('You are the reviewer for this feature group.')
        ->and($dispatcher->commands[0]['message']['text'])->toEndWith(TaskRunInstructions::reviewer(final: true))
        ->and($dispatcher->commands[1]['message']['text'])->toStartWith('Review subtask #'.$task->id);
});
