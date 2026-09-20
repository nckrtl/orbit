<?php

declare(strict_types=1);

use App\Actions\Clusters\SetClusterRouterAction;
use App\Actions\Clusters\UpdateClusterAction;
use App\Actions\Routes\ConvergeRouteAction;
use App\Actions\Routes\ConvergeRouteTargetSetAction;
use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\RemoveRouteAction;
use App\Data\Clusters\UpdateClusterData;
use App\Data\Routes\CreateRouteData;
use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceRemover;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRouteDomain;
use App\Domain\AppInstances\Environment\AppInstanceRouteEnvironmentSynchronizer;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterReplacementProjector;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RoutePublicPublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteRemovalStep;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeClusterRouterReplacementProjector;
use Tests\Support\FakeRouteRemovalProjector;

beforeEach(function (): void {
    $this->events = new RouteDomainChangeEvents;
    $this->projector = new RouteDomainChangeProjectorFake($this->events);
    $this->configuration = new RouteDomainChangeConfiguratorFake($this->events);
    $this->environment = new RouteDomainChangeEnvironmentFake($this->events);
    app()->instance(RouteDomainProjector::class, $this->projector);
    app()->instance(DevelopmentAppInstanceConfigurator::class, $this->configuration);
    app()->instance(AppInstanceRouteEnvironmentSynchronizer::class, $this->environment);
    app()->instance(
        DevelopmentProjectionOperationLock::class,
        new RouteDomainChangeOwnerFake($this->events),
    );
});

it('converges a generated private development Route when Node TLD reconciliation allows it', function (): void {
    $route = route_domain_change_route(laravel: true, generated: true);

    $updated = app(ConvergeRouteAction::class)->execute($route, 'feature.acme.next.test', allowGenerated: true);

    expect($this->events->values)
        ->toBe([
            'owner',
            'workload-certificate',
            'workload-caddy',
            'router-certificate',
            'firewall-policy',
            'workload-verify',
            'router-caddy',
            'url:https://feature.acme.next.test',
            'dns-publication',
            'cleanup',
            'url:https://feature.acme.next.test',
            'workload-verify',
        ])
        ->and($updated->domain)
        ->toBe('feature.acme.next.test')
        ->and($updated->provenance->value)
        ->toBe('generated')
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and($updated->id)
        ->not->toBe($route->id);
});

it('refuses a generated Route unless Node TLD reconciliation allows it', function (): void {
    $route = route_domain_change_route(laravel: false, generated: true);

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'feature.acme.next.test'))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.reconciliation_required');
        });

    expect($route->refresh()->domain)->toBe('feature.acme.dev.test');
});

it('records Cluster TLD generated Route failure, restores the old Cluster TLD and URL, and retries the same change', function (): void {
    [$cluster, $route, $member] = cluster_tld_generated_route();
    $this->projector->failures = [
        'dns-publication' => 1,
        'rollback-dns' => 1,
    ];
    $routerBefore = $cluster->routerAssignment()->get()->map->getAttributes()->all();

    expect(fn () => app(UpdateClusterAction::class)->execute(
        $cluster,
        new UpdateClusterData(
            nameProvided: false,
            name: null,
            tldProvided: true,
            tld: 'next-cluster.test',
            stateProvided: false,
            state: null,
        ),
    ))->toThrow(ResourceOperationException::class, 'Injected dns-publication failure.');

    $replacement = Route::query()->where('domain', 'main.acme.next-cluster.test')->sole();

    expect($cluster->refresh()->tld)
        ->toBe('cluster.test')
        ->and($cluster->refresh()->state)
        ->toBe(ClusterState::Active)
        ->and($route->refresh()->domain)
        ->toBe('main.acme.cluster.test')
        ->and($route->status)
        ->toBe(RouteStatus::Active)
        ->and($route->replaced_by_route_id)
        ->toBe($replacement->id)
        ->and($replacement->status)
        ->toBe(RouteStatus::Failed)
        ->and($replacement->failed_step)
        ->toBe('dns-publication')
        ->and($replacement->error_code)
        ->not->toBeNull()
        ->and($this->events->values)
        ->toContain('url:https://main.acme.next-cluster.test')
        ->toContain('url:https://main.acme.cluster.test')
        ->and($member->refresh()->cluster_id)
        ->toBe($cluster->id)
        ->and($cluster->routerAssignment()->get()->map->getAttributes()->all())
        ->toBe($routerBefore);

    expect(fn () => app(UpdateClusterAction::class)->execute(
        $cluster,
        new UpdateClusterData(
            nameProvided: false,
            name: null,
            tldProvided: true,
            tld: 'other-cluster.test',
            stateProvided: false,
            state: null,
        ),
    ))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('route.domain_change_conflict');
    });

    $this->projector->failures = [];
    $updated = app(UpdateClusterAction::class)->execute(
        $cluster,
        new UpdateClusterData(
            nameProvided: false,
            name: null,
            tldProvided: true,
            tld: 'next-cluster.test',
            stateProvided: false,
            state: null,
        ),
    );
    $replaced = Route::query()->where('domain', 'main.acme.next-cluster.test')->sole();

    expect($updated->tld)
        ->toBe('next-cluster.test')
        ->and($replaced->status)
        ->toBe(RouteStatus::Active)
        ->and(Route::query()->find($route->id))
        ->toBeNull();
});

it('records Router replacement failure, restores the old Router, and retries the same candidate', function (
    string $failure,
): void {
    [$cluster, $route, $member, $current, $replacement] = cluster_router_replacement_route();
    $projector = bind_cluster_router_replacement_projection();
    $projector->failures = [$failure => 1];
    $routeBefore = $route->fresh(['targets'])->toArray();
    $memberBefore = $member->fresh()->toArray();

    expect(fn () => app(SetClusterRouterAction::class)->execute($cluster, $replacement))
        ->toThrow(RuntimeConvergenceException::class, "Injected {$failure} failure.");

    $candidate = NodeRole::query()
        ->where('role', RoleName::Router)
        ->where('node_id', $replacement->id)
        ->sole();

    expect($cluster->routerAssignment()->sole()->node_id)
        ->toBe($current->id)
        ->and($candidate->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($candidate->failed_step)
        ->toBe($failure)
        ->and($route->fresh(['targets'])->toArray())
        ->toBe($routeBefore)
        ->and($member->fresh()->toArray())
        ->toBe($memberBefore)
        ->and($projector->events)
        ->toContain('restore');

    expect(fn () => app(SetClusterRouterAction::class)->execute($cluster, $current))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('cluster.router_transition_conflict');
        });

    $projector->failures = [];
    app(SetClusterRouterAction::class)->execute($cluster, $replacement);

    expect($cluster->routerAssignment()->sole()->node_id)
        ->toBe($replacement->id)
        ->and($route->refresh()->only(['id', 'domain', 'cluster_id', 'status']))
        ->toBe([
            'id' => $route->id,
            'domain' => $route->domain,
            'cluster_id' => $cluster->id,
            'status' => RouteStatus::Active,
        ]);
})->with([
    'router-certificate',
    'firewall-policy',
    'workload-verify',
    'router-caddy',
    'dns-publication',
]);

