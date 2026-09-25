<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskCheckAction;
use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\NullAgentSpawner;
use App\Domain\Tasks\NullCoderSettleNotifier;
use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskJevDecision;
use App\Domain\Tasks\TaskJevOutcome;
use App\Domain\Tasks\TaskPullRequestDescription;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskRunInstructions;
use App\Domain\Tasks\TaskRunPullRequest;
use App\Domain\Tasks\TaskRunReceiptException;
use App\Domain\Tasks\TaskRunReceipts;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskSessionClassifier;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionNextAction;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskSettleMetrics;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3DispatchException;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskRunReceipts;

use function Pest\Laravel\mock;

function tick_group(): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'tick-app',
        'slug' => 'tick-app',
        'repository_url' => 'git@example.test:tick.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'tick-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.212',
        'wireguard_ip' => '10.44.0.212',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/srv/orbit/apps/tick-app/task-21',
        'branch' => 'task-21',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Tick routing',
        'brief' => 'Observe, classify, and execute.',
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
        'started_at' => now(),
    ]);

    test_link_agent_threads($group);

    return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
}

/** @return array{thread: array<string, mixed>} */
function tick_checked_thread(string $status): array
{
    return ['thread' => [
        'session' => ['status' => $status],
        'activities' => [[
            'id' => 'check-1',
            'kind' => 'command.completed',
            'output' => 'composer check',
            'exitCode' => 0,
            'createdAt' => '2026-09-22T12:00:00Z',
        ]],
    ]];
}

function tick_workspace(bool $definesCheckScript = true, ?string $branch = null): void
{
    app()->instance(TaskWorkspaceStateReader::class, new readonly class($definesCheckScript, $branch) implements TaskWorkspaceStateReader
    {
        public function __construct(private bool $definesCheckScript, private ?string $branch) {}

        public function headCommit(AppInstance $instance): ?string
        {
            return null;
        }

        public function currentBranch(AppInstance $instance): ?string
        {
            return $this->branch;
        }

        public function definesComposerCheckScript(AppInstance $instance): bool
        {
            return $this->definesCheckScript;
        }
    });
}

function tick_dispatcher(): T3Dispatcher
{
    return new class implements T3Dispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
}

beforeEach(function (): void {
    tick_workspace();
});

it('flags a prior settling group without a reviewed PR once and retains its workspace', function (): void {
    $group = tick_group();
    $group->update(['status' => TaskGroupStatus::Settling]);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    app(TaskExtensionState::class)->enable();
    mock(CoderSettleNotifier::class)->shouldReceive('assistance')->once()->withArgs(
        fn (TaskGroup $blocked, string $reason): bool => $blocked->id === $group->id && str_contains($reason, 'no reviewed pull request URL'),
    );
    Http::preventStrayRequests();

    app(TaskScheduler::class)->settle($group);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', [
        'id' => $group->id, 'status' => 'settling', 'pr_url' => null,
        'assistance_requested' => true, 'taskable_id' => $group->taskable_id, 'settled_at' => null,
    ]);
    Http::assertNothingSent();
});

it('continues watching a prior settling PR and completes only after it merges', function (): void {
    $group = tick_group();
    $group->app->update(['repository_url' => 'https://github.com/acme/orbit.git']);
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    app(TaskExtensionState::class)->enable();
    GitHubTestSupport::storeApp();
    mock(AppInstanceRemover::class)->shouldReceive('execute')->once()->withArgs(
        fn (AppInstance $instance, bool $force): bool => $instance->id === $group->taskable_id && $force,
    )->andReturnUsing(function (AppInstance $instance): AppInstanceRemoval {
        $instance->delete();

        return new AppInstanceRemoval;
    });
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push(['merged' => false, 'state' => 'open'])
            ->push(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'settling', 'pr_url' => $group->pr_url]);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'completed', 'pr_url' => $group->pr_url, 'taskable_id' => null]);
    $this->assertDatabaseMissing('app_instances', ['id' => $group->taskable_id]);
    Http::assertSentCount(6);
});

/** A settling group whose pull request the tick reads through the faked GitHub App. */
function tick_settling_group(): TaskGroup
{
    $group = tick_group();
    $group->app->update(['repository_url' => 'https://github.com/acme/orbit.git']);
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    app(TaskExtensionState::class)->enable();
    GitHubTestSupport::storeApp();

    return $group;
}

/** @return CoderSettleNotifier&object{reasons: list<string>} */
function tick_assistance_notifier(): CoderSettleNotifier
{
    $notifier = new class implements CoderSettleNotifier
    {
        /** @var list<string> */
        public array $reasons = [];

        public function notify(TaskGroup $group): void {}

        public function escalate(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(TaskGroup $group, string $reason): void
        {
            $this->reasons[] = $reason;
        }
    };
    app()->instance(CoderSettleNotifier::class, $notifier);

    return $notifier;
}

it('asks for assistance once per set of pull request problems and withdraws it when the pull request is healthy', function (): void {
    $group = tick_settling_group();
    $notifier = tick_assistance_notifier();
    $conflict = ['merged' => false, 'state' => 'open', 'mergeable' => false, 'mergeable_state' => 'dirty', 'head' => ['sha' => 'abc123'], 'base' => ['ref' => 'main']];
    $clean = ['merged' => false, 'state' => 'open', 'mergeable' => true, 'mergeable_state' => 'clean', 'head' => ['sha' => 'def456'], 'base' => ['ref' => 'main']];
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push($conflict)->push($conflict)
            ->push([...$clean, 'mergeable_state' => 'unstable'])
            ->push($clean),
        'https://api.github.com/repos/acme/orbit/commits/abc123/check-runs*' => Http::response(['check_runs' => []]),
        'https://api.github.com/repos/acme/orbit/commits/def456/check-runs*' => Http::sequence()
            ->push(['check_runs' => [['name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'failure', 'html_url' => 'https://github.com/acme/orbit/runs/1']]])
            ->push(['check_runs' => [['name' => 'Rust agent', 'status' => 'completed', 'conclusion' => 'success', 'html_url' => 'https://github.com/acme/orbit/runs/2']]]),
    ]);
    $conflictReason = 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.';
    $checkReason = 'The pull request needs attention: Check Rust agent failed: https://github.com/acme/orbit/runs/1.';

    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_reason' => $conflictReason]);
    $requestedAt = $group->fresh()?->updated_at;
    $this->travel(1)->minute();

    app(TaskScheduler::class)->tick();
    expect($notifier->reasons)->toBe([$conflictReason])
        ->and($group->fresh()?->updated_at?->equalTo($requestedAt))->toBeTrue();

    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'assistance_requested' => true, 'assistance_reason' => $checkReason]);
    expect($notifier->reasons)->toBe([$conflictReason, $checkReason]);

    // A re-run on the same head commit is read once the minute-long check cache expires.
    $this->travel(61)->seconds();
    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => false, 'assistance_reason' => null]);
    expect($notifier->reasons)->toHaveCount(2);
});

it('leaves another cause of assistance on a settling group alone while its pull request conflicts or recovers', function (): void {
    $group = tick_settling_group();
    $group->update(['assistance_requested' => true, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);
    $notifier = tick_assistance_notifier();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::sequence()
            ->push(['merged' => false, 'state' => 'open', 'mergeable' => false, 'base' => ['ref' => 'main']])
            ->push(['merged' => false, 'state' => 'open', 'mergeable' => true, 'base' => ['ref' => 'main']]),
    ]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);
    expect($notifier->reasons)->toBe([]);
});

it('withdraws its pull request assistance request when the pull request merges', function (): void {
    $group = tick_settling_group();
    $group->update(['assistance_requested' => true, 'assistance_reason' => 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.']);
    mock(AppInstanceRemover::class)->shouldReceive('execute')->once()->andReturn(new AppInstanceRemoval);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'completed', 'assistance_requested' => false, 'assistance_reason' => null]);
});

