<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\NullAgentSpawner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskJevDecision;
use App\Domain\Tasks\TaskJevOutcome;
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
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3DispatchException;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

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

function tick_final_review(string $url = 'https://github.com/acme/orbit/pull/42'): TaskGroup
{
    $group = tick_group();
    $group->app->update(['repository_url' => 'https://github.com/acme/orbit.git']);
    $group->update(['status' => TaskGroupStatus::Reviewing, 'notify_coder' => true]);
    $task = $group->tasks->sole();
    $task->update(['status' => TaskStatus::Reviewing, 'subtask_start_commit' => str_repeat('b', 40)]);
    $task->comments()->create([
        'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Approved with the final PR.',
        'author' => 'reviewer', 'review_attempt' => $task->review_attempt,
        'reviewer_thread_id' => $group->reviewer_agent_thread_id, 'driver_turn' => 'approved-turn',
        'commit_sha' => str_repeat('a', 40), 'pr_url' => $url, 'posted_at' => now(),
    ]);
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, tick_dispatcher());
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    mock(TaskWorkspaceStateReader::class)->shouldReceive([
        'headCommit' => str_repeat('a', 40), 'currentBranch' => 'task-'.$group->id, 'isClean' => true,
    ]);
    mock(TaskWorkspaceDiffReader::class)->shouldReceive('hasCommitsSince')->andReturnTrue();
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));
    config()->set('orbit.tasks.github_token', 'token');
    Http::preventStrayRequests();

    return $group->fresh(['app', 'tasks', 'taskable']);
}

/** @return array<string, mixed> */
function tick_reviewed_pull_request(TaskGroup $group): array
{
    return [
        'number' => 42, 'html_url' => 'https://github.com/acme/orbit/pull/42',
        'state' => 'open', 'merged' => false,
        'base' => ['repo' => ['full_name' => 'acme/orbit'], 'ref' => 'main'],
        'head' => ['repo' => ['full_name' => 'acme/orbit'], 'ref' => 'task-'.$group->id, 'sha' => str_repeat('a', 40)],
    ];
}

it('stores the final approved PR before settling and watches that same PR on later ticks', function (): void {
    $group = tick_final_review();
    $task = $group->tasks->sole();
    $comment = $task->comments()->sole();
    mock(CoderSettleNotifier::class)->shouldReceive('notify')->once()->withArgs(
        fn (TaskGroup $settled): bool => $settled->pr_url === $comment->pr_url && $settled->tokens === 40,
    );
    Http::fake([
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(tick_reviewed_pull_request($group)),
        'https://api.github.com/repos/acme/orbit' => Http::response(['default_branch' => 'main']),
    ]);
    expect($group->pr_url)->toBeNull();

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', [
        'id' => $group->id, 'status' => TaskGroupStatus::Settling->value,
        'pr_url' => 'https://github.com/acme/orbit/pull/42', 'assistance_requested' => false,
        'tokens' => 40, 'line_diff' => 12, 'duration_ms' => 1500,
    ]);
    $this->assertDatabaseHas('tasks', [
        'id' => $task->id, 'status' => TaskStatus::Completed->value, 'review_handled_comment_id' => $comment->id,
    ]);
    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('keeps an unverified final approval in review and requests assistance after one reminder', function (string $failure): void {
    $url = match ($failure) {
        'wrong host' => 'https://evil.example/acme/orbit/pull/42',
        'wrong repository' => 'https://github.com/acme/other/pull/42',
        default => 'https://github.com/acme/orbit/pull/42',
    };
    $group = tick_final_review($url);
    $task = $group->tasks->sole();
    mock(CoderSettleNotifier::class)->shouldReceive('assistance')->once();
    if ($failure === 'no credentials') {
        config()->set('orbit.tasks.github_token', null);
    }
    Http::fake([
        'https://api.github.com/repos/acme/orbit/pulls/42' => $failure === 'network' ? Http::failedConnection() : Http::response([], 503),
        'https://api.github.com/repos/acme/orbit' => Http::response(['default_branch' => 'main']),
    ]);

    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'reviewing', 'pr_url' => null, 'assistance_requested' => false]);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'reviewing', 'review_handled_comment_id' => null]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'reviewing', 'pr_url' => null, 'assistance_requested' => true]);
    expect(app(T3Dispatcher::class)->commands)->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->with(['wrong host', 'wrong repository', 'no credentials', 'HTTP', 'network']);

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
    expect(Activity::query()->where('description', 'assistance requested')->sole()->subject_type)->toBe(TaskGroup::class);
    expect($group->tasks()->sole()->assistance_requested)->toBeFalse();
    Http::assertNothingSent();
});

