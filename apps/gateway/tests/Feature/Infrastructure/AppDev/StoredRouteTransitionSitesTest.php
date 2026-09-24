<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterReplacementStep;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/*
 * ADR 0141: every site comes from stored state, so any converge on a Node renders the same sites.
 * Each case walks a transition through its stored steps and lists, per Node, the domain and the
 * certificate scope each site names.
 */

it('renders a Route only while it stores the publication record', function (): void {
    $fleet = stored_transition_fleet();
    $route = stored_transition_route($fleet, status: RouteStatus::Pending);

    expect(stored_transition_scopes($fleet['workload']))->toBe([])
        ->and(stored_transition_scopes($fleet['routerA']))->toBe([]);

    $route->publishSites();

    expect(stored_transition_scopes($fleet['workload']))->toBe(['feature.acme.test' => "app-instance-{$fleet['instance']->id}"])
        ->and(stored_transition_scopes($fleet['routerA']))->toBe(['feature.acme.test' => "route-{$route->id}-router"])
        ->and(stored_transition_every_converge_matches($fleet))->toBeTrue();

    // A failed creation clears the record, so the next build withdraws the sites.
    $route->update([
        'status' => RouteStatus::Failed,
        'sites_published' => false,
        'failed_step' => 'projection',
        'error_code' => 'route.projection_failed',
    ]);

    expect(stored_transition_scopes($fleet['workload']))->toBe([])
        ->and(stored_transition_scopes($fleet['routerA']))->toBe([]);
});

it('keeps an authoritative Route published and withdraws it with its removal', function (): void {
    $fleet = stored_transition_fleet();
    $route = stored_transition_route($fleet, status: RouteStatus::Active);

    expect($route->refresh()->sites_published)->toBeTrue();

    $route->update(['sites_published' => false]);

    expect($route->refresh()->sites_published)->toBeTrue();

    $route->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);

    expect($route->refresh()->sites_published)->toBeFalse()
        ->and(stored_transition_scopes($fleet['routerA']))->toBe([]);
});

it('renders a placement change from its stored transition at every step', function (): void {
    $fleet = stored_transition_fleet();
    $route = stored_transition_route($fleet, status: RouteStatus::Active);
    $workloadLive = ['feature.acme.test' => "app-instance-{$fleet['instance']->id}"];
    $live = ['feature.acme.test' => "route-{$route->id}-router"];
    $staging = ['feature.acme.test' => "route-{$route->id}-router-hostname-change"];
    $step = static fn (array $attributes) => Route::query()->whereKey($route->id)->update($attributes);

    // `workload-caddy` stores the candidate placement before it builds the workload.
    $step([
        'replacement_step' => RouteReplacementStep::WorkloadCertificate->value,
        'transition_cluster_id' => $fleet['clusterB']->id,
    ]);

    expect(stored_transition_scopes($fleet['workload']))->toBe($workloadLive)
        ->and(stored_transition_scopes($fleet['routerA']))->toBe($live)
        ->and(stored_transition_scopes($fleet['routerB']))->toBe([]);

    $step(['replacement_step' => RouteReplacementStep::RouterCertificate->value]);

    expect(stored_transition_scopes($fleet['workload']))->toBe($workloadLive)
        ->and(stored_transition_scopes($fleet['routerA']))->toBe($live)
        ->and(stored_transition_scopes($fleet['routerB']))->toBe($staging)
        ->and(stored_transition_every_converge_matches($fleet))->toBeTrue();

    $step(['replacement_step' => RouteReplacementStep::DnsPublished->value]);

    expect(stored_transition_dns())->toContain("host-record=feature.acme.test,{$fleet['routerA']->wireguard_ip}")
        ->and(stored_transition_scopes($fleet['routerB']))->toBe($staging);

    // `database-cutover` swaps the placements: the old one stays served until cleanup.
    $step([
        'cluster_id' => $fleet['clusterB']->id,
        'transition_cluster_id' => $fleet['clusterA']->id,
        'replacement_step' => RouteReplacementStep::DatabaseCutover->value,
    ]);

    expect(stored_transition_scopes($fleet['workload']))->toBe($workloadLive)
        ->and(stored_transition_scopes($fleet['routerA']))->toBe($live)
        ->and(stored_transition_scopes($fleet['routerB']))->toBe($staging)
        ->and(stored_transition_dns())->toContain("host-record=feature.acme.test,{$fleet['routerB']->wireguard_ip}")
        ->and(stored_transition_every_converge_matches($fleet))->toBeTrue();

    // `cleanup` has issued the live Router leaf, so its builds name it and drop the old placement.
    $step(['replacement_step' => RouteReplacementStep::Cleanup->value]);

    expect(stored_transition_scopes($fleet['routerA']))->toBe([])
        ->and(stored_transition_scopes($fleet['routerB']))->toBe($live);

    $step(['replacement_step' => null, 'transition_cluster_id' => null]);

    expect(stored_transition_scopes($fleet['workload']))->toBe($workloadLive)
        ->and(stored_transition_scopes($fleet['routerA']))->toBe([])
        ->and(stored_transition_scopes($fleet['routerB']))->toBe($live);
});

