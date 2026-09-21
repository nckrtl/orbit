<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\NullTaskWorkspaceCommitReader;
use App\Domain\Tasks\T3ThreadReader;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSessionObserver;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskWorkspaceCommitReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskAgentSession;
use App\Models\TaskGroup;
use Carbon\CarbonImmutable;

function observed_group(): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'observed',
        'slug' => 'observed',
        'repository_url' => 'git@github.com:nckrtl/observed.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'observed-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.7',
        'wireguard_ip' => '10.44.0.7',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/fast/apps/observed/task-21',
        'branch' => 'task-21',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Route task sessions',
        'brief' => 'Observe every task thread.',
        'status' => TaskGroupStatus::Running,
        'reviewer_thread_id' => 'reviewer-thread',
        'started_at' => '2026-09-21 09:00:00',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $running = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'Observe thread state',
        'brief' => 'Build the observation.',
        'status' => TaskStatus::Running,
        'implementer_thread_id' => 'implementer-thread',
        'started_at' => '2026-09-21 11:00:00',
    ]);
    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 2,
        'title' => 'Wire the classifier',
        'brief' => 'Later subtask.',
        'status' => TaskStatus::Pending,
    ]);
    TaskAgentSession::query()->create([
        'task_group_id' => $group->id,
        'task_id' => null,
        'node_id' => $node->id,
        'role' => 'reviewer',
        'thread_id' => 'reviewer-thread',
    ]);
    TaskAgentSession::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $running->id,
        'node_id' => $node->id,
        'role' => 'implementer',
        'thread_id' => 'implementer-thread',
    ]);

    return $group->fresh(['tasks', 'taskable']) ?? $group;
}

/**
 * @param  array<string, array<string, mixed>|null>  $snapshots
 */
function observed_threads(array $snapshots): T3ThreadReader
{
    return new class($snapshots) implements T3ThreadReader
    {
        /** @var list<string> */
        public array $read = [];

        /** @param array<string, array<string, mixed>|null> $snapshots */
        public function __construct(private array $snapshots) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->read[] = $threadId;

            return $this->snapshots[$threadId] ?? null;
        }
    };
}

/**
 * @param  list<string>|null  $times
 */
function observed_commits(?array $times): TaskWorkspaceCommitReader
{
    return new class($times) implements TaskWorkspaceCommitReader
    {
        /** @param list<string>|null $times */
        public function __construct(private ?array $times) {}

        public function commitTimes(AppInstance $instance): ?array
        {
            return $this->times === null
                ? null
                : array_map(static fn (string $time): CarbonImmutable => CarbonImmutable::parse($time), $this->times);
        }
    };
}

/**
 * @param  array<string, array<string, mixed>|null>  $snapshots
 * @param  list<string>|null  $commits
 */
function observed_observer(array $snapshots, ?array $commits = null): TaskSessionObserver
{
    return new TaskSessionObserver(observed_threads($snapshots), observed_commits($commits));
}

it('reports an idle implementer with its last turn text', function (): void {
    $group = observed_group();

    $observation = observed_observer([
        'implementer-thread' => [
            'thread' => [
                'session' => ['status' => 'ready'],
                'latestTurn' => ['state' => 'completed'],
                'messages' => [
                    ['role' => 'user', 'text' => 'Implement this subtask.'],
                    ['role' => 'assistant', 'text' => 'Done. Tests pass.'],
                ],
            ],
        ],
    ])->observe($group);

    $thread = $observation->thread('implementer-thread');

    expect($thread?->idle)->toBeTrue()
        ->and($thread?->role)->toBe(TaskThreadRole::Implementer)
        ->and($thread?->taskId)->toBe($group->tasks->first()?->id)
        ->and($thread?->sessionState)->toBe('ready')
        ->and($thread?->lastAssistantText)->toBe('Done. Tests pass.')
        ->and($thread?->lastUserText)->toBe('Implement this subtask.')
        ->and($observation->currentTaskId)->toBe($group->tasks->first()?->id)
        ->and($observation->groupStatus)->toBe(TaskGroupStatus::Running);
});

it('does not call a thread idle while its turn runs', function (): void {
    $observation = observed_observer([
        'implementer-thread' => [
            'thread' => [
                'session' => ['status' => 'ready'],
                'latestTurnId' => 'turn-2',
                'turns' => [
                    ['turnId' => 'turn-1', 'state' => 'completed'],
                    ['turnId' => 'turn-2', 'state' => 'running'],
                ],
            ],
        ],
    ])->observe(observed_group());

    expect($observation->thread('implementer-thread')?->idle)->toBeFalse();
});

it('reports the request id of a pending user-input question', function (): void {
    $observation = observed_observer([
        'implementer-thread' => [
            'thread' => [
                'session' => ['status' => 'running'],
                'latestTurn' => ['state' => 'completed'],
                'activities' => [
                    ['kind' => 'user-input.requested', 'payload' => ['requestId' => 'request-1']],
                    ['kind' => 'user-input.resolved', 'payload' => ['requestId' => 'request-1']],
                    ['kind' => 'user-input.requested', 'payload' => ['requestId' => 'request-2']],
                ],
            ],
        ],
    ])->observe(observed_group());

    $thread = $observation->thread('implementer-thread');

    expect($thread?->pendingUserInputId)->toBe('request-2')
        ->and($thread?->pendingApprovalId)->toBeNull()
        ->and($thread?->idle)->toBeFalse();
});