it('keeps another cause of assistance when the pull request merges', function (): void {
    $group = tick_settling_group();
    $group->update(['assistance_requested' => true, 'assistance_reason' => 'The operator asked to hold this group.']);
    mock(AppInstanceRemover::class)->shouldReceive('execute')->once()->andReturn(new AppInstanceRemoval);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'completed', 'assistance_requested' => true, 'assistance_reason' => 'The operator asked to hold this group.']);
});

it('replaces its pull request assistance request with the cleanup failure when a merged group cannot complete', function (): void {
    $group = tick_settling_group();
    $group->update(['assistance_requested' => true, 'assistance_reason' => 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.']);
    mock(AppInstanceRemover::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('disk full'));
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(['merged' => true, 'state' => 'closed']),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_reason' => 'Merged pull request cleanup failed: disk full']);
});

it('changes nothing on a settling group when GitHub cannot report the pull request', function (): void {
    $group = tick_settling_group();
    $reason = 'The pull request needs attention: It conflicts with main; merge main into the task branch and push.';
    $group->update(['assistance_requested' => true, 'assistance_reason' => $reason]);
    $notifier = tick_assistance_notifier();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_watch'], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response([], 502),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'settling', 'assistance_requested' => true, 'assistance_reason' => $reason]);
    expect($notifier->reasons)->toBe([]);
});

it('returns no decisions when the tasks extension is disabled', function (): void {
    tick_group();

    expect(app(TaskScheduler::class)->tick())->toBe([]);
});

it('drains a pending approval chosen by the faked Choice', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($threadId !== 'implementer-thread') {
                return ['thread' => ['session' => ['status' => 'idle']]];
            }

            return [
                'thread' => [
                    'session' => ['status' => 'waiting'],
                    'pendingApprovals' => [['requestId' => 'approval-tick']],
                ],
            ];
        }
    });

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain('waiting for input')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->tasks()->first()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toHaveCount(1);
});

it('escalates to Coder when a drain dispatch fails', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = new class implements T3Dispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;

            throw new T3DispatchException('T3 approval respond failed.');
        }
    };
    $notifier = new class implements CoderSettleNotifier
    {
        public ?string $reason = null;

        public function notify(TaskGroup $group): void {}

        public function escalate(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->reason = $decision->reason;
        }

        public function assistance(TaskGroup $group, string $reason): void
        {
            $this->reason = $reason;
        }
    };
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($threadId !== 'implementer-thread') {
                return ['thread' => ['session' => ['status' => 'idle']]];
            }

            return [
                'thread' => [
                    'session' => ['status' => 'waiting'],
                    'pendingApprovals' => [['requestId' => 'approval-tick']],
                ],
            ];
        }
    });
    app()->instance(CoderSettleNotifier::class, $notifier);

    app(TaskScheduler::class)->tick();

    expect($group->tasks()->first()?->communication_failures)->toBe(1)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($notifier->reason)->toBeNull()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
});

it('advances the current subtask when Jev marks it done', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $spawner = new class implements AgentSpawner
    {
        public int $reviews = 0;

        public function spawnReviewer(Task $task): ?int
        {
            $group = $task->taskGroup;

            return test_agent_thread($group, 'reviewer-thread')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'implementer-thread', $task)->id;
        }

        public function requestReview(Task $task): void
        {
            $this->reviews++;
        }
    };
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    app()->instance(AgentSpawner::class, $spawner);

    $started = app(TaskScheduler::class)->tick();
    $decisions = app(TaskScheduler::class)->tick();

    expect($started)->toBe([])
        ->and($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($group->fresh()?->tasks->first()?->status)->toBe(TaskStatus::Reviewing)
        ->and($spawner->reviews)->toBe(1);
});

it('hands off only when Orbit can run the workspace check script', function (bool $definesCheckScript, TaskStatus $status): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    tick_workspace($definesCheckScript);
    app()->instance(T3Dispatcher::class, tick_dispatcher());
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe($status);
    $commands = app(T3Dispatcher::class)->commands;
    if ($status === TaskStatus::Running) {
        expect($commands[0]['message']['text'])->toContain('does not define a check script, so Orbit cannot run composer check')
            ->and(app(TaskCheckRunner::class)->starts)->toBe(0);
    }
})->with([
    'project check script' => [true, TaskStatus::Reviewing],
    'missing check script' => [false, TaskStatus::Running],
]);

it('records an unreachable workspace as a communication failure without aborting the tick', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, tick_dispatcher());
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    mock(TaskRunReceipts::class)->shouldReceive('read')->andThrow(new TaskRunReceiptException('The task workspace could not be reached for the run receipt.'));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and(app(T3Dispatcher::class)->commands)->toBe([]);
    Classification::assertNothingClassified();
});

it('stores a ready_for_review receipt, removes it, and hands off to the reviewer', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, tick_dispatcher());
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $contents = FakeTaskRunReceipts::contents('ready_for_review', 'Added the models.');
    $receipts = new FakeTaskRunReceipts([$contents]);
    app()->instance(TaskRunReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $comment = $task->comments()->sole();
    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($comment->getRawOriginal('type'))->toBe('ready_for_review')
        ->and($comment->body)->toBe('Added the models.')
        ->and($comment->author)->toBe('implementer')
        ->and($comment->agent_thread_id)->toBe($task->implementer_agent_thread_id)
        ->and($comment->completion_attempt)->toBe($task->fresh()?->completion_attempt)
        ->and($comment->receipt_hash)->toBe(hash('sha256', $contents))
        ->and($receipts->cleared)->toBe([hash('sha256', $contents)]);
    Classification::assertNothingClassified();
});

it('stores a receipt that was read again after a crash only once', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, tick_dispatcher());
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $contents = FakeTaskRunReceipts::contents('ready_for_review');
    $task->comments()->create([
        'task_group_id' => $group->id, 'type' => 'ready_for_review', 'body' => 'Done.', 'author' => 'implementer',
        'completion_attempt' => $task->completion_attempt, 'receipt_hash' => hash('sha256', $contents), 'posted_at' => now(),
    ]);
    app()->instance(TaskRunReceipts::class, new FakeTaskRunReceipts([$contents]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('asks for assistance with the summary of a blocked receipt', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    $receipts = new FakeTaskRunReceipts([FakeTaskRunReceipts::contents('blocked', 'Installing the extension needs sudo, and sudo was denied.', 'May the Node user run sudo apt-get install php8.5-intl?')]);
    app()->instance(TaskRunReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe("The implementer is blocked: Installing the extension needs sudo, and sudo was denied.\n\nQuestion: May the Node user run sudo apt-get install php8.5-intl?")
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->comments()->sole()->getRawOriginal('type'))->toBe('blocked')
        ->and($receipts->cleared)->toHaveCount(1)
        ->and($dispatcher->commands)->toBe([]);
});

it('reminds an implementer that ends a turn without a receipt once, then asks for assistance', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $notifier = new class implements CoderSettleNotifier
    {
        public ?string $reason = null;

        public function notify(TaskGroup $group): void {}

        public function escalate(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(TaskGroup $group, string $reason): void
        {
            $this->reason = $reason;
        }
    };
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(CoderSettleNotifier::class, $notifier);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle');
        }
    });
    $receipts = new FakeTaskRunReceipts([null, null]);
    app()->instance(TaskRunReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    expect($dispatcher->commands)->toHaveCount(1)
        ->and($reminder)->toBe('Orbit could not confirm the brief is complete. No run receipt was found. '.TaskRunInstructions::implementer())
        ->and($receipts->prepared)->toBe(['implementer'])
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($notifier->reason)->toBe('Checks still failed after the reminder. No run receipt was found.')
        ->and($task->comments()->count())->toBe(0);
    Classification::assertNothingClassified();
});