it('records Router replacement database failure after publication and retries forward', function (): void {
    [$cluster, $route, $member, $current, $replacement] = cluster_router_replacement_route();
    $projector = bind_cluster_router_replacement_projection();
    $routeBefore = $route->fresh(['targets'])->toArray();
    $failCutover = true;
    NodeRole::updating(function (NodeRole $role) use (&$failCutover): void {
        if (
            ! $failCutover
            || $role->role !== RoleName::Router
            || ! $role->isDirty('status')
            || $role->status !== LifecycleStatus::Active
        ) {
            return;
        }

        $original = $role->getOriginal('status');

        if ($original !== LifecycleStatus::Provisioning && $original !== LifecycleStatus::Provisioning->value) {
            return;
        }

        $failCutover = false;

        throw new RuntimeException('Injected database failure.');
    });

    expect(fn () => app(SetClusterRouterAction::class)->execute($cluster, $replacement))
        ->toThrow(RuntimeException::class, 'Injected database failure.');

    $candidate = NodeRole::query()
        ->where('role', RoleName::Router)
        ->where('node_id', $replacement->id)
        ->sole();

    expect($cluster->routerAssignment()->sole()->node_id)
        ->toBe($current->id)
        ->and($candidate->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($candidate->failed_step)
        ->toBe('database')
        ->and($projector->events)
        ->not->toContain('restore')
        ->and($route->fresh(['targets'])->toArray())
        ->toBe($routeBefore);

    app(SetClusterRouterAction::class)->execute($cluster, $replacement);

    expect($cluster->routerAssignment()->sole()->node_id)
        ->toBe($replacement->id)
        ->and($route->refresh()->id)
        ->toBe($routeBefore['id']);
});

it('keeps the replacement Router authoritative when cleanup fails and retries cleanup', function (): void {
    [$cluster, $route, $member, $current, $replacement] = cluster_router_replacement_route();
    $projector = bind_cluster_router_replacement_projection();
    $projector->failures = ['cleanup' => 1];

    expect(fn () => app(SetClusterRouterAction::class)->execute($cluster, $replacement))
        ->toThrow(RuntimeConvergenceException::class, 'Injected cleanup failure.');

    $candidate = NodeRole::query()
        ->where('role', RoleName::Router)
        ->where('node_id', $replacement->id)
        ->sole();

    expect($cluster->routerAssignment()->sole()->node_id)
        ->toBe($replacement->id)
        ->and($candidate->status)
        ->toBe(LifecycleStatus::Active)
        ->and($candidate->failed_step)
        ->toBe('cleanup')
        ->and($candidate->error_code)
        ->toBe('route.test_cleanup')
        ->and($projector->events)
        ->not->toContain('restore');

    expect(fn () => app(SetClusterRouterAction::class)->execute($cluster, $current))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('cluster.router_transition_conflict');
        });

    $projector->failures = [];
    $projector->events = [];
    app(SetClusterRouterAction::class)->execute($cluster, $replacement);

    expect($cluster->routerAssignment()->sole()->node_id)
        ->toBe($replacement->id)
        ->and($candidate->refresh()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => LifecycleStatus::Active,
            'failed_step' => null,
            'error_code' => null,
        ])
        ->and($projector->events)
        ->toBe(['cleanup'])
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Active);
});

it('records Router replacement rollback failure on the same candidate', function (): void {
    [$cluster, $route, $member, $current, $replacement] = cluster_router_replacement_route();
    $projector = bind_cluster_router_replacement_projection();
    $projector->failures = ['dns-publication' => 1, 'restore' => 1];

    expect(fn () => app(SetClusterRouterAction::class)->execute($cluster, $replacement))
        ->toThrow(RuntimeConvergenceException::class, 'Injected restore failure.');

    $candidate = NodeRole::query()
        ->where('role', RoleName::Router)
        ->where('node_id', $replacement->id)
        ->sole();

    expect($cluster->routerAssignment()->sole()->node_id)
        ->toBe($current->id)
        ->and($candidate->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($candidate->failed_step)
        ->toBe('rollback:dns-publication')
        ->and($route->refresh()->id)
        ->toBe($route->id);

    $projector->failures = [];
    app(SetClusterRouterAction::class)->execute($cluster, $replacement);

    expect($cluster->routerAssignment()->sole()->node_id)
        ->toBe($replacement->id);
});

it('records generated Route failure, restores the old URL, and refuses a conflicting mutation', function (): void {
    $route = route_domain_change_route(laravel: true, generated: true);
    $this->projector->failures = [
        'workload-caddy' => 1,
        'rollback-caddy' => 1,
    ];

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'feature.acme.next.test', allowGenerated: true))
        ->toThrow(ResourceOperationException::class, 'Injected workload-caddy failure.');

    $replacement = Route::query()->where('domain', 'feature.acme.next.test')->sole();

    expect($route->refresh()->domain)
        ->toBe('feature.acme.dev.test')
        ->and($route->status)
        ->toBe(RouteStatus::Active)
        ->and($route->replaced_by_route_id)
        ->toBe($replacement->id)
        ->and($replacement->status)
        ->toBe(RouteStatus::Failed)
        ->and($replacement->failed_step)
        ->toBe('workload-caddy');

    expect(fn () => app(ConvergeRouteAction::class)->execute(
        $route,
        'feature.acme.other.test',
        allowGenerated: true,
    ))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('route.domain_change_conflict');
    });

    $this->projector->failures = [];
    $updated = app(ConvergeRouteAction::class)->execute($route, 'feature.acme.next.test', allowGenerated: true);

    expect($updated->domain)
        ->toBe('feature.acme.next.test')
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and(Route::query()->find($route->id))
        ->toBeNull();
});

