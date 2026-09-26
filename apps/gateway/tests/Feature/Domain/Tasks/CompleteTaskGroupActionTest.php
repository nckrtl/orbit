<?php

declare(strict_types=1);

use App\Actions\Tasks\CompleteTaskGroupAction;
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

function complete_group(TaskGroupStatus $status = TaskGroupStatus::Settling): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'complete-app',
        'slug' => 'complete-app',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'complete-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.150',
        'wireguard_ip' => '10.44.0.150',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-20',
        'checkout_path' => '/srv/orbit/apps/complete-app/task-20',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Complete me',
        'brief' => 'Remove the workspace after merge.',
        'status' => $status,
        'pr_url' => 'https://github.com/nckrtl/orbit/pull/543',
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    return $group->fresh(['app', 'taskable']) ?? $group;
}

it('removes the shared App instance and marks a settling group completed', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group();
    $instanceId = $group->taskable_id;
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

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBeNull()
        ->and($completed->settled_at)->not->toBeNull()
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and(AppInstance::query()->find($instanceId))->toBeNull();
});

it('is idempotent for an already completed group and retries a leftover workspace', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group(TaskGroupStatus::Completed);
    $instanceId = $group->taskable_id;
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

    $completed = app(CompleteTaskGroupAction::class)->execute($group);
    $again = app(CompleteTaskGroupAction::class)->execute($completed);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($again->status)->toBe(TaskGroupStatus::Completed)
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and($again->taskable_id)->toBeNull()
        ->and(AppInstance::query()->find($instanceId))->toBeNull();
});

it('marks the group completed and reports the removal failure when the workspace cannot be removed', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = new class implements AppInstanceRemover
    {
        public bool $fail = true;

        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            if ($this->fail) {
                throw new ResourceOperationException('instance.force_failed', 'The Node is unreachable.', 409);
            }
            $instance->delete();

            return new AppInstanceRemoval;
        }
    };
    app()->instance(AppInstanceRemover::class, $remover);
    $group = complete_group();
    $instanceId = $group->taskable_id;

    $completed = app(CompleteTaskGroupAction::class)->execute($group);

    expect($completed->status)->toBe(TaskGroupStatus::Completed)
        ->and($completed->taskable_id)->toBe($instanceId)
        ->and($completed->assistance_requested)->toBeTrue()
        ->and($completed->assistance_reason)->toBe('Workspace removal failed: The Node is unreachable.')
        ->and(AppInstance::query()->find($instanceId))->not->toBeNull();

    $remover->fail = false;
    $retried = app(CompleteTaskGroupAction::class)->execute($completed);

    expect($retried->status)->toBe(TaskGroupStatus::Completed)
        ->and($retried->taskable_id)->toBeNull()
        ->and($retried->assistance_requested)->toBeFalse()
        ->and(AppInstance::query()->find($instanceId))->toBeNull();
});

it('returns 409 tasks.not_settling when the group is still running', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = complete_group(TaskGroupStatus::Running);

    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.not_settling')
                ->and($exception->status)->toBe(409);
        });
});

it('returns 409 tasks.disabled while the extension is off', function (): void {
    $group = complete_group();

    expect(fn () => app(CompleteTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.disabled')
                ->and($exception->status)->toBe(409);
        });
});