it('refuses a receipt with an outcome that does not fit the implementer turn, or a blocked receipt without a question', function (string $contents): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $receipts = new FakeTaskRunReceipts([$contents]);
    app()->instance(TaskRunReceipts::class, $receipts);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands[0]['message']['text'])->toContain('The run receipt was not valid for this turn.')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('--question=')
        ->and($task->comments()->count())->toBe(0)
        ->and($receipts->cleared)->toHaveCount(1);
})->with([
    'a reviewer outcome' => [FakeTaskRunReceipts::contents('approved')],
    'blocked without a question' => [FakeTaskRunReceipts::contents('blocked', 'Gateway implementation not completed; required project guidance/bootstrap review and implementation remain.')],
]);

it('dispatches nothing when Jev selects noop', function (): void {
    tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $notifier = new class implements CoderSettleNotifier
    {
        public bool $called = false;

        public function notify(TaskGroup $group): void
        {
            $this->called = true;
        }

        public function escalate(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->called = true;
        }

        public function assistance(TaskGroup $group, string $reason): void
        {
            $this->called = true;
        }
    };
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    app()->instance(CoderSettleNotifier::class, $notifier);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Running)
        ->and($notifier->called)->toBeFalse();
});

it('runs the artisan tick while the extension is enabled', function (): void {
    app(TaskExtensionState::class)->enable();

    $this->artisan('tasks:tick')
        ->expectsOutput('Routed [0] tasks and started [0] groups.')
        ->assertSuccessful();
});

it('does not classify or advance a task while its T3 thread is active', function (string $status): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class($status) implements T3ThreadReader
    {
        public function __construct(private string $status) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => $threadId === 'implementer-thread' ? $this->status : 'idle'],
                'messages' => [['role' => 'assistant', 'text' => 'Done. Ready for review.']],
            ]];
        }
    });
    app()->instance(TaskSessionClassifier::class, new class implements TaskSessionClassifier
    {
        public function classify(TaskSessionObservation $observation): TaskSessionDecision
        {
            throw new LogicException('Active tasks must not call Jev.');
        }

        public function classifyOutcome(TaskSessionObservation $observation, TaskThreadRole $role): TaskJevDecision
        {
            throw new LogicException('Active tasks must not call Jev.');
        }
    });

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->tasks()->first()?->status)->toBe(TaskStatus::Running);
})->with(['starting', 'running']);

it('ignores tasks that are not in progress even when they have a thread', function (TaskStatus $status): void {
    $group = tick_group();
    $task = $group->tasks->first();
    $task->update(['status' => $status]);
    app(TaskExtensionState::class)->enable();
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            throw new LogicException('Only in-progress tasks should be inspected.');
        }
    });

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($task->fresh()->status)->toBe($status);
    Classification::assertNothingClassified();
})->with([TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled]);

it('does not classify an in-progress task without an attached session', function (): void {
    $group = tick_group();
    $group->tasks->first()->update(['implementer_agent_thread_id' => null]);
    AgentThread::query()->where('task_id', $group->tasks->first()->id)->delete();
    app(TaskExtensionState::class)->enable();

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([]);
    Classification::assertNothingClassified();
});

it('targets the idle in-progress task while another task is working', function (TaskSessionNextAction $action): void {
    $group = tick_group();
    $workingTask = $group->tasks->first();
    $workingTask->update(['status' => TaskStatus::Reviewing]);
    $idleTask = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 2,
        'title' => 'Second task',
        'brief' => 'Finish the second task.',
        'status' => TaskStatus::Running,
    ]);
    test_agent_thread($group, 'second-task-session', $idleTask);
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(AgentSpawner::class, new NullAgentSpawner);
    $reader = new class implements T3ThreadReader
    {
        /** @var list<string> */
        public array $requested = [];

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->requested[] = $threadId;

            return ['thread' => [
                'session' => ['status' => $threadId === 'implementer-thread' ? 'running' : 'idle'],
                'messages' => [['role' => 'assistant', 'text' => 'Ready for the next step.']],
            ]];
        }
    };
    app()->instance(T3ThreadReader::class, $reader);
    $classifier = new class($action) implements TaskSessionClassifier
    {
        /** @var list<TaskSessionObservation> */
        public array $observations = [];

        public function __construct(private TaskSessionNextAction $action) {}

        public function classify(TaskSessionObservation $observation): TaskSessionDecision
        {
            $this->observations[] = $observation;

            return new TaskSessionDecision($this->action, 0.95, 'Route the observed task.');
        }

        public function classifyOutcome(TaskSessionObservation $observation, TaskThreadRole $role): TaskJevDecision
        {
            return new TaskJevDecision(TaskJevOutcome::AssistanceRequired, 1.0, 'Legacy test classifier.');
        }
    };
    app()->instance(TaskSessionClassifier::class, $classifier);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and(TaskCheck::query()->where('task_id', $idleTask->id)->count())->toBe(1)
        ->and(TaskCheck::query()->where('task_id', $workingTask->id)->count())->toBe(0)
        ->and($workingTask->fresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($idleTask->fresh()->status)->toBe(TaskStatus::Running);
})->with([TaskSessionNextAction::ContinueImplementer, TaskSessionNextAction::MarkSubtaskDone]);

it('reminds the implementer with the failing check output once, then asks for assistance', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $notifier = new class implements CoderSettleNotifier
    {
        public ?string $reason = null;

        public function notify(TaskGroup $group): void {}

        public function escalate(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(TaskGroup $group, string $reason): void
        {
            $this->reason = $reason;
        }
    };
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(CoderSettleNotifier::class, $notifier);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "FAILED tests/Feature/ExportTest.php\n");
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([$failed, $failed]));
    app()->instance(TaskRunReceipts::class, new FakeTaskRunReceipts([
        FakeTaskRunReceipts::contents('ready_for_review'), null, FakeTaskRunReceipts::contents('ready_for_review', 'Fixed the export test.'), null,
    ]));

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reminder = $dispatcher->commands[0]['message']['text'];
    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($reminder)->toContain("Orbit ran composer check, and it failed with exit code 1. The end of its output:\n\n```\nFAILED tests/Feature/ExportTest.php\n```")
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Failed);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($notifier->reason)->toStartWith('Checks still failed after the reminder. Orbit ran composer check, and it failed with exit code 1.')
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and(TaskCheck::query()->count())->toBe(2);
});

it('retries the reviewer nudge until the handoff send succeeds', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $spawner = new class implements AgentSpawner
    {
        public int $reviews = 0;

        public function spawnReviewer(Task $task): ?int
        {
            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void
        {
            $this->reviews++;
            if ($this->reviews === 1) {
                throw new AgentDriverException('Reviewer send failed.');
            }
        }
    };
    app()->instance(AgentSpawner::class, $spawner);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('idle');
        }
    });

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $task = $group->tasks()->first();
    expect($spawner->reviews)->toBe(1)
        ->and($task?->status)->toBe(TaskStatus::Reviewing)
        ->and($task?->review_notified_attempt)->toBeNull()
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($spawner->reviews)->toBe(2)
        ->and($task?->fresh()?->review_notified_attempt)->toBe($task?->review_attempt)
        ->and($task?->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($spawner->reviews)->toBe(2)
        ->and($task?->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($group->fresh()?->assistance_requested)->toBeFalse();
});

it('waits for a newer reviewer turn before asking for an outcome', function (?string $observedTurn): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $attempt = $task->fresh()?->review_attempt ?? 1;
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update([
        'status' => TaskStatus::Reviewing,
        'review_notified_attempt' => $attempt,
        'review_notified_turn_id' => 'turn-old',
    ]);
    $reader = new class($observedTurn) implements T3ThreadReader
    {
        public function __construct(public ?string $turnId) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => 'done'],
                'latestTurn' => ['id' => $this->turnId, 'state' => 'completed'],
            ]];
        }
    };
    $dispatcher = tick_dispatcher();
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, $reader);
    app()->instance(TaskRunReceipts::class, new FakeTaskRunReceipts([null]));

    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

    $reader->turnId = 'turn-new';
    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain('No run receipt was found.');
})->with(['turn-old', null, '']);

