<?php

declare(strict_types=1);

use App\Actions\Instances\RemoveInstanceAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceCreationRecovery;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use Tests\Support\LifecycleSshExecutor;

beforeEach(function (): void {
    bind_task_node_reachability();
});

describe('reserved worktree removable', function (): void {
    it('removes an interrupted reservation without recording a failure or running teardown', function (bool $force): void {
        $instance = reserved_removal_instance();
        $transport = reserved_removal_source($instance, $force);

        $removal = app(RemoveInstanceAction::class)->execute($instance, force: $force);

        expect($removal->status->value)->toBe('completed');
        expect($transport->inputs)->toBe([]);
        expect(Instance::query()->find($instance->id))->toBeNull();
        expect(InstanceRemovalMember::query()->where('instance_id', $instance->id)->sole()->source_finalized_at)->not->toBeNull();
    })->with([false, true]);

    it('cancels a group and clears its stranded unattached task workspace', function (): void {
        app(TaskExtensionState::class)->enable();
        $instance = reserved_removal_instance();
        $group = Task::topLevel()->create([
            'project_id' => $instance->project_id,
            'title' => 'Interrupted provision',
            'brief' => 'Clear the interrupted workspace.',
            'status' => TaskGroupStatus::Todo,
        ]);
        $instance->update(['name' => 'task-'.$group->id, 'branch_override' => 'task-'.$group->id]);
        $transport = reserved_removal_source($instance, true);

        $cancelled = app(CancelTaskGroupAction::class)->execute($group);

        expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled);
        expect($transport->inputs)->toBe([]);
        expect($cancelled->taskable_id)->toBeNull();
        expect($cancelled->assistance_requested)->toBeFalse();
        expect(Instance::query()->find($instance->id))->toBeNull();
        expect(InstanceRemoval::query()->sole()->status->value)->toBe('completed');
    });

    it('does not treat an active worktree as failed-create cleanup', function (): void {
        $instance = reserved_removal_instance();
        $instance->project->update(['type' => 'monorepo']);
        $instance->update(['status' => InstanceState::Active]);

        expect(InstanceCreationRecovery::isPreActivation($instance))->toBeFalse();
        expect(fn () => app(RemoveInstanceAction::class)->execute($instance, force: true, requirePreActivation: true))
            ->toThrow(ResourceOperationException::class, 'Failed-create cleanup cannot remove an activated Instance.');
        expect(Instance::query()->find($instance->id))->not->toBeNull();
        expect(InstanceRemoval::query()->count())->toBe(0);
    });

    it('keeps reservations without creation evidence protected even with force', function (array $attributes): void {
        $instance = reserved_removal_instance();
        $instance->update($attributes);

        expect(InstanceCreationRecovery::isPreActivation($instance))->toBeFalse();
        expect(fn () => app(RemoveInstanceAction::class)->execute($instance, force: true))
            ->toThrow(ResourceOperationException::class);
        expect(Instance::query()->find($instance->id))->not->toBeNull();
        expect(InstanceRemoval::query()->count())->toBe(0);
    })->with([
        'already resolved commit' => [['starting_commit' => str_repeat('a', 40)]],
        'no preparation identity' => [['source_prepare_id' => null, 'task_workspace_routed' => null]],
        'registered source' => [['registration_request_id' => 'registered-source']],
        'not a task workspace' => [['task_workspace_routed' => null]],
    ]);

    it('leaves a resolved unrouted worktree on its healthy teardown path', function (): void {
        $instance = reserved_removal_instance();
        $instance->update(['status' => InstanceState::SourceResolved, 'starting_commit' => str_repeat('a', 40)]);

        expect(InstanceCreationRecovery::isPreActivation($instance))->toBeFalse();
    });
});

describe('PHP-FPM pool withdrawal', function (): void {
    it('withdraws the pool and reloads PHP-FPM before it deletes the task workspace source', function (): void {
        $instance = reserved_removal_instance();
        $steps = new ArrayObject;
        reserved_removal_source($instance, true, $steps);

        app(RemoveInstanceAction::class)->execute($instance, force: true);

        expect($steps->getArrayCopy())->toBe(['pool-withdrawn', 'source-deleted']);
    });

    it('keeps the source and the open removal when the pool cannot be withdrawn', function (): void {
        $instance = reserved_removal_instance();
        $steps = new ArrayObject;
        reserved_removal_source($instance, true, $steps, new RuntimeConvergenceException(
            step: 'php-fpm-config',
            errorCode: 'app-dev.php_fpm_config_failed',
            message: 'PHP-FPM configuration failed.',
        ));

        expect(fn () => app(RemoveInstanceAction::class)->execute($instance, force: true))
            ->toThrow(InstanceRemovalException::class);

        $removal = InstanceRemoval::query()->sole();
        expect($steps->getArrayCopy())->toBe(['pool-withdrawn'])
            ->and($removal->failed_step?->value)->toBe('source_finalization')
            ->and($removal->error_code)->toBe('app-dev.php_fpm_config_failed')
            ->and(InstanceRemovalMember::query()->where('instance_id', $instance->id)->sole()->source_finalized_at)->toBeNull()
            ->and(Instance::query()->find($instance->id))->not->toBeNull();
    });
});

