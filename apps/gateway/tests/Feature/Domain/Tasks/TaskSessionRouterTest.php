<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\NullCoderSettleNotifier;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskSessionActor;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionNextAction;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadObservation;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\Tasks\T3\T3Dispatcher;
use App\Infrastructure\Tasks\T3\T3DispatchException;
use App\Infrastructure\Tasks\T3\T3ModelSelection;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;

function router_group(): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'router-app',
        'slug' => 'router-app',
        'repository_url' => 'git@example.test:router.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'router-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.211',
        'wireguard_ip' => '10.44.0.211',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/srv/orbit/apps/router-app/task-21',
        'branch' => 'task-21',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Execute Jev actions',
        'brief' => 'Drain, advance, escalate, or stay quiet.',
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

function router_observation(TaskGroup $group, ?string $pendingApprovalId = null): TaskSessionObservation
{
    return new TaskSessionObservation(
        taskId: $group->tasks->first()->id,
        taskStatus: 'running',
        taskTitle: 'Models',
        taskBrief: 'Store the records.',
        groupId: $group->id,
        groupStatus: $group->status->value,
        title: $group->title,
        brief: $group->brief,
        hasPendingSubtasks: true,
        prUrl: $group->pr_url,
        ciSummary: null,
        threads: [
            new TaskThreadObservation(
                threadId: $group->tasks->firstOrFail()->implementer_agent_thread_id,
                role: TaskThreadRole::Implementer,
                sessState: $pendingApprovalId === null ? 'idle' : 'asking_for_input',
                idle: $pendingApprovalId === null,
                pendingApprovalId: $pendingApprovalId,
                pendingUserInputId: null,
                lastAssistantText: 'I stopped after the models.',
                lastUserText: 'Implement the models.',
                hasNewCommitsSinceThreadStart: false,
                prUrl: $group->pr_url,
                ciSummary: null,
                inputRequests: $pendingApprovalId === null ? [] : [new AgentInputRequest($pendingApprovalId, 'approval')],
            ),
        ],
    );
}

function router_dispatcher(): T3Dispatcher
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

it('dispatches acceptForSession for a pending approval', function (): void {
    $group = router_group();
    $dispatcher = router_dispatcher();
    $observation = router_observation($group, 'approval-3');

    new TaskSessionActor(test_t3_registry($dispatcher), new NullCoderSettleNotifier)->execute(
        $group,
        $observation,
        new TaskSessionDecision(TaskSessionNextAction::DrainApproval, 0.9, 'Jev selected drain_approval.'),
    );

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('thread.approval.respond')
        ->and($dispatcher->commands[0]['threadId'])->toBe('implementer-thread')
        ->and($dispatcher->commands[0]['requestId'])->toBe('approval-3')
        ->and($dispatcher->commands[0]['decision'])->toBe('acceptForSession');
});

it('surfaces a refused drain instead of swallowing the dispatch', function (): void {
    $group = router_group();
    $dispatcher = new class implements T3Dispatcher
    {
        public function dispatch(Node $node, array $command): array
        {
            throw new T3DispatchException('T3 approval respond failed.');
        }
    };

    expect(fn () => new TaskSessionActor(test_t3_registry($dispatcher), new NullCoderSettleNotifier)->execute(
        $group,
        router_observation($group, 'approval-3'),
        new TaskSessionDecision(TaskSessionNextAction::DrainApproval, 0.9, 'Jev selected drain_approval.'),
    ))->toThrow(T3DispatchException::class, 'T3 approval respond failed.');
});

it('starts an implementer turn with the T3 0.0.42 message struct', function (): void {
    $group = router_group();
    $dispatcher = router_dispatcher();

    new TaskSessionActor(test_t3_registry($dispatcher), new NullCoderSettleNotifier)->execute(
        $group,
        router_observation($group),
        new TaskSessionDecision(TaskSessionNextAction::ContinueImplementer, 0.86, 'Jev selected continue_implementer.'),
    );

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('thread.turn.start')
        ->and($dispatcher->commands[0]['message'])->toMatchArray([
            'role' => 'user',
            'attachments' => [],
        ])
        ->and($dispatcher->commands[0]['message']['text'])->toContain('Do not expand scope.')
        ->and($dispatcher->commands[0]['modelSelection'])->toBe(T3ModelSelection::forModel(TaskAgentDefaults::ImplementerModel, TaskAgentDefaults::ImplementerEffort))
        ->and($dispatcher->commands[0]['runtimeMode'])->toBe('full-access')
        ->and($dispatcher->commands[0]['interactionMode'])->toBe('default');
});

it('notifies Coder only when Jev escalates', function (): void {
    $group = router_group();
    $dispatcher = router_dispatcher();
    $notifier = new class implements CoderSettleNotifier
    {
        public ?TaskGroup $escalated = null;

        public ?TaskSessionDecision $decision = null;

        public function notify(TaskGroup $group): void {}

        public function escalate(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->escalated = $group;
            $this->decision = $decision;
        }

        public function assistance(TaskGroup $group, string $reason): void {}
    };
    $decision = new TaskSessionDecision(TaskSessionNextAction::EscalateCoder, 0.2, 'Choice confidence 0.2 is below 0.75.');

    new TaskSessionActor(test_t3_registry($dispatcher), $notifier)->execute($group, router_observation($group), $decision);

    expect($dispatcher->commands)->toBe([])
        ->and($notifier->escalated?->id)->toBe($group->id)
        ->and($notifier->decision?->reason)->toBe('Choice confidence 0.2 is below 0.75.');
});

it('dispatches nothing for noop', function (): void {
    $group = router_group();
    $dispatcher = router_dispatcher();
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

        public function assistance(TaskGroup $group, string $reason): void {}
    };

    new TaskSessionActor(test_t3_registry($dispatcher), $notifier)->execute(
        $group,
        router_observation($group),
        new TaskSessionDecision(TaskSessionNextAction::Noop, 0.95, 'Jev selected noop.'),
    );

    expect($dispatcher->commands)->toBe([])
        ->and($notifier->called)->toBeFalse();
});
