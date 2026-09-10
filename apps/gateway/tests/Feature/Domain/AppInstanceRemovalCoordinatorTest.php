<?php

declare(strict_types=1);

use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Actions\Processes\CascadeAppInstanceProcessesAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Removal\AppInstanceRemovalException;
use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationExpectation;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationState;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceFinalizer;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\AppInstances\Removal\ProductionAppInstanceContentRetention;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\AppInstanceRemovalMember;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->orb181Inspector = new Orb181CoordinatorInspector;
    $this->orb181Finalizer = new Orb181CoordinatorFinalizer($this->orb181Inspector);
    $this->orb181Projector = new Orb181CoordinatorProjector;
    $this->orb181Lock = new Orb181CoordinatorLock;
    $this->orb212EnvironmentLock = new Orb212CoordinatorEnvironmentLock;
    $this->orb131ProcessLock = new Orb131CoordinatorProcessAdmissionLock;
    $this->orb131ProcessRuntime = new Orb131CoordinatorProcessRuntimeManager;
    $this->orb183Content = new Orb183CoordinatorContentRetention;
    $this->orb181Coordinator = new RemoveAppInstanceAction(
        $this->orb181Inspector,
        $this->orb181Finalizer,
        $this->orb181Projector,
        new ManagedCheckoutOverlap,
        $this->orb212EnvironmentLock,
        $this->orb131ProcessLock,
        new CascadeAppInstanceProcessesAction(new RemoveProcessAction(
            $this->orb131ProcessRuntime,
            new ProcessTargetResolver,
        )),
        $this->orb181Lock,
        $this->orb183Content,
        app(RouteStateResolver::class),
    );
});

