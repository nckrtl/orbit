<?php

declare(strict_types=1);

use App\Actions\Doctor\InstanceDoctorProbe;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorInspectionScope;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\PrivateRouteProjectionInspector;
use App\Domain\Doctor\PrivateRouteProjectionObservation;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Doctor\PublicRouteEdgeObservation;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RoutePublicPublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;

it('returns a healthy empty instance report and excludes other nodes', function (): void {
    $node = instance_probe_node();
    $other = instance_probe_node();
    instance_probe_instance(instance_probe_app(), $other);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)->toBe(0)->and($report->issues)->toBeEmpty()->and($calls)->toBe(0);
});

it('checks healthy AppInstances in id order', function (): void {
    $node = instance_probe_node();
    $first = instance_probe_instance(instance_probe_app(), $node);
    $second = instance_probe_instance(instance_probe_app(), $node);
    $seen = [];

    $report = new InstanceDoctorProbe(new class($seen) implements InstanceStateInspector
    {
        public function __construct(
            private array &$seen,
        ) {}

        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            $this->seen[] = $appInstance->id;

            return new InstanceInspectionData(true, true, true, true);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($seen)
        ->toBe([$first->id, $second->id])
        ->and($report->issues)
        ->toBeEmpty();
});

it('short-circuits instance inspection when the node is unreachable', function (): void {
    $node = instance_probe_node();
    instance_probe_instance(instance_probe_app(), $node);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            $this->calls++;
            throw new DoctorInspectionException;
        }
    })->inspect(instance_probe_context($node, reachable: false));

    expect($report->checked)
        ->toBe(1)
        ->and($report->issues[0]->code)
        ->toBe('instance.node_unreachable')
        ->and($report->issues[0]->resourceId)
        ->toBeNull()
        ->and($calls)
        ->toBe(0);
});

it('reports lifecycle and every false instance field in stable order', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(
        instance_probe_app(),
        $node,
        AppInstanceState::Reserved,
    );

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector
    {
        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            return new InstanceInspectionData(false, false, false, false);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(1)
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'instance.lifecycle_not_active',
            'instance.checkout_missing',
            'instance.repository_layout_mismatch',
            'instance.origin_mismatch',
            'instance.source_identity_mismatch',
        ])
        ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id])
        ->and(collect($report->issues)->pluck('expected')->all())
        ->toBe(['active', 'matching', 'matching', 'matching', 'matching'])
        ->and(json_encode($report))
        ->not->toContain($instance->checkout_path);
});

it('continues after a typed instance inspection failure', function (): void {
    $node = instance_probe_node();
    $failed = instance_probe_instance(instance_probe_app(), $node);
    $healthy = instance_probe_instance(instance_probe_app(), $node);

    $report = new InstanceDoctorProbe(new class($failed) implements InstanceStateInspector
    {
        public function __construct(
            private AppInstance $failed,
        ) {}

        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            if ($appInstance->is($this->failed)) {
                throw new DoctorInspectionException;
            }

            return new InstanceInspectionData(true, true, true, true);
        }
    })->inspect(instance_probe_context($node));

    expect($report->checked)
        ->toBe(2)
        ->and($report->issues)
        ->toHaveCount(1)
        ->and($report->issues[0]->code)
        ->toBe('instance.inspection_failed')
        ->and($report->issues[0]->resourceId)
        ->toBe($failed->id)
        ->and($report->issues[0]->observed)
        ->toBe('unverifiable')
        ->and($healthy->id)
        ->toBeGreaterThan($failed->id);
});

it('inspects the supported worktree source layout', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_instance(instance_probe_app(), $node);
    $instance->update(['source_layout' => 'worktree']);
    $calls = 0;

    $report = new InstanceDoctorProbe(new class($calls) implements InstanceStateInspector
    {
        public function __construct(
            private int &$calls,
        ) {}

        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            $this->calls++;

            return new InstanceInspectionData(true, true, true, true);
        }
    })->inspect(instance_probe_context($node));

    expect($report->issues)
        ->toBe([])
        ->and($calls)
        ->toBe(1);
});

