<?php

declare(strict_types=1);

use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Removal\AppInstanceRemovalException;
use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationState;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceFinalizer;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\AppInstances\Removal\ProductionAppInstanceContentRetention;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->orb181Inspector = new Orb181CoordinatorInspector;
    $this->orb181Finalizer = new Orb181CoordinatorFinalizer;
    $this->orb181Projector = new Orb181CoordinatorProjector;
    $this->orb181Lock = new Orb181CoordinatorLock;
    $this->orb183Content = new Orb183CoordinatorContentRetention;
    $this->orb181Coordinator = new RemoveAppInstanceAction(
        $this->orb181Inspector,
        $this->orb181Finalizer,
        $this->orb181Projector,
        new ManagedCheckoutOverlap,
        $this->orb181Lock,
        $this->orb183Content,
    );
});

it('accepts exactly one independent checkout and completes every durable step', function (bool $force): void {
    $instance = orb181_coordinator_instance();
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
        ])->and($this->orb181Lock->acceptedWhileHeld)->toBeTrue();
})->with([false, true]);

it('refuses unsupported removals before any durable mutation', function (string $case): void {
    $instance = orb181_coordinator_instance(
        environment: $case === 'production' ? 'production' : 'development',
        layout: $case === 'worktree'
            ? AppInstanceSourceLayout::Worktree->value
            : AppInstanceSourceLayout::Checkout->value,
    );

    if ($case === 'linked checkout') {
        $this->orb181Inspector->linkedPaths = [
            $instance->checkout_path,
            '/srv/orbit/apps/acme/linked',
        ];
    }

    $before = $instance->refresh()->getAttributes();

    expect(fn () => $this->orb181Coordinator->execute($instance, true))
        ->toThrow(ResourceOperationException::class);
    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and($instance->routes()->count())
        ->toBe(1)
        ->and(AppInstanceRemovalMember::query()->count())
        ->toBe(0)
        ->and($this->orb181Finalizer->calls)
        ->toBeEmpty()
        ->and($this->orb181Projector->calls)
        ->toBeEmpty()
        ->and($this->orb181Inspector->calls)
        ->toBe($case === 'linked checkout' ? ["inspect:{$instance->id}:force"] : []);
})->with(['linked checkout', 'worktree']);

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

final class Orb181CoordinatorInspector implements DevelopmentAppInstanceSourceRemoval
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string>|null */
    public ?array $linkedPaths = null;

    public function inspect(AppInstance $appInstance, bool $force): AppInstanceSourceInventory
    {
        $this->calls[] = sprintf('inspect:%d:%s', $appInstance->id, $force ? 'force' : 'normal');

        return new AppInstanceSourceInventory(
            appInstanceId: $appInstance->id,
            layout: $appInstance->source_layout,
            repositoryIdentity: $appInstance->app->repository_identity,
            checkoutPath: $appInstance->checkout_path,
            root: '/srv/orbit/apps',
            branch: (string) $appInstance->branch,
            startingCommit: (string) $appInstance->starting_commit,
            commonRepositoryPath: $appInstance->checkout_path,
            sourceIdentity: "test:{$appInstance->id}",
            linkedWorktreePaths: $this->linkedPaths ?? [$appInstance->checkout_path],
            digest: hash('sha256', "source\0{$appInstance->id}\0{$appInstance->checkout_path}"),
        );
    }

    public function remove(AppInstance $appInstance, AppInstanceSourceInventory $inventory, bool $force): void
    {
        throw new LogicException('The durable coordinator does not call legacy source removal.');
    }
}

final class Orb181CoordinatorFinalizer implements DevelopmentAppInstanceSourceFinalizer
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<int, AppInstanceSourceRevalidationState> */
    public array $states = [];

    public int $inspectRecordedCalls = 0;

    public bool $failAfterPartialReceipt = false;

    public function prepare(AppInstanceRemovalMember $member): void
    {
        $this->calls[] = "prepare:{$member->app_instance_id}";
    }

    public function revalidate(AppInstanceRemovalMember $member): AppInstanceSourceRevalidationState
    {
        $state = $this->states[$member->app_instance_id] ?? AppInstanceSourceRevalidationState::Present;
        $this->calls[] = "revalidate:{$member->app_instance_id}:{$state->value}";

        return $state;
    }

    public function inspectRecorded(
        AppInstanceRemovalMember $member,
        AppInstanceSourceRevalidationState $state,
    ): AppInstanceSourceInventory {
        $this->inspectRecordedCalls++;
        throw new LogicException('The finalizer owns authenticated source inspection.');
    }

    public function finalize(AppInstanceRemovalMember $member): string
    {
        $state = $this->states[$member->app_instance_id] ?? AppInstanceSourceRevalidationState::Present;
        $this->calls[] = "finalize:{$member->app_instance_id}:{$state->value}";

        if ($this->failAfterPartialReceipt && $state === AppInstanceSourceRevalidationState::Present) {
            $this->states[$member->app_instance_id] = AppInstanceSourceRevalidationState::ReceiptPendingCleanup;

            throw new ResourceOperationException(
                'instance.removal_incomplete',
                'Receipt cleanup was interrupted.',
                502,
            );
        }

        $this->states[$member->app_instance_id] = AppInstanceSourceRevalidationState::Completed;

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

            if ($result instanceof \App\Models\AppInstanceRemoval) {
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
