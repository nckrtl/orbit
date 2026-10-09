<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskSessionObserver;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Tests\Support\AgentSnapshotReader;

function observer_group(): Task
{
    $project = Project::query()->create([
        'name' => 'observe-app',
        'slug' => 'observe-app',
        'repository_url' => 'git@example.test:observe.git',
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => 'observe-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.210',
        'wireguard_ip' => '10.44.0.210',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/srv/orbit/apps/observe-app/task-21',
        'branch' => 'task-21',
        'status' => 'source_resolved',
        'starting_commit' => str_repeat('a', 40),
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Observe idle sessions',
        'brief' => 'Route idle implementer threads.',
        'status' => TaskGroupStatus::Running,
        'pr_url' => 'https://github.com/nckrtl/orbit/pull/21',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Models',
        'brief' => 'Store the records.',
        'status' => TaskStatus::Running,
    ]);
    test_agent_thread($group, 'non-task-thread')->update(['role' => 'spectator']);

    test_link_agent_threads($group);

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

/**
 * @param  array<string, array<string, mixed>>  $snapshots
 */
function observer_reader(array $snapshots): AgentSnapshotReader
{
    return new class($snapshots) implements AgentSnapshotReader
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
        public function lineChanges(Instance $instance, string $baseBranch): ?array
        {
            return null;
        }

        public function lineDiff(Instance $instance, string $baseBranch): int
        {
            return 0;
        }

        public function hasCommitsSince(Instance $instance, string $since): bool
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

it('reads a pending user-input request id from the agent observation', function (): void {
    $group = observer_group();
    $reader = observer_reader([
        'implementer-thread' => [
            'thread' => [
                'session' => ['status' => 'waiting'],
                'pendingUserInputs' => [['requestId' => 'input-req-77']],
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

it('defers a task while the thread that acts in its phase is active, before inspecting context', function (string $status, TaskStatus $taskStatus, string $activeThread): void {
    $group = observer_group();
    $group->tasks->first()->update(['status' => $taskStatus]);
    if ($activeThread === 'reviewer-thread') {
        AgentThread::query()->where('external_id', 'reviewer-thread')->where('task_group_id', $group->id)->update(['task_id' => $group->tasks->first()->id]);
    }
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
        public function hasCommitsSince(Instance $instance, string $since): bool
        {
            throw new LogicException('Active tasks must not inspect workspace commits.');
        }

        public function lineChanges(Instance $instance, string $baseBranch): ?array
        {
            return null;
        }

        public function lineDiff(Instance $instance, string $baseBranch): int
        {
            return 0;
        }
    };

    $observation = new TaskSessionObserver(test_agent_observer(observer_reader($snapshots)), $diff)->observe($group, $group->tasks->first());

    expect($observation->threads)->toBe([]);
})->with(['starting', 'running'])->with([
    'a running task and its implementer' => [TaskStatus::Running, 'implementer-thread'],
    'a task in review and the reviewer' => [TaskStatus::Reviewing, 'reviewer-thread'],
]);

it('observes the acting thread while the other thread works', function (TaskStatus $taskStatus, TaskThreadRole $acting, TaskThreadRole $working): void {
    $group = observer_group();
    $group->tasks->first()->update(['status' => $taskStatus]);
    $reader = observer_reader([
        $acting->value.'-thread' => ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'turn-1', 'state' => 'completed']]],
        $working->value.'-thread' => ['thread' => ['session' => ['status' => 'running']]],
    ]);

    $observation = new TaskSessionObserver(test_agent_observer($reader), new NullTaskWorkspaceDiffReader)->observe($group, $group->tasks->first());

    expect($observation->threads)->toHaveCount(2)
        ->and($observation->thread($acting)?->sessState)->toBe('done')
        ->and($observation->thread($acting)?->turnId)->toBe('turn-1')
        ->and($observation->thread($working)?->sessState)->toBe('working');
})->with([
    'a running task while the shared reviewer works' => [TaskStatus::Running, TaskThreadRole::Implementer, TaskThreadRole::Reviewer],
    'a task in review while its implementer works' => [TaskStatus::Reviewing, TaskThreadRole::Reviewer, TaskThreadRole::Implementer],
]);

it('checks sessions attached to every task even after finding an active session', function (): void {
    $group = observer_group();
    $group->tasks->first()->update(['status' => TaskStatus::Reviewing]);
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 2,
        'title' => 'Second task',
        'brief' => 'Inspect this task too.',
        'status' => TaskStatus::Running,
    ]);
    test_agent_thread($group, 'second-task-session', $task);
    $reader = new class implements AgentSnapshotReader
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
