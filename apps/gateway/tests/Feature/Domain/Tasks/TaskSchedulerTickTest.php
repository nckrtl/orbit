<?php

declare(strict_types=1);

use App\Actions\Tasks\StoreTaskCommentAction;
use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\NullAgentSpawner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskJevDecision;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskSessionClassifier;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskSettleMetrics;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTranscriptCheck;
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

/** @return list<array<string, ChoiceAnswer>> */
function tick_transcript(string $invoked = 'yes', string $passed = 'yes', string $current = 'yes', string $blocked = 'no', float $confidence = 0.95): array
{
    return [[
        'check_invoked' => new ChoiceAnswer($invoked, [], $confidence),
        'check_passed' => new ChoiceAnswer($passed, [], $confidence),
        'check_current' => new ChoiceAnswer($current, [], $confidence),
        'blocked' => new ChoiceAnswer($blocked, [], $confidence),
    ]];
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

it('requests assistance without approving a pending request when tool evidence is missing', function (): void {
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
    $answers = tick_transcript(invoked: 'no', passed: 'no', current: 'no', blocked: 'yes')[0];
    Classification::fake([$answers, $answers]);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain('waiting for input')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('composer check was not found')
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->tasks()->first()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toHaveCount(1);
});

it('requests assistance without calling a dispatcher that would fail on approval', function (): void {
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
    Classification::fake(tick_transcript());

    app(TaskScheduler::class)->tick();

    expect($group->tasks()->first()?->communication_failures)->toBe(1)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($notifier->reason)->toBeNull()
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running);
});

it('keeps the current subtask running when completion has no typed handoff or tool evidence', function (): void {
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
            return tick_checked_thread('done');
        }
    });
    app()->instance(AgentSpawner::class, $spawner);
    Classification::fake(tick_transcript());

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Reviewing)
        ->and($group->fresh()?->tasks->first()?->status)->toBe(TaskStatus::Reviewing)
        ->and($spawner->reviews)->toBe(1);
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
        public function classifyOutcome(TaskSessionObservation $observation, TaskThreadRole $role): TaskJevDecision
        {
            throw new LogicException('Not expected.');
        }

        public function classifyTranscript(TaskSessionObservation $observation, TaskThreadRole $role): array
        {
            throw new TaskSessionClassificationException(
                'TYPESAFE_API_KEY is missing. Task session routing will not invent a next action.',
            );
        }
    });

    app(TaskScheduler::class)->tick();

    expect($group->tasks()->first()?->communication_failures)->toBe(1)
        ->and($notifier->reason)->toBeNull()
        ->and($dispatcher->commands)->toBe([])
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()?->assistance_requested)->toBeFalse();
});

