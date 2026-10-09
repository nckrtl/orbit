<?php

declare(strict_types=1);

use App\Actions\Annotations\AnnotationStoreAction;
use App\Actions\Instances\RemoveInstanceAction;
use App\Actions\Instances\RenameInstanceAction;
use App\Actions\Processes\CascadeInstanceProcessesAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Actions\Schedules\AddScheduleAction;
use App\Data\Annotations\AnnotationInput;
use App\Data\Instances\RenameInstanceData;
use App\Data\Schedules\AddScheduleData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\DevelopmentInstanceBranchInspector;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Instances\Removal\ProductionInstanceContentRetention;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Schedules\ScheduleSpecificationValidator;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\InstanceRename;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Route;
use App\Models\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\LifecycleSshExecutor;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;
use Tests\Support\Schedules\FakeScheduleRuntimeManager;

beforeEach(function (): void {
    $this->orb181Inspector = new Orb181CoordinatorInspector;
    $this->orb181Finalizer = new Orb181CoordinatorFinalizer($this->orb181Inspector);
    $this->orb181Projector = new Orb181CoordinatorProjector;
    $this->orb181Lock = new Orb181CoordinatorLock;
    $this->orb212EnvironmentLock = new Orb212CoordinatorEnvironmentLock;
    $this->orb131ProcessLock = new Orb131CoordinatorProcessAdmissionLock;
    $this->orb131ProcessRuntime = new Orb131CoordinatorProcessRuntimeManager;
    $this->orb183Content = new Orb183CoordinatorContentRetention;
    $this->orb181Coordinator = new RemoveInstanceAction(
        $this->orb181Inspector,
        $this->orb181Finalizer,
        $this->orb181Projector,
        new ManagedCheckoutOverlap,
        $this->orb212EnvironmentLock,
        $this->orb131ProcessLock,
        new CascadeInstanceProcessesAction(new RemoveProcessAction(
            $this->orb131ProcessRuntime,
            new ProcessTargetResolver,
        )),
        $this->orb181Lock,
        $this->orb183Content,
        app(RouteStateResolver::class),
    );
});

it('refuses a per-app route rename that becomes incomplete while removal waits for its owner lock', function (): void {
    $instance = orb181_coordinator_instance();
    $path = $instance->checkout_path;
    $instance->recordAppRuntime('web', ['app_identity' => true, 'vite_environment_identity' => true, 'annotator_store_identity' => true, 'laravel' => false]);
    $domain = 'lock-wait-rename.test';
    app()->instance(DevelopmentInstanceBranchInspector::class, Mockery::mock(DevelopmentInstanceBranchInspector::class)->shouldIgnoreMissing());
    app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class)->shouldIgnoreMissing());
    $event = 'eloquent.updated: '.InstanceRename::class;
    Event::listen($event, static function (InstanceRename $journal): void {
        if ($journal->phase === 'complete') {
            throw new ResourceOperationException('route.domain_change_failed', 'Interrupted completion while removal waits.', 502);
        }
    });
    $this->orb212EnvironmentLock->beforeAcquire = function () use ($instance, $domain): void {
        expect(InstanceRename::query()->count())->toBe(0);
        try {
            app(RenameInstanceAction::class)->execute($instance, new RenameInstanceData(domain: $domain));
            throw new RuntimeException('Rename unexpectedly completed.');
        } catch (Throwable $exception) {
            if (! $exception instanceof ResourceOperationException || $exception->errorCode !== 'route.domain_change_failed') {
                throw $exception;
            }
        }
        expect(InstanceRename::query()->sole()->phase)->toBe('domain_converged');
    };
    try {
        expect(fn () => $this->orb181Coordinator->execute($instance, false))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.lifecycle_busy'));
    } finally {
        Event::forget($event);
    }
    expect(InstanceRemoval::query()->count())->toBe(0)->and(InstanceRemovalMember::query()->count())->toBe(0)
        ->and($this->orb181Finalizer->calls)->toBeEmpty()->and($this->orb181Projector->calls)->toBeEmpty()
        ->and($this->orb181Lock->acceptedWhileHeld)->toBeFalse()
        ->and($instance->refresh()->status)->toBe(InstanceState::Active)->and($instance->checkout_path)->toBe($path)
        ->and($instance->authoritativeRoute('web')->domain)->toBe($domain);
    app(RenameInstanceAction::class)->execute($instance, new RenameInstanceData(domain: $domain));
    expect(InstanceRename::query()->sole()->phase)->toBe('complete')->and($instance->refresh()->status)->toBe(InstanceState::Active);
});

it('per-app route removal freezes both app owners and retries partial withdrawal', function (): void {
    $instance = orb181_coordinator_instance();
    $instance->update(['status' => InstanceState::SourceResolved]);
    $instance->project->update(['apps' => [
        ['name' => 'web', 'path' => 'apps/web', 'type' => 'laravel-app', 'web_root' => 'public'],
        ['name' => 'docs', 'path' => 'apps/docs', 'type' => 'laravel-app', 'web_root' => 'public'],
    ]]);
    $instance->unsetRelation('project');
    $docs = Route::query()->create([
        'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'docs.main.removal.test', 'app' => 'docs', 'publication' => RoutePublication::Private,
        'provenance' => RouteProvenance::Explicit, 'status' => RouteStatus::Pending,
    ]);
    $docs->targets()->create(['instance_id' => $instance->id, 'app' => 'docs', 'position' => 0]);
    $docs->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);
    $ids = $instance->routes()->orderBy('routes.id')->pluck('routes.id')->all();
    $this->orb181Projector->failAfterRoute = $ids[0];
    expect(fn () => $this->orb181Coordinator->execute($instance, false))->toThrow(InstanceRemovalException::class);
    $member = InstanceRemovalMember::query()->sole();
    expect($member->route_ids)->toBe($ids)
        ->and($member->route_id)->toBeNull()
        ->and($member->runtime_cleaned_at)->toBeNull()
        ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue()
        ->and(Route::query()->whereKey($docs->id)->exists())->toBeTrue();
    expect(fn () => $member->update(['route_ids' => [$docs->id]]))
        ->toThrow(QueryException::class);
    $this->orb181Projector->failAfterRoute = null;
    $removal = $this->orb181Coordinator->execute($instance->refresh(), false);
    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and(Route::query()->whereIn('id', $ids)->count())->toBe(0)
        ->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
});

it('refuses newly dirty teardown before acceptance and requires an explicit force retry', function (): void {
    $instance = orb181_coordinator_instance();
    ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'teardown', 'name' => 'cleanup', 'command' => 'cleanup', 'timeout_seconds' => 30, 'position' => 0]);
    $transport = new LifecycleSshExecutor(result: function () use ($instance): int {
        expect($this->orb181Inspector->calls)->not->toBeEmpty();
        $this->orb181Inspector->normalUnsafeIds[] = $instance->id;

        return 0;
    });
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());
    expect(fn () => $this->orb181Coordinator->execute($instance, false))->toThrow(ResourceOperationException::class)
        ->and(InstanceRemoval::query()->count())->toBe(0);
    $removal = $this->orb181Coordinator->execute($instance, true);
    expect($removal->status->value)->toBe('completed')->and($transport->inputs)->toHaveCount(2);
});

it('refuses source identity changes made by teardown', function (): void {
    $instance = orb181_coordinator_instance();
    ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'teardown', 'name' => 'cleanup', 'command' => 'cleanup', 'timeout_seconds' => 30, 'position' => 0]);
    $transport = new LifecycleSshExecutor(result: function () use ($instance): int {
        $this->orb181Inspector->observedCommits[$instance->id] = str_repeat('b', 40);

        return 0;
    });
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());
    expect(fn () => $this->orb181Coordinator->execute($instance, false))->toThrow(ResourceOperationException::class)
        ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue()
        ->and(InstanceRemoval::query()->count())->toBe(0);
});

it('keeps the route and checkout after a teardown command fails', function (): void {
    $instance = orb181_coordinator_instance();
    ProjectLifecycleStep::query()->create(['project_id' => $instance->project_id, 'phase' => 'teardown', 'name' => 'cleanup', 'command' => 'cleanup', 'timeout_seconds' => 30, 'position' => 0]);
    $transport = new LifecycleSshExecutor(result: static fn (): int => 1);
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());
    expect(fn () => $this->orb181Coordinator->execute($instance, false))->toThrow(ResourceOperationException::class)
        ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue()
        ->and($instance->routes()->count())->toBe(1)
        ->and(InstanceRemoval::query()->count())->toBe(0);
});