it('reports every production projection field in stable order without exposing paths', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_production_instance(instance_probe_app(), $node);

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector
    {
        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            return new InstanceInspectionData(
                true,
                true,
                true,
                true,
                productionHomeMatches: false,
                releaseSelectionMatches: false,
                selectedReleaseRootMatches: false,
                environmentProjectionMatches: false,
                phpFpmProjectionMatches: false,
                caddyProjectionMatches: false,
            );
        }
    })->inspect(instance_probe_context($node));

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'instance.production_home_mismatch',
            'instance.release_selection_mismatch',
            'instance.selected_release_root_mismatch',
            'instance.environment_projection_mismatch',
            'instance.php_fpm_projection_mismatch',
            'instance.caddy_projection_mismatch',
        ])
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain($instance->production_home, $instance->production_php_socket);
});

it('reports missing and shared production PHP associations before native projection drift', function (): void {
    $node = instance_probe_node();
    $missing = instance_probe_production_instance(instance_probe_app(), $node);
    $missing->update(['production_php_socket' => null]);
    $sharedFirst = instance_probe_production_instance(instance_probe_app(), $node);
    $sharedSecond = instance_probe_production_instance(instance_probe_app(), $node);
    $sharedSecond->update([
        'production_php_service' => $sharedFirst->production_php_service,
        'production_php_pool' => $sharedFirst->production_php_pool,
        'production_php_socket' => $sharedFirst->production_php_socket,
    ]);

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector
    {
        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            return new InstanceInspectionData(
                true,
                true,
                true,
                true,
                productionHomeMatches: true,
                releaseSelectionMatches: true,
                selectedReleaseRootMatches: true,
                environmentProjectionMatches: true,
                phpFpmProjectionMatches: true,
                caddyProjectionMatches: true,
            );
        }
    })->inspect(instance_probe_context($node));

    expect(collect($report->issues)->map(fn ($issue): array => [$issue->resourceId, $issue->code])->all())
        ->toBe([
            [$missing->id, 'instance.php_fpm_association_missing'],
            [$sharedFirst->id, 'instance.php_fpm_association_shared'],
            [$sharedSecond->id, 'instance.php_fpm_association_shared'],
        ])
        ->and($report->checked)
        ->toBe(3);
});

it('reports unavailable production observations without hiding established drift', function (): void {
    $node = instance_probe_node();
    $instance = instance_probe_production_instance(instance_probe_app(), $node);

    $report = new InstanceDoctorProbe(new class implements InstanceStateInspector
    {
        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            return new InstanceInspectionData(
                true,
                true,
                true,
                true,
                productionHomeMatches: false,
                releaseSelectionMatches: true,
                selectedReleaseRootMatches: true,
                environmentProjectionMatches: false,
                phpFpmProjectionMatches: null,
                caddyProjectionMatches: null,
            );
        }
    })->inspect(instance_probe_context($node));

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'instance.production_home_mismatch',
            'instance.environment_projection_mismatch',
            'instance.inspection_failed',
        ])
        ->and($report->status->value)
        ->toBe('unverifiable')
        ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id]);
});

it('reports public-route drift without placement and skips unselected related nodes', function (): void {
    [$workload, $ingress, $router, $instance] = instance_probe_public_route();
    $inspector = new InstanceProbePublicEdgeInspector;
    $healthy = new class implements InstanceStateInspector
    {
        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            return new InstanceInspectionData(
                checkoutExists: true,
                repositoryLayoutMatches: true,
                originMatches: true,
                sourceIdentityMatches: true,
                productionHomeMatches: true,
                releaseSelectionMatches: true,
                selectedReleaseRootMatches: true,
                environmentProjectionMatches: true,
                phpFpmProjectionMatches: true,
                caddyProjectionMatches: true,
            );
        }
    };

    $unselected = new InstanceDoctorProbe($healthy, $inspector)->inspect(
        instance_probe_context($workload)->withScope(new DoctorInspectionScope([
            $workload->id => instance_probe_context($workload),
        ])),
    );

    expect(array_map(static fn ($issue): string => $issue->code, $unselected->issues))
        ->toBe(['instance.related_node_unverifiable'])
        ->and($inspector->nodes)
        ->toBe([])
        ->and(json_encode($unselected, JSON_THROW_ON_ERROR))
        ->not->toContain('10.10.0')
        ->not->toContain((string) $ingress->id)
        ->not->toContain((string) $router->id);

    $inspector->observation = new PublicRouteEdgeObservation(false, false, false, false);
    $selected = new InstanceDoctorProbe($healthy, $inspector)->inspect(
        instance_probe_context($workload)->withScope(new DoctorInspectionScope([
            $workload->id => instance_probe_context($workload),
            $ingress->id => instance_probe_context($ingress),
            $router->id => instance_probe_context($router),
        ])),
    );

    expect(array_map(static fn ($issue): string => $issue->code, $selected->issues))
        ->toBe([
            'instance.public_ingress_mismatch',
            'instance.private_forwarding_mismatch',
            'instance.public_tls_mismatch',
            'instance.public_firewall_mismatch',
        ])
        ->and($inspector->nodes)
        ->toBe([$ingress->id])
        ->and(collect($selected->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id]);
});

