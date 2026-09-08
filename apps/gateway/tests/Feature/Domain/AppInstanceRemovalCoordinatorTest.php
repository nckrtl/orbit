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
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
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
use App\Models\Node;
use App\Models\Route;

beforeEach(function (): void {
    $this->orb124CoordinatorSource = new Orb124CoordinatorSource;
    $this->orb124CoordinatorProjector = new Orb124CoordinatorProjector;
    $this->orb124CoordinatorLock = new Orb124CoordinatorLock;
    $this->orb124Coordinator = new RemoveAppInstanceAction(
        $this->orb124CoordinatorSource,
        $this->orb124CoordinatorProjector,
        new ManagedCheckoutOverlap,
        $this->orb124CoordinatorLock,
    );
});

it('refuses a normal checkout cascade then removes the fixed worktree-first set with force', function (): void {
    [$checkout, $worktree] = orb124_coordinator_graph();
    $paths = [$checkout->checkout_path, $worktree->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb124CoordinatorSource->paths = $paths;

    expect(fn () => $this->orb124Coordinator->execute($checkout, false))
        ->toThrow(ResourceOperationException::class, 'retry with --force');
    expect(AppInstance::query()->orderBy('id')->pluck('status')->all())
        ->toBe([AppInstanceState::Active, AppInstanceState::Active])
        ->and(Route::query()->count())
        ->toBe(2)
        ->and($this->orb124CoordinatorSource->calls)
        ->toBe(["inspect:{$checkout->id}"]);

    $this->orb124CoordinatorSource->calls = [];
    $removal = $this->orb124Coordinator->execute($checkout->refresh(), true);

    expect($removal->status->value)
        ->toBe('completed')
        ->and($removal->members->pluck('app_instance_id')->all())
        ->toBe([$worktree->id, $checkout->id])
        ->and($removal->members->pluck('row_deleted_at')->filter()->count())
        ->toBe(2)
        ->and(AppInstance::query()->count())
        ->toBe(0)
        ->and($this->orb124CoordinatorSource->finalized)
        ->toBe([$worktree->checkout_path, $checkout->checkout_path])
        ->and($this->orb124CoordinatorProjector->routeCalls)
        ->toBe([$worktree->id, $checkout->id])
        ->and($this->orb124CoordinatorLock->acceptedWhileHeld)
        ->toBeTrue();
});

it('revalidates the unfinished fixed inventory before retry and never extends it', function (): void {
    [$checkout, $worktree] = orb124_coordinator_graph();
    $paths = [$checkout->checkout_path, $worktree->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb124CoordinatorSource->paths = $paths;
    $this->orb124CoordinatorProjector->failRuntime = true;

    expect(fn () => $this->orb124Coordinator->execute($checkout, true))
        ->toThrow(AppInstanceRemovalException::class);
    $operation = $checkout->refresh()->removalMember?->removal;
    expect($operation)
        ->not->toBeNull()->and($operation?->members()->count())->toBe(2)->and(
            $operation?->members()->orderBy('position')->first()?->source_finalized_at,
        )
        ->not->toBeNull();

    $new = orb124_coordinator_instance(
        $checkout->app,
        $checkout->node,
        'new-worktree',
        AppInstanceSourceLayout::Worktree->value,
    );
    $this->orb124CoordinatorSource->paths[] = $new->checkout_path;
    sort($this->orb124CoordinatorSource->paths, SORT_STRING);
    $before = AppInstance::query()->whereKey($new->id)->sole()->getAttributes();
    $this->orb124CoordinatorProjector->failRuntime = false;

    expect(fn () => $this->orb124Coordinator->execute($checkout->refresh(), true))
        ->toThrow(AppInstanceRemovalException::class)
        ->and(fn () => $this->orb124Coordinator->execute($checkout->refresh(), false))
        ->toThrow(ResourceOperationException::class, 'different removal request');
    expect(AppInstance::query()->whereKey($new->id)->sole()->getAttributes())
        ->toBe($before)
        ->and($operation?->members()->count())
        ->toBe(2)
        ->and($operation?->refresh()->error_code)
        ->toBe('instance.removal_conflict')
        ->and($checkout->refresh()->status)
        ->toBe(AppInstanceState::Removing);
});

it('resumes after a lost Route-deletion response without recreating the Route', function (): void {
    [$checkout, $worktree] = orb124_coordinator_graph();
    $this->orb124CoordinatorSource->paths = [$checkout->checkout_path];
    $worktree->update(['status' => AppInstanceState::Removing]);
    $worktreeRoute = $worktree->routes()->firstOrFail();
    $worktreeRoute->targets()->delete();
    $worktreeRoute->delete();
    $worktree->delete();
    $this->orb124CoordinatorProjector->failAfterRouteMutation = true;

    expect(fn () => $this->orb124Coordinator->execute($checkout->refresh(), false))
        ->toThrow(AppInstanceRemovalException::class);
    $member = $checkout->refresh()->removalMember;
    expect($member?->route_cleared_at)
        ->toBeNull()
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($checkout->status)
        ->toBe(AppInstanceState::Removing);

    $this->orb124CoordinatorProjector->failAfterRouteMutation = false;
    $removal = $this->orb124Coordinator->execute($checkout->refresh(), false);

    expect($removal->status->value)
        ->toBe('completed')
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->orb124CoordinatorProjector->routeCalls)
        ->toBe([$checkout->id, $checkout->id]);
});

it('normalizes authenticated linked-worktree finalization state without extending the fixed cascade', function (
    AppInstanceSourceRevalidationState $state,
): void {
    [$checkout, $worktree] = orb124_coordinator_graph();
    $paths = [$checkout->checkout_path, $worktree->checkout_path];
    sort($paths, SORT_STRING);
    $this->orb124CoordinatorSource->paths = $paths;
    $this->orb124CoordinatorSource->failFinalize = true;

    expect(fn () => $this->orb124Coordinator->execute($checkout, true))
        ->toThrow(AppInstanceRemovalException::class);
    $operation = $checkout->refresh()->removalMember?->removal;
    $member = $operation?->members()->orderBy('position')->firstOrFail();
    expect($member?->app_instance_id)
        ->toBe($worktree->id)
        ->and($member?->route_cleared_at)
        ->not->toBeNull();

    $this->orb124CoordinatorSource->failFinalize = false;
    $this->orb124CoordinatorSource->states[$worktree->id] = $state;
    $this->orb124CoordinatorSource->paths = [$checkout->checkout_path];

    if (in_array(
        $state,
        [
            AppInstanceSourceRevalidationState::Quarantined,
            AppInstanceSourceRevalidationState::ReceiptPendingCleanup,
        ],
        true,
    )) {
        $this->orb124CoordinatorSource->paths[] = sprintf(
            '%s/.orbit-removals/%s.%d.quarantine',
            $member?->root,
            $operation?->id,
            $member?->id,
        );
    }

    $unrelated = '/srv/orbit/apps/acme/unregistered-worktree';
    $this->orb124CoordinatorSource->paths[] = $unrelated;
    sort($this->orb124CoordinatorSource->paths, SORT_STRING);

    expect(fn () => $this->orb124Coordinator->execute($checkout->refresh(), true))
        ->toThrow(AppInstanceRemovalException::class);
    expect($operation?->refresh()->error_code)
        ->toBe('instance.removal_conflict')
        ->and($operation?->members()->count())
        ->toBe(2)
        ->and($checkout->refresh()->status)
        ->toBe(AppInstanceState::Removing);

    $this->orb124CoordinatorSource->paths = array_values(array_diff(
        $this->orb124CoordinatorSource->paths,
        [$unrelated],
    ));
    $removal = $this->orb124Coordinator->execute($checkout->refresh(), true);

    expect($removal->status->value)
        ->toBe('completed')
        ->and($removal->total)
        ->toBe(2)
        ->and($this->orb124CoordinatorSource->finalized)
        ->toBe([$worktree->checkout_path, $checkout->checkout_path]);
})->with([
    'quarantine before receipt' => AppInstanceSourceRevalidationState::Quarantined,
    'quarantine after receipt' => AppInstanceSourceRevalidationState::ReceiptPendingCleanup,
    'deleted after receipt' => AppInstanceSourceRevalidationState::Completed,
]);

/** @return array{AppInstance, AppInstance} */
function orb124_coordinator_graph(): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.50',
        'wireguard_ip' => '10.44.0.50',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    return [
        orb124_coordinator_instance($app, $node, 'checkout', AppInstanceSourceLayout::Checkout->value),
        orb124_coordinator_instance($app, $node, 'worktree', AppInstanceSourceLayout::Worktree->value),
    ];
}

