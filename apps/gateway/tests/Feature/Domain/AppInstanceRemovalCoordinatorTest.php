<?php

declare(strict_types=1);

use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Removal\AppInstanceRemovalException;
use App\Domain\AppInstances\Removal\AppInstanceRemovalProjector;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
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
    $this->orb124Coordinator = new RemoveAppInstanceAction(
        $this->orb124CoordinatorSource,
        $this->orb124CoordinatorProjector,
        new ManagedCheckoutOverlap,
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
        ->toBe([$worktree->id, $checkout->id]);
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
        ->toThrow(ResourceOperationException::class, 'inventory changed')
        ->and(fn () => $this->orb124Coordinator->execute($checkout->refresh(), false))
        ->toThrow(ResourceOperationException::class, 'different removal request');
    expect(AppInstance::query()->whereKey($new->id)->sole()->getAttributes())
        ->toBe($before)
        ->and($operation?->members()->count())
        ->toBe(2)
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
            linkedWorktreePaths: $this->paths,
            digest: hash('sha256', "source\0{$appInstance->id}\0{$appInstance->checkout_path}"),
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
        $path = (string) $member->checkout_path;
        $this->calls[] = "finalize:{$member->app_instance_id}";
        $this->finalized[] = $path;
        $this->paths = array_values(array_diff($this->paths, [$path]));

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