it('converges a same-domain Cluster scope change on one Route and restores after a pre-cutover failure', function (): void {
    $route = route_domain_change_route(laravel: true);
    $cluster = Cluster::query()->create(['name' => 'activation', 'state' => 'inactive', 'tld' => 'cluster.test']);
    $router = Node::query()->create([
        'name' => 'activation-router',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
        'user' => 'orbit',
    ]);
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $placement = new RoutePlacement(nodeId: null, clusterId: $cluster->id, effectiveTld: 'dev.test');

    $updated = app(ConvergeRouteAction::class)->execute(
        $route,
        $route->domain,
        allowGenerated: false,
        placement: $placement,
    );

    expect($updated->id)
        ->toBe($route->id)
        ->and($updated->domain)
        ->toBe('old.example.test')
        ->and($updated->cluster_id)
        ->toBe($cluster->id)
        ->and($updated->node_id)
        ->toBeNull()
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and($updated->replacement_step)
        ->toBeNull()
        ->and($this->events->values)
        ->toBe([
            'owner',
            'workload-certificate',
            'workload-caddy',
            'router-certificate',
            'firewall-policy',
            'workload-verify',
            'router-caddy',
            'url:https://old.example.test',
            'dns-publication',
            'cleanup',
            'url:https://old.example.test',
            'workload-verify',
        ]);

    $nodeId = $route->targets->sole()->appInstance->node_id;
    $route->refresh()->update(['node_id' => $nodeId, 'cluster_id' => null]);
    $this->events->values = [];
    $this->projector->failures['router-caddy'] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute(
        $route->refresh(),
        $route->domain,
        placement: $placement,
    ))->toThrow(ResourceOperationException::class, 'Injected router-caddy failure.');

    expect($route->refresh()->cluster_id)
        ->toBeNull()
        ->and($route->refresh()->node_id)
        ->not->toBeNull()
        ->and($route->refresh()->failed_step)
        ->toBe('router-caddy')
        ->and($this->events->values)
        ->toContain('rollback-certificates', 'rollback-caddy', 'rollback-dns');

    $this->projector->failures = [];
    $this->events->values = [];
    $retried = app(ConvergeRouteAction::class)->execute(
        $route->refresh(),
        $route->domain,
        placement: $placement,
    );

    expect($retried->cluster_id)
        ->toBe($cluster->id)
        ->and($retried->failed_step)
        ->toBeNull()
        ->and($retried->replacement_step)
        ->toBeNull();
});

it('applies proposed Cluster placement on a generated domain replacement', function (): void {
    $route = route_domain_change_route(laravel: false, generated: true);
    $cluster = Cluster::query()->create(['name' => 'generated-activation', 'state' => 'inactive', 'tld' => 'next.test']);
    $placement = new RoutePlacement(nodeId: null, clusterId: $cluster->id, effectiveTld: 'next.test');

    $updated = app(ConvergeRouteAction::class)->execute(
        $route,
        'feature.acme.next.test',
        allowGenerated: true,
        placement: $placement,
    );

    expect($updated->id)
        ->not->toBe($route->id)
        ->and($updated->domain)
        ->toBe('feature.acme.next.test')
        ->and($updated->cluster_id)
        ->toBe($cluster->id)
        ->and($updated->node_id)
        ->toBeNull()
        ->and($updated->provenance->value)
        ->toBe('generated');
});

