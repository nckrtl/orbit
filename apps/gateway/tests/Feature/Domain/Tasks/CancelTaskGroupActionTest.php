<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;

function cancellable_task_group(TaskGroupStatus $status, ?string $prUrl = null): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'cancel-app',
        'slug' => 'cancel-app',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'cancel-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.160',
        'wireguard_ip' => '10.44.0.160',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/srv/orbit/apps/cancel-app/task-21',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Cancel me',
        'brief' => 'Remove the stuck task workspace.',
        'status' => $status,
        'pr_url' => $prUrl,
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    return $group->fresh(['app', 'taskable']) ?? $group;
}

/** Records each removal and deletes the row, as a completed removal does. */
function cancel_recording_remover(): object
{
    $remover = new class implements AppInstanceRemover
    {
        /** @var list<array{0: int, 1: bool}> */
        public array $calls = [];

        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            $this->calls[] = [$instance->id, $force];
            $instance->delete();

            return new AppInstanceRemoval;
        }
    };
    app()->instance(AppInstanceRemover::class, $remover);

    return $remover;
}

/** Records each push, and fails while `$failures` remain. */
function cancel_recording_publisher(int $failures = 0): object
{
    $publisher = new class($failures) implements TaskPullRequestPublisher
    {
        /** @var list<int> */
        public array $pushes = [];

        /** @var list<int> the database transaction level at each push */
        public array $transactionLevels = [];

        public function __construct(private int $failures) {}

        public function publish(TaskGroup $group, string $body): string
        {
            throw new LogicException('Cancel never opens a pull request.');
        }

        public function push(TaskGroup $group): void
        {
            $this->pushes[] = $group->id;
            $this->transactionLevels[] = DB::transactionLevel();
            if ($this->failures-- > 0) {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
        }
    };
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    return $publisher;
}

function cancel_subtask(TaskGroup $group, TaskStatus $status, int $position = 1): Task
{
    return Task::query()->create([
        'task_group_id' => $group->id,
        'position' => $position,
        'title' => 'Subtask '.$position,
        'brief' => 'Part of the group.',
        'status' => $status,
    ]);
}

it('cancels an eligible group and removes its shared Instance with its checkout', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = cancel_recording_remover();
    $group = cancellable_task_group(TaskGroupStatus::Running);
    $instanceId = $group->taskable_id;

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    // Removal, not a row delete, so the checkout on the Node goes too.
    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and(AppInstance::query()->find($instanceId))->toBeNull();
});

it('honors an already cancelled group and cleans up an attached Instance', function (): void {
    app(TaskExtensionState::class)->enable();
    cancel_recording_remover();
    $group = cancellable_task_group(TaskGroupStatus::Cancelled);

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and(AppInstance::query()->count())->toBe(0);
});

it('returns 409 for a completed group or a settling group with a pull request', function (TaskGroupStatus $status, ?string $prUrl): void {
    app(TaskExtensionState::class)->enable();
    $group = cancellable_task_group($status, $prUrl);

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.not_cancellable')
                ->and($exception->status)->toBe(409);
        });
})->with([
    'settling with a pull request' => [TaskGroupStatus::Settling, 'https://github.com/nckrtl/orbit/pull/7'],
    'completed' => [TaskGroupStatus::Completed, null],
]);

it('pushes approved commits before it cancels a settling group without a pull request', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = cancel_recording_remover();
    $publisher = cancel_recording_publisher();
    $group = cancellable_task_group(TaskGroupStatus::Settling);
    cancel_subtask($group, TaskStatus::Completed, 1);
    $cancelledSubtask = cancel_subtask($group, TaskStatus::Cancelled, 2);
    $instanceId = $group->taskable_id;
    $testLevel = DB::transactionLevel();

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($publisher->pushes)->toBe([$group->id])
        ->and($publisher->transactionLevels)->toBe([$testLevel])
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and($cancelledSubtask->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('keeps the settling group and its Instance when the push fails', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = cancel_recording_remover();
    cancel_recording_publisher(failures: 1);
    $group = cancellable_task_group(TaskGroupStatus::Settling);
    cancel_subtask($group, TaskStatus::Completed);
    $instanceId = $group->taskable_id;

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.push_failed')
                ->and($exception->status)->toBe(502);
        });

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()->taskable_id)->toBe($instanceId)
        ->and($remover->calls)->toBe([]);

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($remover->calls)->toBe([[$instanceId, true]]);
});

it('does not push a settling group without approved subtasks', function (): void {
    app(TaskExtensionState::class)->enable();
    cancel_recording_remover();
    $publisher = cancel_recording_publisher();
    $group = cancellable_task_group(TaskGroupStatus::Settling);
    cancel_subtask($group, TaskStatus::Cancelled);

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($publisher->pushes)->toBe([])
        ->and($cancelled->status)->toBe(TaskGroupStatus::Cancelled);
});

it('still cancels and drops the workspace record when removal refuses', function (): void {
    app(TaskExtensionState::class)->enable();
    app()->instance(AppInstanceRemover::class, new class implements AppInstanceRemover
    {
        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            throw new ResourceOperationException('instance.remove_refused', 'The checkout could not be inspected.', 409);
        }
    });
    $group = cancellable_task_group(TaskGroupStatus::Running);
    $instanceId = $group->taskable_id;

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and(AppInstance::query()->find($instanceId))->toBeNull();
});