it('requests assistance without a follow-up turn when an idle snapshot has no tool evidence', function (): void {
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
    Classification::fake(tick_transcript(invoked: 'no', passed: 'no', current: 'no'));

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain('composer check was not found')
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
        public function classifyOutcome(TaskSessionObservation $observation, TaskThreadRole $role): TaskJevDecision
        {
            throw new LogicException('Active tasks must not call Jev.');
        }

        public function classifyTranscript(TaskSessionObservation $observation, TaskThreadRole $role): array
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

it('targets the idle in-progress task while another task is working', function (): void {
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
    $classifier = new class implements TaskSessionClassifier
    {
        /** @var list<TaskSessionObservation> */
        public array $observations = [];

        public function classifyOutcome(TaskSessionObservation $observation, TaskThreadRole $role): TaskJevDecision
        {
            throw new LogicException('Missing tool evidence must not call Jev.');
        }

        public function classifyTranscript(TaskSessionObservation $observation, TaskThreadRole $role): array
        {
            return [
                'check_invoked' => new TaskTranscriptCheck('check_invoked', 'no', 0.95),
                'check_passed' => new TaskTranscriptCheck('check_passed', 'no', 0.95),
                'check_current' => new TaskTranscriptCheck('check_current', 'no', 0.95),
                'blocked' => new TaskTranscriptCheck('blocked', 'no', 0.95),
            ];
        }
    };
    app()->instance(TaskSessionClassifier::class, $classifier);

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions)->toBe([])
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($workingTask->fresh()->status)->toBe(TaskStatus::Reviewing)
        ->and($idleTask->fresh()->status)->toBe(TaskStatus::Running);
});

it('asks for assistance when implementer checks still fail after one reminder', function (): void {
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
    $answers = tick_transcript(invoked: 'no')[0];
    Classification::fake([$answers, $answers]);

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain('composer check was not found')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('composer check did not pass')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('change to the tree');

    app(TaskScheduler::class)->tick();

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($notifier->reason)->toContain('Checks still failed')
        ->and($notifier->reason)->toContain('composer check was not found')
        ->and($dispatcher->commands)->toHaveCount(1);
});

it('retries the reviewer nudge until the handoff send succeeds', function (): void {
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    $spawner = new class implements AgentSpawner
    {
        public int $reviews = 0;

        public function spawnReviewer(TaskGroup $group): ?int
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

        public function signOff(Task $task): ?string
        {
            return null;
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
    Classification::fake(tick_transcript());

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
    Classification::fake([['blocked' => new ChoiceAnswer('no', [], 0.95)]]);

    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toBe([])
        ->and($task->fresh()?->status)->toBe(TaskStatus::Reviewing);

    $reader->turnId = 'turn-new';
    app(TaskScheduler::class)->tick();

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['message']['text'])->toContain('changes_requested or approved');
})->with(['turn-old', null, '']);

it('retries review findings until the implementer receives them', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing]);
    $task->comments()->create([
        'task_group_id' => $group->id,
        'type' => 'changes_requested',
        'body' => 'Add the missing test.',
        'author' => 'reviewer',
        'review_attempt' => $task->review_attempt,
        'posted_at' => now(),
    ]);
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
            return ['thread' => ['session' => ['status' => 'done']]];
        }
    });

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Reviewing)
        ->and($task->fresh()?->communication_failures)->toBe(1)
        ->and($group->fresh()?->assistance_requested)->toBeFalse();

    app(TaskScheduler::class)->tick();

    expect($task->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Running)
        ->and($dispatcher->commands[1]['message']['text'])->toContain('Add the missing test.');
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
    Classification::fake();
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
    Classification::fake([['blocked' => new ChoiceAnswer('no', [], 0.95)], ['blocked' => new ChoiceAnswer('no', [], 0.95)]]);
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

        public function classifyTranscript(TaskSessionObservation $observation, TaskThreadRole $role): array
        {
            return ['blocked' => new TaskTranscriptCheck('blocked', 'no', 0.95)];
        }
    });
    app(TaskScheduler::class)->tick();
    expect($dispatcher->commands)->toBe([]);
    expect($group->fresh()->agent_unavailable_since)->not->toBeNull();
});
it('an unavailable reviewer cannot advance an approval', function (): void {
    $group = tick_final_review();
    AgentThread::query()->where('task_group_id', $group->id)->update(['state' => 'done']);
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    });
    mock(CoderSettleNotifier::class)->shouldReceive('notify');
    Http::fake([
        'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response(tick_reviewed_pull_request($group)),
        'https://api.github.com/repos/acme/orbit' => Http::response(['default_branch' => 'main']),
    ]);
    app(TaskScheduler::class)->tick();
    expect($group->tasks()->sole()->status)->toBe(TaskStatus::Reviewing);
});
it('relayed findings require a newer implementer turn and a new check before review', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing]);
    $task->comments()->create([
        'task_group_id' => $group->id, 'type' => 'changes_requested',
        'body' => 'Fix the regression before requesting review again.',
        'author' => 'reviewer', 'review_attempt' => $task->review_attempt, 'posted_at' => now(),
    ]);
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
    $answer = ['blocked' => new ChoiceAnswer('no', [], 0.95)];
    Classification::fake([$answer, $answer]);

    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect(app(T3Dispatcher::class)->commands)->toHaveCount(1);
    Classification::assertNothingClassified();

    $reader->turnId = 'after-findings';
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect(app(T3Dispatcher::class)->commands[1]['message']['text'])->toContain('latest review findings');

    $reader->checkId = 'check-after-findings';
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->status)->toBe(TaskStatus::Reviewing);
});

it('retains reminder send failures across successful classifications and clears them on delivery', function (TaskStatus $status): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $group->update(['status' => $status === TaskStatus::Reviewing ? TaskGroupStatus::Reviewing : TaskGroupStatus::Running]);
    $task->update(['status' => $status, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'old-turn']);
    app(TaskExtensionState::class)->enable();
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
    $answer = ['blocked' => new ChoiceAnswer('no', [], 0.95)];
    Classification::fake([$answer, $answer, $answer]);

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->communication_failures)->toBe(2);

    $dispatcher->fails = false;
    app(TaskScheduler::class)->tick();
    expect($task->fresh()->communication_failures)->toBe(0);
    expect($group->fresh()->assistance_requested)->toBeFalse();
})->with([TaskStatus::Running, TaskStatus::Reviewing]);

it('keeps check evidence from before the findings invalid after assistance is resolved', function (): void {
    $group = tick_group();
    $task = $group->tasks->sole();
    $task->update([
        'completion_handoff_attempt' => $task->completion_attempt,
        'completion_handoff_turn_id' => 'before-findings',
        'completion_handoff_check_id' => 'check-1',
        'assistance_requested' => true,
    ]);
    $group->update(['assistance_requested' => true]);
    app(TaskExtensionState::class)->enable();
    app()->instance(T3Dispatcher::class, tick_dispatcher());
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            $snapshot = tick_checked_thread('done');
            $snapshot['thread']['latestTurn'] = ['id' => 'after-findings', 'state' => 'completed'];

            return $snapshot;
        }
    });
    Classification::fake([['blocked' => new ChoiceAnswer('no', [], 0.95)]]);

    app(StoreTaskCommentAction::class)->execute($task, [
        'type' => 'resolution', 'body' => 'Continue with the review findings.', 'author' => 'operator',
    ]);
    app(TaskScheduler::class)->tick();

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    expect($group->fresh()->assistance_requested)->toBeFalse();
    expect(app(T3Dispatcher::class)->commands[1]['message']['text'])->toContain('latest review findings');
});
