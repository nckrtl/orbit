<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
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
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Tests\Support\AgentCommandDispatcher;

function router_group(): Task
{
    $project = Project::query()->create([
        'name' => 'router-app',
        'slug' => 'router-app',
        'repository_url' => 'git@example.test:router.git',
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => 'router-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.211',
        'wireguard_ip' => '10.44.0.211',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/srv/orbit/apps/router-app/task-21',
        'branch' => 'task-21',
        'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Execute Jev actions',
        'brief' => 'Drain, advance, escalate, or stay quiet.',
        'status' => TaskGroupStatus::Running,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Models',
        'brief' => 'Store the records.',
        'status' => TaskStatus::Running,
        'started_at' => now(),
    ]);

    test_link_agent_threads($group);

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

function router_observation(Task $group, ?string $pendingApprovalId = null): TaskSessionObservation
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

function router_dispatcher(): AgentCommandDispatcher
{
    return new class implements AgentCommandDispatcher
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

    new TaskSessionActor(test_snapshot_registry($dispatcher), new NullCoderSettleNotifier)->execute(
        $group,
        $observation,
        new TaskSessionDecision(TaskSessionNextAction::DrainApproval, 0.9, 'Jev selected drain_approval.'),
    );

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('respond')
        ->and($dispatcher->commands[0]['threadId'])->toBe('implementer-thread')
        ->and($dispatcher->commands[0]['requestId'])->toBe('approval-3')
        ->and($dispatcher->commands[0]['answers'])->toBe(['approve' => true]);
});

it('surfaces a refused drain instead of swallowing the dispatch', function (): void {
    $group = router_group();
    $dispatcher = new class implements AgentCommandDispatcher
    {
        public function dispatch(Node $node, array $command): array
        {
            throw new AgentDriverException('Agent approval respond failed.');
        }
    };

    expect(fn () => new TaskSessionActor(test_snapshot_registry($dispatcher), new NullCoderSettleNotifier)->execute(
        $group,
        router_observation($group, 'approval-3'),
        new TaskSessionDecision(TaskSessionNextAction::DrainApproval, 0.9, 'Jev selected drain_approval.'),
    ))->toThrow(AgentDriverException::class, 'Agent approval respond failed.');
});

it('starts an implementer turn through the agent driver', function (): void {
    $group = router_group();
    $dispatcher = router_dispatcher();

    new TaskSessionActor(test_snapshot_registry($dispatcher), new NullCoderSettleNotifier)->execute(
        $group,
        router_observation($group),
        new TaskSessionDecision(TaskSessionNextAction::ContinueImplementer, 0.86, 'Jev selected continue_implementer.'),
    );

    expect($dispatcher->commands)->toHaveCount(1)
        ->and($dispatcher->commands[0]['type'])->toBe('send')
        ->and($dispatcher->commands[0]['message']['text'])->toContain('Do not expand scope.')
        ->and($dispatcher->commands[0]['model'])->toBe(TaskAgentDefaults::ImplementerModel)
        ->and($dispatcher->commands[0]['effort'])->toBe(config('orbit.tasks.implementer_effort'));
});

it('notifies Coder only when Jev escalates', function (): void {
    $group = router_group();
    $dispatcher = router_dispatcher();
    $notifier = new class implements CoderSettleNotifier
    {
        public ?Task $escalated = null;

        public ?TaskSessionDecision $decision = null;

        public function notify(Task $group): void {}

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->escalated = $group;
            $this->decision = $decision;
        }

        public function assistance(Task $group, string $reason): void {}
    };
    $decision = new TaskSessionDecision(TaskSessionNextAction::EscalateCoder, 0.2, 'Choice confidence 0.2 is below 0.75.');

    new TaskSessionActor(test_snapshot_registry($dispatcher), $notifier)->execute($group, router_observation($group), $decision);

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

        public function notify(Task $group): void
        {
            $this->called = true;
        }

        public function escalate(Task $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
        {
            $this->called = true;
        }

        public function assistance(Task $group, string $reason): void {}
    };

    new TaskSessionActor(test_snapshot_registry($dispatcher), $notifier)->execute(
        $group,
        router_observation($group),
        new TaskSessionDecision(TaskSessionNextAction::Noop, 0.95, 'Jev selected noop.'),
    );

    expect($dispatcher->commands)->toBe([])
        ->and($notifier->called)->toBeFalse();
});