it('allows removal only after a failed pre-cutover transfer has finished rollback', function (bool $cutover, ?array $evidence, string $status, bool $closed, array $imports = [], string $step = 'reserved'): void {
    $instance = orb181_coordinator_instance();
    $transfer = InstanceTransfer::query()->create([
        'instance_id' => $instance->id,
        'source_node_id' => $instance->node_id,
        'destination_node_id' => $instance->node_id,
        'destination_name' => 'preview',
        'destination_path' => '/srv/orbit/apps/shop/preview',
        'destination_domain' => 'preview.shop.dev.orbit',
        'source_layout' => 'checkout',
        'source_path' => $instance->checkout_path,
        'source_route_id' => $instance->routes()->sole()->id,
        'status' => $status,
        'current_step' => $step,
        'imported_environment_keys' => $imports,
        'cutover_at' => $cutover ? now() : null,
        'recovery_evidence' => $evidence,
    ]);

    if (! $closed) {
        expect(fn () => $this->orb181Coordinator->execute($instance, false))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.transfer_incomplete'));
        $this->assertModelExists($instance);
        expect(InstanceRemoval::query()->count())->toBe(0);

        return;
    }

    $removal = $this->orb181Coordinator->execute($instance, false);

    expect($removal->status->value)->toBe('completed')
        ->and($transfer->refresh()->instance_id)->toBeNull()
        ->and($transfer->status->value)->toBe('failed');
    $this->assertModelMissing($instance);
})->with([
    'finished rollback' => [false, null, 'failed', true],
    'unfinished rollback' => [false, ['incomplete' => ['source-runtime']], 'failed', false],
    'post-cutover failure' => [true, null, 'failed', false],
    'unfinished transfer' => [false, null, 'in_progress', false],
    'retained imported environment' => [false, null, 'failed', false, ['OWNED_IMPORT']],
    'rollback has not reset the checkpoint' => [false, null, 'failed', false, [], 'source-captured'],
]);

it('accepts exactly one independent checkout and completes every durable step', function (bool $force): void {
    $instance = orb181_coordinator_instance();
    $process = Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['/bin/true']],
        'restart_policy' => 'always',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Failed,
    ]);
    $removal = $this->orb181Coordinator->execute($instance, $force);
    $member = $removal->members->sole();

    expect($removal->status->value)
        ->toBe('completed')
        ->and($removal->force)
        ->toBe($force)
        ->and($removal->total)
        ->toBe(1)
        ->and($member->only([
            'position',
            'instance_id',
            'project_id',
            'node_id',
            'route_id',
            'name',
            'environment',
            'source_layout',
            'repository_identity',
            'checkout_path',
            'root',
            'branch',
            'starting_commit',
            'source_commit',
            'common_repository_path',
            'source_identity',
            'linked_worktree_paths',
            'source_digest',
        ]))
        ->toMatchArray([
            'position' => 0,
            'instance_id' => $instance->id,
            'project_id' => $instance->project_id,
            'node_id' => $instance->node_id,
            'route_id' => $instance->routes->sole()->id,
            'name' => 'dev',
            'environment' => 'development',
            'source_layout' => 'checkout',
            'repository_identity' => $instance->project->repository_identity,
            'checkout_path' => $instance->checkout_path,
            'root' => 'public',
            'branch' => 'dev',
            'starting_commit' => str_repeat('a', 40),
            'source_commit' => str_repeat('a', 40),
            'common_repository_path' => $instance->checkout_path,
            'source_identity' => "test:{$instance->id}",
            'linked_worktree_paths' => [$instance->checkout_path],
        ])
        ->and($member->source_prepared_at)
        ->not->toBeNull()->and($member->route_cleared_at)
        ->not->toBeNull()->and($member->source_finalized_at)
        ->not->toBeNull()->and($member->runtime_cleaned_at)
        ->not->toBeNull()->and($member->row_deleted_at)
        ->not->toBeNull()->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse()->and(
            Route::query()->count(),
        )->toBe(0)->and($this->orb181Finalizer->calls)->toBe([
            "prepare:{$instance->id}",
            "revalidate:{$instance->id}:present",
            "finalize:{$instance->id}:present",
        ])->and($this->orb181Projector->calls)->toBe([
            "route:{$instance->id}",
            "runtime:{$instance->id}",
        ])->and($this->orb131ProcessLock->owners)->toBe([[$instance->id]])->and(
            $this->orb131ProcessRuntime->removed,
        )->toBe([$process->id])->and($this->orb181Lock->acceptedWhileHeld)->toBeTrue();
})->with([false, true]);

it('cascades owned Schedules before successful Instance row deletion', function (): void {
    $runtime = new FakeScheduleRuntimeManager;
    app()->instance(ScheduleRuntimeManager::class, $runtime);
    $instance = orb181_coordinator_instance();
    $schedule = Schedule::query()->create([
        'target_type' => Instance::MorphAlias,
        'target_id' => $instance->id,
        'host_node_id' => $instance->node_id,
        'name' => 'daily',
        'calendar' => 'daily',
        'command' => 'true',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);

    $removal = $this->orb181Coordinator->execute($instance, false);

    expect($removal->status->value)->toBe('completed')
        ->and(Schedule::query()->whereKey($schedule->id)->exists())->toBeFalse()
        ->and($runtime->removed)->toBe([['id' => $schedule->id, 'cascade' => true]]);
});

it('blocks new Schedules for every member after forced removal accepts its fixed set', function (): void {
    [$checkout, $first, $second] = orb182_coordinator_graph();
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;
    $this->orb181Finalizer->failPrepareFor = $first->id;

    expect(fn () => $this->orb181Coordinator->execute($checkout, true))
        ->toThrow(InstanceRemovalException::class);

    $runtime = new FakeScheduleRuntimeManager;
    $addSchedule = new AddScheduleAction(
        new ScheduleSpecificationValidator,
        new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver),
        $runtime,
        $this->orb131ProcessLock,
    );

    foreach ([$checkout, $first, $second] as $member) {
        expect($member->refresh()->status)->toBe(InstanceState::Removing)
            ->and(fn () => $addSchedule->execute(orb72_coordinator_schedule_data($member)))
            ->toThrow(fn (ResourceOperationException $exception): bool => str_starts_with($exception->errorCode, 'schedule.'));
    }

    expect(Schedule::query()->count())->toBe(0)
        ->and($runtime->installed)->toBeEmpty();
});

