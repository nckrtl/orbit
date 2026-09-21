<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\T3ThreadReader;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskSessionObserver;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskAgentSession;
use App\Models\TaskGroup;

function observer_group(): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'observe-app',
        'slug' => 'observe-app',
        'repository_url' => 'git@example.test:observe.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'observe-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.210',
        'wireguard_ip' => '10.44.0.210',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/srv/orbit/apps/observe-app/task-21',
        'branch' => 'task-21',
        'status' => 'source_resolved',
        'starting_commit' => str_repeat('a', 40),
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Observe idle sessions',
        'brief' => 'Route idle implementer threads.',
        'status' => TaskGroupStatus::Running,
        'reviewer_thread_id' => 'reviewer-thread',
        'pr_url' => 'https://github.com/nckrtl/orbit/pull/21',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'Models',
        'brief' => 'Store the records.',
        'status' => TaskStatus::Running,
        'implementer_thread_id' => 'implementer-thread',
    ]);
    TaskAgentSession::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $task->id,
        'node_id' => $node->id,
        'role' => 'implementer',
        'thread_id' => 'implementer-thread',
    ]);
    TaskAgentSession::query()->create([
        'task_group_id' => $group->id,
        'node_id' => $node->id,
        'role' => 'reviewer',
        'thread_id' => 'reviewer-thread',
    ]);
    TaskAgentSession::query()->create([
        'task_group_id' => $group->id,
        'node_id' => $node->id,
        'role' => 'spectator',
        'thread_id' => 'non-task-thread',
    ]);

    return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
}

/**
 * @param  array<string, array<string, mixed>>  $snapshots
 */
function observer_reader(array $snapshots): T3ThreadReader
{
    return new class($snapshots) implements T3ThreadReader
    {
        /** @param array<string, array<string, mixed>> $snapshots */
        public function __construct(private array $snapshots) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return $this->snapshots[$threadId] ?? null;
        }
    };
}

it('marks an idle implementer observation with the last turn text', function (): void {
    $group = observer_group();
    $reader = observer_reader([
        'implementer-thread' => [
            'thread' => [
                'session' => ['status' => 'idle'],
                'messages' => [
                    ['role' => 'user', 'text' => 'Implement the models.'],
                    ['role' => 'assistant', 'text' => 'I finished the models and stopped.'],
                ],
            ],
        ],
        'reviewer-thread' => [
            'thread' => [
                'session' => ['status' => 'ready'],
                'messages' => [],
            ],
        ],
        'non-task-thread' => [
            'thread' => [
                'session' => ['status' => 'idle'],
                'messages' => [['role' => 'assistant', 'text' => 'A non-task thread must never appear.']],
            ],
        ],
    ]);
    $diff = new class implements TaskWorkspaceDiffReader
    {
        public function lineChanges(AppInstance $instance, string $baseBranch): ?array
        {
            return null;
        }

        public function lineDiff(AppInstance $instance, string $baseBranch): int
        {
            return 0;
        }

        public function hasCommitsSince(AppInstance $instance, string $since): bool
        {
            return $since === str_repeat('a', 40);
        }
    };

    $observation = new TaskSessionObserver($reader, $diff)->observe($group);
    $implementer = $observation->thread(TaskThreadRole::Implementer);

    expect($observation->threads)->toHaveCount(2)
        ->and(array_map(static fn ($thread): string => $thread->threadId, $observation->threads))
        ->not->toContain('non-task-thread')
        ->and($implementer?->idle)->toBeTrue()
        ->and($implementer?->sessState)->toBe('idle')
        ->and($implementer?->lastAssistantText)->toBe('I finished the models and stopped.')
        ->and($implementer?->lastUserText)->toBe('Implement the models.')
        ->and($implementer?->pendingApprovalId)->toBeNull()
        ->and($implementer?->pendingUserInputId)->toBeNull()
        ->and($implementer?->hasNewCommitsSinceThreadStart)->toBeTrue()
        ->and($implementer?->prUrl)->toBe('https://github.com/nckrtl/orbit/pull/21');
});

it('reads a pending user-input request id from subscribeThread activities', function (): void {
    $group = observer_group();
    $reader = observer_reader([
        'implementer-thread' => [
            'thread' => [
                'sess' => ['state' => 'waiting'],
                'activities' => [
                    [
                        'kind' => 'user-input',
                        'payload' => ['requestId' => 'input-req-77'],
                    ],
                ],
            ],
        ],
        'reviewer-thread' => [
            'thread' => [
                'session' => ['status' => 'idle'],
            ],
        ],
    ]);

    $observation = new TaskSessionObserver($reader, new NullTaskWorkspaceDiffReader)->observe($group);
    $implementer = $observation->thread(TaskThreadRole::Implementer);

    expect($implementer?->idle)->toBeFalse()
        ->and($implementer?->pendingUserInputId)->toBe('input-req-77')
        ->and($implementer?->sessState)->toBe('waiting');
});
