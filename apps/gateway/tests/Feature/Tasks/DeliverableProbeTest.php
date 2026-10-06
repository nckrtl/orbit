<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskCheckAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RunTaskDeliverableProbeAction;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;

/** @return array{Task, Task} */
function deliverable_probe_task(): array
{
    $project = Project::query()->create([
        'name' => 'probe', 'slug' => 'probe', 'repository_url' => 'git@example.test:probe.git',
        'default_branch' => 'main', 'task_check' => 'composer check',
    ]);
    $node = Node::query()->create([
        'name' => 'probe-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.210', 'wireguard_ip' => '10.44.0.210',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'probe',
        'checkout_path' => '/tmp/probe', 'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Probe group', 'brief' => 'Dry-run declared deliverables.',
        'status' => TaskGroupStatus::Running, 'execution_mode' => TaskExecutionMode::Managed,
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id, 'position' => 1, 'title' => 'Probe task', 'brief' => 'Run the declared test.',
        'status' => TaskStatus::Running, 'started_at' => now(), 'subtask_start_commit' => str_repeat('c', 40),
        'deliverables' => [
            ['id' => 'test', 'type' => 'command', 'description' => 'Focused test', 'command' => 'vendor/bin/pest --filter=ExactTest',
                'directory' => 'apps/gateway', 'fails_on_base' => true, 'paths' => ['apps/gateway/tests/Feature/ExactTest.php']],
            ['id' => 'other', 'type' => 'command', 'description' => 'Another test', 'command' => 'false', 'directory' => '.'],
            ['id' => 'file', 'type' => 'file', 'description' => 'A file', 'path' => 'file.php', 'change' => 'modified'],
            ['id' => 'review', 'type' => 'review', 'description' => 'Review it'],
        ],
    ]);
    test_link_agent_threads($group->fresh(['tasks', 'taskable']));
    app(TaskExtensionState::class)->enable();

    return [$group->fresh(['tasks', 'taskable', 'project']), $task->fresh()];
}

function deliverable_probe_refused(Closure $call, int $status): void
{
    try {
        $call();
        test()->fail('Expected the probe to be refused.');
    } catch (ResourceOperationException $exception) {
        expect($exception->status)->toBe($status);
    }
}