function orb124_coordinator_instance(
    OrbitApp $app,
    Node $node,
    string $name,
    string $layout,
): AppInstance {
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'source_layout' => $layout,
        'checkout_path' => "/srv/orbit/apps/acme/{$name}",
        'branch' => $name,
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
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

final class Orb124CoordinatorSource implements DevelopmentAppInstanceSourceRemoval
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $paths = [];

    /** @var list<string> */
    public array $finalized = [];

    /** @var array<int, AppInstanceSourceRevalidationState> */
    public array $states = [];

    public bool $failFinalize = false;

    public function inspect(AppInstance $appInstance, bool $force): AppInstanceSourceInventory
    {
        $this->calls[] = "inspect:{$appInstance->id}";
        $checkout = collect($this->paths)
            ->first(
                static fn (string $path): bool => str_ends_with($path, '/checkout'),
            );

        return new AppInstanceSourceInventory(
            appInstanceId: $appInstance->id,
            layout: $appInstance->source_layout,
            repositoryIdentity: $appInstance->app->repository_identity,
            checkoutPath: $appInstance->checkout_path,
            root: '/srv/orbit/apps/acme',
            branch: $appInstance->branch,
            startingCommit: $appInstance->starting_commit,
            commonRepositoryPath: is_string($checkout) ? $checkout : $appInstance->checkout_path,
            sourceIdentity: "test:{$appInstance->id}",
            linkedWorktreePaths: $this->paths,
            digest: hash('sha256', "source\0{$appInstance->id}\0{$appInstance->checkout_path}"),
        );
    }

    public function prepare(AppInstanceRemovalMember $member): void
    {
        $this->calls[] = "prepare:{$member->app_instance_id}";
    }

    public function revalidate(AppInstanceRemovalMember $member): AppInstanceSourceRevalidationState
    {
        $this->calls[] = "revalidate:{$member->app_instance_id}";

        return $this->states[$member->app_instance_id] ?? AppInstanceSourceRevalidationState::Present;
    }

    public function inspectRecorded(
        AppInstanceRemovalMember $member,
        AppInstanceSourceRevalidationState $state,
    ): AppInstanceSourceInventory {
        $appInstance = AppInstance::query()->with('app')->findOrFail($member->app_instance_id);
        $inventory = $this->inspect($appInstance, (bool) $member->removal()->firstOrFail()->force);
        $paths = $inventory->linkedWorktreePaths;

        foreach ($member->removal()->firstOrFail()->members as $recorded) {
            $quarantine = sprintf(
                '%s/.orbit-removals/%s.%d.quarantine',
                $recorded->root,
                $recorded->app_instance_removal_id,
                $recorded->id,
            );
            $paths = array_map(
                static fn (string $path): string => $path === $quarantine
                    ? (string) $recorded->checkout_path
                    : $path,
                $paths,
            );
        }
        sort($paths, SORT_STRING);

        return new AppInstanceSourceInventory(
            appInstanceId: $inventory->appInstanceId,
            layout: $inventory->layout,
            repositoryIdentity: $inventory->repositoryIdentity,
            checkoutPath: $inventory->checkoutPath,
            root: $inventory->root,
            branch: $inventory->branch,
            startingCommit: $inventory->startingCommit,
            commonRepositoryPath: $inventory->commonRepositoryPath,
            sourceIdentity: $inventory->sourceIdentity,
            linkedWorktreePaths: $paths,
            digest: $inventory->digest,
        );
    }

    public function finalize(AppInstanceRemovalMember $member): string
    {
        if ($this->failFinalize) {
            throw new ResourceOperationException(
                'instance.source_interrupted',
                'Source finalization was interrupted.',
                502,
            );
        }

        $path = (string) $member->checkout_path;
        $quarantine = sprintf(
            '%s/.orbit-removals/%s.%d.quarantine',
            $member->root,
            $member->app_instance_removal_id,
            $member->id,
        );
        $this->calls[] = "finalize:{$member->app_instance_id}";
        $this->finalized[] = $path;
        $this->paths = array_values(array_diff($this->paths, [$path, $quarantine]));
        $this->states[$member->app_instance_id] = AppInstanceSourceRevalidationState::Completed;

        return hash('sha256', "receipt\0{$member->source_digest}");
    }
}

final class Orb124CoordinatorProjector implements AppInstanceRemovalProjector
{
    /** @var list<int> */
    public array $routeCalls = [];

    public bool $failRuntime = false;

    public bool $failAfterRouteMutation = false;

    public function clearRouteTarget(AppInstanceRemovalMember $member): string
    {
        $this->routeCalls[] = $member->app_instance_id;
        $route = Route::query()->find($member->route_id);

        if (! $route instanceof Route) {
            return 'deleted';
        }

        $route->targets()->where('app_instance_id', $member->app_instance_id)->delete();
        $route->delete();

        if ($this->failAfterRouteMutation) {
            throw new ResourceOperationException(
                'instance.route_interrupted',
                'Route deletion response was lost.',
                502,
            );
        }

        return 'deleted';
    }

    public function cleanupRuntime(AppInstanceRemovalMember $member): void
    {
        if ($this->failRuntime) {
            throw new ResourceOperationException(
                'instance.runtime_interrupted',
                'Runtime cleanup interrupted.',
                502,
            );
        }
    }
}

final class Orb124CoordinatorLock implements AppDevSourceOperationLock
{
    public bool $acceptedWhileHeld = false;

    private bool $held = false;

    public function synchronized(int $nodeId, \Closure $operation): mixed
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