it('accepts exactly one independent checkout and completes every durable step', function (bool $force): void {
    $instance = orb181_coordinator_instance();
    $process = Process::query()->create([
        'owner_type' => AppInstance::class,
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
            'app_instance_id',
            'app_id',
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
            'app_instance_id' => $instance->id,
            'app_id' => $instance->app_id,
            'node_id' => $instance->node_id,
            'route_id' => $instance->routes->sole()->id,
            'name' => 'dev',
            'environment' => 'development',
            'source_layout' => 'checkout',
            'repository_identity' => $instance->app->repository_identity,
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
        ->not->toBeNull()->and(AppInstance::query()->whereKey($instance->id)->exists())->toBeFalse()->and(
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
        ->and($removal->members->sole()->app_instance_id)
        ->toBe($first->id)
        ->and(AppInstance::query()->whereKey([$checkout->id, $second->id])->count())
        ->toBe(2)
        ->and(
            Route::query()
                ->whereHas('targets', fn ($query) => $query->whereIn(
                    'app_instance_id',
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
    expect(AppInstance::query()->count())->toBe(3)->and(Route::query()->count())->toBe(3);

    $removal = $this->orb181Coordinator->execute($checkout->refresh(), true);
    $members = $removal->members()->orderBy('position')->get();

    expect($members->pluck('app_instance_id')->all())
        ->toBe([$first->id, $second->id, $checkout->id])
        ->and($this->orb212EnvironmentLock->owners)
        ->toBe([[$checkout->id], [$checkout->id, $first->id, $second->id]])
        ->and($members->every(fn (AppInstanceRemovalMember $member): bool => $member->linked_worktree_paths === $paths))
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
        ->and(AppInstance::query()->count())
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
        ->and(AppInstanceRemovalMember::query()->count())
        ->toBe(0)
        ->and(AppInstance::query()->where('status', AppInstanceState::Active->value)->count())
        ->toBe(3);
});

it('leaves an owned running Process unchanged when source preflight refuses removal', function (): void {
    $instance = orb181_coordinator_instance();
    $process = Process::query()->create([
        'owner_type' => AppInstance::class,
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
        ->and(AppInstanceRemovalMember::query()->count())
        ->toBe(0);
});

it('refuses an unregistered checkout member before accepting either mode', function (bool $force): void {
    [$checkout, $first] = orb182_coordinator_graph();
    $unknown = '/srv/orbit/apps/acme/unregistered';
    $paths = [$checkout->checkout_path, $first->checkout_path, $unknown];
    sort($paths, SORT_STRING);
    $this->orb181Inspector->linkedPaths = $paths;
    $this->orb181Inspector->commonRepositoryPath = $checkout->checkout_path;

    expect(fn () => $this->orb181Coordinator->execute($checkout, $force))
        ->toThrow(ResourceOperationException::class, 'Every linked worktree must be a registered AppInstance');
    expect(AppInstanceRemovalMember::query()->count())
        ->toBe(0)
        ->and(AppInstance::query()->count())
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
        ->toThrow(AppInstanceRemovalException::class);
    $operation = $checkout->refresh()->removalMember?->removal;
    $members = $operation?->members()->orderBy('position')->get();
    expect($members?->get(0)?->row_deleted_at)->not->toBeNull()->and($members?->get(1)?->route_cleared_at)->toBeNull();

    $unknown = '/srv/orbit/apps/acme/new-worktree';
    $this->orb181Inspector->linkedPaths[] = $unknown;
    sort($this->orb181Inspector->linkedPaths, SORT_STRING);
    $this->orb181Finalizer->failPrepareFor = null;

    expect(fn () => $this->orb181Coordinator->execute($checkout->refresh(), true))
        ->toThrow(AppInstanceRemovalException::class);
    expect($operation?->refresh()->error_code)
        ->toBe('instance.removal_conflict')
        ->and($operation?->members()->count())
        ->toBe(3)
        ->and($members?->get(1)?->refresh()->route_cleared_at)
        ->toBeNull()
        ->and(
            Route::query()
                ->whereHas('targets', fn ($query) => $query->where(
                    'app_instance_id',
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
        ->toThrow(AppInstanceRemovalException::class);
    $operation = $checkout->refresh()->removalMember?->removal;
    $members = $operation?->members()->orderBy('position')->get();
    expect($members?->first()?->row_deleted_at)->not->toBeNull();

    $this->orb181Finalizer->failPrepareFor = null;
    $this->orb181Finalizer->replacementPaths = [$first->checkout_path];

    expect(fn () => $this->orb181Coordinator->execute($checkout->refresh(), true))
        ->toThrow(AppInstanceRemovalException::class);
    expect($operation?->refresh()->error_code)
        ->toBe('instance.removal_conflict')
        ->and($members?->get(1)?->refresh()->route_cleared_at)
        ->toBeNull()
        ->and(
            Route::query()
                ->whereHas('targets', fn ($query) => $query->where('app_instance_id', $second->id))
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
        ->toThrow(AppInstanceRemovalException::class);
    $operation = $checkout->refresh()->removalMember?->removal;
    $firstMember = $operation?->members()->orderBy('position')->firstOrFail();
    expect($firstMember?->app_instance_id)
        ->toBe($first->id)
        ->and($firstMember?->source_finalized_at)
        ->toBeNull()
        ->and($this->orb181Finalizer->states[$first->id])
        ->toBe(AppInstanceSourceRevalidationState::ReceiptPendingCleanup);

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
        BEFORE UPDATE OF status ON app_instance_removals
        WHEN NEW.status = 'completed'
        BEGIN
            SELECT RAISE(ABORT, 'Injected final cascade completion failure.');
        END
        SQL);

    try {
        expect(fn () => $this->orb181Coordinator->execute($checkout, true))
            ->toThrow(AppInstanceRemovalException::class);
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
            AppInstance::query()->whereKey($checkout->id)->sole()->status,
        )->toBe(AppInstanceState::Removing);

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
        ->and(AppInstance::query()->find($instance->id))
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
        ->toThrow(AppInstanceRemovalException::class);
    $member = AppInstanceRemovalMember::query()->sole();
    expect($member->route_cleared_at)
        ->not->toBeNull()->and($member->source_finalized_at)
        ->not->toBeNull()->and($member->runtime_cleaned_at)->toBeNull()->and(Route::query()->find(
            $routeId,
        ))->toBeNull()->and($instance->refresh()->status)->toBe(AppInstanceState::Removing);

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
        BEFORE UPDATE OF source_finalized_at ON app_instance_removal_members
        WHEN NEW.source_finalized_at IS NOT NULL
        BEGIN
            SELECT RAISE(ABORT, 'Injected source checkpoint failure.');
        END
        SQL);

    try {
        expect(fn () => $this->orb181Coordinator->execute($instance, false))
            ->toThrow(AppInstanceRemovalException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS orb181_fail_source_finalized_checkpoint');
    }

    $member = AppInstanceRemovalMember::query()->sole();
    expect($member->source_finalized_at)
        ->toBeNull()
        ->and($member->removal()->firstOrFail()->status->value)
        ->toBe('failed')
        ->and($this->orb181Finalizer->states[$instance->id])
        ->toBe(AppInstanceSourceRevalidationState::Completed)
        ->and($this->orb181Finalizer->inspectRecordedCalls)
        ->toBe(0);

    $removal = $this->orb181Coordinator->execute($instance->refresh(), false);

    expect($removal->status->value)
        ->toBe('completed')
        ->and(AppInstance::query()->whereKey($instance->id)->exists())
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
        ->toThrow(AppInstanceRemovalException::class);
    expect(AppInstanceRemovalMember::query()->sole()->source_finalized_at)
        ->toBeNull()
        ->and($this->orb181Finalizer->states[$instance->id])
        ->toBe(AppInstanceSourceRevalidationState::ReceiptPendingCleanup)
        ->and($this->orb181Finalizer->inspectRecordedCalls)
        ->toBe(0);

    $this->orb181Finalizer->failAfterPartialReceipt = false;
    $removal = $this->orb181Coordinator->execute($instance->refresh(), false);

    expect($removal->status->value)
        ->toBe('completed')
        ->and(AppInstance::query()->whereKey($instance->id)->exists())
        ->toBeFalse()
        ->and($this->orb181Finalizer->inspectRecordedCalls)
        ->toBe(0)
        ->and($this->orb181Finalizer->calls)
        ->toContain(
            "revalidate:{$instance->id}:receipt-pending-cleanup",
            "finalize:{$instance->id}:receipt-pending-cleanup",
        );
});

it('requires an identical force value when resuming accepted removal', function (): void {
    $instance = orb181_coordinator_instance();
    $this->orb181Projector->failRuntime = true;

    expect(fn () => $this->orb181Coordinator->execute($instance, true))
        ->toThrow(AppInstanceRemovalException::class)
        ->and(fn () => $this->orb181Coordinator->execute($instance->refresh(), false))
        ->toThrow(ResourceOperationException::class, 'different removal request');
    expect($instance->refresh()->status)
        ->toBe(AppInstanceState::Removing)
        ->and($instance->removalMember?->removal->force)
        ->toBeTrue();
});

function orb181_coordinator_instance(
    string $environment = 'development',
    string $layout = AppInstanceSourceLayout::Checkout->value,
): AppInstance {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $cluster = $environment === 'production'
        ? Cluster::query()->create(['name' => 'production', 'state' => 'active'])
        : null;
    $node = Node::query()->create([
        'cluster_id' => $cluster?->id,
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.50',
        'wireguard_ip' => '10.44.0.50',
    ]);
    $node->roles()->create([
        'role' => $environment === 'production' ? RoleName::AppProd : RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'dev',
        'environment' => $environment,
        'source_layout' => $layout,
        'checkout_path' => '/srv/orbit/apps/acme/dev',
        'branch' => 'dev',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $environment === 'production' ? null : $node->id,
        'cluster_id' => $cluster?->id,
        'generation_basis_node_id' => $environment === 'production' ? null : $node->id,
        'hostname' => 'dev.acme.test',
        'provenance' => $environment === 'production' ? RouteProvenance::Explicit : RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    return $instance->load(['app', 'node', 'routes.targets']);
}

/** @return array{AppInstance, AppInstance, AppInstance} */
function orb182_coordinator_graph(): array
{
    $checkout = orb181_coordinator_instance();

    return [
        $checkout,
        orb182_coordinator_member($checkout, 'worktree-a'),
        orb182_coordinator_member($checkout, 'worktree-b'),
    ];
}

function orb182_coordinator_member(AppInstance $checkout, string $name): AppInstance
{
    $instance = AppInstance::query()->create([
        'app_id' => $checkout->app_id,
        'node_id' => $checkout->node_id,
        'name' => $name,
        'environment' => 'development',
        'source_layout' => AppInstanceSourceLayout::Worktree->value,
        'checkout_path' => "/srv/orbit/apps/acme/{$name}",
        'branch' => $name,
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $checkout->app_id,
        'node_id' => $checkout->node_id,
        'generation_basis_node_id' => $checkout->node_id,
        'hostname' => "{$name}.acme.test",
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    return $instance->load(['app', 'node', 'routes.targets']);
}

final class Orb181CoordinatorInspector implements DevelopmentAppInstanceSourceRemoval
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

    /** @var array<int, string> */
    public array $observedCommits = [];

    public function inspect(
        AppInstance $appInstance,
        bool $force,
        bool $inspectContent = true,
    ): AppInstanceSourceInventory {
        $this->calls[] = sprintf('inspect:%d:%s', $appInstance->id, $force ? 'force' : 'normal');

        if (in_array($appInstance->id, $this->inspectionFailureIds, true)) {
            throw new RuntimeConvergenceException(
                'app-instance-source-removal-inspect',
                $force ? 'instance.force_failed' : 'instance.remove_refused',
                'Source inspection failed.',
            );
        }

        if ($inspectContent && ! $force && in_array($appInstance->id, $this->normalUnsafeIds, true)) {
            throw new RuntimeConvergenceException(
                'app-instance-source-removal-inspect',
                'instance.remove_refused',
                'Source content is unsafe.',
            );
        }

        $paths = $this->linkedPaths ?? [$appInstance->checkout_path];
        $root = '/srv/orbit/apps';
        $commonRepositoryPath = $this->commonRepositoryPath ?? $appInstance->checkout_path;
        $observedCommit = $this->observedCommits[$appInstance->id] ?? (string) $appInstance->starting_commit;
        $payload = [
            'app_instance_id' => $appInstance->id,
            'layout' => $appInstance->source_layout,
            'repository_identity' => $appInstance->app->repository_identity,
            'checkout_path' => $appInstance->checkout_path,
            'root' => $root,
            'branch' => (string) $appInstance->branch,
            'starting_commit' => $observedCommit,
            'common_repository_path' => $commonRepositoryPath,
            'source_identity' => "test:{$appInstance->id}",
            'linked_worktree_paths' => $paths,
        ];

        return new AppInstanceSourceInventory(
            appInstanceId: $appInstance->id,
            layout: $appInstance->source_layout,
            repositoryIdentity: $appInstance->app->repository_identity,
            checkoutPath: $appInstance->checkout_path,
            root: $root,
            branch: (string) $appInstance->branch,
            startingCommit: $observedCommit,
            commonRepositoryPath: $commonRepositoryPath,
            sourceIdentity: "test:{$appInstance->id}",
            linkedWorktreePaths: $paths,
            digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    public function remove(AppInstance $appInstance, AppInstanceSourceInventory $inventory, bool $force): void
    {
        throw new LogicException('The durable coordinator does not call legacy source removal.');
    }
}

final class Orb181CoordinatorFinalizer implements DevelopmentAppInstanceSourceFinalizer
{
    public function __construct(
        private Orb181CoordinatorInspector $inspector,
    ) {}

    /** @var list<string> */
    public array $calls = [];

    /** @var array<int, AppInstanceSourceRevalidationState> */
    public array $states = [];

    public int $inspectRecordedCalls = 0;

    public bool $failAfterPartialReceipt = false;

    public ?int $failPrepareFor = null;

    /** @var array<int, list<string>> */
    public array $finalizeExpectations = [];

    /** @var list<string> */
    public array $replacementPaths = [];

    public function prepare(
        AppInstanceRemovalMember $member,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): void {
        $this->calls[] = "prepare:{$member->app_instance_id}";

        if ($this->failPrepareFor === $member->app_instance_id) {
            throw new ResourceOperationException(
                'instance.source_interrupted',
                'Source preparation interrupted.',
                502,
            );
        }
    }

    public function revalidate(
        AppInstanceRemovalMember $member,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): AppInstanceSourceRevalidationState {
        $state = $this->states[$member->app_instance_id] ?? AppInstanceSourceRevalidationState::Present;
        $this->calls[] = "revalidate:{$member->app_instance_id}:{$state->value}";

        if (in_array($member->checkout_path, $this->replacementPaths, true)) {
            throw new ResourceOperationException(
                'instance.removal_conflict',
                'A completed source path was replaced after removal acceptance.',
                409,
            );
        }

        if (in_array(
            $state,
            [AppInstanceSourceRevalidationState::Present, AppInstanceSourceRevalidationState::Quarantined],
            true,
        )) {
            $instance = AppInstance::query()->find($member->app_instance_id);

            if (
                ! $instance instanceof AppInstance
                || $instance->app_id !== $member->app_id
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
        AppInstanceRemovalMember $member,
        AppInstanceSourceRevalidationState $state,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): AppInstanceSourceInventory {
        $this->inspectRecordedCalls++;
        throw new LogicException('The finalizer owns authenticated source inspection.');
    }

    public function finalize(
        AppInstanceRemovalMember $member,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): string {
        $state = $this->states[$member->app_instance_id] ?? AppInstanceSourceRevalidationState::Present;
        $this->calls[] = "finalize:{$member->app_instance_id}:{$state->value}";
        $this->finalizeExpectations[$member->app_instance_id] =
            $expectation?->permittedLinkedWorktreePaths ?? $member->linked_worktree_paths;

        if ($this->failAfterPartialReceipt && $state === AppInstanceSourceRevalidationState::Present) {
            $this->states[$member->app_instance_id] = AppInstanceSourceRevalidationState::ReceiptPendingCleanup;
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

        $this->states[$member->app_instance_id] = AppInstanceSourceRevalidationState::Completed;
        $this->inspector->linkedPaths = array_values(array_diff(
            $this->inspector->linkedPaths ?? [],
            [(string) $member->checkout_path],
        ));

        return hash('sha256', "receipt\0{$member->source_digest}");
    }
}

final class Orb183CoordinatorContentRetention implements ProductionAppInstanceContentRetention
{
    /** @var list<string> */
    public array $calls = [];

    public function inventory(AppInstance $appInstance): AppInstanceSourceInventory
    {
        $this->calls[] = "inventory:{$appInstance->id}";

        return new AppInstanceSourceInventory(
            appInstanceId: $appInstance->id,
            layout: $appInstance->source_layout,
            repositoryIdentity: $appInstance->app->repository_identity,
            checkoutPath: $appInstance->checkout_path,
            root: (string) $appInstance->effectiveRoot(),
            branch: (string) $appInstance->branch,
            startingCommit: (string) $appInstance->starting_commit,
            commonRepositoryPath: $appInstance->checkout_path,
            sourceIdentity: "production:{$appInstance->id}:{$appInstance->node_id}",
            linkedWorktreePaths: [],
            digest: hash('sha256', "production\0{$appInstance->id}\0{$appInstance->checkout_path}"),
        );
    }

    public function prepare(AppInstanceRemovalMember $member): void
    {
        $this->calls[] = "prepare:{$member->app_instance_id}";
    }

    public function revalidate(AppInstanceRemovalMember $member): void
    {
        $this->calls[] = "revalidate:{$member->app_instance_id}";
    }

    public function finalize(AppInstanceRemovalMember $member): string
    {
        $this->calls[] = "finalize:{$member->app_instance_id}";

        return hash('sha256', "production-retained\0{$member->source_digest}");
    }
}

final class Orb181CoordinatorProjector implements AppInstanceRemovalProjector
{
    /** @var list<string> */
    public array $calls = [];

    public bool $failRuntime = false;

    public function clearRouteTarget(AppInstanceRemovalMember $member): string
    {
        $this->calls[] = "route:{$member->app_instance_id}";
        $route = Route::query()->find($member->route_id);

        if (! $route instanceof Route) {
            return 'deleted';
        }

        $route->targets()->where('app_instance_id', $member->app_instance_id)->delete();
        $route->delete();

        return 'deleted';
    }

    public function cleanupRuntime(AppInstanceRemovalMember $member): void
    {
        $this->calls[] = "runtime:{$member->app_instance_id}";

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

            if ($result instanceof AppInstanceRemoval) {
                $this->acceptedWhileHeld = AppInstance::query()
                    ->whereKey($result->members->pluck('app_instance_id'))
                    ->get()
                    ->every(static fn (AppInstance $member): bool => $member->status === AppInstanceState::Removing);
            }

            return $result;
        } finally {
            $this->held = false;
        }
    }
}

final class Orb212CoordinatorEnvironmentLock implements AppInstanceEnvironmentOperationLock
{
    /** @var list<list<int>> */
    public array $owners = [];

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        $owners = array_values(array_unique(array_map(intval(...), $appInstanceIds)));
        sort($owners, SORT_NUMERIC);
        $this->owners[] = $owners;

        return $operation();
    }
}

final class Orb131CoordinatorProcessAdmissionLock implements ProcessAdmissionLock
{
    /** @var list<list<int>> */
    public array $owners = [];

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        $owners = array_values(array_unique(array_map(intval(...), $appInstanceIds)));
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

    public function start(Process $process): void {}

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