it('cascades every forced-set Schedule and preserves another Instance Schedule on the same Node', function (): void {
    $runtime = new FakeScheduleRuntimeManager;
    app()->instance(ScheduleRuntimeManager::class, $runtime);
    [$checkout, $first, $second] = orb182_coordinator_graph();
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;
    $owned = collect([$checkout, $first, $second])->map(
        static fn (Instance $member): Schedule => orb72_coordinator_schedule($member, "daily-{$member->id}"),
    );
    $otherApp = Project::query()->create([
        'name' => 'Other',
        'slug' => 'other',
        'repository_url' => 'https://example.test/other.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $otherInstance = Instance::query()->create([
        'project_id' => $otherApp->id,
        'node_id' => $checkout->node_id,
        'name' => 'other',
        'environment' => 'development',
        'source_layout' => InstanceSourceLayout::Checkout->value,
        'checkout_path' => '/srv/orbit/apps/other/dev',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $otherSchedule = orb72_coordinator_schedule($otherInstance, 'other-daily');
    $nodeSchedule = Schedule::query()->create([
        'target_type' => Node::class,
        'target_id' => $checkout->node_id,
        'host_node_id' => $checkout->node_id,
        'name' => 'node-daily',
        'calendar' => 'daily',
        'command' => 'true',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);

    $removal = $this->orb181Coordinator->execute($checkout, true);

    expect($removal->status->value)->toBe('completed')
        ->and(Schedule::query()->whereKey($owned->pluck('id'))->count())->toBe(0)
        ->and($otherSchedule->fresh())->not->toBeNull()
        ->and($nodeSchedule->fresh())->not->toBeNull()
        ->and($runtime->removed)->toHaveCount(3);

    foreach ($owned as $schedule) {
        expect($runtime->removed)->toContain(['id' => $schedule->id, 'cascade' => true]);
    }
});

it('records historical and observed source commits independently', function (
    bool $force,
    string $observedCommit,
): void {
    $instance = orb181_coordinator_instance();
    $this->orb181Inspector->observedCommits[$instance->id] = $observedCommit;

    $member = $this->orb181Coordinator->execute($instance, $force)->members->sole();

    expect($member->starting_commit)
        ->toBe(str_repeat('a', 40))
        ->and($member->source_commit)
        ->toBe($observedCommit);
})->with([
    'normal newer published source' => [false, str_repeat('b', 40)],
    'forced unpublished source' => [true, str_repeat('c', 40)],
]);

it('removes one worktree in either mode while preserving its siblings', function (bool $force): void {
    [$checkout, $first, $second] = orb182_coordinator_graph();
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;

    $removal = $this->orb181Coordinator->execute($first, $force);

    expect($removal->total)
        ->toBe(1)
        ->and($removal->status->value)
        ->toBe('completed')
        ->and($removal->members->sole()->instance_id)
        ->toBe($first->id)
        ->and(Instance::query()->whereKey([$checkout->id, $second->id])->count())
        ->toBe(2)
        ->and(
            Route::query()
                ->whereHas('targets', fn ($query) => $query->whereIn(
                    'instance_id',
                    [$checkout->id, $second->id],
                ))
                ->count(),
        )
        ->toBe(2)
        ->and($this->orb181Inspector->linkedPaths)
        ->not
        ->toContain($first->checkout_path)
        ->and($this->orb181Inspector->linkedPaths)
        ->toContain($checkout->checkout_path, $second->checkout_path);
})->with([false, true]);

it('refuses normal checkout cascade then removes the immutable worktree-first set with force', function (): void {
    [$checkout, $first, $second] = orb182_coordinator_graph();
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;

    expect(fn () => $this->orb181Coordinator->execute($checkout, false))
        ->toThrow(ResourceOperationException::class, 'retry with --force');
    expect(Instance::query()->count())->toBe(3)->and(Route::query()->count())->toBe(3);

    $removal = $this->orb181Coordinator->execute($checkout->refresh(), true);
    $members = $removal->members()->orderBy('position')->get();

    expect($members->pluck('instance_id')->all())
        ->toBe([$first->id, $second->id, $checkout->id])
        ->and($this->orb212EnvironmentLock->owners)
        ->toBe([[$checkout->id], [$checkout->id, $first->id, $second->id]])
        ->and($members->every(fn (InstanceRemovalMember $member): bool => $member->linked_worktree_paths === $paths))
        ->toBeTrue()
        ->and($members->pluck('source_digest')->unique()->count())
        ->toBe(3)
        ->and($this->orb181Finalizer->finalizeExpectations)
        ->toBe([
            $first->id => $paths,
            $second->id => [$checkout->checkout_path, $second->checkout_path],
            $checkout->id => [$checkout->checkout_path],
        ])
        ->and($this->orb181Projector->calls)
        ->toBe([
            "route:{$first->id}",
            "runtime:{$first->id}",
            "route:{$second->id}",
            "runtime:{$second->id}",
            "route:{$checkout->id}",
            "runtime:{$checkout->id}",
        ])
        ->and(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0);
});

it('returns force guidance before inspecting unsafe checkout content', function (): void {
    [$checkout, $first, $second] = orb182_coordinator_graph();
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;
    $this->orb181Inspector->normalUnsafeIds = [$checkout->id];

    expect(fn () => $this->orb181Coordinator->execute($checkout, false))
        ->toThrow(ResourceOperationException::class, 'retry with --force');
    expect($this->orb181Inspector->calls)
        ->toBe(["inspect:{$checkout->id}:normal"])
        ->and(InstanceRemovalMember::query()->count())
        ->toBe(0)
        ->and(Instance::query()->where('status', InstanceState::Active->value)->count())
        ->toBe(3);
});

it('leaves an owned running Process unchanged when source preflight refuses removal', function (): void {
    $instance = orb181_coordinator_instance();
    $process = Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['/bin/true']],
        'restart_policy' => 'always',
        'desired_state' => 'running',
        'status' => LifecycleStatus::Active,
    ]);
    $original = $process->fresh()->getRawOriginal();
    $this->orb181Inspector->normalUnsafeIds = [$instance->id];

    expect(fn () => $this->orb181Coordinator->execute($instance, false))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.remove_refused');
        });

    expect($process->refresh()->getRawOriginal())
        ->toBe($original)
        ->and($this->orb131ProcessLock->owners)
        ->toBeEmpty()
        ->and($this->orb131ProcessRuntime->removed)
        ->toBeEmpty();
});

it('keeps normal refusal semantics when checkout inventory inspection fails', function (): void {
    [$checkout] = orb182_coordinator_graph();
    $this->orb181Inspector->inspectionFailureIds = [$checkout->id];
    $exception = null;

    try {
        $this->orb181Coordinator->execute($checkout, false);
    } catch (ResourceOperationException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->toBeInstanceOf(ResourceOperationException::class)
        ->and($exception?->errorCode)
        ->toBe('instance.remove_refused')
        ->and($this->orb181Inspector->calls)
        ->toBe(["inspect:{$checkout->id}:normal"])
        ->and(InstanceRemovalMember::query()->count())
        ->toBe(0);
});

it('answers the identity check that inspection refused in normal and forced removal', function (bool $force): void {
    [$checkout] = orb182_coordinator_graph();
    $this->orb181Inspector->inspectionFailureIds = [$checkout->id];
    $this->orb181Inspector->inspectionFailureCode = 'instance.source_origin_mismatch';

    expect(fn () => $this->orb181Coordinator->execute($checkout, $force))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('instance.source_origin_mismatch')
                ->and($exception->status)
                ->toBe(409)
                ->and($exception->getMessage())
                ->toBe('Source inspection failed.');
        });
    expect($this->orb181Inspector->calls)
        ->toBe(['inspect:'.$checkout->id.':'.($force ? 'force' : 'normal')])
        ->and(InstanceRemovalMember::query()->count())
        ->toBe(0)
        ->and($this->orb181Finalizer->calls)
        ->toBeEmpty();
})->with([
    'normal removal' => false,
    'force' => true,
]);

it('refuses an unregistered checkout member before accepting either mode', function (bool $force): void {
    [$checkout, $first] = orb182_coordinator_graph();
    $unknown = '/srv/orbit/apps/acme/unregistered';
    $paths = [$checkout->checkout_path, $first->checkout_path, $unknown];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;

    expect(fn () => $this->orb181Coordinator->execute($checkout, $force))
        ->toThrow(ResourceOperationException::class, 'Every linked worktree must be a registered Instance');
    expect(InstanceRemovalMember::query()->count())
        ->toBe(0)
        ->and(Instance::query()->count())
        ->toBe(3)
        ->and(Route::query()->count())
        ->toBe(3)
        ->and($this->orb181Finalizer->calls)
        ->toBeEmpty();
})->with([false, true]);

it('refuses a new source on retry before the next member changes', function (): void {
    [$checkout, $first, $second] = orb182_coordinator_graph();
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;
    $this->orb181Finalizer->failPrepareFor = $second->id;

    expect(fn () => $this->orb181Coordinator->execute($checkout, true))
        ->toThrow(InstanceRemovalException::class);
    $operation = $checkout->refresh()->removalMember?->removal;
    $members = $operation?->members()->orderBy('position')->get();
    expect($members?->get(0)?->row_deleted_at)->not->toBeNull()->and($members?->get(1)?->route_cleared_at)->toBeNull();

    $unknown = '/srv/orbit/apps/acme/new-worktree';
    $this->orb181Inspector->linkedPaths[] = $unknown;
    sort($this->orb181Inspector->linkedPaths, SORT_STRING);
    $this->orb181Finalizer->failPrepareFor = null;

    expect(fn () => $this->orb181Coordinator->execute($checkout->refresh(), true))
        ->toThrow(InstanceRemovalException::class);
    expect($operation?->refresh()->error_code)
        ->toBe('instance.removal_conflict')
        ->and($operation?->members()->count())
        ->toBe(3)
        ->and($members?->get(1)?->refresh()->route_cleared_at)
        ->toBeNull()
        ->and(
            Route::query()
                ->whereHas('targets', fn ($query) => $query->where(
                    'instance_id',
                    $second->id,
                ))
                ->exists(),
        )
        ->toBeTrue();
});

it('refuses a replacement at a completed member path before the next member changes', function (): void {
    [$checkout, $first, $second] = orb182_coordinator_graph();
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;
    $this->orb181Finalizer->failPrepareFor = $second->id;

    expect(fn () => $this->orb181Coordinator->execute($checkout, true))
        ->toThrow(InstanceRemovalException::class);
    $operation = $checkout->refresh()->removalMember?->removal;
    $members = $operation?->members()->orderBy('position')->get();
    expect($members?->first()?->row_deleted_at)->not->toBeNull();

    $this->orb181Finalizer->failPrepareFor = null;
    $this->orb181Finalizer->replacementPaths = [$first->checkout_path];

    expect(fn () => $this->orb181Coordinator->execute($checkout->refresh(), true))
        ->toThrow(InstanceRemovalException::class);
    expect($operation?->refresh()->error_code)
        ->toBe('instance.removal_conflict')
        ->and($members?->get(1)?->refresh()->route_cleared_at)
        ->toBeNull()
        ->and(
            Route::query()
                ->whereHas('targets', fn ($query) => $query->where('instance_id', $second->id))
                ->exists(),
        )
        ->toBeTrue();
});

