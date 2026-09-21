<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskSessionClassifier;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionNextAction;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3DispatchException;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

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
        ->and($decisions[0]->action)->toBe(TaskSessionNextAction::DrainApproval)
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('thread.approval.respond')
        ->and($dispatcher->commands[0]['requestId'])->toBe('approval-tick')
        ->and($dispatcher->commands[0]['decision'])->toBe('acceptForSession')
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
        ->and($decisions[0]->reason)->toBe('T3 approval respond failed.')
        ->and($notifier->reason)->toBe('T3 approval respond failed.')
        ->and($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('thread.approval.respond')
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

    expect($decisions[0]->action)->toBe(TaskSessionNextAction::MarkSubtaskDone)
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
    });

    $decisions = app(TaskScheduler::class)->tick();

    expect($decisions[0]->action)->toBe(TaskSessionNextAction::EscalateCoder)
        ->and($decisions[0]->reason)->toContain('TYPESAFE_API_KEY is missing')
        ->and($notifier->reason)->toContain('TYPESAFE_API_KEY is missing')
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

    expect($decisions[0]->action)->toBe(TaskSessionNextAction::Noop)
        ->and($dispatcher->commands)->toBe([])
        ->and($notifier->called)->toBeFalse();
});

it('runs the artisan tick while the extension is enabled', function (): void {
    app(TaskExtensionState::class)->enable();

    $this->artisan('tasks:tick')
        ->expectsOutput('Routed [0] task groups.')
        ->assertSuccessful();
});