it('retries review findings until the implementer receives them', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt]);
    app()->instance(TaskRunReceipts::class, new FakeTaskRunReceipts([FakeTaskRunReceipts::contents('changes_requested', 'Add the missing test.')]));
    $dispatcher = new class implements T3Dispatcher
    {
        public int $calls = 0;

        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->calls++;
            $this->commands[] = $command;
            if ($this->calls === 1) {
                throw new T3DispatchException('relay failed');
            }

            return ['sequence' => $this->calls, 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'completed']]];
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($task->comments()->sole()->getRawOriginal('type'))->toBe('changes_requested')
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($dispatcher->commands[1]['message']['text'])->toContain('Add the missing test.')
        ->and($dispatcher->commands[1]['message']['text'])->toContain(TaskRunInstructions::implementer())
        ->and(app(TaskRunReceipts::class)->prepared)->toBe(['implementer', 'implementer']);
});

it('repeated reminder send failures reach assistance', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'waiting'], 'pendingApprovals' => [['requestId' => 'pending-1']]]];
        }
    });
    app()->instance(T3Dispatcher::class, new class implements T3Dispatcher
    {
        public function dispatch(Node $node, array $command): array
        {
            throw new T3DispatchException('send failed');
        }
    });
    for ($i = 0; $i < 6; $i++) {
        app(TaskScheduler::class)->tick();
    }
    expect($group->tasks()->sole()->communication_failures)->toBeGreaterThanOrEqual(5);
    expect($group->fresh()->assistance_requested)->toBeTrue();
});

it('handoff waits while reviewer still reports its old turn', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            $snapshot = tick_checked_thread('done');
            $snapshot['thread']['latestTurn'] = ['id' => $threadId.'-old', 'state' => 'completed'];

            return $snapshot;
        }
    });
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    expect($group->fresh()->status)->toBe(TaskGroupStatus::Reviewing);
    expect($dispatcher->commands)->toHaveCount(1);
    app(TaskScheduler::class)->tick();
    expect($dispatcher->commands)->toHaveCount(1);
});

it('unavailable implementer uses observation grace instead of rubric', function (): void {
    $group = tick_group();
    AgentThread::query()->where('task_id', $group->tasks->sole()->id)->update(['state' => 'done']);
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    });
    app()->instance(TaskSessionClassifier::class, new class implements TaskSessionClassifier
    {
        public function classify(TaskSessionObservation $observation): TaskSessionDecision
        {
            throw new LogicException('unused');
        }

        public function classifyOutcome(TaskSessionObservation $observation, TaskThreadRole $role): TaskJevDecision
        {
            throw new LogicException('unused');
        }
    });
    app(TaskScheduler::class)->tick();
    expect($dispatcher->commands)->toBe([]);
    expect($group->fresh()->agent_unavailable_since)->not->toBeNull();
});
it('an unavailable reviewer cannot advance an approval', function (): void {
    [$group, $task, $receipts, $signer] = tick_review([FakeTaskRunReceipts::contents('approved')]);
    AgentThread::query()->where('task_group_id', $group->id)->update(['state' => 'done']);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($receipts->reads())->toBe(0)
        ->and($signer->messages)->toBe([]);
});

it('relayed findings require a newer implementer turn, a new receipt, and a new check before review', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt]);
    app()->instance(TaskRunReceipts::class, new FakeTaskRunReceipts([
        FakeTaskRunReceipts::contents('changes_requested', 'Fix the regression before requesting review again.'),
        null,
        FakeTaskRunReceipts::contents('ready_for_review'),
    ]));
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, tick_dispatcher());
    $reader = new class implements T3ThreadReader
    {
        public string $turnId = 'before-findings';

        public string $checkId = 'check-1';

        public function snapshot(Node $node, string $threadId): ?array
        {
            $snapshot = tick_checked_thread('done');
            $snapshot['thread']['latestTurn'] = ['id' => $this->turnId, 'state' => 'completed'];
            $snapshot['thread']['activities'][0]['id'] = $this->checkId;

            return $snapshot;
        }
    };
    app()->instance(T3ThreadReader::class, $reader);

    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect(app(T3Dispatcher::class)->commands)->toHaveCount(1);
    Classification::assertNothingClassified();

    $reader->turnId = 'after-findings';
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect(app(T3Dispatcher::class)->commands[1]['message']['text'])->toContain('No run receipt was found.');

    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Running);

    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Reviewing);
});

it('retains reminder send failures across successful classifications and clears them on delivery', function (TaskStatus $status): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => $status === TaskStatus::Reviewing ? TaskGroupStatus::Reviewing : TaskGroupStatus::Running]);
    $task->update(['status' => $status, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'old-turn']);
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskRunReceipts::class, new FakeTaskRunReceipts([]));
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'new-turn', 'state' => 'completed']]];
        }
    });
    $dispatcher = new class implements T3Dispatcher
    {
        public bool $fails = true;

        public function dispatch(Node $node, array $command): array
        {
            if ($this->fails) {
                throw new T3DispatchException('send failed');
            }

            return ['sequence' => 1, 'thread_id' => $command['threadId']];
        }
    };
    app()->instance(T3Dispatcher::class, $dispatcher);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->communication_failures)->toBe(2);

    $dispatcher->fails = false;
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->communication_failures)->toBe(0);
    expect($group->fresh()->assistance_requested)->toBeFalse();
})->with([TaskStatus::Running, TaskStatus::Reviewing]);

/**
 * A task in review whose reviewer has finished the turn after the handoff. Unless it is the last,
 * a later subtask waits behind it.
 *
 * @param  list<string|null>  $receipts
 * @return array{TaskGroup, Task, FakeTaskRunReceipts, object}
 */
function tick_review(array $receipts, bool $onBranch = true, bool $last = false): array
{
    $group = tick_group();
    $task = $group->tasks->sole();
    if (! $last) {
        Task::query()->create(['task_group_id' => $group->id, 'position' => 2, 'title' => 'Routes', 'brief' => 'Add the routes.', 'status' => TaskStatus::Todo]);
    }
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'handoff-turn']);
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, tick_dispatcher());
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => 'review-turn', 'state' => 'completed']]];
        }
    });
    tick_workspace(branch: $onBranch ? 'task-'.$group->id : 'main');
    $receipts = new FakeTaskRunReceipts($receipts);
    app()->instance(TaskRunReceipts::class, $receipts);
    $signer = new class implements TaskWorkspaceSigner
    {
        /** @var list<string> */
        public array $messages = [];

        public bool $fails = false;

        public function commit(AppInstance $instance, string $message): ?string
        {
            $this->messages[] = $message;

            return $this->fails ? null : str_repeat('c', 40);
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);

    return [$group->fresh(['app', 'tasks', 'taskable']) ?? $group, $task, $receipts, $signer];
}