it('finishes receipt-backed cleanup before advancing the remaining fixed members', function (): void {
    [$checkout, $first, $second] = orb182_coordinator_graph();
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;
    $this->orb181Finalizer->failAfterPartialReceipt = true;

    expect(fn () => $this->orb181Coordinator->execute($checkout, true))
        ->toThrow(InstanceRemovalException::class);
    $operation = $checkout->refresh()->removalMember?->removal;
    $firstMember = $operation?->members()->orderBy('position')->firstOrFail();
    expect($firstMember?->instance_id)
        ->toBe($first->id)
        ->and($firstMember?->source_finalized_at)
        ->toBeNull()
        ->and($this->orb181Finalizer->states[$first->id])
        ->toBe(InstanceSourceRevalidationState::ReceiptPendingCleanup);

    $this->orb181Finalizer->failAfterPartialReceipt = false;
    $removal = $this->orb181Coordinator->execute($checkout->refresh(), true);

    expect($removal->status->value)
        ->toBe('completed')
        ->and($removal->total)
        ->toBe(3)
        ->and($this->orb181Finalizer->calls)
        ->toContain(
            "revalidate:{$first->id}:receipt-pending-cleanup",
            "finalize:{$first->id}:receipt-pending-cleanup",
        )
        ->and($this->orb181Projector->calls)
        ->toBe([
            "route:{$first->id}",
            "runtime:{$first->id}",
            "route:{$second->id}",
            "runtime:{$second->id}",
            "route:{$checkout->id}",
            "runtime:{$checkout->id}",
        ]);
});

it('retains the requested checkout and completed member evidence when final completion fails', function (): void {
    [$checkout, $first, $second] = orb182_coordinator_graph();
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER orb182_fail_final_cascade_completion
        BEFORE UPDATE OF status ON instance_removals
        WHEN NEW.status = 'completed'
        BEGIN
            SELECT RAISE(ABORT, 'Injected final cascade completion failure.');
        END
        SQL);

    try {
        expect(fn () => $this->orb181Coordinator->execute($checkout, true))
            ->toThrow(InstanceRemovalException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS orb182_fail_final_cascade_completion');
    }

    $operation = $checkout->refresh()->removalMember?->removal;
    $members = $operation?->members()->orderBy('position')->get();
    expect($operation?->refresh()->status->value)
        ->toBe('failed')
        ->and($operation?->total)
        ->toBe(3)
        ->and($members?->get(0)?->row_deleted_at)
        ->not->toBeNull()->and($members?->get(1)?->row_deleted_at)
        ->not->toBeNull()->and($members?->get(2)?->row_deleted_at)->toBeNull()->and(
            Instance::query()->whereKey($checkout->id)->sole()->status,
        )->toBe(InstanceState::Removing);

    $removal = $this->orb181Coordinator->execute($checkout->refresh(), true);

    expect($removal->status->value)
        ->toBe('completed')
        ->and($removal->members->pluck('row_deleted_at')->filter()->count())
        ->toBe(3)
        ->and($this->orb181Projector->calls)
        ->toBe([
            "route:{$first->id}",
            "runtime:{$first->id}",
            "route:{$second->id}",
            "runtime:{$second->id}",
            "route:{$checkout->id}",
            "runtime:{$checkout->id}",
        ]);
});

it('completes one production removal with retained-content evidence and no development source calls', function (): void {
    $instance = orb181_coordinator_instance(environment: 'production');
    $routeId = $instance->routes->sole()->id;
    $removal = $this->orb181Coordinator->execute($instance, false);
    $member = $removal->members->sole();

    expect($removal->status->value)
        ->toBe('completed')
        ->and($removal->total)
        ->toBe(1)
        ->and($member->environment)
        ->toBe('production')
        ->and($member->linked_worktree_paths)
        ->toBe([])
        ->and($member->route_outcome)
        ->toBe('deleted')
        ->and($member->finalization_receipt)
        ->toBe(hash('sha256', "production-retained\0{$member->source_digest}"))
        ->and(Route::query()->find($routeId))
        ->toBeNull()
        ->and(Instance::query()->find($instance->id))
        ->toBeNull()
        ->and($this->orb181Inspector->calls)
        ->toBeEmpty()
        ->and($this->orb181Finalizer->calls)
        ->toBeEmpty()
        ->and($this->orb183Content->calls)
        ->toBe([
            "inventory:{$instance->id}",
            "prepare:{$instance->id}",
            "revalidate:{$instance->id}",
            "finalize:{$instance->id}",
        ]);
});

it('resumes production cleanup without restoring a cleared target or deleted Route', function (): void {
    $instance = orb181_coordinator_instance(environment: 'production');
    $routeId = $instance->routes->sole()->id;
    $this->orb181Projector->failRuntime = true;

    expect(fn () => $this->orb181Coordinator->execute($instance, false))
        ->toThrow(InstanceRemovalException::class);
    $member = InstanceRemovalMember::query()->sole();
    expect($member->route_cleared_at)
        ->not->toBeNull()->and($member->source_finalized_at)
        ->not->toBeNull()->and($member->runtime_cleaned_at)->toBeNull()->and(Route::query()->find(
            $routeId,
        ))->toBeNull()->and($instance->refresh()->status)->toBe(InstanceState::Removing);

    $this->orb181Projector->failRuntime = false;
    $removal = $this->orb181Coordinator->execute($instance->refresh(), false);

    expect($removal->status->value)
        ->toBe('completed')
        ->and(Route::query()->find($routeId))
        ->toBeNull()
        ->and($this->orb181Projector->calls)
        ->toBe([
            "route:{$instance->id}",
            "runtime:{$instance->id}",
            "runtime:{$instance->id}",
        ]);
});

it('recovers a completed source receipt after its checkpoint transaction fails', function (): void {
    $instance = orb181_coordinator_instance();
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER orb181_fail_source_finalized_checkpoint
        BEFORE UPDATE OF source_finalized_at ON instance_removal_members
        WHEN NEW.source_finalized_at IS NOT NULL
        BEGIN
            SELECT RAISE(ABORT, 'Injected source checkpoint failure.');
        END
        SQL);

    try {
        expect(fn () => $this->orb181Coordinator->execute($instance, false))
            ->toThrow(InstanceRemovalException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS orb181_fail_source_finalized_checkpoint');
    }

    $member = InstanceRemovalMember::query()->sole();
    expect($member->source_finalized_at)
        ->toBeNull()
        ->and($member->removal()->firstOrFail()->status->value)
        ->toBe('failed')
        ->and($this->orb181Finalizer->states[$instance->id])
        ->toBe(InstanceSourceRevalidationState::Completed)
        ->and($this->orb181Finalizer->inspectRecordedCalls)
        ->toBe(0);

    $removal = $this->orb181Coordinator->execute($instance->refresh(), false);

    expect($removal->status->value)
        ->toBe('completed')
        ->and(Instance::query()->whereKey($instance->id)->exists())
        ->toBeFalse()
        ->and($this->orb181Finalizer->inspectRecordedCalls)
        ->toBe(0)
        ->and($this->orb181Finalizer->calls)
        ->toBe([
            "prepare:{$instance->id}",
            "revalidate:{$instance->id}:present",
            "finalize:{$instance->id}:present",
            "revalidate:{$instance->id}:completed",
            "revalidate:{$instance->id}:completed",
            "finalize:{$instance->id}:completed",
        ]);
});

it('recovers authenticated receipt cleanup without re-inspecting partial source state', function (): void {
    $instance = orb181_coordinator_instance();
    $this->orb181Finalizer->failAfterPartialReceipt = true;

    expect(fn () => $this->orb181Coordinator->execute($instance, false))
        ->toThrow(InstanceRemovalException::class);
    expect(InstanceRemovalMember::query()->sole()->source_finalized_at)
        ->toBeNull()
        ->and($this->orb181Finalizer->states[$instance->id])
        ->toBe(InstanceSourceRevalidationState::ReceiptPendingCleanup)
        ->and($this->orb181Finalizer->inspectRecordedCalls)
        ->toBe(0);

    $this->orb181Finalizer->failAfterPartialReceipt = false;
    $removal = $this->orb181Coordinator->execute($instance->refresh(), false);

    expect($removal->status->value)
        ->toBe('completed')
        ->and(Instance::query()->whereKey($instance->id)->exists())
        ->toBeFalse()
        ->and($this->orb181Finalizer->inspectRecordedCalls)
        ->toBe(0)
        ->and($this->orb181Finalizer->calls)
        ->toContain(
            "revalidate:{$instance->id}:receipt-pending-cleanup",
            "finalize:{$instance->id}:receipt-pending-cleanup",
        );
});

