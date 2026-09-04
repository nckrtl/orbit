<?php

declare(strict_types=1);

use App\Actions\Apps\RemoveAppAction;
use App\Actions\Clusters\ClearClusterRouterAction;
use App\Actions\Clusters\RemoveClusterAction;
use App\Actions\Nodes\RemoveNodeAction;
use App\Actions\Nodes\RemoveNodeRoleAction;
use App\Actions\Routes\ClearRouteTargetAction;
use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\RemoveRouteAction;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteTarget;

beforeEach(function (): void {
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'main_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = route_removal_node('dev');
    $this->instance = AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
        'checkout_path' => '/srv/dev',
        'status' => AppInstanceState::Active,
    ]);
    $this->route = app(CreateRouteAction::class)->ensureForAppInstance($this->instance, null);
    $this->guard = app(RouteRemovalGuard::class);
});

it('guards App ownership, Node scope, target host, retained basis, and app roles', function (): void {
    expect(fn () => $this->guard->assertAppRemovable($this->orbitApp))
        ->toThrow(ResourceOperationException::class, 'still owns Routes')
        ->and(fn () => $this->guard->assertNodeRemovable($this->node))
        ->toThrow(ResourceOperationException::class, 'referenced by Routes')
        ->and(fn () => $this->guard->assertRoleRemovable($this->node, RoleName::AppDev))
        ->toThrow(NodeRoleValidationException::class, 'hosts Route targets');

    app(ClearRouteTargetAction::class)->execute($this->route);
    expect(fn () => $this->guard->assertNodeRemovable($this->node))
        ->toThrow(ResourceOperationException::class, 'referenced by Routes');
});

it('guards Cluster scope and Router clearing even when the Cluster has no TLD', function (): void {
    $cluster = Cluster::query()->create(['name' => 'routing', 'state' => 'active', 'tld' => null]);
    $this->route->update(['node_id' => null, 'cluster_id' => $cluster->id]);

    expect(fn () => $this->guard->assertClusterRemovable($cluster))
        ->toThrow(ResourceOperationException::class, 'still scopes Routes')
        ->and(fn () => $this->guard->assertRouterRemovable($cluster))
        ->toThrow(ResourceOperationException::class, 'require its Router');
});

it('runs ownership and scope guards through destructive action entry points', function (): void {
    $caller = route_removal_node('caller');

    expect(fn () => app(RemoveAppAction::class)->execute($this->orbitApp))
        ->toThrow(ResourceOperationException::class, 'still owns Routes')
        ->and(fn () => app(RemoveNodeAction::class)->execute($this->node, $caller, offline: true, force: true))
        ->toThrow(ResourceOperationException::class, 'referenced by Routes');

    $cluster = Cluster::query()->create(['name' => 'destructive', 'state' => 'active', 'tld' => null]);
    $this->route->update(['node_id' => null, 'cluster_id' => $cluster->id]);

    expect(fn () => app(RemoveClusterAction::class)->execute($cluster))
        ->toThrow(ResourceOperationException::class, 'still scopes Routes')
        ->and(fn () => app(ClearClusterRouterAction::class)->execute($cluster))
        ->toThrow(ResourceOperationException::class, 'require its Router');
});

it('runs the target-host guard before every app-role removal mode', function (
    bool $force,
    bool $purgeData,
    bool $offline,
): void {
    expect(fn () => app(RemoveNodeRoleAction::class)->execute(
        $this->node,
        RoleName::AppDev,
        force: $force,
        purgeData: $purgeData,
        offline: $offline,
    ))
        ->toThrow(NodeRoleValidationException::class, 'hosts Route targets');
})->with([
    'ordinary' => [false, false, false],
    'force' => [true, false, false],
    'purge' => [true, true, false],
    'offline' => [true, true, true],
]);

it('Route removal deletes only owned target rows and releases unrelated resources', function (): void {
    $unrelatedNode = route_removal_node('unrelated');
    $unrelatedApp = OrbitApp::query()->create([
        'name' => 'Other',
        'slug' => 'other',
        'repository_url' => 'https://example.test/other.git',
    ]);

    app(RemoveRouteAction::class)->execute($this->route);

    expect(Route::query()->count())
        ->toBe(0)
        ->and(RouteTarget::query()->count())
        ->toBe(0)
        ->and($this->instance->fresh())
        ->not->toBeNull()->and($unrelatedNode->fresh())
        ->not->toBeNull()->and($unrelatedApp->fresh())
        ->not->toBeNull();
});

function route_removal_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'tld' => "{$name}.test",
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => '10.44.2.'.(Node::query()->count() + 2),
    ]);
}