it('commits an approved subtask with the title and the reviewer summary, then starts the next subtask', function (): void {
    [$group, $task, $receipts, $signer] = tick_review([FakeTaskRunReceipts::contents('approved', 'Checked the models and their tests.')]);
    $next = Task::query()->where('title', 'Routes')->sole();
    $spawner = new class implements AgentSpawner
    {
        /** @var list<int> */
        public array $implementers = [];

        public function spawnReviewer(Task $task): ?int
        {
            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->implementers[] = $task->id;

            return test_agent_thread($task->taskGroup, 'implementer-'.$task->id, $task)->id;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);

    app(TaskScheduler::class)->tick();

    $approval = $task->comments()->sole();
    expect($signer->messages)->toBe(["Models\n\nChecked the models and their tests."])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($next->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($spawner->implementers)->toBe([$next->id])
        ->and($approval->getRawOriginal('type'))->toBe('approved')
        ->and($approval->author)->toBe('reviewer')
        ->and($approval->review_attempt)->toBe($task->review_attempt)
        ->and($approval->commit_sha)->toBe(str_repeat('c', 40))
        ->and($task->fresh()?->review_handled_comment_id)->toBe($approval->id)
        ->and($receipts->cleared)->toHaveCount(1);
    Classification::assertNothingClassified();
});

it('retries the commit of an approved subtask without storing the approval twice', function (): void {
    [$group, $task, , $signer] = tick_review([FakeTaskRunReceipts::contents('approved', 'Looks good.')]);
    $signer->fails = true;

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(1);

    $signer->fails = false;
    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed)
        ->and($signer->messages)->toHaveCount(2);
});

it('does not commit an approval while the workspace is on another branch', function (): void {
    [$group, $task, , $signer] = tick_review([FakeTaskRunReceipts::contents('approved', 'Looks good.'), null], onBranch: false);

    app(TaskScheduler::class)->tick();

    $dispatcher = app(T3Dispatcher::class);
    expect($signer->messages)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($dispatcher->commands[0]['message']['text'])->toBe('Orbit could not confirm the review is complete. The workspace branch is not task-'.$group->id.'. Switch back to it. '.TaskRunInstructions::reviewer());

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. No run receipt was found.');
});

it('asks for assistance with the summary of a blocked reviewer receipt', function (): void {
    [$group, $task] = tick_review([FakeTaskRunReceipts::contents('blocked', 'The brief contradicts ADR 0098.', 'Should the subtask follow the brief or ADR 0098?')]);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe("The reviewer is blocked: The brief contradicts ADR 0098.\n\nQuestion: Should the subtask follow the brief or ADR 0098?")
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and(app(T3Dispatcher::class)->commands)->toBe([]);
});

it('reminds a reviewer that ends a turn without a receipt once, then asks for assistance', function (): void {
    [$group, $task, $receipts] = tick_review([null, null]);

    app(TaskScheduler::class)->tick();

    expect(app(T3Dispatcher::class)->commands[0]['message']['text'])->toBe('Orbit could not confirm the review is complete. No run receipt was found. '.TaskRunInstructions::reviewer())
        ->and($receipts->prepared)->toBe(['reviewer']);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($task->fresh()?->assistance_reason)->toBe('Checks still failed after the reminder. No run receipt was found.');
    Classification::assertNothingClassified();
});

it('refuses an implementer outcome in a reviewer turn', function (): void {
    [$group, $task, $receipts, $signer] = tick_review([FakeTaskRunReceipts::contents('ready_for_review')]);

    app(TaskScheduler::class)->tick();

    expect(app(T3Dispatcher::class)->commands[0]['message']['text'])->toContain('The run receipt was not valid for this turn.')
        ->and($task->comments()->count())->toBe(0)
        ->and($signer->messages)->toBe([])
        ->and($receipts->cleared)->toHaveCount(1);
});

it('starts the reviewer with the first review request when the group has no reviewer yet', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    AgentThread::query()->whereKey($group->reviewer_agent_thread_id)->delete();
    $group->update(['status' => TaskGroupStatus::Reviewing, 'reviewer_agent_thread_id' => null]);
    $task->update(['status' => TaskStatus::Reviewing]);
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, tick_dispatcher());
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $spawner = new class implements AgentSpawner
    {
        /** @var list<int> */
        public array $reviewers = [];

        public function spawnReviewer(Task $task): ?int
        {
            $this->reviewers[] = $task->id;

            return test_agent_thread($task->taskGroup, 'reviewer-thread-2')->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void {}
    };
    app()->instance(AgentSpawner::class, $spawner);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($spawner->reviewers)->toBe([$task->id])
        ->and($group->fresh()?->reviewer_agent_thread_id)->toBe(AgentThread::query()->where('external_id', 'reviewer-thread-2')->sole()->id)
        ->and($task->fresh()?->review_notified_attempt)->toBe($task->fresh()?->review_attempt)
        ->and(app(TaskRunReceipts::class)->prepared)->toBe(['reviewer:final']);
});

function tick_final_approval(): string
{
    return json_encode([
        'outcome' => 'approved',
        'summary' => 'Checked the feature.',
        'pull_request' => ['summary' => 'Adds tick routing.', 'changes' => ['Tasks store their records.'], 'breaking' => []],
        'nonce' => bin2hex(random_bytes(8)),
    ], JSON_THROW_ON_ERROR);
}

/** @param list<list<string>> $missing */
function tick_publishing(array $missing = [[]], int $failures = 0): object
{
    $coverage = new class($missing) implements TaskBriefCoverage
    {
        public int $calls = 0;

        /** @param list<list<string>> $missing */
        public function __construct(private array $missing) {}

        public function missing(TaskGroup $group, TaskRunPullRequest $pullRequest): array
        {
            $this->calls++;

            return array_shift($this->missing) ?? [];
        }
    };
    $publisher = new class($failures) implements TaskPullRequestPublisher
    {
        /** @var list<string> */
        public array $bodies = [];

        public function __construct(private int $failures) {}

        public function publish(TaskGroup $group, string $body): string
        {
            $this->bodies[] = $body;
            if ($this->failures-- > 0) {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }

            return 'https://github.com/acme/orbit/pull/42';
        }

        public function push(TaskGroup $group): void {}
    };
    app()->instance(TaskBriefCoverage::class, $coverage);
    app()->instance(TaskPullRequestPublisher::class, $publisher);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    app()->instance(CoderSettleNotifier::class, new NullCoderSettleNotifier);

    return (object) ['coverage' => $coverage, 'publisher' => $publisher];
}

it('commits the last approved subtask, opens the pull request with the reviewer fields, and settles the group', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    $approval = $task->comments()->sole();
    expect($signer->messages)->toBe(["Models\n\nChecked the feature."])
        ->and($publishing->publisher->bodies)->toBe([TaskPullRequestDescription::render(new TaskRunPullRequest('Adds tick routing.', ['Tasks store their records.'], []), 1)])
        ->and($approval->pull_request)->toBe(['summary' => 'Adds tick routing.', 'changes' => ['Tasks store their records.'], 'breaking' => []])
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
});

it('counts only the delivered subtasks in the pull request description', function (): void {
    [$group, , , $signer] = tick_review([tick_final_approval()], last: true);
    foreach ([TaskStatus::Completed, TaskStatus::Cancelled, TaskStatus::Failed] as $index => $status) {
        Task::query()->create(['task_group_id' => $group->id, 'position' => $index + 2, 'title' => $status->value, 'brief' => 'Other subtask.', 'status' => $status]);
    }
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    expect($signer->messages)->toHaveCount(1)
        ->and($publishing->publisher->bodies)->toBe([TaskPullRequestDescription::render(new TaskRunPullRequest('Adds tick routing.', ['Tasks store their records.'], []), 2)]);
});

it('reminds the reviewer when the approval of the last subtask has no pull request fields', function (): void {
    [$group, $task, , $signer] = tick_review([FakeTaskRunReceipts::contents('approved')], last: true);
    $publishing = tick_publishing();

    app(TaskScheduler::class)->tick();

    expect(app(T3Dispatcher::class)->commands[0]['message']['text'])->toBe('Orbit could not confirm the review is complete. The approval of the last subtask needs --pr-summary, --pr-change, and --pr-breaking. '.TaskRunInstructions::reviewer(final: true))
        ->and($publishing->coverage->calls)->toBe(0)
        ->and($signer->messages)->toBe([]);
});