it('converges a generated private Route onto Cluster scope without replacing its domain', function (): void {
    $route = route_domain_change_route(laravel: true, generated: true);
    $cluster = Cluster::query()->create(['name' => 'membership-generated', 'state' => 'active', 'tld' => null]);
    $router = Node::query()->create([
        'name' => 'membership-generated-router',
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
        'user' => 'orbit',
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);

    $updated = app(ConvergeRouteAction::class)->execute(
        $route,
        $route->domain,
        allowGenerated: true,
        placement: new RoutePlacement(nodeId: null, clusterId: $cluster->id, effectiveTld: 'dev.test'),
    );

    expect($updated->id)
        ->toBe($route->id)
        ->and($updated->domain)
        ->toBe('feature.acme.dev.test')
        ->and($updated->node_id)
        ->toBeNull()
        ->and($updated->cluster_id)
        ->toBe($cluster->id)
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and($updated->replacement_step)
        ->toBeNull()
        ->and($updated->failed_step)
        ->toBeNull();
});

it('records scope-only failure, restores the old scope, and refuses a conflicting domain retry', function (): void {
    $route = route_domain_change_route(laravel: true, generated: true);
    $cluster = Cluster::query()->create(['name' => 'membership-retry', 'state' => 'active', 'tld' => null]);
    $router = Node::query()->create([
        'name' => 'membership-retry-router',
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.41',
        'wireguard_ip' => '10.44.0.41',
        'user' => 'orbit',
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $placement = new RoutePlacement(nodeId: null, clusterId: $cluster->id, effectiveTld: 'dev.test');
    $this->projector->failures = [
        'workload-caddy' => 1,
        'rollback-caddy' => 1,
    ];

    expect(fn () => app(ConvergeRouteAction::class)->execute(
        $route,
        $route->domain,
        allowGenerated: true,
        placement: $placement,
    ))->toThrow(ResourceOperationException::class, 'Injected workload-caddy failure.');

    expect($route->refresh()->node_id)
        ->not->toBeNull()
        ->and($route->cluster_id)
        ->toBeNull()
        ->and($route->status)
        ->toBe(RouteStatus::Active)
        ->and($route->failed_step)
        ->toBe('workload-caddy')
        ->and($route->replaced_by_route_id)
        ->toBeNull();

    expect(fn () => app(ConvergeRouteAction::class)->execute(
        $route,
        'feature.acme.other.test',
        allowGenerated: true,
        placement: new RoutePlacement(nodeId: null, clusterId: $cluster->id, effectiveTld: 'other.test'),
    ))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('route.domain_change_conflict');
    });

    $this->projector->failures = [];
    $updated = app(ConvergeRouteAction::class)->execute(
        $route,
        $route->domain,
        allowGenerated: true,
        placement: $placement,
    );

    expect($updated->id)
        ->toBe($route->id)
        ->and($updated->cluster_id)
        ->toBe($cluster->id)
        ->and($updated->failed_step)
        ->toBeNull();
});

it('prepares every projection and Laravel URL before DNS then cuts over and cleans up', function (): void {
    $route = route_domain_change_route(laravel: true);

    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($this->events->values)
        ->toBe([
            'owner',
            'workload-certificate',
            'workload-caddy',
            'router-certificate',
            'firewall-policy',
            'workload-verify',
            'router-caddy',
            'url:https://next.example.test',
            'dns-publication',
            'cleanup',
            'url:https://next.example.test',
            'workload-verify',
        ])
        ->and($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and($updated->replaces_route_id)
        ->toBeNull()
        ->and($updated->replaced_by_route_id)
        ->toBeNull()
        ->and($updated->replacement_step)
        ->toBeNull()
        ->and($updated->id)
        ->not->toBe($route->id)
        ->and(Route::query()->find($route->id))
        ->toBeNull();
});

it('cleans up from the Route that is now authoritative, so the live certificate names the served domain', function (): void {
    $route = route_domain_change_route(laravel: true);

    app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    // Cleanup issues the live app-instance leaf and removes the staging scopes. Handing it the
    // retiring Route publishes a certificate for a domain the Node no longer serves, and Caddy then
    // falls back to automatic HTTPS for a private Orbit domain.
    expect($this->events->cleanupDomains)->toBe(['cleanup:next.example.test']);
});

it('does not configure Laravel for a source profile classified as non-Laravel', function (): void {
    $route = route_domain_change_route(laravel: false);

    app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($this->events->values)->not->toContain('url:https://next.example.test', 'url:https://old.example.test');
});

it('synchronizes the production candidate environment before DNS and preserves the Route target', function (): void {
    $route = route_domain_change_route(laravel: true, environment: 'production');
    $targetId = $route->targets->sole()->app_instance_id;

    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($this->events->values)
        ->toBe([
            'owner',
            'workload-certificate',
            'workload-caddy',
            'router-certificate',
            'firewall-policy',
            'workload-verify',
            'router-caddy',
            'environment:candidate',
            'dns-publication',
            'cleanup',
            'environment:candidate',
            'workload-verify',
        ])
        ->and($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->targets)
        ->toHaveCount(1)
        ->and($updated->targets->sole()->app_instance_id)
        ->toBe($targetId);
});

it('replaces a shared production Route while preserving the ordered target pool', function (): void {
    $route = route_domain_change_shared_production_route();
    $expected = $route->targets()->orderBy('position')->pluck('app_instance_id')->all();
    expect($expected)->toHaveCount(2);

    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toBe($expected)
        ->and($updated->id)
        ->not->toBe($route->id)
        ->and(Route::query()->find($route->id))
        ->toBeNull()
        ->and(array_count_values($this->events->values)['workload-certificate'])
        ->toBe(2);
});

it('leaves the old Route authoritative and removes the replacement after a pre-cutover failure', function (
    string $failure,
): void {
    $route = route_domain_change_route(laravel: true);
    $this->projector->failures[$failure] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, "Injected {$failure} failure.");

    expect($route->refresh()->domain)
        ->toBe('old.example.test')
        ->and($route->status)
        ->toBe(RouteStatus::Active)
        ->and($route->replaced_by_route_id)
        ->toBeNull()
        ->and(Route::query()->where('domain', 'next.example.test')->exists())
        ->toBeFalse()
        ->and($this->events->values)
        ->toContain('rollback-certificates', 'rollback-caddy', 'rollback-dns');
})->with([
    'workload certificate' => ['workload-certificate', 'workload-certificate'],
    'workload Caddy' => ['workload-caddy', 'workload-caddy'],
    'Router certificate' => ['router-certificate', 'router-certificate'],
    'firewall policy' => ['firewall-policy', 'firewall-policy'],
    'workload verification' => ['workload-verify', 'workload-verify'],
    'Router Caddy' => ['router-caddy', 'router-caddy'],
    'DNS publication' => ['dns-publication', 'dns-publication'],
]);

it('records Laravel URL failure, restores the old URL, and removes the replacement', function (): void {
    $route = route_domain_change_route(laravel: true);
    $this->configuration->failures = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected Laravel URL failure.');

    expect($route->refresh()->domain)
        ->toBe('old.example.test')
        ->and($route->replaced_by_route_id)
        ->toBeNull()
        ->and($this->events->values)
        ->toContain('url:https://old.example.test')
        ->and(Route::query()->where('domain', 'next.example.test')->exists())
        ->toBeFalse();
});

it('retains an inspectable failed replacement when pre-cutover cleanup is incomplete', function (): void {
    $route = route_domain_change_route(laravel: false);
    $this->projector->failures = [
        'workload-caddy' => 1,
        'rollback-caddy' => 1,
    ];

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected workload-caddy failure.');

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();

    expect($route->refresh()->domain)
        ->toBe('old.example.test')
        ->and($route->status)
        ->toBe(RouteStatus::Active)
        ->and($route->replaced_by_route_id)
        ->toBe($replacement->id)
        ->and($replacement->status)
        ->toBe(RouteStatus::Failed)
        ->and($replacement->replaces_route_id)
        ->toBe($route->id)
        ->and($replacement->failed_step)
        ->toBe('workload-caddy');
});

it('recovers only the identical failed replacement and refuses a conflicting domain', function (): void {
    $route = route_domain_change_route(laravel: false);
    $this->projector->failures = [
        'workload-certificate' => 1,
        'rollback-certificates' => 1,
    ];

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class);

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();
    $before = $replacement->fresh()->getAttributes();
    $oldBefore = $route->refresh()->getAttributes();

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'other.example.test'))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.domain_change_conflict');
        });

    expect($replacement->fresh()->getAttributes())
        ->toBe($before)
        ->and($route->fresh()->getAttributes())
        ->toBe($oldBefore);

    $this->projector->failures = [];
    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and(Route::query()->find($route->id))
        ->toBeNull();
});