it('reports private Route drift in stable field order without exposing projections', function (): void {
    [$workload, $router, $instance, $route] = instance_probe_private_cluster_route();
    $inspector = new InstanceProbePrivateProjectionInspector;
    $inspector->observation = new PrivateRouteProjectionObservation(
        false,
        false,
        false,
        false,
        false,
        false,
        false,
    );
    $healthy = instance_probe_healthy_inspector();

    $report = new InstanceDoctorProbe($healthy, null, $inspector)->inspect(
        instance_probe_context($workload)->withScope(new DoctorInspectionScope([
            $workload->id => instance_probe_context($workload),
            $router->id => instance_probe_context($router),
        ])),
    );

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            'instance.private_routing_scope_mismatch',
            'instance.router_caddy_mismatch',
            'instance.workload_caddy_mismatch',
            'instance.private_certificate_mismatch',
            'instance.private_dns_mismatch',
            'instance.private_firewall_mismatch',
            'instance.laravel_url_mismatch',
        ])
        ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$instance->id])
        ->and(collect($report->issues)->pluck('expected')->unique()->all())
        ->toBe(['matching'])
        ->and(json_encode($report, JSON_THROW_ON_ERROR))
        ->not->toContain($route->domain)
        ->not->toContain((string) $instance->checkout_path)
        ->not->toContain('10.10.0')
        ->not->toContain('APP_URL')
        ->not->toContain('/etc/caddy')
        ->not->toContain('ufw');
});

it('maps missing stale malformed and unreachable private observations to bounded findings', function (): void {
    [$workload, $router, $instance] = instance_probe_private_cluster_route();
    $healthy = instance_probe_healthy_inspector();
    $scope = instance_probe_context($workload)->withScope(new DoctorInspectionScope([
        $workload->id => instance_probe_context($workload),
        $router->id => instance_probe_context($router),
    ]));

    $missing = new InstanceDoctorProbe($healthy, null, new InstanceProbePrivateProjectionInspector(
        new PrivateRouteProjectionObservation(false, true, true, true, true, true, true),
    ))->inspect($scope);
    $malformed = new InstanceDoctorProbe($healthy, null, new InstanceProbePrivateProjectionInspector(
        new PrivateRouteProjectionObservation(true, true, null, true, true, true, true),
    ))->inspect($scope);
    $unreachable = new InstanceDoctorProbe($healthy, null, new class implements PrivateRouteProjectionInspector
    {
        public function inspect(AppInstance $instance, Route $route): PrivateRouteProjectionObservation
        {
            throw new DoctorInspectionException;
        }
    })->inspect($scope);

    expect(array_map(static fn ($issue): string => $issue->code, $missing->issues))
        ->toBe(['instance.private_routing_scope_mismatch'])
        ->and(array_map(static fn ($issue): string => $issue->code, $malformed->issues))
        ->toBe(['instance.inspection_failed'])
        ->and($malformed->status->value)
        ->toBe('unverifiable')
        ->and(array_map(static fn ($issue): string => $issue->code, $unreachable->issues))
        ->toBe(['instance.inspection_failed'])
        ->and(json_encode([$missing, $malformed, $unreachable], JSON_THROW_ON_ERROR))
        ->not->toContain('10.10.0')
        ->not->toContain('ssh timeout')
        ->not->toContain((string) $instance->checkout_path);
});

