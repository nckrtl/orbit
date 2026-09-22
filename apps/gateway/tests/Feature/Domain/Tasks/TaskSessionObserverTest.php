<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskSessionObserver;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
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
    ]);
    test_agent_thread($group, 'non-task-thread')->update(['role' => 'spectator']);

    test_link_agent_threads($group);

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
    $group->tasks->first()->update(['status' => TaskStatus::Reviewing]);
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

    $observation = new TaskSessionObserver(test_agent_observer($reader), $diff)->observe($group, $group->tasks->first());
    $implementer = $observation->thread(TaskThreadRole::Implementer);

    expect($observation->threads)->toHaveCount(2)
        ->and(array_map(static fn ($thread): int => $thread->threadId, $observation->threads))
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

    $observation = new TaskSessionObserver(test_agent_observer($reader), new NullTaskWorkspaceDiffReader)->observe($group, $group->tasks->first());
    $implementer = $observation->thread(TaskThreadRole::Implementer);

    expect($implementer?->idle)->toBeFalse()
        ->and($implementer?->pendingUserInputId)->toBe('input-req-77')
        ->and($implementer?->sessState)->toBe('asking_for_input');
});

it('defers a task with an active T3 session before inspecting context', function (string $status, string $activeThread): void {
    $group = observer_group();
    $group->tasks->first()->update(['status' => TaskStatus::Reviewing]);
    $snapshots = [
        'implementer-thread' => ['thread' => ['session' => ['status' => 'idle']]],
        'reviewer-thread' => ['thread' => ['session' => ['status' => 'idle']]],
    ];
    $snapshots[$activeThread] = [
        'thread' => [
            'session' => ['status' => $status],
            'latestTurn' => ['state' => 'completed'],
            'pendingApprovals' => [['requestId' => 'old-approval']],
            'pendingUserInputs' => [['requestId' => 'old-input']],
            'messages' => [
                ['role' => 'assistant', 'text' => 'Done. Ready for review.'],
            ],
        ],
    ];
    $diff = new class implements TaskWorkspaceDiffReader
    {
        public function hasCommitsSince(AppInstance $instance, string $since): bool
        {
            throw new LogicException('Active tasks must not inspect workspace commits.');
        }

        public function lineChanges(AppInstance $instance, string $baseBranch): ?array
        {
            return null;
        }

        public function lineDiff(AppInstance $instance, string $baseBranch): int
        {
            return 0;
        }
    };

    $observation = new TaskSessionObserver(test_agent_observer(observer_reader($snapshots)), $diff)->observe($group, $group->tasks->first());

    expect($observation->threads)->toBe([]);
})->with(['starting', 'running'])->with(['implementer-thread', 'reviewer-thread']);

it('checks sessions attached to every task even after finding an active session', function (): void {
    $group = observer_group();
    $group->tasks->first()->update(['status' => TaskStatus::Reviewing]);
    $task = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 2,
        'title' => 'Second task',
        'brief' => 'Inspect this task too.',
        'status' => TaskStatus::Running,
    ]);
    test_agent_thread($group, 'second-task-session', $task);
    $reader = new class implements T3ThreadReader
    {
        /** @var list<string> */
        public array $requested = [];

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->requested[] = $threadId;

            return ['thread' => ['session' => ['status' => 'running']]];
        }
    };

    foreach ($group->tasks()->get() as $attachedTask) {
        new TaskSessionObserver(test_agent_observer($reader), new NullTaskWorkspaceDiffReader)->observe($group, $attachedTask);
    }

    expect($reader->requested)->toBe([
        'reviewer-thread',
        'implementer-thread',
        'reviewer-thread',
        'second-task-session',
    ]);
});