it('lets force take over a failed normal removal without repeating completed steps', function (): void {
    $instance = orb181_coordinator_instance();
    $this->orb181Projector->failRuntime = true;

    expect(fn () => $this->orb181Coordinator->execute($instance, false))
        ->toThrow(InstanceRemovalException::class);
    $operation = InstanceRemoval::query()->sole();
    $member = $operation->members->sole();
    $this->orb181Projector->failRuntime = false;
    $calls = $this->orb181Finalizer->calls;

    $completed = $this->orb181Coordinator->execute($instance->refresh(), true);

    expect($completed->id)->toBe($operation->id)
        ->and($completed->force)->toBeTrue()
        ->and($completed->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($completed->members->sole()->source_finalized_at->equalTo($member->source_finalized_at))->toBeTrue()
        ->and($this->orb181Finalizer->calls)->toBe([...$calls, "revalidate:{$instance->id}:completed"])
        ->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
});

it('refuses force takeover while a normal removal is still running', function (): void {
    $instance = orb181_coordinator_instance();
    $this->orb181Projector->failRuntime = true;
    expect(fn () => $this->orb181Coordinator->execute($instance, false))
        ->toThrow(InstanceRemovalException::class);
    $operation = InstanceRemoval::query()->sole();
    $operation->update([
        'status' => InstanceRemovalStatus::Removing,
        'failed_step' => null,
        'error_code' => null,
    ]);
    expect(fn () => $operation->update(['force' => true]))->toThrow(QueryException::class);

    expect(fn () => $this->orb181Coordinator->execute($instance->refresh(), true))
        ->toThrow(ResourceOperationException::class, 'different removal request');
    expect(InstanceRemoval::query()->sole()->force)->toBeFalse();
});

it('keeps identity guards when force takes over failed source preparation', function (): void {
    $instance = orb181_coordinator_instance();
    $this->orb181Finalizer->failPrepareFor = $instance->id;
    expect(fn () => $this->orb181Coordinator->execute($instance, false))
        ->toThrow(InstanceRemovalException::class);
    $operation = InstanceRemoval::query()->sole();
    $this->orb181Finalizer->failPrepareFor = null;
    $this->orb181Finalizer->replacementPaths = [$instance->checkout_path];

    expect(fn () => $this->orb181Coordinator->execute($instance->refresh(), true))
        ->toThrow(InstanceRemovalException::class);

    expect($operation->refresh()->force)->toBeTrue()
        ->and($operation->status)->toBe(InstanceRemovalStatus::Failed)
        ->and($operation->error_code)->toBe('instance.removal_conflict')
        ->and($operation->members->sole()->source_finalized_at)->toBeNull()
        ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue();
    expect(fn () => $operation->update(['force' => false]))->toThrow(QueryException::class);
});

it('refuses to downgrade a forced removal on retry', function (): void {
    $instance = orb181_coordinator_instance();
    $this->orb181Projector->failRuntime = true;

    expect(fn () => $this->orb181Coordinator->execute($instance, true))
        ->toThrow(InstanceRemovalException::class)
        ->and(fn () => $this->orb181Coordinator->execute($instance->refresh(), false))
        ->toThrow(ResourceOperationException::class, 'different removal request');
    expect($instance->refresh()->status)
        ->toBe(InstanceState::Removing)
        ->and($instance->removalMember?->removal->force)
        ->toBeTrue();
});

it('closes a public Route handler before deleting its identity and leaves unrelated public Routes', function (): void {
    $removed = orb181_coordinator_instance('production');
    $removed->routes->sole()->update(['publication' => RoutePublication::Public]);
    $survivor = orb181_coordinator_instance('production');
    $survivor->routes->sole()->update([
        'publication' => RoutePublication::Public,
    ]);

    $this->orb181Coordinator->execute($removed, true);

    expect(Route::query()->find($removed->routes->sole()->id))
        ->toBeNull()
        ->and($survivor->routes->sole()->refresh()->publication)
        ->toBe(RoutePublication::Public)
        ->and($this->orb181Projector->calls)
        ->toContain('remove-public-edge:'.$removed->id)
        ->and(Instance::query()->whereKey($removed->id)->exists())
        ->toBeFalse();
});

it('removes an Instance that has no Route without touching Route projections', function (string $environment): void {
    $instance = orb181_coordinator_instance(environment: $environment, withRoute: false);
    $removal = $this->orb181Coordinator->execute($instance, false);
    $member = $removal->members->sole();

    expect($removal->status->value)
        ->toBe('completed')
        ->and($member->route_id)
        ->toBeNull()
        ->and($member->route_outcome)
        ->toBe('none')
        ->and($member->route_cleared_at)
        ->not->toBeNull()
        ->and($member->row_deleted_at)
        ->not->toBeNull()
        ->and(Instance::query()->whereKey($instance->id)->exists())
        ->toBeFalse()
        ->and($this->orb181Projector->calls)
        ->toBe(["runtime:{$instance->id}"]);
})->with(['development', 'production']);

it('removes a source-resolved task workspace that never received a Route and converges its Node', function (): void {
    $instance = orb181_coordinator_instance(withRoute: false);
    $instance->project->update(['type' => ProjectType::LaravelApp]);
    $instance->update(['status' => InstanceState::SourceResolved]);
    $removal = $this->orb181Coordinator->execute($instance->refresh()->load(['project', 'node', 'routes.targets']), true);
    $member = $removal->members->sole();

    expect($removal->status->value)
        ->toBe('completed')
        ->and($member->route_outcome)
        ->toBe('none')
        ->and($member->source_finalized_at)
        ->not->toBeNull()
        ->and($member->runtime_published)
        ->toBeFalse()
        ->and($this->orb181Projector->calls)
        ->toBe(["runtime:{$instance->id}"])
        ->and(Instance::query()->whereKey($instance->id)->exists())
        ->toBeFalse();
});

it('removes a source-resolved Instance with a pending Route and preserves the worktree seed', function (bool $force, string $routeStatus): void {
    $instance = orb1193_coordinator_workspace();
    $route = $instance->routes->sole();
    $route->update([
        'status' => $routeStatus,
        'failed_step' => $routeStatus === 'failed' ? 'publication' : null,
        'error_code' => $routeStatus === 'failed' ? 'route.publication_failed' : null,
    ]);
    $target = $route->targets->sole();
    $seedPath = dirname($instance->checkout_path).'/default';
    $this->orb181Inspector->commonRepositoryPath = $seedPath;
    $this->orb181Inspector->linkedPaths = [$seedPath, $instance->checkout_path];

    $removal = $this->orb181Coordinator->execute($instance, $force);
    $member = $removal->members->sole();

    expect($removal->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($removal->total)->toBe(1)
        ->and($member->source_layout)->toBe(InstanceSourceLayout::Worktree->value)
        ->and($member->common_repository_path)->toBe($seedPath)
        ->and($member->route_id)->toBe($route->id)
        ->and($member->route_outcome)->toBe('deleted')
        ->and($member->source_finalized_at)->not->toBeNull()
        ->and($member->runtime_published)->toBeFalse()
        ->and($this->orb181Finalizer->calls)->toBe([
            "prepare:{$instance->id}",
            "revalidate:{$instance->id}:present",
            "finalize:{$instance->id}:present",
        ])
        ->and($this->orb181Projector->calls)->toBe(["route:{$instance->id}", "runtime:{$instance->id}"]);
    $this->assertModelMissing($instance);
    $this->assertModelMissing($route);
    $this->assertModelMissing($target);
})->with([false, true])->with(['pending', 'failed']);

it('refuses a source-resolved workspace with an active Route in either removal mode', function (bool $force): void {
    $instance = orb1193_coordinator_workspace();
    $route = $instance->routes->sole();
    $route->update(['status' => RouteStatus::Active]);

    expect(fn () => $this->orb181Coordinator->execute($instance, $force))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused'));
    $this->assertModelExists($instance);
    $this->assertModelExists($route);
    expect(InstanceRemoval::query()->count())->toBe(0)
        ->and($this->orb181Finalizer->calls)->toBeEmpty();
})->with([false, true]);

it('refuses a source-resolved workspace whose pending Route also targets another Instance', function (bool $force): void {
    $instance = orb1193_coordinator_workspace();
    $route = $instance->routes->sole();
    $other = Instance::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'name' => 'other',
        'environment' => 'development',
        'source_layout' => 'worktree',
        'checkout_path' => dirname($instance->checkout_path).'/other',
        'branch' => 'other',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::SourceResolved,
    ]);
    // Simulate inconsistent stored targets without weakening the production persistence contract.
    $trigger = DB::table('sqlite_master')->where('name', 'route_targets_contract_insert')->sole()->sql;
    DB::statement('DROP TRIGGER route_targets_contract_insert');
    try {
        $target = $route->targets()->create(['instance_id' => $other->id, 'position' => 1]);
    } finally {
        DB::statement($trigger);
    }

    expect(fn () => $this->orb181Coordinator->execute($instance, $force))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused'));
    $this->assertModelExists($instance);
    $this->assertModelExists($other);
    $this->assertModelExists($route);
    $this->assertModelExists($target);
    expect(InstanceRemoval::query()->count())->toBe(0)
        ->and($this->orb181Finalizer->calls)->toBeEmpty();
})->with([false, true]);