it('names each subtask the change list misses and does not commit', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing([['Models']]);

    app(TaskScheduler::class)->tick();

    expect(app(T3Dispatcher::class)->commands[0]['message']['text'])->toContain('The pull request change list does not cover the subtask "Models".')
        ->and($signer->messages)->toBe([])
        ->and($publishing->publisher->bodies)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('retries opening the pull request without storing the approval twice', function (): void {
    [$group, $task] = tick_review([tick_final_approval()], last: true);
    $publishing = tick_publishing([[], []], failures: 1);

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and($group->fresh()?->pr_url)->toBeNull()
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

    app(TaskScheduler::class)->tick();

    expect($task->comments()->count())->toBe(1)
        ->and($publishing->publisher->bodies)->toHaveCount(2)
        ->and($group->fresh()?->pr_url)->toBe('https://github.com/acme/orbit/pull/42')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Settling);
});

it('counts a failed coverage answer as a communication failure', function (): void {
    [$group, $task, , $signer] = tick_review([tick_final_approval()], last: true);
    tick_publishing();
    app()->instance(TaskBriefCoverage::class, new class implements TaskBriefCoverage
    {
        public function missing(TaskGroup $group, TaskRunPullRequest $pullRequest): array
        {
            throw new TaskSessionClassificationException('TypeSafe Jev request failed (ConnectionException).');
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and($signer->messages)->toBe([])
        ->and(app(T3Dispatcher::class)->commands)->toBe([]);
});

/**
 * A running task whose idle implementer ended its turn with ready_for_review, with the given check readings.
 *
 * @param  list<TaskCheckReading>  $readings
 * @return array{TaskGroup, Task, FakeTaskCheckRunner, object}
 */
function tick_checking(array $readings): array
{
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, tick_dispatcher());
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done');
        }
    });
    $checks = new FakeTaskCheckRunner($readings);
    app()->instance(TaskCheckRunner::class, $checks);
    $notifier = new class implements CoderSettleNotifier
    {
        public ?string $reason = null;

        public function notify(TaskGroup $group): void {}

        public function escalate(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void {}

        public function assistance(TaskGroup $group, string $reason): void
        {
            $this->reason = $reason;
        }
    };
    app()->instance(CoderSettleNotifier::class, $notifier);

    return [$group, $group->tasks->sole(), $checks, $notifier];
}

it('waits while the check process runs and starts the reviewer only after it passes', function (): void {
    $finished = TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('b', 40), [], "checks passed\n", 1790170874.25);
    [$group, $task, $checks] = tick_checking([TaskCheckReading::running(), TaskCheckReading::running(), $finished]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $check = TaskCheck::query()->sole();
    expect($checks->starts)->toBe(1)
        ->and($check->status)->toBe(TaskCheckStatus::Running)
        ->and($check->task_comment_id)->toBe($task->comments()->sole()->id)
        ->and($check->pid)->toBe(4001)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and(app(T3Dispatcher::class)->commands)->toBe([]);

    app(TaskScheduler::class)->tick();

    expect($check->fresh()?->status)->toBe(TaskCheckStatus::Passed)
        ->and($check->fresh()?->exit_code)->toBe(0)
        ->and($check->fresh()?->finished_at?->getTimestamp())->toBe(1790170874)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
});

it('starts the check again when the tree changed during the run, then names the changed paths the second time', function (): void {
    $changed = TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('c', 40), ['storage/check.cache'], "ok\n");
    [$group, $task, $checks] = tick_checking([$changed, $changed]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(2)
        ->and(TaskCheck::query()->pluck('status')->all())->toBe([TaskCheckStatus::Changed, TaskCheckStatus::Running])
        ->and(app(T3Dispatcher::class)->commands)->toBe([]);

    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(2)
        ->and(app(T3Dispatcher::class)->commands[0]['message']['text'])->toContain('The workspace changed while composer check ran, twice. Changed paths: storage/check.cache.')
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);
});

it('starts a lost check again once, then asks for assistance', function (): void {
    [$group, $task, $checks, $notifier] = tick_checking([TaskCheckReading::lost(''), TaskCheckReading::lost('')]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(2)
        ->and($task->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($checks->starts)->toBe(2)
        ->and($task->fresh()?->assistance_requested)->toBeTrue()
        ->and($notifier->reason)->toBe("Orbit's composer check stopped twice without a result.")
        ->and(TaskCheck::query()->pluck('status')->all())->toBe([TaskCheckStatus::Lost, TaskCheckStatus::Lost]);
});

it('reminds the implementer after an operator cancels the check', function (): void {
    [$group, $task, $checks] = tick_checking([TaskCheckReading::running()]);
    app(TaskScheduler::class)->tick();

    $cancelled = app(CancelTaskCheckAction::class)->execute($group, $task);
    app(TaskScheduler::class)->tick();

    expect($cancelled->status)->toBe(TaskCheckStatus::Cancelled)
        ->and($checks->cancels)->toBe(1)
        ->and(app(T3Dispatcher::class)->commands[0]['message']['text'])->toContain("An operator cancelled Orbit's composer check before it finished.")
        ->and($checks->starts)->toBe(1);
});

it('keeps a check running when the workspace cannot be read and counts a communication failure', function (): void {
    [$group, $task] = tick_checking([]);
    app(TaskScheduler::class)->tick();
    mock(TaskCheckRunner::class)->shouldReceive('read')->andThrow(new TaskCheckException('The task workspace could not be reached for the check.'));

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->communication_failures)->toBe(1)
        ->and(TaskCheck::query()->sole()->status)->toBe(TaskCheckStatus::Running)
        ->and($task->fresh()?->status)->toBe(TaskStatus::Running);
});

/**
 * Reports each thread in the given session status. Each thread's latest turn is "{thread}-turn".
 *
 * @param  array<string, string>  $states
 */
function tick_thread_states(array $states): T3ThreadReader
{
    return new class($states) implements T3ThreadReader
    {
        /** @param array<string, string> $states */
        public function __construct(public array $states) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => $this->states[$threadId] ?? 'idle'],
                'latestTurn' => ['id' => $threadId.'-turn', 'state' => 'completed'],
            ]];
        }
    };
}