it('inspects private Routes without mutating managed state', function (): void {
    [$workload, $router, $instance, $route] = instance_probe_private_cluster_route();
    $before = [
        $route->fresh()->getAttributes(),
        $instance->fresh()->getAttributes(),
        $workload->fresh()->getAttributes(),
        $router->fresh()->getAttributes(),
    ];
    $probe = new InstanceDoctorProbe(
        instance_probe_healthy_inspector(),
        null,
        new InstanceProbePrivateProjectionInspector,
    );

    $report = $probe->inspect(instance_probe_context($workload)->withScope(new DoctorInspectionScope([
        $workload->id => instance_probe_context($workload),
        $router->id => instance_probe_context($router),
    ])));

    expect($report->issues)
        ->toBeEmpty()
        ->and($route->fresh()->getAttributes())
        ->toBe($before[0])
        ->and($instance->fresh()->getAttributes())
        ->toBe($before[1])
        ->and($workload->fresh()->getAttributes())
        ->toBe($before[2])
        ->and($router->fresh()->getAttributes())
        ->toBe($before[3])
        ->and($instance->fresh()->status)
        ->toBe(AppInstanceState::Active)
        ->and($route->fresh()->status)
        ->toBe(RouteStatus::Active);
});

it('keeps a valid private Route healthy when the application returns HTTP 500', function (): void {
    [$workload, $router] = instance_probe_private_cluster_route();
    $inspector = new InstanceProbePrivateProjectionInspector;
    $probe = new InstanceDoctorProbe(instance_probe_healthy_inspector(), null, $inspector);

    $report = $probe->inspect(instance_probe_context($workload)->withScope(new DoctorInspectionScope([
        $workload->id => instance_probe_context($workload),
        $router->id => instance_probe_context($router),
    ])));

    expect($report->issues)
        ->toBeEmpty()
        ->and($report->status->value)
        ->toBe('healthy')
        ->and($inspector->routes)
        ->toHaveCount(1);
});

it('skips unselected private Routers and keeps Node resource field and issue order', function (): void {
    [$firstWorkload, $firstRouter, $first] = instance_probe_private_cluster_route();
    [$secondWorkload, $secondRouter, $second] = instance_probe_private_cluster_route();
    $observation = new PrivateRouteProjectionObservation(false, true, false, true, true, true, true);
    $unselectedInspector = new InstanceProbePrivateProjectionInspector($observation);
    $inspector = new InstanceProbePrivateProjectionInspector($observation);
    $healthy = instance_probe_healthy_inspector();

    $unselected = new InstanceDoctorProbe($healthy, null, $unselectedInspector)->inspect(
        instance_probe_context($firstWorkload)->withScope(new DoctorInspectionScope([
            $firstWorkload->id => instance_probe_context($firstWorkload),
        ])),
    );
    $ordered = new InstanceDoctorProbe($healthy, null, $inspector)->inspect(
        instance_probe_context($firstWorkload)->withScope(new DoctorInspectionScope([
            $firstWorkload->id => instance_probe_context($firstWorkload),
            $firstRouter->id => instance_probe_context($firstRouter),
        ])),
    );
    $secondReport = new InstanceDoctorProbe($healthy, null, $inspector)->inspect(
        instance_probe_context($secondWorkload)->withScope(new DoctorInspectionScope([
            $secondWorkload->id => instance_probe_context($secondWorkload),
            $secondRouter->id => instance_probe_context($secondRouter),
        ])),
    );

    expect(array_map(static fn ($issue): string => $issue->code, $unselected->issues))
        ->toBe(['instance.related_node_unverifiable'])
        ->and($unselectedInspector->nodes)
        ->toBe([])
        ->and(array_map(static fn ($issue): string => $issue->code, $ordered->issues))
        ->toBe([
            'instance.private_routing_scope_mismatch',
            'instance.workload_caddy_mismatch',
        ])
        ->and(collect($ordered->issues)->pluck('resourceId')->all())
        ->toBe([$first->id, $first->id])
        ->and($second->id)
        ->toBeGreaterThan($first->id)
        ->and(collect($secondReport->issues)->pluck('resourceId')->unique()->all())
        ->toBe([$second->id])
        ->and(json_encode($unselected, JSON_THROW_ON_ERROR))
        ->not->toContain((string) $firstRouter->id);
});

function instance_probe_healthy_inspector(): InstanceStateInspector
{
    return new class implements InstanceStateInspector
    {
        public function inspect(AppInstance $appInstance): InstanceInspectionData
        {
            return new InstanceInspectionData(
                checkoutExists: true,
                repositoryLayoutMatches: true,
                originMatches: true,
                sourceIdentityMatches: true,
                productionHomeMatches: true,
                releaseSelectionMatches: true,
                selectedReleaseRootMatches: true,
                environmentProjectionMatches: true,
                phpFpmProjectionMatches: true,
                caddyProjectionMatches: true,
            );
        }
    };
}