it('stops rendering the candidate placement when a restore resets its step', function (): void {
    $fleet = stored_transition_fleet();
    $route = stored_transition_route($fleet, status: RouteStatus::Active);
    Route::query()->whereKey($route->id)->update([
        'replacement_step' => RouteReplacementStep::RouterCaddy->value,
        'transition_cluster_id' => $fleet['clusterB']->id,
    ]);

    expect(stored_transition_scopes($fleet['routerB']))->toBe(['feature.acme.test' => "route-{$route->id}-router-hostname-change"]);

    Route::query()->whereKey($route->id)->update(['replacement_step' => RouteReplacementStep::Reserved->value]);

    expect(stored_transition_scopes($fleet['routerB']))->toBe([])
        ->and(stored_transition_scopes($fleet['routerA']))->toBe(['feature.acme.test' => "route-{$route->id}-router"]);
});

it('keeps a cut over domain change on its staging certificates until cleanup', function (): void {
    $fleet = stored_transition_fleet();
    $route = stored_transition_route($fleet, status: RouteStatus::Active);
    $replacement = stored_transition_replacement($route, $fleet['instance'], RouteReplacementStep::DnsPublished);
    $route->update(['status' => RouteStatus::Retiring]);
    $replacement->update(['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);

    expect(stored_transition_scopes($fleet['workload']))->toBe(['next.acme.test' => "app-instance-{$fleet['instance']->id}-hostname-change"])
        ->and(stored_transition_scopes($fleet['routerA']))->toBe(['next.acme.test' => "route-{$replacement->id}-router-hostname-change"]);

    $replacement->update(['replacement_step' => RouteReplacementStep::Cleanup]);

    expect(stored_transition_scopes($fleet['workload']))->toBe(['next.acme.test' => "app-instance-{$fleet['instance']->id}"])
        ->and(stored_transition_scopes($fleet['routerA']))->toBe(['next.acme.test' => "route-{$replacement->id}-router"]);
});

it('renders both Routers during a Router replacement from the stored router rows', function (): void {
    $fleet = stored_transition_fleet(ingress: true);
    $route = stored_transition_route($fleet, status: RouteStatus::Active, public: true);
    $router = ['feature.acme.test' => "route-{$route->id}-router"];
    $candidate = NodeRole::query()->create([
        'node_id' => $fleet['candidate']->id,
        'cluster_id' => $fleet['clusterA']->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Provisioning,
    ]);
    $old = NodeRole::query()
        ->where('node_id', $fleet['routerA']->id)
        ->where('role', RoleName::Router)
        ->sole();
    $checkpoint = static fn (ClusterRouterReplacementStep|string|null $step, ?string $errorCode = null, ?LifecycleStatus $status = null) => $candidate->update([
        'failed_step' => $step instanceof ClusterRouterReplacementStep ? $step->value : $step,
        'error_code' => $errorCode,
        ...($status instanceof LifecycleStatus ? ['status' => $status] : []),
    ]);

    expect(stored_transition_scopes($fleet['candidate']))->toBe([]);

    $checkpoint(ClusterRouterReplacementStep::RouterCertificate);

    expect(stored_transition_scopes($fleet['candidate']))->toBe([]);

    // From `router-caddy` on, both Routers serve the Cluster's Router sites.
    $checkpoint(ClusterRouterReplacementStep::WorkloadVerified);

    expect(stored_transition_scopes($fleet['candidate']))->toBe($router)
        ->and(stored_transition_scopes($fleet['routerA']))->toBe($router)
        ->and(stored_transition_ingress_upstream($fleet))->toBe($fleet['routerA']->wireguard_ip)
        ->and(stored_transition_every_converge_matches($fleet))->toBeTrue();

    $checkpoint(ClusterRouterReplacementStep::DnsPublished);

    expect(stored_transition_dns())->toContain("host-record=feature.acme.test,{$fleet['routerA']->wireguard_ip}")
        ->and(stored_transition_dns([$fleet['clusterA']->id => ['router_node_id' => $fleet['candidate']->id]]))
        ->toContain("host-record=feature.acme.test,{$fleet['candidate']->wireguard_ip}");

    // A restore marks the candidate before it builds it, so the build withdraws its sites.
    $checkpoint('rollback:router-caddy', 'app-dev.caddy_config_failed', LifecycleStatus::Failed);

    expect(stored_transition_scopes($fleet['candidate']))->toBe([]);

    $checkpoint(ClusterRouterReplacementStep::RouterCaddy, 'app-dev.caddy_config_failed', LifecycleStatus::Failed);

    expect(stored_transition_scopes($fleet['candidate']))->toBe([]);

    // A failure after publication keeps both Routers serving.
    $checkpoint(ClusterRouterReplacementStep::DatabaseCutover, 'node_role.operation_failed', LifecycleStatus::Failed);

    expect(stored_transition_scopes($fleet['candidate']))->toBe($router);

    // `database` makes the candidate the active Router; the Ingress upstream follows it.
    $old->update(['status' => LifecycleStatus::Removing]);
    $checkpoint(ClusterRouterReplacementStep::DatabaseCutover, null, LifecycleStatus::Active);

    expect(stored_transition_scopes($fleet['candidate']))->toBe($router)
        ->and(stored_transition_scopes($fleet['routerA']))->toBe($router)
        ->and(stored_transition_ingress_upstream($fleet))->toBe($fleet['candidate']->wireguard_ip)
        ->and(stored_transition_dns())->toContain("host-record=feature.acme.test,{$fleet['candidate']->wireguard_ip}")
        ->and(stored_transition_every_converge_matches($fleet))->toBeTrue();

    // Cleanup marks the old Router row before it builds that Router.
    $old->update(['failed_step' => ClusterRouterTransition::OldRouterCleanup]);

    expect(stored_transition_scopes($fleet['routerA']))->toBe([])
        ->and(stored_transition_scopes($fleet['candidate']))->toBe($router);
});

it('serves a crash-left pending domain change on the candidate Router from its staging certificate', function (): void {
    $fleet = stored_transition_fleet();
    $route = stored_transition_route($fleet, status: RouteStatus::Active);
    $replacement = stored_transition_replacement($route, $fleet['instance'], RouteReplacementStep::RouterCaddy);
    NodeRole::query()->create([
        'node_id' => $fleet['candidate']->id,
        'cluster_id' => $fleet['clusterA']->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Provisioning,
        'failed_step' => ClusterRouterReplacementStep::WorkloadVerified->value,
    ]);

    expect(stored_transition_scopes($fleet['candidate']))->toBe([
        'feature.acme.test' => "route-{$route->id}-router",
        'next.acme.test' => "route-{$replacement->id}-router-hostname-change",
    ]);
});

it('renders the unavailable answer of an Instance removal from stored state', function (bool $coLocated): void {
    $fleet = stored_transition_fleet(coLocated: $coLocated);
    $route = stored_transition_route($fleet, status: RouteStatus::Active);
    $servingNode = $coLocated ? $fleet['workload'] : $fleet['routerA'];
    stored_transition_remove_target($route, $fleet['instance']);

    $sites = new AppDevSiteRepository()->forNode($servingNode);

    expect($sites)->toHaveCount(1)
        ->and($sites->sole()->unavailable)->toBeTrue()
        ->and($sites->sole()->domain)->toBe('feature.acme.test')
        ->and($sites->sole()->scope)->toBe($coLocated ? "app-instance-{$fleet['instance']->id}" : "route-{$route->id}-router")
        ->and(stored_transition_dns())->toContain("host-record=feature.acme.test,{$servingNode->wireguard_ip}")
        ->and(stored_transition_every_converge_matches($fleet))->toBeTrue();

    // Removal clears the record and builds before it removes the certificate and the Route.
    $route->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);

    expect(new AppDevSiteRepository()->forNode($servingNode))->toHaveCount(0)
        ->and(stored_transition_dns())->not->toContain('feature.acme.test');
})->with(['Router serves it' => false, 'workload serves it' => true]);

/**
 * @return array{clusterA: Cluster, clusterB: Cluster, workload: Node, routerA: Node, routerB: Node, candidate: Node, ingress: ?Node, instance: AppInstance}
 */
function stored_transition_fleet(bool $coLocated = false, bool $ingress = false): array
{
    $clusterA = Cluster::query()->create(['name' => 'a-'.Str::lower(Str::random(6)), 'state' => ClusterState::Active]);
    $clusterB = Cluster::query()->create(['name' => 'b-'.Str::lower(Str::random(6)), 'state' => ClusterState::Active]);
    $node = static function (Cluster $cluster, string $name, int $octet, ?RoleName $role) {
        $node = Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => $name,
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => "192.0.2.{$octet}",
            'wireguard_ip' => "10.44.0.{$octet}",
            'user' => 'orbit',
        ]);

        if ($role instanceof RoleName) {
            $node->roles()->create([
                'cluster_id' => in_array($role, [RoleName::Router, RoleName::Ingress], true) ? $cluster->id : null,
                'role' => $role,
                'status' => LifecycleStatus::Active,
            ]);
        }

        return $node;
    };
    $workload = $node($clusterA, 'workload', 10, RoleName::AppDev);
    $routerA = $coLocated ? $workload : $node($clusterA, 'router-a', 20, null);
    $routerA->roles()->create(['cluster_id' => $clusterA->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active]);
    $routerB = $node($clusterB, 'router-b', 30, RoleName::Router);
    $candidate = $node($clusterA, 'candidate', 40, null);
    $ingressNode = $ingress ? $node($clusterA, 'ingress', 50, RoleName::Ingress) : null;
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::lower(Str::random(6)),
        'repository_url' => 'https://example.test/acme.git',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'feature',
        'checkout_path' => '/home/orbit/apps/acme/feature',
        'root' => 'public',
        'branch' => 'feature',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::SourceResolved,
    ]);

    return [
        'clusterA' => $clusterA,
        'clusterB' => $clusterB,
        'workload' => $workload,
        'routerA' => $routerA,
        'routerB' => $routerB,
        'candidate' => $candidate,
        'ingress' => $ingressNode,
        'instance' => $instance,
    ];
}