describe('a thread that works outside the task phase', function (): void {
    it('collects a blocked implementer receipt and asks for assistance while the shared reviewer works', function (): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        app(TaskExtensionState::class)->enable();
        $dispatcher = tick_dispatcher();
        app()->instance(T3Dispatcher::class, $dispatcher);
        app()->instance(T3ThreadReader::class, tick_thread_states(['implementer-thread' => 'done', 'reviewer-thread' => 'running']));
        $receipts = new FakeTaskRunReceipts([FakeTaskRunReceipts::contents('blocked', 'Composer cannot reach the private package mirror.', 'Should I add the mirror credentials to auth.json?')]);
        app()->instance(TaskRunReceipts::class, $receipts);

        app(TaskScheduler::class)->tick();

        expect($task->comments()->sole()->getRawOriginal('type'))->toBe('blocked')
            ->and($receipts->cleared)->toHaveCount(1)
            ->and($task->fresh()?->assistance_requested)->toBeTrue()
            ->and($task->fresh()?->assistance_reason)->toBe("The implementer is blocked: Composer cannot reach the private package mirror.\n\nQuestion: Should I add the mirror credentials to auth.json?")
            ->and($group->fresh()?->assistance_requested)->toBeTrue()
            ->and($dispatcher->commands)->toBe([]);
    });

    it('leaves a running task alone while its implementer works', function (string $reviewerState): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        app(TaskExtensionState::class)->enable();
        $dispatcher = tick_dispatcher();
        app()->instance(T3Dispatcher::class, $dispatcher);
        app()->instance(T3ThreadReader::class, tick_thread_states(['implementer-thread' => 'running', 'reviewer-thread' => $reviewerState]));
        $receipts = new FakeTaskRunReceipts([FakeTaskRunReceipts::contents('blocked', 'Stuck.', 'Which API version?')]);
        app()->instance(TaskRunReceipts::class, $receipts);

        $decisions = app(TaskScheduler::class)->tick();

        expect($decisions)->toBe([])
            ->and($receipts->cleared)->toBe([])
            ->and($task->comments()->count())->toBe(0)
            ->and($task->fresh()?->assistance_requested)->toBeFalse()
            ->and($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($dispatcher->commands)->toBe([]);
        Classification::assertNothingClassified();
    })->with(['idle', 'running']);

    it('moves a passing handoff to review but asks for the review only once the reviewer is idle', function (): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        app(TaskExtensionState::class)->enable();
        $spawner = new class implements AgentSpawner
        {
            public int $reviews = 0;

            public function spawnReviewer(Task $task): ?int
            {
                return null;
            }

            public function spawnImplementer(Task $task): ?int
            {
                return null;
            }

            public function requestReview(Task $task): void
            {
                $this->reviews++;
            }
        };
        app()->instance(AgentSpawner::class, $spawner);
        $reader = tick_thread_states(['implementer-thread' => 'done', 'reviewer-thread' => 'running']);
        app()->instance(T3ThreadReader::class, $reader);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
            ->and($spawner->reviews)->toBe(0)
            ->and($task->fresh()?->review_notified_attempt)->toBeNull()
            ->and($task->fresh()?->communication_failures)->toBe(0)
            ->and(app(TaskRunReceipts::class)->prepared)->toBe([]);

        $reader->states['reviewer-thread'] = 'idle';
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($spawner->reviews)->toBe(1)
            ->and($task->fresh()?->review_notified_attempt)->toBe($task->fresh()?->review_attempt)
            ->and($task->fresh()?->review_notified_turn_id)->toBe('reviewer-thread-turn')
            ->and(app(TaskRunReceipts::class)->prepared)->toBe(['reviewer:final']);
    });

    it('relays review findings only once the implementer is idle', function (): void {
        $group = tick_group();
        $task = $group->tasks->sole();
        $group->update(['status' => TaskGroupStatus::Reviewing]);
        $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'handoff-turn']);
        app(TaskExtensionState::class)->enable();
        $dispatcher = tick_dispatcher();
        app()->instance(T3Dispatcher::class, $dispatcher);
        $reader = tick_thread_states(['implementer-thread' => 'running', 'reviewer-thread' => 'done']);
        app()->instance(T3ThreadReader::class, $reader);
        app()->instance(TaskRunReceipts::class, new FakeTaskRunReceipts([FakeTaskRunReceipts::contents('changes_requested', 'Add the missing test.')]));

        app(TaskScheduler::class)->tick();

        expect($task->comments()->sole()->getRawOriginal('type'))->toBe('changes_requested')
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($task->fresh()?->communication_failures)->toBe(0)
            ->and($dispatcher->commands)->toBe([]);

        $reader->states['implementer-thread'] = 'idle';
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($dispatcher->commands)->toHaveCount(1)
            ->and($dispatcher->commands[0]['message']['text'])->toContain('Add the missing test.');
    });

    it('commits an approval only once the implementer stops changing the workspace', function (): void {
        [$group, $task, , $signer] = tick_review([FakeTaskRunReceipts::contents('approved', 'Checked the models.')]);
        $reader = tick_thread_states(['implementer-thread' => 'running', 'reviewer-thread' => 'done']);
        app()->instance(T3ThreadReader::class, $reader);
        app()->instance(AgentSpawner::class, new class implements AgentSpawner
        {
            public function spawnReviewer(Task $task): ?int
            {
                return null;
            }

            public function spawnImplementer(Task $task): ?int
            {
                return test_agent_thread($task->taskGroup, 'implementer-'.$task->id, $task)->id;
            }

            public function requestReview(Task $task): void {}
        });

        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe([])
            ->and($task->comments()->sole()->getRawOriginal('type'))->toBe('approved')
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

        $reader->states['implementer-thread'] = 'idle';
        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe(["Models\n\nChecked the models."])
            ->and($task->fresh()?->status)->toBe(TaskStatus::Completed);
    });
});

/**
 * A running subtask with deliverables whose implementer hands off with the given confirmations, and a check that
 * passes with the given evidence.
 *
 * @param  list<array<string, string>>  $deliverables
 * @param  array<string, string>  $confirmations
 * @param  array<string, mixed>|null  $evidence
 * @param  list<TaskCheckReading>|null  $readings  the check readings; null passes once with the evidence
 * @return array{0: TaskGroup, 1: Task, 2: FakeTaskCheckRunner, 3: CoderSettleNotifier, 4: FakeTaskRunReceipts}
 */
function tick_deliverables(array $deliverables, array $confirmations, ?array $evidence, ?array $readings = null, ?array $receipts = null): array
{
    [$group, $task, $checks, $notifier] = tick_checking($readings ?? [FakeTaskCheckRunner::passed($evidence)]);
    $task->update(['deliverables' => $deliverables, 'subtask_start_commit' => str_repeat('5', 40)]);
    $fake = new FakeTaskRunReceipts($receipts ?? [FakeTaskRunReceipts::contents('ready_for_review', 'Done.', null, $confirmations)]);
    app()->instance(TaskRunReceipts::class, $fake);

    return [$group, $task->fresh() ?? $task, $checks, $notifier, $fake];
}

