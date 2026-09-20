<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\T3Dispatcher;
use App\Domain\Tasks\T3DispatchException;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Infrastructure\Tasks\T3AgentSpawner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;

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

        public function dispatch(Node $node, array $command): array
        {
            expect($command)->not->toHaveKey('command')
                ->and($node->wireguard_ip)->toBe('10.44.0.110');

            if ($this->fail) {
                throw new T3DispatchException;
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

    $reviewerCreate = $dispatcher->commands[1];
    $implementerCreate = $dispatcher->commands[4];

    expect($reviewerCreate['title'])->toStartWith('Reviewer:')
        ->and($reviewerCreate['modelSelection'])->toBe([
            'instanceId' => TaskAgentDefaults::ReviewerModel,
            'model' => TaskAgentDefaults::ReviewerModel,
            'options' => [['effort' => TaskAgentDefaults::ReviewerEffort]],
        ])
        ->and($reviewerCreate['worktreePath'])->toBe('/srv/orbit/apps/orbit/task-1')
        ->and($reviewerCreate['branch'])->toBe('task-1')
        ->and($implementerCreate['modelSelection']['model'])->toBe(TaskAgentDefaults::ImplementerModel)
        ->and($dispatcher->commands[2]['message'])->toContain('long-lived reviewer')
        ->and($dispatcher->commands[5]['message'])->toContain('Implement this subtask');
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