/** @param array{clusterA: Cluster, instance: AppInstance} $fleet */
function stored_transition_route(array $fleet, RouteStatus $status, bool $public = false): Route
{
    $route = Route::query()->create([
        'app_id' => $fleet['instance']->app_id,
        'cluster_id' => $fleet['clusterA']->id,
        'domain' => 'feature.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => $public ? RoutePublication::Public : RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $fleet['instance']->id, 'position' => 0]);

    if ($status !== RouteStatus::Pending) {
        $route->update([
            'status' => $status,
            'replacement_step' => $public ? RouteReplacementStep::IngressFirewall : null,
        ]);
        $fleet['instance']->update(['status' => AppInstanceState::Active]);
    }

    return $route->refresh();
}

function stored_transition_replacement(Route $route, AppInstance $instance, RouteReplacementStep $step): Route
{
    $replacement = Route::query()->create([
        'app_id' => $route->app_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'next.acme.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => $step,
    ]);
    $replacement->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['replaced_by_route_id' => $replacement->id]);

    return $replacement;
}

/** An Instance removal has cleared the final target; its member row stays open. */
function stored_transition_remove_target(Route $route, AppInstance $instance): void
{
    $removal = AppInstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => false,
        'inventory_digest' => str_repeat('d', 64),
        'total' => 1,
        'status' => AppInstanceRemovalStatus::Removing,
        'current_step' => AppInstanceRemovalStep::SourcePreparation,
    ]);
    $member = $removal->members()->create([
        'position' => 0,
        'app_instance_id' => $instance->id,
        'app_id' => $instance->app_id,
        'node_id' => $instance->node_id,
        'route_id' => $route->id,
        'name' => $instance->name,
        'environment' => $instance->environment,
        'source_layout' => $instance->source_layout,
        'repository_identity' => $instance->app->repository_identity,
        'checkout_path' => $instance->checkout_path,
        'root' => $instance->effectiveRoot(),
        'branch' => $instance->branch,
        'starting_commit' => $instance->starting_commit,
        'source_commit' => $instance->starting_commit,
        'common_repository_path' => $instance->checkout_path,
        'source_identity' => "1:{$instance->id}",
        'linked_worktree_paths' => [$instance->checkout_path],
        'source_digest' => str_repeat('b', 64),
    ]);
    $instance->update(['status' => AppInstanceState::Removing]);
    $member->update(['source_prepared_at' => now()]);
    $route->targets()->delete();
}

