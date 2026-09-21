<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\T3Dispatcher;
use App\Domain\Tasks\T3DispatchException;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Infrastructure\Tasks\HttpT3Dispatcher;
use App\Infrastructure\Tasks\T3AgentSpawner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskAgentSession;
use App\Models\TaskGroup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
 * @return array{T3AgentSpawner, object, object}
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

    return [new T3AgentSpawner($dispatcher, $signer), $dispatcher, $signer];
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
    $reviewerSelection = [
        'instanceId' => TaskAgentDefaults::ReviewerModel,
        'model' => TaskAgentDefaults::ReviewerModel,
        'options' => [['id' => 'effort', 'value' => TaskAgentDefaults::ReviewerEffort]],
    ];
    $implementerSelection = [
        'instanceId' => TaskAgentDefaults::ImplementerModel,
        'model' => TaskAgentDefaults::ImplementerModel,
        'options' => [['id' => 'effort', 'value' => TaskAgentDefaults::ImplementerEffort]],
    ];

    expect($reviewerCreate['title'])->toStartWith('Orbit task #'.$group->id.' · Reviewer:')
        ->and($reviewerProject['defaultModelSelection'])->toBe($reviewerSelection)
        ->and($reviewerCreate['modelSelection'])->toBe($reviewerSelection)
        ->and($implementerProject['defaultModelSelection'])->toBe($implementerSelection)
        ->and($implementerCreate['modelSelection'])->toBe($implementerSelection)
        ->and($reviewerCreate['worktreePath'])->toBe('/srv/orbit/apps/orbit/task-1')
        ->and($reviewerCreate['branch'])->toBe('task-1')
        ->and($dispatcher->commands[2]['message'])->toContain('long-lived reviewer')
        ->and($dispatcher->commands[5]['message'])->toContain('Implement this subtask');
});

it('posts T3 model options as id and value JSON objects', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.110:3773/api/orchestration/dispatch' => Http::response(['sequence' => 1]),
    ]);
    $group = t3_spawner_group();
    [, , $signer] = t3_spawner_stack();

    $threadId = (new T3AgentSpawner(app(HttpT3Dispatcher::class), $signer))->spawnReviewer($group);

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
});

it('sends please review to the stored reviewer thread and commits on sign-off', function (): void {
    $group = t3_spawner_group();
    $group->reviewer_thread_id = 'reviewer-existing';
    $group->save();
    [$spawner, $dispatcher, $signer] = t3_spawner_stack();

    $spawner->requestReview($group->tasks->first());
    $sha = $spawner->signOff($group->tasks->first());

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('thread.turn.start')
        ->and($dispatcher->commands[0]['threadId'])->toBe('reviewer-existing')
        ->and($dispatcher->commands[0]['message'])->toStartWith('please review')
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

it('returns the created thread id when turn start fails after thread create', function (?string $adoptProjectId): void {
    $group = t3_spawner_group();
    [$spawner, $dispatcher] = t3_spawner_stack();
    $dispatcher->adoptProjectId = $adoptProjectId;
    $dispatcher->failTurnStartRemaining = 2;

    Log::shouldReceive('warning')
        ->once()
        ->with('T3 thread.turn.start failed after the thread was created.', Mockery::on(function (array $context): bool {
            expect($context['thread_id'])->toBeString()->not->toBe('')
                ->and($context['exception'])->toBe('T3 turn start failed.')
                ->and($context['http_status'])->toBeNull()
                ->and($context['http_body'])->toBeNull();

            return true;
        }));

    $reviewerId = $spawner->spawnReviewer($group);

    expect($reviewerId)->not->toBeNull()
        ->and($reviewerId)->not->toBe('')
        ->and(array_column($dispatcher->commands, 'type'))->toBe([
            'project.create',
            'thread.create',
            'thread.turn.start',
            'thread.turn.start',
        ])
        ->and($dispatcher->commands[2]['threadId'])->toBe($reviewerId)
        ->and($dispatcher->commands[3]['threadId'])->toBe($reviewerId);

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
    $group->reviewer_thread_id = 'kept-reviewer';
    $group->save();
    $group->tasks->first()->update(['implementer_thread_id' => 'kept-implementer']);
    [$spawner, $dispatcher] = t3_spawner_stack();

    expect($spawner->spawnReviewer($group->fresh(['taskable']) ?? $group))->toBe('kept-reviewer')
        ->and($spawner->spawnImplementer($group->tasks->first()->fresh(['taskGroup.taskable']) ?? $group->tasks->first()))
        ->toBe('kept-implementer')
        ->and($dispatcher->commands)->toBe([]);
});

it('persists both role links before a refused opening turn and keeps them after workspace removal', function (): void {
    $group = t3_spawner_group();
    [$spawner, $dispatcher] = t3_spawner_stack();
    $dispatcher->failTurnStartRemaining = 4;
    $reviewer = $spawner->spawnReviewer($group);
    $implementer = $spawner->spawnImplementer($group->tasks->firstOrFail());
    $group->taskable->delete();
    $links = TaskAgentSession::query()->where('task_group_id', $group->id)->orderBy('id')->get();
    expect($links)->toHaveCount(2)
        ->and($links[0]->thread_id)->toBe($reviewer)
        ->and($links[0]->task_id)->toBeNull()
        ->and($links[1]->thread_id)->toBe($implementer)
        ->and($links[1]->task_id)->toBe($group->tasks->firstOrFail()->id)
        ->and($links[1]->node_id)->not->toBeNull();
});

it('imports legacy thread links using the instance morph alias', function (): void {
    $group = t3_spawner_group();
    $group->update(['reviewer_thread_id' => 'legacy-review']);
    $task = $group->tasks->firstOrFail();
    $task->update(['implementer_thread_id' => 'legacy-implement']);
    $migration = require database_path('migrations/2026_09_21_124805_create_task_agent_sessions_table.php');
    $migration->down();
    $migration->up();
    $links = TaskAgentSession::query()->orderBy('id')->get();
    expect($links)->toHaveCount(2)
        ->and($links[0]->node_id)->toBe($group->taskable->node_id)
        ->and($links[1]->task_id)->toBe($task->id)
        ->and($links[1]->thread_id)->toBe('legacy-implement');
});
