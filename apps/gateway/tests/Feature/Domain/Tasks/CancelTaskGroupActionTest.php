<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\TaskGroup;

function cancellable_task_group(TaskGroupStatus $status): TaskGroup
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

it('returns 409 when a group is settling or completed', function (TaskGroupStatus $status): void {
    app(TaskExtensionState::class)->enable();
    $group = cancellable_task_group($status);

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.not_cancellable')
                ->and($exception->status)->toBe(409);
        });
})->with([
    'settling' => TaskGroupStatus::Settling,
    'completed' => TaskGroupStatus::Completed,
]);
