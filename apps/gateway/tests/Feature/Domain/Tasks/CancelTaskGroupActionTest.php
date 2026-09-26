<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskComment;
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

        /** @var list<string> */
        public array $commits = [];

        /** @var list<int> the database transaction level at each push */
        public array $transactionLevels = [];

        public function __construct(private int $failures) {}

        public function publish(TaskGroup $group, string $body, string $commit): string
        {
            throw new LogicException('Cancel never opens a pull request.');
        }

        public function push(TaskGroup $group, string $commit): void
        {
            $this->pushes[] = $group->id;
            $this->commits[] = $commit;
            $this->transactionLevels[] = DB::transactionLevel();
            if ($this->failures-- > 0) {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
        }
    };
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    return $publisher;
}

function cancel_approval(Task $task, string $commit): void
{
    TaskComment::query()->create([
        'task_group_id' => $task->task_group_id,
        'task_id' => $task->id,
        'type' => 'approved',
        'body' => 'Approved.',
        'author' => 'reviewer',
        'review_attempt' => 1,
        'commit_sha' => $commit,
        'posted_at' => now(),
    ]);
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

beforeEach(function (): void {
    bind_task_node_reachability();
});

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
    $approved = cancel_subtask($group, TaskStatus::Completed, 1);
    cancel_approval($approved, str_repeat('c', 40));
    $cancelledSubtask = cancel_subtask($group, TaskStatus::Cancelled, 2);
    $instanceId = $group->taskable_id;
    $testLevel = DB::transactionLevel();

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($publisher->pushes)->toBe([$group->id])
        ->and($publisher->commits)->toBe([str_repeat('c', 40)])
        ->and($publisher->transactionLevels)->toBe([$testLevel])
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and($cancelledSubtask->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('cancels an unreachable Node without pushing and the sweep retries removal', function (): void {
    app(TaskExtensionState::class)->enable();
    bind_task_node_reachability(unreachable: true);
    $remover = cancel_recording_remover();
    $publisher = cancel_recording_publisher();
    $group = cancellable_task_group(TaskGroupStatus::Settling);
    $approved = cancel_subtask($group, TaskStatus::Completed, 1);
    cancel_approval($approved, str_repeat('c', 40));
    $running = cancel_subtask($group, TaskStatus::Running, 2);
    $instanceId = $group->taskable_id;

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBe($instanceId)
        ->and($cancelled->assistance_requested)->toBeTrue()
        ->and($cancelled->assistance_reason)->toBe(RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node is unreachable.')
        ->and($publisher->pushes)->toBe([])
        ->and($remover->calls)->toBe([])
        ->and($running->fresh()?->status)->toBe(TaskStatus::Cancelled)
        ->and(AppInstance::query()->find($instanceId))->not->toBeNull();

    $again = app(CancelTaskGroupAction::class)->execute($cancelled);

    expect($again->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($again->taskable_id)->toBe($instanceId)
        ->and($remover->calls)->toBe([]);

    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1)
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and(AppInstance::query()->find($instanceId))->toBeNull()
        ->and($group->fresh()?->taskable_id)->toBeNull()
        ->and($group->fresh()?->assistance_requested)->toBeFalse();
});

it('keeps the settling group and its Instance when the push fails', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = cancel_recording_remover();
    cancel_recording_publisher(failures: 1);
    $group = cancellable_task_group(TaskGroupStatus::Settling);
    cancel_approval(cancel_subtask($group, TaskStatus::Completed), str_repeat('c', 40));
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

it('asks for assistance and keeps the clone when removal of a source-resolved workspace is refused', function (): void {
    app(TaskExtensionState::class)->enable();
    app()->instance(AppInstanceRemover::class, new class implements AppInstanceRemover
    {
        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            throw new ResourceOperationException('instance.force_failed', 'The checkout could not be inspected.', 409);
        }
    });
    $group = cancellable_task_group(TaskGroupStatus::Running);
    $instanceId = $group->taskable_id;

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
        ->toThrow(ResourceOperationException::class, 'The checkout could not be inspected.');

    $fresh = $group->fresh();
    expect($fresh?->status)->toBe(TaskGroupStatus::Running)
        ->and($fresh?->taskable_id)->toBe($instanceId)
        ->and($fresh?->assistance_requested)->toBeTrue()
        ->and($fresh?->assistance_reason)->toBe('Workspace removal failed: The checkout could not be inspected.')
        ->and(AppInstance::query()->find($instanceId))->not->toBeNull();
});

/** A group that holds no Instance while its provisioned `task-{id}` workspace stays on the Node. */
function cancel_unattached_workspace(TaskGroupStatus $status, string $instanceStatus = 'source_resolved'): array
{
    $group = cancellable_task_group($status);
    $attached = $group->taskable;
    $group->taskable()->dissociate();
    $group->save();
    $attached?->delete();
    $name = 'task-'.$group->id;
    $workspace = AppInstance::query()->create([
        'app_id' => $group->app_id,
        'node_id' => Node::query()->where('name', 'cancel-node')->value('id'),
        'name' => $name,
        'branch_override' => $name,
        'checkout_path' => "/srv/orbit/apps/cancel-app/{$name}",
        'status' => $instanceStatus,
    ]);

    return [$group->fresh(['app', 'taskable']) ?? $group, $workspace];
}

describe('a workspace the group never attached', function (): void {
    it('removes the leftover workspace of a group swept back to todo', function (): void {
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Todo);

        $cancelled = app(CancelTaskGroupAction::class)->execute($group);

        expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($remover->calls)->toBe([[$workspace->id, true]])
            ->and(AppInstance::query()->find($workspace->id))->toBeNull();
    });

    it('removes the leftover workspace of a group stranded in reserved past the bound', function (): void {
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Reserved);
        $group->forceFill(['reserved_at' => now()->subSeconds(3601)])->save();

        app(CancelTaskGroupAction::class)->execute($group);

        expect($remover->calls)->toBe([[$workspace->id, true]]);
    });

    it('leaves the workspace to a claim still in flight', function (): void {
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Reserved, 'reserved');
        $group->forceFill(['reserved_at' => now()->subSeconds(30)])->save();

        $cancelled = app(CancelTaskGroupAction::class)->execute($group);

        expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($remover->calls)->toBe([])
            ->and(AppInstance::query()->find($workspace->id))->not->toBeNull();
    });

    it('removes a workspace the claim attached before the cancel landed', function (): void {
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Reserved);
        $group->forceFill(['reserved_at' => now()->subSeconds(30)])->save();
        $stale = $group->fresh(['app', 'taskable']);
        $group->taskable()->associate($workspace);
        $group->save();

        app(CancelTaskGroupAction::class)->execute($stale);

        expect($remover->calls)->toBe([[$workspace->id, true]])
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($group->fresh()?->taskable_id)->toBeNull();
    });

    it('removes an Instance a claim attached after cancel looked for the workspace', function (): void {
        app(TaskExtensionState::class)->enable();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Todo);
        $late = AppInstance::query()->create([
            'app_id' => $group->app_id,
            'node_id' => $workspace->node_id,
            'name' => 'late-attach',
            'checkout_path' => '/srv/orbit/apps/cancel-app/late-attach',
            'status' => 'source_resolved',
        ]);
        $remover = new class($group->id, $late) implements AppInstanceRemover
        {
            /** @var list<int> */
            public array $calls = [];

            public function __construct(private int $groupId, private AppInstance $late) {}

            public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
            {
                $this->calls[] = $instance->id;
                if ($this->calls === [$instance->id] && $instance->id !== $this->late->id) {
                    $group = TaskGroup::query()->findOrFail($this->groupId);
                    $group->taskable()->associate($this->late);
                    $group->save();
                }
                $instance->delete();

                return new AppInstanceRemoval;
            }
        };
        app()->instance(AppInstanceRemover::class, $remover);

        $cancelled = app(CancelTaskGroupAction::class)->execute($group);

        expect($remover->calls)->toBe([$workspace->id, $late->id])
            ->and($cancelled->taskable_id)->toBeNull()
            ->and(AppInstance::query()->whereKey([$workspace->id, $late->id])->exists())->toBeFalse();
    });

    it('keeps a half-provisioned workspace and asks for assistance when removal refuses', function (): void {
        app(TaskExtensionState::class)->enable();
        app()->instance(AppInstanceRemover::class, new class implements AppInstanceRemover
        {
            public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
            {
                throw new ResourceOperationException('instance.remove_refused', 'AppInstance is not active.', 409);
            }
        });
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Todo, 'checkout_prepared');

        expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
            ->toThrow(ResourceOperationException::class, 'AppInstance is not active.');

        $fresh = $group->fresh();
        expect($fresh?->status)->toBe(TaskGroupStatus::Todo)
            ->and($fresh?->assistance_requested)->toBeTrue()
            ->and($fresh?->assistance_reason)->toBe('Workspace removal failed: AppInstance is not active.')
            ->and(AppInstance::query()->find($workspace->id))->not->toBeNull();
    });

    it('never removes an Instance that only shares the workspace name', function (): void {
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Todo);
        $workspace->update(['branch_override' => null]);

        app(CancelTaskGroupAction::class)->execute($group);

        expect($remover->calls)->toBe([])
            ->and(AppInstance::query()->find($workspace->id))->not->toBeNull();
    });
});
