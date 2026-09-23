<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Infrastructure\Tasks\T3\HttpT3Dispatcher;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3DispatchException;
use App\Infrastructure\Tasks\T3\T3ModelSelection;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
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
    $signer = new class implements TaskWorkspaceSigner
    {
        public int $commits = 0;

        public function commit(AppInstance $instance, string $message): ?string
        {
            $this->commits++;
            expect($message)->toStartWith('Reviewer sign-off:')
                ->and($instance->checkout_path)->toBe('/srv/orbit/apps/orbit/task-1');

            return str_repeat('b', 40);
        }
    };

    return [new TaskAgentSpawner(test_t3_registry($dispatcher), $signer), $dispatcher, $signer];
}

it('spawns a long-lived reviewer and a fresh implementer on the instance Node', function (): void {
    $group = t3_spawner_group();
    [$spawner, $dispatcher] = t3_spawner_stack();

    $reviewerId = $spawner->spawnReviewer($group);
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
        ->and($dispatcher->commands[2]['message']['text'])->toContain('long-lived reviewer')
        ->and($dispatcher->commands[2]['modelSelection'])->toBe($reviewerSelection)
        ->and($dispatcher->commands[2]['runtimeMode'])->toBe('full-access')
        ->and($dispatcher->commands[2]['interactionMode'])->toBe('default')
        ->and($dispatcher->commands[5]['message']['text'])->toContain('Implement this subtask')
        ->and($dispatcher->commands[5]['modelSelection'])->toBe($implementerSelection)
        ->and($dispatcher->commands[5]['runtimeMode'])->toBe('full-access')
        ->and($dispatcher->commands[5]['interactionMode'])->toBe('default')
        ->and($implementerSelection['instanceId'])->toBe('codex')
        ->and($implementerSelection['options'])->toBe([['id' => 'reasoningEffort', 'value' => 'low']]);
});

it('posts T3 model options as id and value JSON objects', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.110:3773/api/orchestration/dispatch' => Http::response(['sequence' => 1]),
    ]);
    $group = t3_spawner_group();
    [, , $signer] = t3_spawner_stack();

    $threadId = (new TaskAgentSpawner(test_t3_registry(app(HttpT3Dispatcher::class)), $signer))->spawnReviewer($group);

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

it('sends please review to the stored reviewer thread and commits on sign-off', function (): void {
    $group = t3_spawner_group();
    $group->reviewer_agent_thread_id = test_agent_thread($group, 'reviewer-existing')->id;
    $group->save();
    [$spawner, $dispatcher, $signer] = t3_spawner_stack();

    $spawner->requestReview($group->tasks->first());
    $sha = $spawner->signOff($group->tasks->first());

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('thread.turn.start')
        ->and($dispatcher->commands[0]['threadId'])->toBe('reviewer-existing')
        ->and($dispatcher->commands[0]['message']['text'])->toStartWith('please review')
        ->and($dispatcher->commands[0]['message']['role'])->toBe('user')
        ->and($dispatcher->commands[0]['modelSelection'])->toBe(T3ModelSelection::forModel(TaskAgentDefaults::ReviewerModel, TaskAgentDefaults::ReviewerEffort))
        ->and($dispatcher->commands[0]['runtimeMode'])->toBe('full-access')
        ->and($dispatcher->commands[0]['interactionMode'])->toBe('default')
        ->and($sha)->toBe(str_repeat('b', 40))
        ->and($signer->commits)->toBe(1);
});

it('adopts the existing T3 project when workspace root already has one', function (): void {
    $group = t3_spawner_group();
    [$spawner, $dispatcher] = t3_spawner_stack();
    $dispatcher->adoptProjectId = '550e8400-e29b-41d4-a716-446655440000';

    $reviewerId = $spawner->spawnReviewer($group);

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

    $reviewerId = $spawner->spawnReviewer($group);

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

    expect($spawner->spawnReviewer($group))->toBeNull()
        ->and($spawner->spawnImplementer($group->tasks->first()))->toBeNull();
});

it('reuses persisted thread ids instead of spawning again', function (): void {
    $group = t3_spawner_group();
    $group->reviewer_agent_thread_id = test_agent_thread($group, 'kept-reviewer')->id;
    $group->save();
    $group->tasks->first()->update(['implementer_agent_thread_id' => test_agent_thread($group, 'kept-implementer', $group->tasks->firstOrFail())->id]);
    [$spawner, $dispatcher] = t3_spawner_stack();

    expect($spawner->spawnReviewer($group->fresh(['taskable']) ?? $group))->toBe($group->reviewer_agent_thread_id)
        ->and($spawner->spawnImplementer($group->tasks->first()->fresh(['taskGroup.taskable']) ?? $group->tasks->first()))
        ->toBe($group->tasks->firstOrFail()->implementer_agent_thread_id)
        ->and($dispatcher->commands)->toBe([]);
});

it('keeps persisted role links after workspace removal', function (): void {
    $group = t3_spawner_group();
    [$spawner] = t3_spawner_stack();
    $reviewer = $spawner->spawnReviewer($group);
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
    $id = $spawner->spawnReviewer($group);
    $thread = AgentThread::query()->findOrFail($id);
    $thread->update(['effort' => null]);

    test_t3_registry(dispatcher: $dispatcher)->get('t3')->send($thread, 'Please review.');

    $command = $dispatcher->commands[array_key_last($dispatcher->commands)];
    expect($command['type'])->toBe('thread.turn.start')
        ->and($command['modelSelection']['options'])->toBe([['id' => 'effort', 'value' => 'high']]);
});
