<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskCheckAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\NullTaskWorkspaceStateReader;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Tests\Support\FakeTaskCheckRunner;

it('starts one baseline check and asks for no assistance when the todo move and the tick race', function (): void {
    $project = Project::query()->create([
        'name' => 'baseline start race',
        'slug' => 'baseline-start-race',
        'repository_url' => 'git@example.test:baseline-start-race.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => 'baseline-start-race-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.194',
        'wireguard_ip' => '10.44.0.194',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'baseline-start-race',
        'checkout_path' => '/tmp/tasks-baseline-start-race',
        'status' => 'reserved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Baseline start race',
        'brief' => 'One baseline check wins the start.',
        'status' => TaskGroupStatus::Running,
        'execution_mode' => TaskExecutionMode::Managed,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First task',
        'brief' => 'First task brief',
        'status' => TaskStatus::Todo,
    ]);
    $inner = new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "install-apps-cli failed\n", failedStep: 'install-apps-cli'),
    ]);
    $checks = new class($inner) implements TaskCheckRunner
    {
        public bool $racing = true;

        public function __construct(private FakeTaskCheckRunner $inner) {}

        public function start(Instance $instance, ?string $command, array $setup = [], ?array $deliverables = null): TaskCheckProcess
        {
            $process = $this->inner->start($instance, $command, $setup, $deliverables);
            if ($this->racing) {
                $this->racing = false;
                app(TaskScheduler::class)->tick();
            }

            return $process;
        }

        public function read(Instance $instance, TaskCheckProcess $process): TaskCheckReading
        {
            return $this->inner->read($instance, $process);
        }

        public function cancel(Instance $instance, TaskCheckProcess $process): void
        {
            $this->inner->cancel($instance, $process);
        }

        public function snapshot(Instance $instance): TaskWorkspaceSnapshot
        {
            return $this->inner->snapshot($instance);
        }
    };
    app()->instance(TaskCheckRunner::class, $checks);
    app()->instance(TaskWorkspaceStateReader::class, new NullTaskWorkspaceStateReader);
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->startTask($task);

    $baselines = TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Baseline->value)->get();

    expect($baselines)->toHaveCount(1)
        ->and($baselines->sole()->status)->toBe(TaskCheckStatus::Running)
        ->and($baselines->sole()->pid)->toBe(4001)
        ->and($baselines->sole()->process_started)->toBe('Wed Sep 23 12:00:01 2026')
        ->and($inner->starts)->toBe(1)
        ->and($group->fresh()?->assistance_requested)->toBeFalse()
        ->and($group->fresh()?->assistance_reason)->toBeNull()
        ->and($task->fresh()?->assistance_requested)->toBeFalse()
        ->and($task->fresh()?->implementer_agent_thread_id)->toBeNull();
});

it('asks for assistance when a baseline claim stays unstarted past the start limit', function (): void {
    $this->freezeTime();
    [$group, $task] = baseline_start_task('baseline-claim-stall', '10.44.0.195', TaskStatus::Running);
    TaskCheck::query()->create([
        'task_id' => $task->id,
        'kind' => TaskCheckKind::Baseline,
        'status' => TaskCheckStatus::Running,
        'pid' => 0,
        'process_started' => '',
        'head_before' => '',
        'tree_before' => '',
        'started_at' => now(),
    ]);
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    app()->instance(TaskWorkspaceStateReader::class, new NullTaskWorkspaceStateReader);
    app(TaskExtensionState::class)->enable();

    $this->travel(TaskScheduler::BASELINE_START_LIMIT_SECONDS + 1)->seconds();
    app(TaskScheduler::class)->tick();

    expect(TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Baseline->value)->get())->toHaveCount(1)
        ->and($checks->starts)->toBe(0)
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($group->fresh()?->assistance_reason)->toBe('The baseline start was interrupted, and a check may still run in the workspace.');
});

it('cancels the started baseline process when the claim is cancelled during the start', function (): void {
    [$group, $task] = baseline_start_task('baseline-claim-cancel', '10.44.0.196', TaskStatus::Todo);
    $checks = new class($group, $task) implements TaskCheckRunner
    {
        /** @var list<int> */
        public array $cancelledPids = [];

        public function __construct(private Task $group, private Task $task) {}

        public function start(Instance $instance, ?string $command, array $setup = [], ?array $deliverables = null): TaskCheckProcess
        {
            app(CancelTaskCheckAction::class)->execute($this->group->fresh(['taskable']) ?? $this->group, $this->task);

            return new TaskCheckProcess(4001, 'Wed Sep 23 12:00:01 2026', str_repeat('a', 40), str_repeat('b', 40));
        }

        public function read(Instance $instance, TaskCheckProcess $process): TaskCheckReading
        {
            return TaskCheckReading::running();
        }

        public function cancel(Instance $instance, TaskCheckProcess $process): void
        {
            $this->cancelledPids[] = $process->pid;
        }

        public function snapshot(Instance $instance): TaskWorkspaceSnapshot
        {
            return new TaskWorkspaceSnapshot(str_repeat('a', 40), str_repeat('b', 40));
        }
    };
    app()->instance(TaskCheckRunner::class, $checks);
    app()->instance(TaskWorkspaceStateReader::class, new NullTaskWorkspaceStateReader);
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->startTask($task);

    $check = TaskCheck::query()->where('task_id', $task->id)->where('kind', TaskCheckKind::Baseline->value)->sole();

    expect($checks->cancelledPids)->toContain(4001)
        ->and($check->status)->toBe(TaskCheckStatus::Cancelled)
        ->and($check->pid)->toBe(0);
});

/**
 * @return array{Task, Task}
 */
function baseline_start_task(string $slug, string $ip, TaskStatus $status): array
{
    $project = Project::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'default_branch' => 'main',
        'task_check' => 'composer check',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => $slug.'-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $slug,
        'checkout_path' => '/tmp/tasks-'.$slug,
        'status' => 'reserved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => $slug,
        'brief' => 'Baseline claim.',
        'status' => TaskGroupStatus::Running,
        'execution_mode' => TaskExecutionMode::Managed,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First task',
        'brief' => 'First task brief',
        'status' => $status,
    ]);

    return [$group->fresh(['taskable']) ?? $group, $task];
}