it('records database cutover failure without making the replacement authoritative', function (): void {
    $route = route_domain_change_route(laravel: false);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER route_domain_change_cutover_failure
        BEFORE UPDATE OF status ON routes
        WHEN NEW.status = 'activating' AND NEW.domain = 'next.example.test'
        BEGIN
            SELECT RAISE(ABORT, 'Injected database cutover failure.');
        END
        SQL);

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(QueryException::class);

    expect($route->refresh()->domain)
        ->toBe('old.example.test')
        ->and($route->status)
        ->toBe(RouteStatus::Active)
        ->and($this->events->values)
        ->toContain('rollback-dns', 'rollback-caddy', 'rollback-certificates');
});

it('exposes activating and retiring Routes through one cutover transition', function (): void {
    $route = route_domain_change_route(laravel: false);
    $this->projector->failures['cleanup'] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected cleanup failure.');

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();

    expect($replacement->status)
        ->toBe(RouteStatus::Activating)
        ->and($replacement->replacement_step)
        ->toBe(RouteReplacementStep::DatabaseCutover)
        ->and($replacement->failed_step)
        ->toBe('cleanup')
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Retiring)
        ->and($route->domain)
        ->toBe('old.example.test')
        ->and($replacement->replaces_route_id)
        ->toBe($route->id)
        ->and($route->replaced_by_route_id)
        ->toBe($replacement->id);
});

it('keeps the replacement authoritative when cleanup fails and retries cleanup only', function (): void {
    $route = route_domain_change_route(laravel: false);
    $this->projector->failures['cleanup'] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected cleanup failure.');

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();
    $eventCount = count($this->events->values);

    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($updated->id)
        ->toBe($replacement->id)
        ->and($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and($updated->replaces_route_id)
        ->toBeNull()
        ->and(Route::query()->find($route->id))
        ->toBeNull()
        ->and(array_slice($this->events->values, $eventCount))
        ->toBe(['owner', 'cleanup', 'workload-verify']);
});

it('records cleanup failure when deleting the retiring Route fails', function (): void {
    $route = route_domain_change_route(laravel: false);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER route_domain_change_cleanup_failure
        BEFORE DELETE ON routes
        WHEN OLD.status = 'retiring'
        BEGIN
            SELECT RAISE(ABORT, 'Injected cleanup persistence failure.');
        END
        SQL);

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(QueryException::class);

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();

    expect($replacement->status)
        ->toBe(RouteStatus::Activating)
        ->and($replacement->domain)
        ->toBe('next.example.test')
        ->and($replacement->failed_step)
        ->toBe('cleanup')
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Retiring);
});

it('retains failed_step evidence at each untargeted removal boundary and resumes without restoring projections', function (
    string $failure,
): void {
    $route = route_untargeted_removal_route();
    $unrelated = route_untargeted_removal_unrelated($route);
    $projector = new FakeRouteRemovalProjector;
    $projector->failures[$failure] = 1;
    app()->instance(RouteRemovalProjector::class, $projector);

    expect(fn () => app(RemoveRouteAction::class)->execute($route))
        ->toThrow(ResourceOperationException::class, "Injected {$failure} failure.");

    expect($route->refresh()->status)
        ->toBe(RouteStatus::Failed)
        ->and($route->failed_step)
        ->toBe($failure)
        ->and($route->error_code)
        ->toBe("route.test_{$failure}")
        ->and(Route::query()->whereKey($unrelated->id)->exists())
        ->toBeTrue()
        ->and($projector->events)
        ->toContain($failure);

    $completed = count($projector->events);
    $retried = app(RemoveRouteAction::class)->execute($route);

    expect(Route::query()->whereKey($route->id)->exists())
        ->toBeFalse()
        ->and($retried->id)
        ->toBe($route->id)
        ->and(array_slice($projector->events, $completed))
        ->toBe(['dns', 'certificates', 'caddy', 'firewall'])
        ->and($unrelated->fresh())
        ->not->toBeNull();
})->with([
    'DNS' => ['dns'],
    'certificates' => ['certificates'],
    'Caddy' => ['caddy'],
    'firewall' => ['firewall'],
]);

it('records the earliest revalidation failure on untargeted removal retry', function (): void {
    $route = route_untargeted_removal_route();
    $projector = new FakeRouteRemovalProjector;
    $projector->failures['firewall'] = 1;
    app()->instance(RouteRemovalProjector::class, $projector);

    expect(fn () => app(RemoveRouteAction::class)->execute($route))
        ->toThrow(ResourceOperationException::class, 'Injected firewall failure.');

    $projector->failures['dns'] = 1;

    expect(fn () => app(RemoveRouteAction::class)->execute($route))
        ->toThrow(ResourceOperationException::class, 'Injected dns failure.');

    expect($route->refresh()->status)
        ->toBe(RouteStatus::Failed)
        ->and($route->failed_step)
        ->toBe(RouteRemovalStep::Dns->value)
        ->and($route->error_code)
        ->toBe('route.test_dns');
});

it('records record-deletion failure and refuses a conflicting mutation on untargeted removal retry', function (): void {
    $route = route_untargeted_removal_route();
    app()->instance(RouteRemovalProjector::class, new FakeRouteRemovalProjector);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER route_untargeted_removal_record_failure
        BEFORE DELETE ON routes
        WHEN OLD.domain = 'untargeted.example.test'
        BEGIN
            SELECT RAISE(ABORT, 'Injected record deletion failure.');
        END
        SQL);

    expect(fn () => app(RemoveRouteAction::class)->execute($route))
        ->toThrow(QueryException::class);

    expect($route->refresh()->status)
        ->toBe(RouteStatus::Failed)
        ->and($route->failed_step)
        ->toBe(RouteRemovalStep::Record->value)
        ->and($route->error_code)
        ->toBe('route.removal_failed');

    $conflict = AppInstance::query()->create([
        'app_id' => $route->app_id,
        'node_id' => $route->node_id,
        'name' => 'conflict',
        'checkout_path' => '/srv/acme/conflict',
        'branch' => 'main',
        'starting_commit' => str_repeat('b', 40),
        'status' => AppInstanceState::Reserved,
    ]);
    $route->targets()->create(['app_instance_id' => $conflict->id, 'position' => 0]);

    expect(fn () => app(RemoveRouteAction::class)->execute($route))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.owner_changed');
        });

    expect($route->fresh())->not->toBeNull();
});