function instance_probe_node(): Node
{
    static $number = 60;
    $number++;

    return Node::query()->create([
        'name' => "instance-probe-node-{$number}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => "10.44.0.{$number}",
    ]);
}

function instance_probe_app(): App
{
    static $number = 0;
    $number++;

    return App::query()->create([
        'name' => "Instance App {$number}",
        'slug' => "instance-app-{$number}",
        'repository_url' => "https://github.com/acme/private-instance-{$number}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
}

function instance_probe_instance(
    App $app,
    Node $node,
    AppInstanceState $status = AppInstanceState::Active,
): AppInstance {
    $suffix = $app->appInstances()->count() + 1;

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => "development-{$suffix}",
        'environment' => 'development',
        'checkout_path' => "/private/instance/{$app->slug}/development-{$suffix}",
        'branch' => "development-{$suffix}",
        'starting_commit' => str_repeat((string) $suffix, 40),
        'status' => $status,
    ]);
}

function instance_probe_production_instance(App $app, Node $node): AppInstance
{
    $user = "orbit-app-{$app->id}";

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/{$user}/releases/initial",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'production_php_service' => "orbit-{$user}-php8.5-fpm.service",
        'production_php_pool' => "orbit-{$user}",
        'production_php_socket' => "/run/php/{$user}.sock",
        'selected_php_version' => '8.5',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::Active,
    ]);
}

function instance_probe_context(Node $node, bool $reachable = true): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData($reachable, 'linux', 'x86_64', true));
}

/** @return array{Node, Node, Node, AppInstance} */
function instance_probe_public_route(): array
{
    $cluster = Cluster::query()->create(['name' => 'doctor-public', 'state' => ClusterState::Active]);
    $workload = instance_probe_node();
    $ingress = instance_probe_node();
    $router = instance_probe_node();
    foreach ([$workload, $ingress, $router] as $node) {
        $node->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.'.($node->id % 200)]);
    }
    $ingress->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Ingress,
        'status' => LifecycleStatus::Active,
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $workload->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $instance = instance_probe_production_instance(instance_probe_app(), $workload);
    $route = Route::query()->create([
        'app_id' => $instance->app_id,
        'cluster_id' => $cluster->id,
        'domain' => 'doctor.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'public_publication' => RoutePublicPublication::Inactive,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update([
        'status' => RouteStatus::Active,
        'public_publication' => RoutePublicPublication::Active,
    ]);

    return [$workload, $ingress, $router, $instance];
}

/** @return array{Node, Node, AppInstance, Route} */
function instance_probe_private_cluster_route(): array
{
    static $number = 0;
    $number++;
    $cluster = Cluster::query()->create([
        'name' => "doctor-private-{$number}",
        'state' => ClusterState::Active,
    ]);
    $workload = instance_probe_node();
    $router = instance_probe_node();
    foreach ([$workload, $router] as $node) {
        $node->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.'.($node->id % 200)]);
    }
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $workload->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $instance = instance_probe_instance(instance_probe_app(), $workload);
    $instance->update(['source_is_laravel' => true]);
    $route = Route::query()->create([
        'app_id' => $instance->app_id,
        'cluster_id' => $cluster->id,
        'domain' => "private-{$number}.doctor.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return [$workload, $router, $instance->fresh(), $route->fresh()];
}

final class InstanceProbePublicEdgeInspector implements PublicRouteEdgeInspector
{
    /** @var list<int> */
    public array $nodes = [];

    public PublicRouteEdgeObservation $observation;

    public function __construct()
    {
        $this->observation = new PublicRouteEdgeObservation(true, true, true, true);
    }

    public function inspect(Node $node, Route $route): PublicRouteEdgeObservation
    {
        $this->nodes[] = $node->id;

        return $this->observation;
    }
}

final class InstanceProbePrivateProjectionInspector implements PrivateRouteProjectionInspector
{
    /** @var list<int> */
    public array $nodes = [];

    /** @var list<int> */
    public array $routes = [];

    public PrivateRouteProjectionObservation $observation;

    public function __construct(?PrivateRouteProjectionObservation $observation = null)
    {
        $this->observation = $observation ?? new PrivateRouteProjectionObservation(
            true,
            true,
            true,
            true,
            true,
            true,
            true,
        );
    }

    public function inspect(AppInstance $instance, Route $route): PrivateRouteProjectionObservation
    {
        $this->nodes[] = $instance->node_id;
        $this->routes[] = $route->id;

        return $this->observation;
    }
}