it('reports a pending approval listed on the snapshot', function (): void {
    $observation = observed_observer([
        'implementer-thread' => [
            'thread' => [
                'session' => ['status' => 'running'],
                'pendingApprovals' => [
                    ['requestId' => 'approval-done', 'status' => 'resolved'],
                    ['requestId' => 'approval-open', 'status' => 'pending'],
                ],
            ],
        ],
    ])->observe(observed_group());

    $thread = $observation->thread('implementer-thread');

    expect($thread?->pendingApprovalId)->toBe('approval-open')
        ->and($thread?->idle)->toBeFalse();
});

it('observes only the threads Orbit started for this group', function (): void {
    $group = observed_group();
    $other = TaskGroup::query()->create([
        'app_id' => $group->app_id,
        'title' => 'Another group',
        'brief' => 'Not observed.',
        'status' => TaskGroupStatus::Running,
        'reviewer_thread_id' => 'foreign-reviewer-thread',
    ]);
    TaskAgentSession::query()->create([
        'task_group_id' => $other->id,
        'task_id' => null,
        'node_id' => $group->taskable?->node_id,
        'role' => 'reviewer',
        'thread_id' => 'foreign-reviewer-thread',
    ]);
    $threads = observed_threads([]);

    $observation = new TaskSessionObserver($threads, new NullTaskWorkspaceCommitReader)->observe($group);

    expect(array_map(
        static fn (object $thread): string => $thread->threadId,
        $observation->threads,
    ))->toBe(['reviewer-thread', 'implementer-thread'])
        ->and($threads->read)->toBe(['reviewer-thread', 'implementer-thread']);
});

it('reads a thread on the Node its recorded session names', function (): void {
    $group = observed_group();
    $moved = Node::query()->create([
        'name' => 'observed-node-two',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.8',
        'wireguard_ip' => '10.44.0.8',
    ]);
    TaskAgentSession::query()->where('task_group_id', $group->id)
        ->where('role', 'implementer')
        ->update(['node_id' => $moved->id]);
    $threads = new class implements T3ThreadReader
    {
        /** @var list<string> */
        public array $hosts = [];

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->hosts[] = $threadId.'@'.(string) $node->wireguard_ip;

            return null;
        }
    };

    new TaskSessionObserver($threads, new NullTaskWorkspaceCommitReader)->observe($group);

    expect($threads->hosts)->toBe([
        'reviewer-thread@10.44.0.7',
        'implementer-thread@10.44.0.8',
    ]);
});

it('keeps a thread with an unknown state when T3 refuses the snapshot', function (): void {
    $observation = observed_observer(['implementer-thread' => null])->observe(observed_group());

    $thread = $observation->thread('implementer-thread');

    expect($thread?->sessionState)->toBeNull()
        ->and($thread?->idle)->toBeFalse()
        ->and($thread?->lastAssistantText)->toBeNull()
        ->and($thread?->newCommits)->toBeNull();
});

it('counts the workspace commits made after each thread started', function (): void {
    $observation = observed_observer([], [
        '2026-09-21T12:00:00+00:00',
        '2026-09-21T10:00:00+00:00',
        '2026-09-21T08:00:00+00:00',
    ])->observe(observed_group());

    expect($observation->thread('reviewer-thread')?->newCommits)->toBe(2)
        ->and($observation->thread('implementer-thread')?->newCommits)->toBe(1);
});

it('reads the pull request and checks state that T3 links to a thread', function (): void {
    $observation = observed_observer([
        'reviewer-thread' => [
            'thread' => [
                'session' => ['status' => 'ready'],
                'linkedPullRequest' => [
                    'url' => 'https://github.com/nckrtl/observed/pull/7',
                    'snapshot' => ['state' => 'open', 'checksState' => 'passing'],
                ],
            ],
        ],
    ])->observe(observed_group());

    expect($observation->prUrl)->toBe('https://github.com/nckrtl/observed/pull/7')
        ->and($observation->ciSummary)->toBe('passing')
        ->and($observation->thread('reviewer-thread')?->prUrl)->toBe('https://github.com/nckrtl/observed/pull/7');
});

it('observes a group through the scheduler', function (): void {
    $group = observed_group();
    app()->instance(T3ThreadReader::class, observed_threads([
        'implementer-thread' => ['thread' => ['session' => ['status' => 'stopped']]],
    ]));
    app()->instance(TaskWorkspaceCommitReader::class, new NullTaskWorkspaceCommitReader);

    $observation = app(TaskScheduler::class)->observe($group);

    expect($observation->groupId)->toBe($group->id)
        ->and($observation->thread('implementer-thread')?->idle)->toBeTrue()
        ->and($observation->toArray()['threads'][1]['session_state'])->toBe('stopped');
});