describe('DeliverableProbe', function (): void {
    it('starts only the declared command verbatim with no project check or setup', function (bool $base): void {
        [$group, $task] = deliverable_probe_task();
        $runner = new FakeTaskCheckRunner;
        app()->instance(TaskCheckRunner::class, $runner);
        $check = app(RunTaskDeliverableProbeAction::class)->execute($group, $task, 'test', $base, $task->implementerThread);
        $command = ['id' => 'test', 'command' => 'vendor/bin/pest --filter=ExactTest', 'directory' => 'apps/gateway'];
        if ($base) {
            $command += ['fails_on_base' => true, 'paths' => ['apps/gateway/tests/Feature/ExactTest.php']];
        }
        $comment = TaskComment::query()->findOrFail($check->task_comment_id);
        expect($check->kind)->toBe(TaskCheckKind::Probe)
            ->and($check->status)->toBe(TaskCheckStatus::Running)
            ->and($comment->type)->toBe(TaskCommentType::DeliverableProbe)
            ->and($comment->agent_thread_id)->toBe($task->implementer_agent_thread_id)
            ->and($comment->completion_attempt)->toBe($task->completion_attempt)
            ->and($runner->commands)->toBe([''])
            ->and($runner->setups)->toBe([[]])
            ->and($runner->deliverables)->toBe([['start' => str_repeat('c', 40), 'commands' => [$command]]]);
    })->with([false, true]);

    it('refuses any running check across the group', function (string $kind): void {
        [$group, $task] = deliverable_probe_task();
        $other = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Other', 'brief' => 'Other', 'status' => TaskStatus::Todo]);
        TaskCheck::query()->create([
            'task_id' => $other->id, 'kind' => $kind, 'status' => TaskCheckStatus::Running,
            'pid' => 123, 'process_started' => 'started', 'head_before' => '', 'tree_before' => '', 'started_at' => now(),
        ]);
        deliverable_probe_refused(fn () => app(RunTaskDeliverableProbeAction::class)->execute($group, $task, 'test'), 409);
        expect(TaskComment::query()->count())->toBe(0);
    })->with(['baseline', 'handoff', 'probe']);

    it('refuses a second probe until the first finishes', function (): void {
        [$group, $task] = deliverable_probe_task();
        $action = app(RunTaskDeliverableProbeAction::class);
        $action->execute($group, $task, 'test');
        deliverable_probe_refused(fn () => $action->execute($group, $task, 'other'), 409);
        expect(TaskCheck::query()->count())->toBe(1);
    });

    it('rejects non-command and unknown deliverables', function (string $id, int $status): void {
        [$group, $task] = deliverable_probe_task();
        deliverable_probe_refused(fn () => app(RunTaskDeliverableProbeAction::class)->execute($group, $task, $id), $status);
        expect(TaskCheck::query()->count())->toBe(0);
    })->with([['file', 422], ['review', 422], ['unknown', 404]]);

    it('limits all deliverables together to three probes per completion attempt', function (): void {
        [$group, $task] = deliverable_probe_task();
        $action = app(RunTaskDeliverableProbeAction::class);
        foreach (['test', 'other', 'test'] as $id) {
            $action->execute($group, $task, $id);
            $action->reconcile($group);
        }
        deliverable_probe_refused(fn () => $action->execute($group, $task, 'test'), 429);
        $task->increment('completion_attempt');
        $action->execute($group, $task, 'test');
        expect(TaskCheck::query()->count())->toBe(4);
    });

    it('requires a running subtask and its implementer or an operator', function (): void {
        [$group, $task] = deliverable_probe_task();
        $action = app(RunTaskDeliverableProbeAction::class);
        deliverable_probe_refused(fn () => $action->execute($group, $task, 'test', thread: $group->reviewerThread), 403);
        $task->update(['status' => TaskStatus::Reviewing]);
        deliverable_probe_refused(fn () => $action->execute($group, $task, 'test'), 409);
        expect(TaskCheck::query()->count())->toBe(0);
    });

    it('records a failing command receipt without changing attempts reminders or assistance on ticks', function (): void {
        [$group, $task] = deliverable_probe_task();
        test_bind_snapshot_driver();
        app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
        {
            public function snapshot(Node $node, string $threadId): ?array
            {
                return ['thread' => ['session' => ['status' => 'running']]];
            }
        });
        $output = str_repeat('a', 20000)."\nFAILED\n";
        // The empty project COMMAND succeeds; the declared command's evidence carries the actual failure.
        $runner = new FakeTaskCheckRunner([TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('b', 40), [], $output,
            deliverables: ['commands' => ['test' => ['exit_code' => 1, 'base_exit_code' => 2]]],
            execution: ['managed_user' => 'orbit', 'uid' => 1001, 'tmpdir' => '/tmp/orbit-check-1001-test'])]);
        app()->instance(TaskCheckRunner::class, $runner);
        $check = app(RunTaskDeliverableProbeAction::class)->execute($group, $task, 'test', true);
        $before = $task->only(['completion_attempt', 'completion_reminder_attempt', 'completion_reminder_input_id', 'completion_handoff_comment_id']);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        $receipt = json_decode(TaskComment::query()->findOrFail($check->task_comment_id)->body, true, flags: JSON_THROW_ON_ERROR);
        expect($check->fresh()->status)->toBe(TaskCheckStatus::Failed)
            ->and($check->fresh()->exit_code)->toBe(1)
            ->and($check->fresh()->output)->toBe($output)
            ->and($task->fresh()->only(array_keys($before)))->toBe($before)
            ->and($task->fresh()->assistance_requested)->toBeFalse()
            ->and($group->fresh()->assistance_requested)->toBeFalse()
            ->and($receipt)->toMatchArray([
                'check_id' => $check->id, 'kind' => 'probe', 'deliverable' => 'test',
                'command' => 'vendor/bin/pest --filter=ExactTest', 'directory' => 'apps/gateway',
                'managed_user' => 'orbit', 'uid' => 1001, 'tmpdir' => '/tmp/orbit-check-1001-test',
                'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40), 'exit_code' => 1, 'base_exit_code' => 2,
            ])
            ->and(strlen($receipt['output_tail']))->toBeLessThanOrEqual(16384)
            ->and($receipt['output_tail'])->toEndWith("FAILED\n")
            ->and($receipt['started_at'])->not->toBeEmpty()
            ->and($receipt['finished_at'])->not->toBeEmpty();
    });

    it('keeps an ambiguous start reserved and recovers it without another execution or quota reset', function (string $failure): void {
        [$group, $task] = deliverable_probe_task();
        $failed = TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('b', 40), [], "FAILED\n",
            deliverables: ['commands' => ['test' => ['exit_code' => 1]]],
            execution: ['managed_user' => 'orbit', 'uid' => 1001, 'tmpdir' => '/tmp/orbit-check-1001-recovery']);
        $runner = new FakeTaskCheckRunner([$failed]);
        $level = DB::transactionLevel();
        $fault = (object) ['active' => $failure === 'identity_write'];
        DB::connection()->beforeExecuting(function (string $query) use ($fault): void {
            if ($fault->active && str_starts_with($query, 'update "task_checks" set "pid"')) {
                $fault->active = false;
                throw new RuntimeException('Injected process identity write failure.');
            }
        });
        $runner->afterStart = function () use ($failure, $level): void {
            // RefreshDatabase's outer test transaction is still open, but the action's reservation transaction is not.
            expect(DB::transactionLevel())->toBe($level)
                ->and(TaskCheck::query()->sole()->pid)->toBe(0)
                ->and(TaskComment::query()->sole()->type)->toBe(TaskCommentType::DeliverableProbe);
            if ($failure === 'reply_lost') {
                throw new TaskCheckException('Injected lost SSH start response.');
            }
            if ($failure === 'gateway_crash') {
                throw new RuntimeException('Injected Gateway crash before process identity persistence.');
            }
        };
        app()->instance(TaskCheckRunner::class, $runner);
        $action = app(RunTaskDeliverableProbeAction::class);
        if ($failure === 'reply_lost') {
            deliverable_probe_refused(fn () => $action->execute($group, $task, 'test'), 502);
        } else {
            expect(fn () => $action->execute($group, $task, 'test'))->toThrow(RuntimeException::class, 'Injected');
        }
        $claim = TaskCheck::query()->sole();
        expect($claim->status)->toBe(TaskCheckStatus::Running)
            ->and($claim->pid)->toBe(0)
            ->and(TaskComment::query()->count())->toBe(1);
        deliverable_probe_refused(fn () => $action->execute($group, $task, 'other'), 409);
        deliverable_probe_refused(fn () => app(CancelTaskCheckAction::class)->execute($group, $task), 409);

        $runner->afterStart = null;
        $action->reconcile($group);
        $receipt = json_decode(TaskComment::query()->findOrFail($claim->task_comment_id)->body, true, flags: JSON_THROW_ON_ERROR);
        expect($runner->starts)->toBe(1)
            ->and($runner->startTransactionLevels)->toBe([$level, $level])
            ->and($claim->fresh()->pid)->toBe(4001)
            ->and($claim->fresh()->status)->toBe(TaskCheckStatus::Failed)
            ->and($receipt)->toMatchArray(['check_id' => $claim->id, 'kind' => 'probe', 'deliverable' => 'test',
                'command' => 'vendor/bin/pest --filter=ExactTest', 'exit_code' => 1, 'managed_user' => 'orbit', 'uid' => 1001]);
        foreach (['test', 'other'] as $id) {
            $action->execute($group, $task, $id);
            $action->reconcile($group);
        }
        deliverable_probe_refused(fn () => $action->execute($group, $task, 'test'), 429);
        expect(TaskCheck::query()->count())->toBe(3)
            ->and(TaskComment::query()->count())->toBe(3);
    })->with(['reply_lost', 'gateway_crash', 'identity_write']);

    it('keeps the reservation until a terminal check and receipt commit together', function (): void {
        [$group, $task] = deliverable_probe_task();
        $reading = TaskCheckReading::lost('The reserved child was not released.',
            ['managed_user' => 'orbit', 'uid' => 1001, 'tmpdir' => '/tmp/orbit-check-1001-abandoned']);
        $runner = new FakeTaskCheckRunner([$reading, $reading]);
        app()->instance(TaskCheckRunner::class, $runner);
        $action = app(RunTaskDeliverableProbeAction::class);
        $claim = $action->execute($group, $task, 'test');
        $fault = (object) ['active' => true];
        DB::connection()->beforeExecuting(function (string $query) use ($fault): void {
            if ($fault->active && str_starts_with($query, 'update "task_comments" set "body"')) {
                $fault->active = false;
                throw new RuntimeException('Injected terminal receipt write failure.');
            }
        });
        expect(fn () => $action->reconcile($group))->toThrow(RuntimeException::class, 'Injected terminal receipt write failure.')
            ->and($claim->fresh()->status)->toBe(TaskCheckStatus::Running)
            ->and(TaskComment::query()->count())->toBe(1);
        deliverable_probe_refused(fn () => $action->execute($group, $task, 'other'), 409);
        $action->reconcile($group);
        $receipt = json_decode(TaskComment::query()->findOrFail($claim->task_comment_id)->body, true, flags: JSON_THROW_ON_ERROR);
        expect($claim->fresh()->status)->toBe(TaskCheckStatus::Lost)
            ->and($receipt)->toMatchArray(['check_id' => $claim->id, 'kind' => 'probe', 'deliverable' => 'test',
                'exit_code' => null, 'managed_user' => 'orbit', 'uid' => 1001, 'tmpdir' => '/tmp/orbit-check-1001-abandoned',
                'output_tail' => 'The reserved child was not released.'])
            ->and($receipt['finished_at'])->not->toBeEmpty()
            ->and($task->fresh()->completion_reminder_attempt)->toBeNull()
            ->and($group->fresh()->assistance_requested)->toBeFalse()
            ->and($runner->starts)->toBe(1);
    });

    it('starts no remote process when reservation persistence fails', function (): void {
        [$group, $task] = deliverable_probe_task();
        $runner = new FakeTaskCheckRunner;
        app()->instance(TaskCheckRunner::class, $runner);
        $fault = (object) ['active' => true];
        DB::connection()->beforeExecuting(function (string $query) use ($fault): void {
            if ($fault->active && str_starts_with($query, 'insert into "task_checks"')) {
                $fault->active = false;
                throw new RuntimeException('Injected reservation write failure.');
            }
        });
        expect(fn () => app(RunTaskDeliverableProbeAction::class)->execute($group, $task, 'test'))
            ->toThrow(RuntimeException::class, 'Injected reservation write failure.')
            ->and($runner->starts)->toBe(0)
            ->and(TaskCheck::query()->count())->toBe(0)
            ->and(TaskComment::query()->count())->toBe(0);
    });

    it('reattaches a reserved probe and keeps handoff excluded until its terminal receipt', function (): void {
        [$group, $task] = deliverable_probe_task();
        test_bind_snapshot_driver();
        app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
        {
            public function snapshot(Node $node, string $threadId): ?array
            {
                return ['thread' => ['session' => ['status' => 'idle']]];
            }
        });
        $runner = new FakeTaskCheckRunner([TaskCheckReading::running(), FakeTaskCheckRunner::passed()]);
        $runner->afterStart = fn () => throw new TaskCheckException('Lost start reply.');
        app()->instance(TaskCheckRunner::class, $runner);
        deliverable_probe_refused(fn () => app(RunTaskDeliverableProbeAction::class)->execute($group, $task, 'test'), 502);
        $task->update(['deliverables' => []]);
        app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([FakeTaskTurnReceipts::contents('ready_for_review'), null]));
        $runner->afterStart = null;
        app(TaskScheduler::class)->tick();
        expect($runner->starts)->toBe(1)
            ->and(TaskCheck::query()->where('kind', TaskCheckKind::Handoff->value)->count())->toBe(0)
            ->and($runner->deliverables[0]['commands'][0]['command'])->toBe('vendor/bin/pest --filter=ExactTest')
            ->and($runner->requestedDeliverables[1])->toBe($runner->requestedDeliverables[0]);
        app(TaskScheduler::class)->tick();
        expect(TaskCheck::query()->where('kind', TaskCheckKind::Probe->value)->sole()->status)->toBe(TaskCheckStatus::Passed)
            ->and(TaskCheck::query()->where('kind', TaskCheckKind::Handoff->value)->count())->toBe(1)
            ->and($runner->starts)->toBe(2);
    });

    it('records an already finished accepted probe under a hold without starting or cancelling it', function (): void {
        [$group, $task] = deliverable_probe_task();
        $reading = TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('b', 40), [], "Failed before the hold\n",
            deliverables: ['commands' => ['test' => ['exit_code' => 1]]],
            execution: ['managed_user' => 'orbit', 'uid' => 1001, 'tmpdir' => '/tmp/orbit-check-1001-ended']);
        $runner = new FakeTaskCheckRunner([$reading]);
        $runner->afterStart = fn () => throw new TaskCheckException('Lost accepted start reply.');
        app()->instance(TaskCheckRunner::class, $runner);
        $action = app(RunTaskDeliverableProbeAction::class);
        deliverable_probe_refused(fn () => $action->execute($group, $task, 'test'), 502);
        $group->update(['watched_pr_completion' => 'merged']);
        $action->retireHeld($group);
        $check = TaskCheck::query()->sole();
        $receipt = json_decode(TaskComment::query()->findOrFail($check->task_comment_id)->body, true, flags: JSON_THROW_ON_ERROR);
        expect($check->status)->toBe(TaskCheckStatus::Failed)
            ->and($check->pid)->toBe(4001)
            ->and($receipt)->toMatchArray(['check_id' => $check->id, 'exit_code' => 1, 'uid' => 1001])
            ->and($runner->starts)->toBe(1)
            ->and(count($runner->startTransactionLevels))->toBe(1)
            ->and($runner->cancels)->toBe(0);
    });

    it('retires interrupted probe reservations under ended-PR holds and completes without new execution', function (string $state, string $interruption, bool $loseRetirementReply): void {
        [$group, $task] = deliverable_probe_task();
        $driver = new FakeAgentDriver('pi');
        $driver->supportsInterruption = true;
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
        $instance = $group->taskable;
        $instanceId = $group->taskable_id;
        $runner = new FakeTaskCheckRunner([TaskCheckReading::running()]);
        if ($interruption === 'reply_lost') {
            $runner->afterStart = fn () => throw new TaskCheckException('Lost accepted start reply.');
        } else {
            $runner->beforeStart = fn () => throw new RuntimeException('Gateway crashed before remote dispatch.');
        }
        app()->instance(TaskCheckRunner::class, $runner);
        $action = app(RunTaskDeliverableProbeAction::class);
        if ($interruption === 'reply_lost') {
            deliverable_probe_refused(fn () => $action->execute($group, $task, 'test'), 502);
        } else {
            expect(fn () => $action->execute($group, $task, 'test'))->toThrow(RuntimeException::class, 'Gateway crashed');
        }
        $claim = TaskCheck::query()->sole();
        expect($claim->pid)->toBe(0);
        $action->retireHeld($group);
        expect($runner->retiredKeys)->toBe([]);
        $requestsBeforeHold = count($runner->startTransactionLevels);
        $runner->afterStart = null;
        $runner->beforeStart = null;
        $runner->failNextRetirement = $loseRetirementReply;
        $group->update(['watched_pr_completion' => $state]);
        $remover = new class($claim->id) implements InstanceRemover
        {
            public int $calls = 0;

            public function __construct(private int $checkId) {}

            public function execute(Instance $instance, bool $force): InstanceRemoval
            {
                $this->calls++;
                $check = TaskCheck::query()->findOrFail($this->checkId);
                $receipt = json_decode(TaskComment::query()->findOrFail($check->task_comment_id)->body, true, flags: JSON_THROW_ON_ERROR);
                // Both the fence and terminal receipt precede workspace removal.
                expect($check->status)->toBe(TaskCheckStatus::Cancelled)
                    ->and($receipt['check_id'])->toBe($check->id)
                    ->and($receipt['finished_at'])->not->toBeEmpty();
                $instance->delete();

                return new InstanceRemoval;
            }
        };
        app()->instance(InstanceRemover::class, $remover);
        if ($loseRetirementReply) {
            deliverable_probe_refused(fn () => app(CompleteTaskGroupAction::class)->execute($group), 502);
            expect($group->fresh()->status)->toBe(TaskGroupStatus::Running)
                ->and($claim->fresh()->status)->toBe(TaskCheckStatus::Running)
                ->and(Instance::query()->find($instanceId))->not->toBeNull()
                ->and($remover->calls)->toBe(0);
        }
        app(TaskScheduler::class)->tick();
        $receipt = json_decode(TaskComment::query()->findOrFail($claim->task_comment_id)->body, true, flags: JSON_THROW_ON_ERROR);
        expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed)
            ->and($group->fresh()->taskable_id)->toBeNull()
            ->and($task->fresh()->status)->toBe(TaskStatus::Cancelled)
            ->and($claim->fresh()->status)->toBe(TaskCheckStatus::Cancelled)
            ->and($runner->starts)->toBe($interruption === 'reply_lost' ? 1 : 0)
            ->and($runner->cancels)->toBe($interruption === 'reply_lost' ? 1 : 0)
            ->and(count($runner->startTransactionLevels))->toBe($requestsBeforeHold)
            ->and($remover->calls)->toBe(1)
            ->and(Instance::query()->find($instanceId))->toBeNull()
            ->and($receipt)->toMatchArray(['check_id' => $claim->id, 'kind' => 'probe', 'deliverable' => 'test',
                'command' => 'vendor/bin/pest --filter=ExactTest', 'managed_user' => 'orbit', 'uid' => 1001, 'exit_code' => null]);
        // Even a delayed old SSH start cannot execute the retired key.
        expect(fn () => $runner->start($instance, '', key: 'probe-'.$claim->id))->toThrow(TaskCheckException::class, 'retired');
    })->with([
        ['merged', 'reply_lost', false], ['closed', 'reply_lost', false],
        ['merged', 'pre_start', false], ['closed', 'pre_start', false],
        ['merged', 'reply_lost', true], ['closed', 'reply_lost', true],
        ['merged', 'pre_start', true], ['closed', 'pre_start', true],
    ]);

    it('still reminds and requests assistance for failing handoffs after a failing probe', function (): void {
        [$group, $task] = deliverable_probe_task();
        $failedProbe = TaskCheckReading::finished(0, str_repeat('a', 40), str_repeat('b', 40), [], "Probe failed\n",
            deliverables: ['commands' => ['test' => ['exit_code' => 1]]]);
        app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([$failedProbe]));
        $probes = app(RunTaskDeliverableProbeAction::class);
        $probes->execute($group, $task, 'test');
        $probes->reconcile($group);
        $task->update(['deliverables' => []]);
        test_bind_snapshot_driver();
        $dispatcher = new class implements AgentCommandDispatcher
        {
            /** @var list<array<string, mixed>> */
            public array $commands = [];

            public function dispatch(Node $node, array $command): array
            {
                $this->commands[] = $command;

                return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
            }
        };
        app()->instance(AgentCommandDispatcher::class, $dispatcher);
        app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
        {
            public function snapshot(Node $node, string $threadId): ?array
            {
                return ['thread' => ['session' => ['status' => 'idle']]];
            }
        });
        $failed = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "FAILED\n");
        app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([$failed, $failed]));
        app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts([
            FakeTaskTurnReceipts::contents('ready_for_review'), null,
            FakeTaskTurnReceipts::contents('ready_for_review', 'Tried again.'), null,
        ]));

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();
        expect($task->fresh()->completion_reminder_attempt)->toBe($task->completion_attempt)
            ->and($dispatcher->commands)->toHaveCount(1)
            ->and($group->fresh()->assistance_requested)->toBeFalse();
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_requested)->toBeTrue()
            ->and($group->fresh()->assistance_reason)->toContain('Checks still failed after the reminder.')
            ->and(TaskCheck::query()->where('kind', TaskCheckKind::Handoff->value)->count())->toBe(2);
    });
});