it('prepares public Ingress after Router Caddy and activates the handler only after cutover', function (): void {
    $route = route_domain_change_public_route();

    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test', RoutePublication::Public);

    expect($this->events->values)
        ->toBe([
            'owner',
            'workload-certificate',
            'workload-caddy',
            'router-certificate',
            'firewall-policy',
            'workload-verify',
            'router-caddy',
            'ingress-certificate',
            'ingress-caddy',
            'public-edge-verified',
            'environment:candidate',
            'dns-publication',
            'public-activated',
            'ingress-firewall',
            'cleanup',
            'environment:candidate',
            'workload-verify',
        ])
        ->and($updated->publication)
        ->toBe(RoutePublication::Public)
        ->and($updated->public_publication)
        ->toBe(RoutePublicPublication::Active)
        ->and($updated->id)
        ->not->toBe($route->id);
});

it('rolls back the public edge before cutover and keeps the handler inactive', function (string $failure): void {
    $route = route_domain_change_public_route();
    $this->projector->failures[$failure] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test', RoutePublication::Public))
        ->toThrow(ResourceOperationException::class, "Injected {$failure} failure.");

    expect($route->refresh()->domain)
        ->toBe('old.example.test')
        ->and($route->status)
        ->toBe(RouteStatus::Active)
        ->and($route->public_publication)
        ->toBe(RoutePublicPublication::Inactive)
        ->and($route->replaced_by_route_id)
        ->toBeNull()
        ->and($this->events->values)
        ->toContain('rollback-public-edge')
        ->and(Route::query()->where('domain', 'next.example.test')->exists())
        ->toBeFalse();
})->with([
    'ingress certificate' => ['ingress-certificate'],
    'ingress Caddy' => ['ingress-caddy'],
    'public edge' => ['public-edge-verified'],
]);

it('keeps an unverified public handler inactive when activation fails after cutover and recovers forward', function (): void {
    $route = route_domain_change_public_route();
    $this->projector->failures['public-activated'] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test', RoutePublication::Public))
        ->toThrow(ResourceOperationException::class, 'Injected public-activated failure.');

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();

    expect($replacement->status)
        ->toBe(RouteStatus::Activating)
        ->and($replacement->public_publication)
        ->toBe(RoutePublicPublication::Inactive)
        ->and($this->events->values)
        ->toContain('rollback-public-edge')
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Retiring);

    $this->projector->failures = [];
    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test', RoutePublication::Public);

    expect($updated->id)
        ->toBe($replacement->id)
        ->and($updated->public_publication)
        ->toBe(RoutePublicPublication::Active)
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and(Route::query()->find($route->id))
        ->toBeNull();
});

it('refuses a conflicting publication request without changing recorded intent', function (): void {
    $route = route_domain_change_public_route();
    $this->projector->failures = [
        'ingress-certificate' => 1,
        'rollback-public-edge' => 1,
    ];

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test', RoutePublication::Public))
        ->toThrow(ResourceOperationException::class);

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();
    $before = $replacement->fresh()->getAttributes();

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test', RoutePublication::Private))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.domain_change_conflict');
        });

    expect($replacement->fresh()->getAttributes())->toBe($before);
});

