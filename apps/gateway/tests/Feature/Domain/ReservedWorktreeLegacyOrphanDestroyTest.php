<?php

declare(strict_types=1);

use App\Actions\Instances\RemoveInstanceAction;
use App\Domain\Instances\InstanceCreationRecovery;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use Tests\Support\LifecycleSshExecutor;

beforeEach(function (): void {
    bind_task_node_reachability();
});

describe('legacy reserved worktree orphan without source_prepare_id', function (): void {
    it('is pre-activation removable via RemoveInstanceAction (LIVE 348/369 shape)', function (bool $force): void {
        $instance = legacy_reserved_removal_instance();
        $transport = legacy_reserved_removal_source($instance, $force);

        expect(InstanceCreationRecovery::isPreActivation($instance))->toBeTrue();

        $removal = app(RemoveInstanceAction::class)->execute($instance, force: $force);

        expect($removal->status->value)->toBe('completed');
        expect($transport->inputs)->toBe([]);
        expect(Instance::query()->find($instance->id))->toBeNull();
        expect(InstanceRemovalMember::query()->where('instance_id', $instance->id)->sole()->source_finalized_at)->not->toBeNull();
    })->with(['non-force' => false, 'force' => true]);
});

function legacy_reserved_removal_instance(): Instance
{
    $project = Project::query()->create([
        'name' => 'Reserved removal',
        'slug' => 'reserved-removal',
        'type' => 'laravel-app',
        'repository_url' => 'https://example.test/reserved-removal.git',
        'default_branch' => 'main',
        'root' => 'public',
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
        'source_prepare_id' => null,
        'registration_request_id' => null,
        'checkout_path' => '/srv/orbit/apps/reserved-removal/task-1253',
        'task_workspace_routed' => false,
        'status' => InstanceState::Reserved,
        'starting_commit' => null,
        'failed_step' => null,
        'error_code' => null,
    ]);
}

function legacy_reserved_removal_source(Instance $instance, bool $force): LifecycleSshExecutor
{
    $instance->loadMissing('project');
    $source = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
    $source->shouldReceive('inspect')->withArgs(fn (Instance $candidate, bool $forced): bool => $candidate->id === $instance->id && $forced === $force)
        ->andReturn(new InstanceSourceInventory(
            instanceId: $instance->id,
            layout: 'worktree',
            repositoryIdentity: $instance->project->repository_identity,
            checkoutPath: $instance->checkout_path,
            root: '/srv/orbit/apps',
            branch: $instance->branch_override,
            startingCommit: str_repeat('0', 40),
            commonRepositoryPath: $instance->checkout_path,
            sourceIdentity: 'absent',
            linkedWorktreePaths: [$instance->checkout_path],
            digest: hash('sha256', 'absent-reserved-worktree'),
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
    $finalizer->shouldReceive('finalize')->once()->andReturn(hash('sha256', 'reserved-receipt'));
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $finalizer);
    $projector = Mockery::mock(InstanceRemovalProjector::class);
    $projector->shouldReceive('withdrawPhpPool')->once();
    $projector->shouldReceive('cleanupRuntime')->once();
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