/** @return list<array<string, string>> */
function tick_all_deliverables(): array
{
    return [
        ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/*.md', 'change' => 'modified'],
        ['id' => 'export-test', 'type' => 'test', 'description' => 'Test the export', 'project' => 'apps/gateway', 'file' => 'tests/Feature/ExportTest.php', 'name' => 'exports every subtask'],
        ['id' => 'web-tests', 'type' => 'command', 'description' => 'The web tests pass', 'command' => 'bun test', 'directory' => 'apps/web'],
        ['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask'],
    ];
}

/** @return array<string, string> */
function tick_all_confirmations(): array
{
    return ['reference-page' => 'Export section', 'export-test' => 'ExportTest', 'web-tests' => 'bun test passes', 'error-copy' => 'Named in each error'];
}

/**
 * Evidence where every deliverable of tick_all_deliverables() passes, changed by the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tick_evidence(array $overrides = []): array
{
    return [...[
        'start' => str_repeat('5', 40),
        'diff' => [
            ['status' => 'M', 'path' => 'docs/reference/tasks.md'],
            ['status' => 'A', 'path' => 'apps/gateway/tests/Feature/ExportTest.php'],
        ],
        'tests' => ['export-test' => ['exit_code' => 0, 'cases' => [['name' => 'it exports every subtask', 'status' => 'passed']]]],
        'commands' => ['web-tests' => ['exit_code' => 0, 'output' => "12 pass\n"]],
    ], ...$overrides];
}

/** The implementer's reminder after two ticks: the handoff check starts, then passes. */
function tick_deliverable_reminder(): string
{
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    return app(T3Dispatcher::class)->commands[0]['message']['text'] ?? '';
}

describe('subtask deliverables at handoff', function (): void {
    it('asks the check for the diff, the test files, and the commands, then starts the reviewer when every deliverable passes', function (): void {
        [$group, $task, $checks, , $receipts] = tick_deliverables(tick_all_deliverables(), tick_all_confirmations(), tick_evidence());

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($checks->deliverables)->toBe([[
            'start' => str_repeat('5', 40),
            'tests' => [['id' => 'export-test', 'project' => 'apps/gateway', 'file' => 'tests/Feature/ExportTest.php']],
            'commands' => [['id' => 'web-tests', 'command' => 'bun test', 'directory' => 'apps/web']],
        ]])
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
            ->and($task->comments()->sole()->deliverables)->toBe(tick_all_confirmations())
            ->and(TaskCheck::query()->sole()->deliverable_evidence)->toBe(tick_evidence())
            ->and($receipts->turnDeliverables)->toBe([['reference-page', 'export-test', 'web-tests', 'error-copy']]);
    });

    it('skips deliverables for a subtask without any', function (): void {
        [$group, $task, $checks] = tick_deliverables([], [], null);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($checks->deliverables)->toBe([null])
            ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);
    });

    it('returns a failing file deliverable to the implementer with the reason', function (array $diff, string $change, string $reason): void {
        $deliverable = ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/*.md', 'change' => $change];
        [$group, $task] = tick_deliverables([$deliverable], ['reference-page' => 'Done'], ['start' => str_repeat('5', 40), 'diff' => $diff, 'tests' => [], 'commands' => []]);

        $reminder = tick_deliverable_reminder();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($reminder)->toContain("Orbit could not verify these deliverables:\n- reference-page (file): {$reason}")
            ->and($reminder)->toContain('--deliverable=ID=evidence for each deliverable of this subtask (reference-page)');
    })->with([
        'a missing path' => [[['status' => 'M', 'path' => 'docs/guides/tasks.md']], 'any', "no path in the subtask's diff matches docs/reference/*.md."],
        'a nested path the glob does not reach' => [[['status' => 'M', 'path' => 'docs/reference/cli/tasks.md']], 'modified', "no path in the subtask's diff matches docs/reference/*.md."],
        'a modified file that should be created' => [[['status' => 'M', 'path' => 'docs/reference/tasks.md']], 'created', 'the diff modifies docs/reference/tasks.md, but the deliverable needs docs/reference/*.md created.'],
        'a created file that should be modified' => [[['status' => 'A', 'path' => 'docs/reference/export.md']], 'modified', 'the diff adds docs/reference/export.md, but the deliverable needs docs/reference/*.md modified.'],
        'a deleted file' => [[['status' => 'D', 'path' => 'docs/reference/tasks.md']], 'any', 'the diff deletes docs/reference/tasks.md, but the deliverable needs docs/reference/*.md modified.'],
    ]);

    it('returns a failing test deliverable to the implementer with the reason', function (array $overrides, string $reason): void {
        $deliverable = ['id' => 'export-test', 'type' => 'test', 'description' => 'Test the export', 'project' => 'apps/gateway', 'file' => 'tests/Feature/ExportTest.php', 'name' => 'exports every subtask'];
        [$group, $task] = tick_deliverables([$deliverable], ['export-test' => 'ExportTest'], tick_evidence([...['commands' => []], ...$overrides]));

        $reminder = tick_deliverable_reminder();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($reminder)->toContain("- export-test (test): {$reason}");
    })->with([
        'absent from the diff' => [['diff' => [['status' => 'M', 'path' => 'apps/gateway/tests/Feature/OtherTest.php']]], "apps/gateway/tests/Feature/ExportTest.php is not added or modified in the subtask's diff."],
        'failed' => [['tests' => ['export-test' => ['exit_code' => 1, 'cases' => [['name' => 'it exports every subtask', 'status' => 'failed']]]]], 'Orbit ran apps/gateway/tests/Feature/ExportTest.php, and "it exports every subtask" failed.'],
        'only replayed, so never run by the check' => [['tests' => []], "Orbit's check did not run apps/gateway/tests/Feature/ExportTest.php, so the test has no executed result. A replayed or cached result does not count."],
        'without a test of that name' => [['tests' => ['export-test' => ['exit_code' => 0, 'cases' => [['name' => 'it renders', 'status' => 'passed']]]]], 'Orbit ran apps/gateway/tests/Feature/ExportTest.php (exit code 0), and no test name contains "exports every subtask". The run reported "it renders".'],
        'skipped' => [['tests' => ['export-test' => ['exit_code' => 0, 'cases' => [['name' => 'it exports every subtask', 'status' => 'skipped']]]]], 'Orbit ran apps/gateway/tests/Feature/ExportTest.php, and "it exports every subtask" skipped.'],
    ]);

    it('returns a command that exits non-zero to the implementer with the end of its output', function (): void {
        $deliverable = ['id' => 'web-tests', 'type' => 'command', 'description' => 'The web tests pass', 'command' => 'bun test', 'directory' => 'apps/web'];
        [$group, $task] = tick_deliverables([$deliverable], ['web-tests' => 'Passes'], tick_evidence(['commands' => ['web-tests' => ['exit_code' => 1, 'output' => "1 fail\n"]]]));

        $reminder = tick_deliverable_reminder();

        expect($task->fresh()?->status)->toBe(TaskStatus::Running)
            ->and($reminder)->toContain("- web-tests (command): `bun test` in apps/web exited with 1. The end of its output:\n\n```\n1 fail\n```");
    });

    it('fails every mechanical deliverable when the check recorded no evidence', function (): void {
        [$group, $task] = tick_deliverables(tick_all_deliverables(), tick_all_confirmations(), null);

        expect(tick_deliverable_reminder())->toContain("Orbit's check recorded no evidence for the deliverables reference-page, export-test, web-tests.");
    });

    it('refuses a hand-written receipt that does not confirm every deliverable before the check runs', function (): void {
        [$group, $task, $checks] = tick_deliverables(tick_all_deliverables(), ['reference-page' => 'Export section'], tick_evidence());

        app(TaskScheduler::class)->tick();

        expect($checks->starts)->toBe(0)
            ->and(app(T3Dispatcher::class)->commands[0]['message']['text'])->toContain('The run receipt does not confirm the deliverables export-test, web-tests, error-copy. Pass --deliverable=ID=evidence for each one.');
    });

    it('asks for assistance when a deliverable still fails after the reminder', function (): void {
        $failing = FakeTaskCheckRunner::passed(tick_evidence(['commands' => ['web-tests' => ['exit_code' => 2, 'output' => "boom\n"]]]));
        [$group, $task, $checks, $notifier] = tick_deliverables(
            tick_all_deliverables(),
            tick_all_confirmations(),
            null,
            [$failing, $failing],
            [FakeTaskRunReceipts::contents('ready_for_review', 'Done.', null, tick_all_confirmations()), null, FakeTaskRunReceipts::contents('ready_for_review', 'Fixed it.', null, tick_all_confirmations()), null],
        );

        tick_deliverable_reminder();
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        expect($task->fresh()?->assistance_requested)->toBeTrue()
            ->and($notifier->reason)->toStartWith("Checks still failed after the reminder. Orbit could not verify these deliverables:\n- web-tests (command): `bun test` in apps/web exited with 2.")
            ->and($checks->starts)->toBe(2)
            ->and(app(T3Dispatcher::class)->commands)->toHaveCount(1);
    });

    it('reminds a reviewer whose approval does not confirm each review deliverable', function (): void {
        [$group, $task, , $signer] = tick_review([FakeTaskRunReceipts::contents('approved', 'Checked.', null, ['reference-page' => 'Read it'])]);
        $task->update(['deliverables' => tick_all_deliverables()]);

        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe([])
            ->and(app(T3Dispatcher::class)->commands[0]['message']['text'])->toContain('The run receipt does not confirm the deliverables error-copy.')
            ->and(app(T3Dispatcher::class)->commands[0]['message']['text'])->toContain('The approval must confirm each review deliverable (error-copy) with --deliverable=ID=evidence');
    });

    it('commits an approval that confirms each review deliverable', function (): void {
        [$group, $task, , $signer] = tick_review([FakeTaskRunReceipts::contents('approved', 'Checked.', null, ['error-copy' => 'Each error names the subtask'])]);
        $task->update(['deliverables' => tick_all_deliverables()]);

        app(TaskScheduler::class)->tick();

        expect($signer->messages)->toBe(["Models\n\nChecked."])
            ->and($task->comments()->sole()->deliverables)->toBe(['error-copy' => 'Each error names the subtask']);
    });
});