it('prepares workloads before publishing a production target set and does not report a partial set', function (): void {
    [$route, $first, $second] = route_target_set_expandable_pool();

    $updated = app(ConvergeRouteTargetSetAction::class)->execute(
        $route,
        new SetRouteTargetsData([$first->id, $second->id], []),
    );

    expect($updated->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toBe([$first->id, $second->id])
        ->and($updated->target_set_intent)
        ->toBeNull()
        ->and($this->events->values)
        ->toContain('workload-certificate')
        ->toContain('workload-caddy')
        ->toContain('workload-verify')
        ->toContain('environment:authoritative')
        ->toContain('router-caddy');
    expect(array_search('workload-verify', $this->events->values, true))
        ->toBeLessThan(array_search('router-caddy', $this->events->values, true));
});

it('restores preparations when a target-set change fails before database commit', function (): void {
    [$route, $first, $second] = route_target_set_expandable_pool();
    $this->projector->failures['workload-verify'] = 1;
    $before = $route->targets()->orderBy('position')->pluck('app_instance_id')->all();

    expect(fn () => app(ConvergeRouteTargetSetAction::class)->execute(
        $route,
        new SetRouteTargetsData([$first->id, $second->id], []),
    ))->toThrow(ResourceOperationException::class, 'Injected workload-verify failure.');

    expect($route->refresh()->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toBe($before)
        ->and($route->failed_step)
        ->toBe('workload-prepared')
        ->and($this->events->values)
        ->toContain('rollback-caddy')
        ->toContain('rollback-certificates');
});

it('retains committed target-set progress and resumes the recorded intent', function (): void {
    [$route, $first, $second] = route_target_set_expandable_pool();
    $this->projector->failures['router-caddy'] = 1;

    expect(fn () => app(ConvergeRouteTargetSetAction::class)->execute(
        $route,
        new SetRouteTargetsData([$first->id, $second->id], []),
    ))->toThrow(ResourceOperationException::class, 'Injected router-caddy failure.');

    expect($route->refresh()->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toBe([$first->id, $second->id])
        ->and($route->failed_step)
        ->toBe('router-published')
        ->and($route->target_set_intent['targets'] ?? null)
        ->toBe([$first->id, $second->id]);

    $this->projector->failures = [];
    $this->events->values = [];
    $updated = app(ConvergeRouteTargetSetAction::class)->execute(
        $route,
        new SetRouteTargetsData([$first->id, $second->id], []),
    );

    expect($updated->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toBe([$first->id, $second->id])
        ->and($updated->target_set_intent)
        ->toBeNull()
        ->and($updated->failed_step)
        ->toBeNull()
        ->and($this->events->values)
        ->toContain('router-caddy')
        ->not->toContain('rollback-caddy')
        ->not->toContain('workload-certificate');
});

it('refuses a competing target-set intent and treats an identical completed change as a no-op', function (): void {
    [$route, $first, $second] = route_target_set_expandable_pool();
    app(ConvergeRouteTargetSetAction::class)->execute(
        $route,
        new SetRouteTargetsData([$first->id, $second->id], []),
    );
    $this->events->values = [];

    $again = app(ConvergeRouteTargetSetAction::class)->execute(
        $route,
        new SetRouteTargetsData([$first->id, $second->id], []),
    );

    expect($again->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toBe([$first->id, $second->id])
        ->and($this->events->values)
        ->toBe(['owner']);

    $route->update([
        'target_set_intent' => ['targets' => [$second->id], 'dispositions' => []],
        'target_set_step' => 'reserved',
    ]);
    $before = $route->fresh()->targets()->orderBy('position')->pluck('app_instance_id')->all();

    expect(fn () => app(ConvergeRouteTargetSetAction::class)->execute(
        $route,
        new SetRouteTargetsData([$first->id], [
            new RouteTargetDispositionData($second->id, remove: true),
        ]),
    ))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('route.target_set_conflict');
    });

    expect($route->refresh()->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toBe($before);
});

it('invokes authorized App instance removal after the replacement pool is recorded', function (): void {
    [$route, $first, $second] = route_target_set_expandable_pool();
    $removed = new class implements AppInstanceRemover
    {
        /** @var list<array{0: int, 1: bool}> */
        public array $calls = [];

        public function execute(AppInstance $instance, bool $force): AppInstanceRemoval
        {
            $this->calls[] = [$instance->id, $force];

            return new AppInstanceRemoval;
        }
    };
    app()->instance(AppInstanceRemover::class, $removed);

    $updated = app(ConvergeRouteTargetSetAction::class)->execute(
        $route,
        new SetRouteTargetsData([$second->id], [
            new RouteTargetDispositionData($first->id, remove: true),
        ]),
    );

    expect($updated->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toContain($second->id)
        ->and($removed->calls)
        ->toBe([[$first->id, true]])
        ->and($this->events->values)
        ->toContain('router-caddy');
});

/** @return array{Cluster, Route, Node} */
function bind_cluster_router_replacement_projection(): FakeClusterRouterReplacementProjector
{
    $projector = new FakeClusterRouterReplacementProjector;
    app()->instance(ClusterRouterReplacementProjector::class, $projector);
    app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger
    {
        public function converge(Node $node, NodeRole $assignment): void {}

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

        public function removeUnreachable(Node $node, NodeRole $assignment): void {}
    });

    return $projector;
}

/**
 * @return array{0: Cluster, 1: Route, 2: Node, 3: Node, 4: Node}
 */
function cluster_router_replacement_route(): array
{
    [$cluster, $route, $member] = cluster_tld_generated_route();
    $current = $cluster->routerAssignment()->sole()->node;
    $replacement = Node::query()->create([
        'name' => 'cluster-router-next',
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.82',
        'wireguard_ip' => '10.44.0.82',
        'user' => 'orbit',
    ]);

    return [$cluster, $route, $member, $current, $replacement];
}

function cluster_tld_generated_route(): array
{
    $cluster = Cluster::query()->create([
        'name' => 'cluster-tld',
        'tld' => 'cluster.test',
        'state' => ClusterState::Active,
    ]);
    $router = Node::query()->create([
        'name' => 'cluster-tld-router',
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => null,
        'public_ssh_host' => '192.0.2.80',
        'wireguard_ip' => '10.44.0.80',
        'user' => 'orbit',
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $member = Node::query()->create([
        'name' => 'cluster-tld-workload',
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => null,
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $member->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/srv/acme/main',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = app(CreateRouteAction::class)->ensureForAppInstance($instance, null);
    $route->update(['status' => RouteStatus::Active]);

    return [$cluster, $route->refresh()->load(['targets.appInstance.app', 'targets.appInstance.node']), $member];
}

function route_domain_change_route(bool $laravel, string $environment = 'development', bool $generated = false): Route
{
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'workload',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'dev.test',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => $environment,
        'checkout_path' => '/srv/acme/main',
        'production_home' => $environment === 'production' ? '/srv/acme/main' : null,
        'production_user' => $environment === 'production' ? 'orbit-acme' : null,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => $laravel,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $generated ? $node->id : null,
        'domain' => $generated ? 'feature.acme.dev.test' : 'old.example.test',
        'provenance' => $generated ? 'generated' : 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $route->refresh()->load(['targets.appInstance.app', 'targets.appInstance.node']);
}

/** @return array{Route, AppInstance, AppInstance} */
function route_target_set_expandable_pool(): array
{
    $route = route_domain_change_shared_production_route();
    $first = $route->targets[0]->appInstance;
    $second = $route->targets[1]->appInstance;
    $sibling = Route::query()->create([
        'app_id' => $route->app_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'sibling.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->where('app_instance_id', $second->id)->update([
        'route_id' => $sibling->id,
        'position' => 0,
    ]);
    $sibling->update(['status' => RouteStatus::Active]);

    return [$route->refresh()->load(['targets.appInstance.app', 'targets.appInstance.node']), $first->refresh(), $second->refresh()];
}

function route_domain_change_shared_production_route(): Route
{
    $app = OrbitApp::query()->create([
        'name' => 'Shared',
        'slug' => 'shared',
        'repository_url' => 'https://example.test/shared.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $cluster = Cluster::query()->create(['name' => 'shared', 'state' => 'active']);
    $instances = collect(['one', 'two'])->map(function (string $name) use ($app, $cluster): AppInstance {
        $suffix = $name === 'one' ? '71' : '72';
        $node = Node::query()->create([
            'name' => "shared-{$name}",
            'cluster_id' => $cluster->id,
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => "192.0.2.{$suffix}",
            'wireguard_ip' => "10.44.0.{$suffix}",
            'user' => 'orbit',
        ]);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);

        return AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => $name,
            'environment' => 'production',
            'checkout_path' => "/var/www/shared/{$name}",
            'production_home' => "/var/www/shared/{$name}",
            'production_user' => 'orbit-shared',
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'selected_php_version' => '8.5',
            'source_is_laravel' => false,
            'provisioning_step' => 'active',
            'status' => AppInstanceState::SourceResolved,
        ]);
    });
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => 'old.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['app_instance_id' => $instances[0]->id, 'position' => 0]);
    $route->targets()->create(['app_instance_id' => $instances[1]->id, 'position' => 1]);
    $route->update(['status' => 'active']);
    $instances[0]->update(['status' => AppInstanceState::Active]);
    $instances[1]->update(['status' => AppInstanceState::Active]);

    return $route->refresh()->load(['targets.appInstance.app', 'targets.appInstance.node', 'cluster']);
}

function route_untargeted_removal_route(): Route
{
    $app = OrbitApp::query()->create([
        'name' => 'Untargeted',
        'slug' => 'untargeted',
        'repository_url' => 'https://example.test/untargeted.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'untargeted-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'untargeted.test',
        'public_ssh_host' => '192.0.2.91',
        'wireguard_ip' => '10.44.0.91',
        'user' => 'orbit',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'checkout_path' => '/srv/untargeted/main',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::Active,
    ]);
    $route = app(CreateRouteAction::class)->execute(new CreateRouteData(
        appId: $app->id,
        domain: 'untargeted.example.test',
        publication: RoutePublication::Private,
        appInstanceId: $instance->id,
        nodeId: null,
        clusterId: null,
    ))['route'];
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Reserved]);
    $route->targets()->delete();

    return $route->refresh()->load(['app', 'node', 'targets']);
}

function route_untargeted_removal_unrelated(Route $route): Route
{
    return Route::query()->create([
        'app_id' => $route->app_id,
        'node_id' => $route->node_id,
        'domain' => 'unrelated.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
}

function route_domain_change_public_route(): Route
{
    $app = OrbitApp::query()->create([
        'name' => 'Public',
        'slug' => 'public',
        'repository_url' => 'https://example.test/public.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $cluster = Cluster::query()->create(['name' => 'public', 'state' => 'active']);
    $node = Node::query()->create([
        'name' => 'public-prod',
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
        'lan_ip' => '10.10.0.81',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/var/www/public/current',
        'production_home' => '/var/www/public',
        'production_user' => 'orbit-public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => 'old.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $route->refresh()->load(['targets.appInstance.app', 'targets.appInstance.node', 'cluster']);
}

final class RouteDomainChangeEnvironmentFake implements AppInstanceRouteEnvironmentSynchronizer
{
    /** @var array<string, int> */
    public array $failures = [];

    public function __construct(
        private readonly RouteDomainChangeEvents $events,
    ) {}

    public function synchronizeRouteDomain(
        AppInstance $instance,
        AppInstanceEnvironmentRouteDomain $domain,
    ): AppInstanceEnvironmentResult {
        $this->events->values[] = "environment:{$domain->value}";

        if (($this->failures[$domain->value] ?? 0) > 0) {
            $this->failures[$domain->value]--;

            throw new ResourceOperationException(
                errorCode: "route.test_environment_{$domain->value}",
                message: "Injected environment {$domain->value} failure.",
            );
        }

        return new AppInstanceEnvironmentResult($instance->id, 'sync', true, 1);
    }
}

final class RouteDomainChangeEvents
{
    /** @var list<string> */
    public array $values = [];

    /** @var list<string> Domains the projector was given after cutover, in call order. */
    public array $cleanupDomains = [];
}

final class RouteDomainChangeProjectorFake implements RouteDomainProjector
{
    /** @var array<string, int> */
    public array $failures = [];

    public function __construct(
        private readonly RouteDomainChangeEvents $events,
    ) {}

    public function prepareWorkloadCertificate(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->event('workload-certificate');
    }

    public function prepareWorkloadCaddy(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->event('workload-caddy');
    }

    public function prepareRouterCertificate(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->event('router-certificate');
    }

    public function prepareFirewallPolicy(AppInstance $appInstance, Route $candidate): void
    {
        $this->event('firewall-policy');
    }

    public function verifyWorkload(AppInstance $appInstance, Route $candidate): void
    {
        $this->event('workload-verify');
    }

    public function prepareRouterCaddy(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->event('router-caddy');
    }

    public function prepareIngressCertificate(Route $candidate): void
    {
        $this->event('ingress-certificate');
    }

    public function stageIngressCaddy(Route $candidate): void
    {
        $this->event('ingress-caddy');
    }

    public function prepareIngressFirewall(Route $candidate): void
    {
        $this->event('ingress-firewall');
    }

    public function verifyPublicEdge(Route $candidate): void
    {
        $this->event('public-edge-verified');
    }

    public function activatePublicHandler(Route $candidate): void
    {
        $this->event('public-activated');
    }

    public function rollbackPublicEdge(Route $route): void
    {
        $this->event('rollback-public-edge');
    }

    public function publishDns(Route $current, Route $candidate): void
    {
        $this->event('dns-publication');
    }

    public function cleanup(AppInstance $appInstance, Route $route): void
    {
        $this->events->cleanupDomains[] = "cleanup:{$route->domain}";
        $this->event('cleanup');
    }

    public function rollbackDns(Route $route): void
    {
        $this->event('rollback-dns');
    }

    public function rollbackCaddy(AppInstance $appInstance, Route $route): void
    {
        $this->event('rollback-caddy');
    }

    public function rollbackCertificates(AppInstance $appInstance, Route $route): void
    {
        $this->event('rollback-certificates');
    }

    private function event(string $event): void
    {
        $this->events->values[] = $event;

        if (($this->failures[$event] ?? 0) < 1) {
            return;
        }

        $this->failures[$event]--;

        throw new ResourceOperationException(
            errorCode: "route.test_{$event}",
            message: "Injected {$event} failure.",
        );
    }
}

final class RouteDomainChangeConfiguratorFake implements DevelopmentAppInstanceConfigurator
{
    public int $failures = 0;

    public function __construct(
        private RouteDomainChangeEvents $events,
    ) {}

    public function inspect(AppInstance $appInstance): DevelopmentSourceProfile
    {
        return new DevelopmentSourceProfile('8.5', (bool) $appInstance->source_is_laravel);
    }

    public function configureLaravelUrl(AppInstance $appInstance, string $url): void
    {
        $this->events->values[] = "url:{$url}";

        if ($this->failures < 1) {
            return;
        }

        $this->failures--;

        throw new ResourceOperationException(
            errorCode: 'route.test_laravel_url',
            message: 'Injected Laravel URL failure.',
        );
    }
}

final readonly class RouteDomainChangeOwnerFake implements DevelopmentProjectionOperationLock
{
    public function __construct(
        private RouteDomainChangeEvents $events,
    ) {}

    public function run(Closure $operation): mixed
    {
        $this->events->values[] = 'owner';

        return $operation();
    }
}