it('refuses a source-resolved production Instance with a pending Route', function (bool $force): void {
    $instance = orb181_coordinator_instance(environment: 'production');
    $route = $instance->routes->sole();
    $instance->update(['status' => InstanceState::SourceResolved]);
    $route->update(['status' => RouteStatus::Pending]);

    expect(fn () => $this->orb181Coordinator->execute($instance, $force))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused'));
    $this->assertModelExists($instance);
    $this->assertModelExists($route);
    expect(InstanceRemoval::query()->count())->toBe(0)
        ->and($this->orb183Content->calls)->toBeEmpty();
})->with([false, true]);

it('resumes a routed source-resolved workspace removal without repeating Route deletion', function (): void {
    $instance = orb1193_coordinator_workspace();
    $route = $instance->routes->sole();
    $target = $route->targets->sole();
    $this->orb181Projector->failRuntime = true;

    expect(fn () => $this->orb181Coordinator->execute($instance, false))->toThrow(InstanceRemovalException::class);
    $operation = InstanceRemoval::query()->sole();
    expect($operation->status)->toBe(InstanceRemovalStatus::Failed);
    $this->assertModelExists($instance);
    $this->assertModelMissing($route);
    $this->assertModelMissing($target);
    $this->orb181Projector->failRuntime = false;

    $resumed = $this->orb181Coordinator->execute($instance->refresh(), false);

    expect($resumed->id)->toBe($operation->id)
        ->and($resumed->status)->toBe(InstanceRemovalStatus::Completed)
        ->and($this->orb181Projector->calls)->toBe([
            "route:{$instance->id}", "runtime:{$instance->id}", "runtime:{$instance->id}",
        ]);
    $this->assertModelMissing($instance);
});

it('keeps normal source refusals for a source-resolved workspace with a pending Route', function (): void {
    $instance = orb1193_coordinator_workspace();
    $route = $instance->routes->sole();
    $this->orb181Inspector->normalUnsafeIds[] = $instance->id;

    expect(fn () => $this->orb181Coordinator->execute($instance, false))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused'));
    $this->assertModelExists($instance);
    $this->assertModelExists($route);
    expect(InstanceRemoval::query()->count())->toBe(0);
});

it('upgrades and reverses the routed workspace removal persistence guards', function (): void {
    $instance = orb1193_coordinator_workspace();
    $migration = require database_path('migrations/2026_10_19_000000_allow_source_resolved_workspace_route_removal.php');
    undo_named_app_removal_inventory_guard_for_migration_test();
    $migration->down();
    try {
        expect(fn () => $this->orb181Coordinator->execute($instance, false))
            ->toThrow(QueryException::class, 'Invalid AppInstance removal contract.');
        $this->assertModelExists($instance);
        expect(InstanceRemoval::query()->count())->toBe(0);
    } finally {
        $migration->up();
    }

    $removal = $this->orb181Coordinator->execute($instance, false);

    expect($removal->status)->toBe(InstanceRemovalStatus::Completed);
    $this->assertModelMissing($instance);
});

function orb1193_coordinator_workspace(): Instance
{
    $instance = orb181_coordinator_instance(layout: InstanceSourceLayout::Worktree->value, withRoute: false);
    $instance->project->update(['type' => ProjectType::LaravelApp]);
    $cluster = Cluster::query()->create(['name' => 'workspace-'.$instance->id, 'state' => 'active']);
    $instance->node->update(['cluster_id' => $cluster->id]);
    $instance->update([
        'status' => InstanceState::SourceResolved,
        'task_workspace_routed' => false,
        'source_prepare_id' => (string) Str::uuid(),
    ]);
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => null,
        'cluster_id' => $cluster->id,
        'generation_basis_node_id' => $instance->node_id,
        'domain' => 'workspace-'.$instance->id.'.acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);

    return $instance->refresh()->load(['project', 'node', 'routes.targets']);
}

it('withdraws the runtime of a source-resolved Instance whose pending Route was destroyed before removal', function (): void {
    // The 2026-10-08 incident: the pending Route's sites had published a PHP-FPM pool, `route:destroy`
    // deleted the Route row, and removal then saw no Route and no active runtime, so it skipped the
    // converge and deleted the checkout under a live pool.
    $instance = orb181_coordinator_instance();
    $instance->update(['status' => InstanceState::SourceResolved]);
    $instance->routes->sole()->update(['status' => RouteStatus::Pending]);
    $instance->routes->sole()->delete();
    $removal = $this->orb181Coordinator->execute($instance->refresh()->load(['project', 'node', 'routes.targets']), true);
    $member = $removal->members->sole();

    expect($removal->status->value)
        ->toBe('completed')
        ->and($member->route_id)
        ->toBeNull()
        ->and($member->runtime_published)
        ->toBeFalse()
        ->and($this->orb181Projector->calls)
        ->toBe(["runtime:{$instance->id}"]);
});

it('removes a failed source-resolved development Instance and its partial routed runtime', function (): void {
    $instance = orb181_coordinator_instance();
    $instance->update(['status' => InstanceState::SourceResolved, 'failed_step' => 'provisioning', 'error_code' => 'instance.provisioning_failed']);

    $this->orb181Coordinator->execute($instance->refresh()->load(['project', 'node', 'routes.targets']), true);
    expect(Instance::query()->whereKey($instance->id)->exists())->toBeFalse()
        ->and($this->orb181Projector->calls)->toBe(["route:{$instance->id}", "runtime:{$instance->id}"]);
});

it('removes the Route an operator set on a monorepo Instance', function (): void {
    $instance = orb181_coordinator_instance();
    $instance->project->update(['type' => ProjectType::Monorepo]);
    $routeId = $instance->routes->sole()->id;
    $removal = $this->orb181Coordinator->execute($instance->refresh()->load(['project', 'node', 'routes.targets']), false);
    $member = $removal->members->sole();

    expect($removal->status->value)
        ->toBe('completed')
        ->and($member->route_id)
        ->toBe($routeId)
        ->and($member->route_outcome)
        ->toBe('deleted')
        ->and(Route::query()->find($routeId))
        ->toBeNull()
        ->and($this->orb181Projector->calls)
        ->toBe(["route:{$instance->id}", "runtime:{$instance->id}"]);
});

it('refuses removal evidence that hides a Route target or claims no Route for a routed Instance', function (): void {
    $routed = orb181_coordinator_instance();
    $routeless = orb181_coordinator_instance(withRoute: false);
    $removal = fn (Instance $instance): InstanceRemoval => InstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => false,
        'inventory_digest' => str_repeat('d', 64),
        'total' => 1,
        'status' => 'removing',
        'current_step' => 'source_preparation',
    ]);
    $member = static fn (InstanceRemoval $operation, Instance $instance, ?int $routeId): InstanceRemovalMember => $operation->members()->create([
        'position' => 0,
        'instance_id' => $instance->id,
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'route_id' => $routeId,
        'name' => $instance->name,
        'environment' => 'development',
        'source_layout' => 'checkout',
        'repository_identity' => $instance->project->repository_identity,
        'checkout_path' => $instance->checkout_path,
        'root' => 'public',
        'branch' => 'dev',
        'starting_commit' => str_repeat('a', 40),
        'source_commit' => str_repeat('a', 40),
        'common_repository_path' => $instance->checkout_path,
        'source_identity' => "test:{$instance->id}",
        'linked_worktree_paths' => [$instance->checkout_path],
        'source_digest' => str_repeat('e', 64),
    ]);

    expect(fn () => $member($removal($routed), $routed, null))
        ->toThrow(QueryException::class, 'Invalid AppInstance removal member contract.');

    $accepted = $member($removal($routeless), $routeless, null);
    $routeless->update(['status' => InstanceState::Removing]);
    $accepted->update(['source_prepared_at' => now()]);

    expect(fn () => $accepted->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']))
        ->toThrow(QueryException::class, 'Invalid AppInstance removal member contract.');

    $accepted->refresh()->update(['route_cleared_at' => now(), 'route_outcome' => 'none']);

    expect($accepted->refresh()->route_outcome)->toBe('none');
});