function reserved_removal_instance(): Instance
{
    $project = Project::query()->create([
        'name' => 'Reserved removal',
        'slug' => 'reserved-removal',
        'type' => 'laravel-app',
        'repository_url' => 'https://example.test/reserved-removal.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public', 'laravel-app'),
    ]);
    $node = Node::query()->create([
        'name' => 'reserved-removal',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.125',
        'wireguard_ip' => '10.44.1.125',
    ]);
    $node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-1253',
        'branch_override' => 'task-1253',
        'source_layout' => 'worktree',
        'source_prepare_id' => 'reserved-prepare',
        'checkout_path' => '/srv/orbit/apps/reserved-removal/task-1253',
        'task_workspace_routed' => false,
        'status' => InstanceState::Reserved,
        'starting_commit' => null,
        'failed_step' => null,
        'error_code' => null,
    ]);
}

/**
 * @param  ArrayObject<int, string>|null  $steps  Records pool withdrawal and source deletion in order.
 */
function reserved_removal_source(
    Instance $instance,
    bool $force,
    ?ArrayObject $steps = null,
    ?Throwable $withdrawalFailure = null,
): LifecycleSshExecutor {
    $steps ??= new ArrayObject;
    $instance->loadMissing('project');
    $source = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
    $source->shouldReceive('inspect')->withArgs(fn (Instance $candidate, bool $forced): bool => $candidate->id === $instance->id && $forced === $force)
        ->andReturn(new InstanceSourceInventory(
            instanceId: $instance->id,
            layout: 'worktree',
            repositoryIdentity: $instance->project->repository_identity,
            checkoutPath: $instance->checkout_path,
            root: '/srv/orbit/apps',
            branch: null,
            startingCommit: '',
            commonRepositoryPath: '/srv/orbit/apps/reserved-removal/main',
            sourceIdentity: 'reserved-prepare',
            linkedWorktreePaths: [$instance->checkout_path],
            digest: hash('sha256', 'reserved-prepare'),
        ));
    app()->instance(DevelopmentInstanceSourceRemoval::class, $source);
    $finalizer = Mockery::mock(DevelopmentInstanceSourceFinalizer::class);
    $finalizer->shouldReceive('prepare')->once()->withArgs(function (InstanceRemovalMember $member): bool {
        $current = Instance::query()->findOrFail($member->instance_id);
        expect($current->status)->toBe(InstanceState::Removing);
        expect(InstanceCreationRecovery::isPreActivation($current, removing: true))->toBeTrue();

        return true;
    });
    $finalizer->shouldReceive('revalidate')->andReturn(InstanceSourceRevalidationState::Present);
    $finalizer->shouldReceive('finalize')->times($withdrawalFailure instanceof Throwable ? 0 : 1)->andReturnUsing(function () use ($steps): string {
        $steps[] = 'source-deleted';

        return hash('sha256', 'reserved-receipt');
    });
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $finalizer);
    $projector = Mockery::mock(InstanceRemovalProjector::class);
    $projector->shouldReceive('withdrawPhpPool')->once()->andReturnUsing(function (InstanceRemovalMember $member) use ($instance, $steps, $withdrawalFailure): void {
        expect($member->instance_id)->toBe($instance->id);
        $steps[] = 'pool-withdrawn';

        if ($withdrawalFailure instanceof Throwable) {
            throw $withdrawalFailure;
        }
    });
    $projector->shouldReceive('cleanupRuntime')->times($withdrawalFailure instanceof Throwable ? 0 : 1)->withArgs(
        fn (InstanceRemovalMember $member): bool => $member->instance_id === $instance->id,
    );
    $projector->shouldNotReceive('clearRouteTarget');
    app()->instance(InstanceRemovalProjector::class, $projector);
    ProjectLifecycleStep::query()->create([
        'project_id' => $instance->project_id,
        'phase' => 'teardown',
        'name' => 'cleanup',
        'command' => 'project-cleanup',
        'timeout_seconds' => 30,
        'position' => 0,
    ]);
    $transport = new LifecycleSshExecutor;
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());

    return $transport;
}