/** @return array<string, string> Each site's domain and the certificate scope it names. */
function stored_transition_scopes(Node $node): array
{
    return new AppDevSiteRepository()
        ->forNode($node)
        ->reject(static fn (AppDevSite $site): bool => $site->publicListener)
        ->mapWithKeys(static fn (AppDevSite $site): array => [$site->domain => $site->certificateScope ?? $site->scope])
        ->all();
}

/**
 * Any converge on a Node renders the same sites as the fleet-wide inventory.
 *
 * @param  array<string, mixed>  $fleet
 */
function stored_transition_every_converge_matches(array $fleet): bool
{
    $all = new AppDevSiteRepository()->all();
    $identity = static fn (Collection $sites): array => $sites
        ->map(static fn (AppDevSite $site): string => "{$site->nodeId}|{$site->domain}|{$site->scope}|".($site->certificateScope ?? '-'))
        ->sort()
        ->values()
        ->all();

    foreach ($fleet as $node) {
        if (! $node instanceof Node) {
            continue;
        }

        $first = new AppDevSiteRepository()->forNode($node);
        $second = new AppDevSiteRepository()->forNode($node);

        if ($identity($first) !== $identity($second) || $identity($first) !== $identity($all->where('nodeId', $node->id))) {
            return false;
        }
    }

    return true;
}

/** @param array<int, array{router_node_id?: ?int}> $clusterOverrides */
function stored_transition_dns(array $clusterOverrides = []): string
{
    return new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render(clusterOverrides: $clusterOverrides);
}

/** @param array{ingress: ?Node} $fleet */
function stored_transition_ingress_upstream(array $fleet): ?string
{
    $site = new AppDevSiteRepository()
        ->forNode($fleet['ingress'])
        ->first(static fn (AppDevSite $site): bool => $site->publicListener);

    return $site?->upstreamAddresses[0] ?? null;
}