function orb181_coordinator_instance(
    string $environment = 'development',
    string $layout = InstanceSourceLayout::Checkout->value,
    bool $withRoute = true,
): Instance {
    static $sequence = 0;
    $sequence++;
    $slug = "acme-{$sequence}";
    $project = Project::query()->create([
        'name' => "Acme {$sequence}",
        'slug' => $slug,
        'repository_url' => "https://example.test/{$slug}.git",
        'default_branch' => 'main',
        'root' => 'public',
        'type' => $withRoute ? ProjectType::LaravelApp : ProjectType::Monorepo,
    ]);
    $cluster = $environment === 'production'
        ? Cluster::query()->create(['name' => "production-{$sequence}", 'state' => 'active'])
        : null;
    $node = Node::query()->create([
        'cluster_id' => $cluster?->id,
        'name' => "app-dev-{$sequence}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.(50 + $sequence),
        'wireguard_ip' => '10.44.0.'.(50 + $sequence),
    ]);
    $node->roles()->create([
        'role' => $environment === 'production' ? RoleName::AppProd : RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'dev',
        'environment' => $environment,
        'source_layout' => $layout,
        'checkout_path' => "/srv/orbit/apps/{$slug}/dev",
        'branch' => 'dev',
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::SourceResolved,
    ]);
    if (! $withRoute) {
        $instance->update(['status' => InstanceState::Active, 'task_workspace_routed' => false]);

        return $instance->load(['project', 'node', 'routes.targets']);
    }

    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $environment === 'production' ? null : $node->id,
        'cluster_id' => $cluster?->id,
        'generation_basis_node_id' => $environment === 'production' ? null : $node->id,
        'domain' => "dev-{$sequence}.acme.test",
        'provenance' => $environment === 'production' ? RouteProvenance::Explicit : RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);

    return $instance->load(['project', 'node', 'routes.targets']);
}

function orb72_coordinator_schedule_data(Instance $instance): AddScheduleData
{
    return new AddScheduleData(
        ScheduleTargetType::Instance,
        $instance->id,
        'new-daily',
        'daily',
        'true',
        3600,
        false,
    );
}