it('continues watching a prior settling PR and completes only after it merges', function (): void {
    $group = tick_group();
    $group->app->update(['repository_url' => 'https://github.com/acme/orbit.git']);
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    app(TaskExtensionState::class)->enable();
    config()->set('orbit.tasks.github_token', 'token');
    mock(AppInstanceRemover::class)->shouldReceive('execute')->once()->withArgs(
        fn (AppInstance $instance, bool $force): bool => $instance->id === $group->taskable_id && $force,
    )->andReturnUsing(function (AppInstance $instance): AppInstanceRemoval {
        $instance->delete();

        return new AppInstanceRemoval;
    });
    Http::preventStrayRequests();
    Http::fakeSequence('https://api.github.com/repos/acme/orbit/pulls/42')
        ->push(['merged' => false, 'state' => 'open'])
        ->push(['merged' => true, 'state' => 'closed']);

    app(TaskScheduler::class)->tick();
    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'settling', 'pr_url' => $group->pr_url]);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $this->assertDatabaseHas('task_groups', ['id' => $group->id, 'status' => 'completed', 'pr_url' => $group->pr_url, 'taskable_id' => null]);
    $this->assertDatabaseMissing('app_instances', ['id' => $group->taskable_id]);
    Http::assertSentCount(2);
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
    Classification::fake([[
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::DrainApproval->value, [], 0.9),
    ]]);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toHaveCount(1)
        ->and($decisions[0]->action)->toBe(TaskSessionNextAction::EscalateCoder)
        ->and($dispatcher->commands)->toHaveCount(0)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
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
    Classification::fake([[
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::DrainApproval->value, [], 0.9),
    ]]);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions[0]->action)->toBe(TaskSessionNextAction::EscalateCoder)
        ->and($decisions[0]->reason)->toContain('composer check')
        ->and($notifier->reason)->toContain('composer check')
        ->and($dispatcher->commands)->toHaveCount(0)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
});

it('advances the current subtask when Jev marks it done', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
    $spawner = new class implements AgentSpawner
    {
        public int $reviews = 0;

        public function spawnReviewer(TaskGroup $group): ?int
        {
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

        public function signOff(Task $task): ?string
        {
            return 'sha';
        }
    };
    app()->instance(T3Dispatcher::class, $dispatcher);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done']]];
        }
    });
    app()->instance(AgentSpawner::class, $spawner);
    Classification::fake([[
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::MarkSubtaskDone->value, [], 0.92),
    ]]);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions[0]->action)->toBe(TaskSessionNextAction::EscalateCoder)
        ->and($dispatcher->commands)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->tasks->first()?->status)->toBe(TaskStatus::Running)
        ->and($spawner->reviews)->toBe(0);
});

it('notifies Coder when classification fails closed', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $dispatcher = tick_dispatcher();
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
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });
    app()->instance(CoderSettleNotifier::class, $notifier);
    app()->instance(TaskSessionClassifier::class, new class implements TaskSessionClassifier
    {
        public function classify(TaskSessionObservation $observation): TaskSessionDecision
        {
            throw new TaskSessionClassificationException(
                'TYPESAFE_API_KEY is missing. Task session routing will not invent a next action.',
            );
        }

        public function classifyOutcome(TaskSessionObservation $observation, TaskThreadRole $role): TaskJevDecision
        {
            throw new LogicException('Not expected.');
        }
    });

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions[0]->action)->toBe(TaskSessionNextAction::EscalateCoder)
        ->and($decisions[0]->reason)->toContain('composer check')
        ->and($notifier->reason)->toContain('composer check')
        ->and($dispatcher->commands)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
});

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
    Classification::fake([[
        'next_action' => new ChoiceAnswer(TaskSessionNextAction::Noop->value, [], 0.97),
    ]]);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions[0]->action)->toBe(TaskSessionNextAction::EscalateCoder)
        ->and($dispatcher->commands)->toBe([])
        ->and($notifier->called)->toBeTrue();
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
})->with([TaskStatus::Pending, TaskStatus::Reserved, TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled]);

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

    expect($decisions)->toHaveCount(1)
        ->and($dispatcher->commands)->toBe([])
        ->and($workingTask->fresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($idleTask->fresh()->status)->toBe(TaskStatus::Running);
})->with([TaskSessionNextAction::ContinueImplementer, TaskSessionNextAction::MarkSubtaskDone]);