function orb72_coordinator_schedule(Instance $instance, string $name): Schedule
{
    return Schedule::query()->create([
        'target_type' => Instance::MorphAlias,
        'target_id' => $instance->id,
        'host_node_id' => $instance->node_id,
        'name' => $name,
        'calendar' => 'daily',
        'command' => 'true',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);
}

/** @return array{Instance, Instance, Instance} */
function orb182_coordinator_graph(): array
{
    $checkout = orb181_coordinator_instance();

    return [
        $checkout,
        orb182_coordinator_member($checkout, 'worktree-a'),
        orb182_coordinator_member($checkout, 'worktree-b'),
    ];
}

function orb182_coordinator_member(Instance $checkout, string $name): Instance
{
    $instance = Instance::query()->create([
        'project_id' => $checkout->project_id,
        'node_id' => $checkout->node_id,
        'name' => $name,
        'environment' => 'development',
        'source_layout' => InstanceSourceLayout::Worktree->value,
        'checkout_path' => "/srv/orbit/apps/acme/{$name}",
        'branch' => $name,
        'starting_commit' => str_repeat('a', 40),
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $checkout->project_id,
        'node_id' => $checkout->node_id,
        'generation_basis_node_id' => $checkout->node_id,
        'domain' => "{$name}.acme.test",
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);

    return $instance->load(['project', 'node', 'routes.targets']);
}

final class Orb181CoordinatorInspector implements DevelopmentInstanceSourceRemoval
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string>|null */
    public ?array $linkedPaths = null;

    public ?string $commonRepositoryPath = null;

    /** @var list<int> */
    public array $normalUnsafeIds = [];

    /** @var list<int> */
    public array $inspectionFailureIds = [];

    public ?string $inspectionFailureCode = null;

    /** @var array<int, string> */
    public array $observedCommits = [];

    public function inspect(
        Instance $instance,
        bool $force,
        bool $inspectContent = true,
    ): InstanceSourceInventory {
        $this->calls[] = sprintf('inspect:%d:%s', $instance->id, $force ? 'force' : 'normal');

        if (in_array($instance->id, $this->inspectionFailureIds, true)) {
            throw new RuntimeConvergenceException(
                'app-instance-source-removal-inspect',
                $this->inspectionFailureCode ?? ($force ? 'instance.force_failed' : 'instance.remove_refused'),
                'Source inspection failed.',
            );
        }

        if ($inspectContent && ! $force && in_array($instance->id, $this->normalUnsafeIds, true)) {
            throw new RuntimeConvergenceException(
                'app-instance-source-removal-inspect',
                'instance.remove_refused',
                'Source content is unsafe.',
            );
        }

        $paths = $this->linkedPaths ?? [$instance->checkout_path];
        $root = '/srv/orbit/apps';
        $commonRepositoryPath = $this->commonRepositoryPath ?? $instance->checkout_path;
        $observedCommit = $this->observedCommits[$instance->id] ?? (string) $instance->starting_commit;
        $payload = [
            'instance_id' => $instance->id,
            'layout' => $instance->source_layout,
            'repository_identity' => $instance->project->repository_identity,
            'checkout_path' => $instance->checkout_path,
            'root' => $root,
            'branch' => (string) $instance->branch,
            'starting_commit' => $observedCommit,
            'common_repository_path' => $commonRepositoryPath,
            'source_identity' => "test:{$instance->id}",
            'linked_worktree_paths' => $paths,
        ];

        return new InstanceSourceInventory(
            instanceId: $instance->id,
            layout: $instance->source_layout,
            repositoryIdentity: $instance->project->repository_identity,
            checkoutPath: $instance->checkout_path,
            root: $root,
            branch: (string) $instance->branch,
            startingCommit: $observedCommit,
            commonRepositoryPath: $commonRepositoryPath,
            sourceIdentity: "test:{$instance->id}",
            linkedWorktreePaths: $paths,
            digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    public function remove(Instance $instance, InstanceSourceInventory $inventory, bool $force): void
    {
        throw new LogicException('The durable coordinator does not call legacy source removal.');
    }
}

final class Orb181CoordinatorFinalizer implements DevelopmentInstanceSourceFinalizer
{
    public function __construct(
        private Orb181CoordinatorInspector $inspector,
    ) {}

    /** @var list<string> */
    public array $calls = [];

    /** @var array<int, InstanceSourceRevalidationState> */
    public array $states = [];

    public int $inspectRecordedCalls = 0;

    public bool $failAfterPartialReceipt = false;

    public ?int $failPrepareFor = null;

    /** @var array<int, list<string>> */
    public array $finalizeExpectations = [];

    /** @var list<string> */
    public array $replacementPaths = [];

    public function prepare(
        InstanceRemovalMember $member,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): void {
        $this->calls[] = "prepare:{$member->instance_id}";

        if ($this->failPrepareFor === $member->instance_id) {
            throw new ResourceOperationException(
                'instance.source_interrupted',
                'Source preparation interrupted.',
                502,
            );
        }
    }

    public function revalidate(
        InstanceRemovalMember $member,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): InstanceSourceRevalidationState {
        $state = $this->states[$member->instance_id] ?? InstanceSourceRevalidationState::Present;
        $this->calls[] = "revalidate:{$member->instance_id}:{$state->value}";

        if (in_array($member->checkout_path, $this->replacementPaths, true)) {
            throw new ResourceOperationException(
                'instance.removal_conflict',
                'A completed source path was replaced after removal acceptance.',
                409,
            );
        }

        if (in_array(
            $state,
            [InstanceSourceRevalidationState::Present, InstanceSourceRevalidationState::Quarantined],
            true,
        )) {
            $instance = Instance::query()->find($member->instance_id);

            if (
                ! $instance instanceof Instance
                || $instance->project_id !== $member->project_id
                || $instance->node_id !== $member->node_id
                || $instance->checkout_path !== $member->checkout_path
                || $instance->source_layout !== $member->source_layout
            ) {
                throw new ResourceOperationException(
                    'instance.removal_conflict',
                    'Source ownership changed after removal acceptance.',
                    409,
                );
            }

            $live = $this->inspector->linkedPaths ?? [$member->checkout_path];
            $required = $expectation?->requiredLinkedWorktreePaths ?? $member->linked_worktree_paths;
            $permitted = $expectation?->permittedLinkedWorktreePaths ?? $member->linked_worktree_paths;

            if (array_diff($required, $live) !== [] || array_diff($live, $permitted) !== []) {
                throw new ResourceOperationException(
                    'instance.removal_conflict',
                    'The linked-worktree inventory changed after removal acceptance.',
                    409,
                );
            }
        }

        return $state;
    }

    public function inspectRecorded(
        InstanceRemovalMember $member,
        InstanceSourceRevalidationState $state,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): InstanceSourceInventory {
        $this->inspectRecordedCalls++;
        throw new LogicException('The finalizer owns authenticated source inspection.');
    }

    public function finalize(
        InstanceRemovalMember $member,
        ?InstanceSourceRevalidationExpectation $expectation = null,
    ): string {
        $state = $this->states[$member->instance_id] ?? InstanceSourceRevalidationState::Present;
        $this->calls[] = "finalize:{$member->instance_id}:{$state->value}";
        $this->finalizeExpectations[$member->instance_id] =
            $expectation?->permittedLinkedWorktreePaths ?? $member->linked_worktree_paths;

        if ($this->failAfterPartialReceipt && $state === InstanceSourceRevalidationState::Present) {
            $this->states[$member->instance_id] = InstanceSourceRevalidationState::ReceiptPendingCleanup;
            $this->inspector->linkedPaths = array_values(array_diff(
                $this->inspector->linkedPaths ?? [],
                [(string) $member->checkout_path],
            ));

            throw new ResourceOperationException(
                'instance.removal_incomplete',
                'Receipt cleanup was interrupted.',
                502,
            );
        }

        $this->states[$member->instance_id] = InstanceSourceRevalidationState::Completed;
        $this->inspector->linkedPaths = array_values(array_diff(
            $this->inspector->linkedPaths ?? [],
            [(string) $member->checkout_path],
        ));

        return hash('sha256', "receipt\0{$member->source_digest}");
    }
}

final class Orb183CoordinatorContentRetention implements ProductionInstanceContentRetention
{
    /** @var list<string> */
    public array $calls = [];

    public function inventory(Instance $instance): InstanceSourceInventory
    {
        $this->calls[] = "inventory:{$instance->id}";

        return new InstanceSourceInventory(
            instanceId: $instance->id,
            layout: $instance->source_layout,
            repositoryIdentity: $instance->project->repository_identity,
            checkoutPath: $instance->checkout_path,
            root: (string) $instance->effectiveRoot(),
            branch: (string) $instance->branch,
            startingCommit: (string) $instance->starting_commit,
            commonRepositoryPath: $instance->checkout_path,
            sourceIdentity: "production:{$instance->id}:{$instance->node_id}",
            linkedWorktreePaths: [],
            digest: hash('sha256', "production\0{$instance->id}\0{$instance->checkout_path}"),
        );
    }

    public function prepare(InstanceRemovalMember $member): void
    {
        $this->calls[] = "prepare:{$member->instance_id}";
    }

    public function revalidate(InstanceRemovalMember $member): void
    {
        $this->calls[] = "revalidate:{$member->instance_id}";
    }

    public function finalize(InstanceRemovalMember $member): string
    {
        $this->calls[] = "finalize:{$member->instance_id}";

        return hash('sha256', "production-retained\0{$member->source_digest}");
    }
}

final class Orb181CoordinatorProjector implements InstanceRemovalProjector
{
    /** @var list<string> */
    public array $calls = [];

    public bool $failRuntime = false;

    public ?int $failAfterRoute = null;

    public function clearRouteTarget(InstanceRemovalMember $member): string
    {
        $this->calls[] = "route:{$member->instance_id}";
        $retained = false;
        foreach ($member->route_ids ?? ($member->route_id === null ? [] : [$member->route_id]) as $id) {
            $route = Route::query()->find($id);
            if (! $route instanceof Route) {
                continue;
            }
            $retained = $this->clearOneRoute($route, $member) === 'retained' || $retained;
            if ($this->failAfterRoute === $id) {
                throw new ResourceOperationException('instance.runtime_interrupted', 'Partial app withdrawal interrupted.', 502);
            }
        }

        return $retained ? 'retained' : 'deleted';
    }

    private function clearOneRoute(Route $route, InstanceRemovalMember $member): string
    {
        $route->targets()->where('instance_id', $member->instance_id)->delete();

        if ($route->targets()->exists()) {
            return 'retained';
        }

        if ($route->publication === RoutePublication::Public) {
            $route->update([
                'status' => RouteStatus::Retiring,
                'publication' => RoutePublication::Private,
            ]);
            $this->calls[] = "remove-public-edge:{$member->instance_id}";
        }

        $route->delete();

        return 'deleted';
    }

    public function withdrawPhpPool(InstanceRemovalMember $member): void {}

    public function cleanupRuntime(InstanceRemovalMember $member): void
    {
        $this->calls[] = "runtime:{$member->instance_id}";

        if ($this->failRuntime) {
            throw new ResourceOperationException(
                'instance.runtime_interrupted',
                'Runtime cleanup interrupted.',
                502,
            );
        }
    }
}

final class Orb181CoordinatorLock implements AppDevSourceOperationLock
{
    public bool $acceptedWhileHeld = false;

    private bool $held = false;

    public function synchronized(int $nodeId, Closure $operation): mixed
    {
        if ($this->held) {
            return $operation();
        }

        $this->held = true;

        try {
            $result = $operation();

            if ($result instanceof InstanceRemoval) {
                $this->acceptedWhileHeld = Instance::query()
                    ->whereKey($result->members->pluck('instance_id'))
                    ->get()
                    ->every(static fn (Instance $member): bool => $member->status === InstanceState::Removing);
            }

            return $result;
        } finally {
            $this->held = false;
        }
    }
}

final class Orb212CoordinatorEnvironmentLock implements InstanceEnvironmentOperationLock
{
    public ?Closure $beforeAcquire = null;

    /** @var list<list<int>> */
    public array $owners = [];

    public function run(array $instanceIds, Closure $operation): mixed
    {
        $before = $this->beforeAcquire;
        $this->beforeAcquire = null;
        $before?->__invoke();
        $owners = array_values(array_unique(array_map(intval(...), $instanceIds)));
        sort($owners, SORT_NUMERIC);
        $this->owners[] = $owners;

        return $operation();
    }
}

final class Orb131CoordinatorProcessAdmissionLock implements ProcessAdmissionLock
{
    /** @var list<list<int>> */
    public array $owners = [];

    public function run(array $instanceIds, Closure $operation): mixed
    {
        $owners = array_values(array_unique(array_map(intval(...), $instanceIds)));
        sort($owners, SORT_NUMERIC);
        $this->owners[] = $owners;

        return $operation();
    }
}

final class Orb131CoordinatorProcessRuntimeManager implements ProcessRuntimeManager
{
    /** @var list<int> */
    public array $removed = [];

    public function assertCanStart(Process $process): void {}

    public function converge(Process $process): void {}

    public function start(Process $process, bool $explicit = false): void {}

    public function stop(Process $process): void {}

    public function restart(Process $process): void {}

    public function remove(Process $process): void
    {
        $this->removed[] = $process->id;
    }

    public function status(Process $process): string
    {
        return 'stopped';
    }

    public function logs(Process $process, int $lines): string
    {
        return '';
    }
}

it('refuses to cascade create rollback into another registered instance', function (): void {
    [$checkout, $first, $second] = orb182_coordinator_graph();
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;
    expect(fn () => $this->orb181Coordinator->execute($checkout, force: true, runTeardown: false, allowCascade: false))
        ->toThrow(ResourceOperationException::class, 'Create rollback cannot remove other Instances.')
        ->and(Instance::query()->count())->toBe(3)
        ->and(InstanceRemoval::query()->count())->toBe(0);
});

it('cancels unfinished annotation tasks on Instance removal and preserves completed and unrelated tasks', function (): void {
    $instance = orb181_coordinator_instance();
    $other = orb181_coordinator_instance();
    $store = app(AnnotationStoreAction::class);
    $create = fn (Instance $owner, string $id) => $store->create($owner, new AnnotationInput(['id' => $id, 'comment' => 'Adjust heading', 'threadId' => 'annotation-thread']));
    $pending = $create($instance, 'pending-annotation');
    $running = $create($instance, 'running-annotation');
    $store->transition($running, 'in_progress', null);
    $done = $create($instance, 'done-annotation');
    $store->transition($done, 'in_progress', null);
    $store->transition($done, 'resolved', 'Already completed');
    $unrelated = $create($other, 'unrelated-annotation');
    $this->orb181Coordinator->execute($instance, false);
    foreach ([$pending, $running] as $annotation) {
        expect($annotation->refresh()->task->status)->toBe(TaskStatus::Cancelled);
        expect($annotation->task->parent->status)->toBe(TaskGroupStatus::Cancelled);
        expect($annotation->delivery)->toBe('cancelled');
        expect(fn () => $store->transition($annotation, 'resolved', 'Late reply'))->toThrow(ResourceOperationException::class);
    }
    expect($done->refresh()->task->status)->toBe(TaskStatus::Completed);
    expect($done->task->completion_summary)->toBe('Already completed');
    expect($unrelated->refresh()->task->status)->toBe(TaskStatus::Todo);
});
