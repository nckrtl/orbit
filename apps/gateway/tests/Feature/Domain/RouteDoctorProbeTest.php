<?php

declare(strict_types=1);

use App\Actions\Doctor\RouteDoctorProbe;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\CustomProxyRouteInspector;
use App\Domain\Doctor\CustomProxyRouteObservation;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyStatus;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteCustomProxy;

describe(RouteDoctorProbe::class, function (): void {
    it('checks only Route status without inspection when the Node has no custom proxies', function (): void {
        $node = route_doctor_node();
        route_doctor_app_route($node);
        $inspector = Mockery::mock(CustomProxyRouteInspector::class);
        $inspector->shouldNotReceive('inspect');

        $report = new RouteDoctorProbe($inspector)->inspect(route_doctor_context($node));

        expect($report->family)
            ->toBe(DoctorFamily::Route)
            ->and($report->status)
            ->toBe(DoctorFamilyStatus::Healthy)
            ->and($report->checked)
            ->toBe(1)
            ->and($report->issues)
            ->toBeEmpty();
    });

    it('reports one node-unreachable finding without inspecting when the Node is down', function (): void {
        $node = route_doctor_node();
        $proxy = route_doctor_custom_proxy($node, 'executor.orbit');
        $inspector = Mockery::mock(CustomProxyRouteInspector::class);
        $inspector->shouldNotReceive('inspect');

        $report = new RouteDoctorProbe($inspector)->inspect(
            new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)),
        );

        expect($report->checked)
            ->toBe(1)
            ->and($report->issues)
            ->toHaveCount(1)
            ->and($report->issues[0]->code)
            ->toBe('route.node_unreachable')
            ->and($report->issues[0]->kind->value)
            ->toBe('unverifiable')
            ->and($report->issues[0]->resourceType)
            ->toBe('route')
            ->and($report->issues[0]->resourceId)
            ->toBeNull()
            ->and($report->issues[0]->resourceName)
            ->toBeNull()
            ->and(RouteCustomProxy::query()->whereKey($proxy->route_id)->exists())
            ->toBeTrue();
    });

    it('maps dns, certificate, Caddy, and upstream observations to issue codes', function (
        bool $dns,
        bool $certificate,
        bool $caddy,
        bool $upstream,
        array $codes,
    ): void {
        $node = route_doctor_node();
        $proxy = route_doctor_custom_proxy($node, 'executor.orbit');
        $inspector = Mockery::mock(CustomProxyRouteInspector::class);
        $inspector
            ->shouldReceive('inspect')
            ->once()
            ->with(Mockery::on(fn (RouteCustomProxy $inspected): bool => $inspected->is($proxy)))
            ->andReturn(new CustomProxyRouteObservation($dns, $certificate, $caddy, $upstream));

        $report = new RouteDoctorProbe($inspector)->inspect(route_doctor_context($node));

        expect($report->checked)
            ->toBe(1)
            ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
            ->toBe($codes)
            ->and(collect($report->issues)->pluck('resourceId')->unique()->all())
            ->toBe($codes === [] ? [] : [$proxy->route_id])
            ->and(collect($report->issues)->pluck('resourceName')->unique()->all())
            ->toBe($codes === [] ? [] : ['executor.orbit']);
    })->with([
        'healthy' => [true, true, true, true, []],
        'dns' => [false, true, true, true, ['route.dns_mismatch']],
        'certificate' => [true, false, true, true, ['route.certificate_mismatch']],
        'caddy' => [true, true, false, true, ['route.caddy_mismatch']],
        'upstream' => [true, true, true, false, ['route.upstream_unreachable']],
        'all drift' => [false, false, false, false, [
            'route.dns_mismatch',
            'route.certificate_mismatch',
            'route.caddy_mismatch',
            'route.upstream_unreachable',
        ]],
    ]);

    it('maps an inspector failure to route.inspection_failed', function (): void {
        $node = route_doctor_node();
        $proxy = route_doctor_custom_proxy($node, 'grafana.internal');
        $inspector = Mockery::mock(CustomProxyRouteInspector::class);
        $inspector->shouldReceive('inspect')->once()->andThrow(new DoctorInspectionException);

        $report = new RouteDoctorProbe($inspector)->inspect(route_doctor_context($node));

        expect($report->issues)
            ->toHaveCount(1)
            ->and($report->issues[0]->code)
            ->toBe('route.inspection_failed')
            ->and($report->issues[0]->resourceId)
            ->toBe($proxy->route_id)
            ->and($report->issues[0]->resourceName)
            ->toBe('grafana.internal');
    });

    it('inspects custom proxies in route_id order and only counts App Routes', function (): void {
        $node = route_doctor_node();
        route_doctor_app_route($node);
        $second = route_doctor_custom_proxy($node, 'foo.bar');
        $first = route_doctor_custom_proxy($node, 'executor.orbit');
        $seen = [];
        $inspector = Mockery::mock(CustomProxyRouteInspector::class);
        $inspector
            ->shouldReceive('inspect')
            ->twice()
            ->andReturnUsing(function (RouteCustomProxy $proxy) use (&$seen): CustomProxyRouteObservation {
                $seen[] = $proxy->route_id;

                return new CustomProxyRouteObservation(true, true, true, true);
            });

        $report = new RouteDoctorProbe($inspector)->inspect(route_doctor_context($node));

        expect($report->checked)
            ->toBe(3)
            ->and($seen)
            ->toBe([$first->route_id < $second->route_id ? $first->route_id : $second->route_id,
                $first->route_id < $second->route_id ? $second->route_id : $first->route_id])
            ->and($report->issues)
            ->toBeEmpty();
    });

    it('checks only the status of an analytics tracking Route on its Router Node', function (): void {
        $router = route_doctor_node();
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => null, 'state' => ClusterState::Active]);
        $router->update(['cluster_id' => $cluster->id]);
        $router->roles()->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
        $tracking = Route::query()->create([
            'kind' => RouteKind::AnalyticsTracking,
            'cluster_id' => $cluster->id,
            'domain' => 'analytics.shop.example.com',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Public,
            'status' => RouteStatus::Pending,
        ]);
        $tracking->update(['status' => RouteStatus::Active]);
        $inspector = Mockery::mock(CustomProxyRouteInspector::class);
        $inspector->shouldNotReceive('inspect');

        $report = new RouteDoctorProbe($inspector)->inspect(route_doctor_context($router));

        expect($report->status)->toBe(DoctorFamilyStatus::Healthy)
            ->and($report->checked)->toBe(1)
            ->and($report->issues)->toBeEmpty();
    });

    it('reports a Route whose stored status is not active', function (RouteStatus $status): void {
        $node = route_doctor_node();
        $route = route_doctor_app_route($node);
        $route->update([
            'status' => $status,
            'failed_step' => $status === RouteStatus::Failed ? 'caddy' : null,
            'error_code' => $status === RouteStatus::Failed ? 'route.caddy_failed' : null,
        ]);
        $inspector = Mockery::mock(CustomProxyRouteInspector::class);
        $inspector->shouldNotReceive('inspect');

        $report = new RouteDoctorProbe($inspector)->inspect(route_doctor_context($node));

        expect($report->status)->toBe(DoctorFamilyStatus::Drift)
            ->and($report->checked)->toBe(1)
            ->and($report->issues)->toHaveCount(1)
            ->and($report->issues[0]->code)->toBe('route.lifecycle_not_active')
            ->and($report->issues[0]->kind->value)->toBe('drift')
            ->and($report->issues[0]->resourceType)->toBe('route')
            ->and($report->issues[0]->resourceId)->toBe($route->id)
            ->and($report->issues[0]->resourceName)->toBe($route->domain)
            ->and($report->issues[0]->expected)->toBe('active')
            ->and($report->issues[0]->observed)->toBe($status->value)
            ->and($route->refresh()->status)->toBe($status);
    })->with([
        'pending' => [RouteStatus::Pending],
        'activating' => [RouteStatus::Activating],
        'retiring' => [RouteStatus::Retiring],
        'failed' => [RouteStatus::Failed],
    ]);

    it('reports Route status on an unreachable Node before the node-unreachable finding', function (): void {
        $node = route_doctor_node();
        $proxy = route_doctor_custom_proxy($node, 'executor.orbit');
        Route::query()->whereKey($proxy->route_id)->update([
            'status' => RouteStatus::Failed,
            'failed_step' => 'caddy',
            'error_code' => 'route.caddy_failed',
        ]);
        $inspector = Mockery::mock(CustomProxyRouteInspector::class);
        $inspector->shouldNotReceive('inspect');

        $report = new RouteDoctorProbe($inspector)->inspect(
            new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)),
        );

        expect(array_map(static fn ($issue): string => $issue->code, $report->issues))
            ->toBe(['route.lifecycle_not_active', 'route.node_unreachable'])
            ->and($report->issues[0]->observed)->toBe('failed');
    });

    it('reports a Cluster Route on the Node that holds the Cluster Router role only', function (): void {
        $router = route_doctor_node();
        $member = route_doctor_node();
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => null, 'state' => ClusterState::Active]);
        $router->update(['cluster_id' => $cluster->id]);
        $member->update(['cluster_id' => $cluster->id]);
        $router->roles()->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
        $route = Route::query()->create([
            'kind' => RouteKind::AnalyticsTracking,
            'cluster_id' => $cluster->id,
            'domain' => 'analytics.shop.example.com',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Public,
            'status' => RouteStatus::Pending,
        ]);
        $inspector = Mockery::mock(CustomProxyRouteInspector::class);
        $inspector->shouldNotReceive('inspect');
        $probe = new RouteDoctorProbe($inspector);

        $onRouter = $probe->inspect(route_doctor_context($router));
        $onMember = $probe->inspect(route_doctor_context($member));

        expect(collect($onRouter->issues)->pluck('resourceId')->all())->toBe([$route->id])
            ->and($onRouter->issues[0]->code)->toBe('route.lifecycle_not_active')
            ->and($onMember->checked)->toBe(0)
            ->and($onMember->issues)->toBeEmpty();
    });
});

function route_doctor_context(Node $node): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true));
}

function route_doctor_node(): Node
{
    return Node::query()->create([
        'name' => 'route-doctor-'.Node::query()->count(),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.(50 + Node::query()->count()),
        'wireguard_ip' => '10.44.0.'.(50 + Node::query()->count()),
        'user' => 'orbit',
    ]);
}

function route_doctor_custom_proxy(Node $node, string $domain): RouteCustomProxy
{
    $route = Route::query()->create([
        'kind' => RouteKind::CustomProxy,
        'app_id' => null,
        'node_id' => $node->id,
        'cluster_id' => null,
        'generation_basis_node_id' => null,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        'failed_step' => null,
        'error_code' => null,
    ]);
    $route->customProxy()->create([
        'node_id' => $node->id,
        'process_id' => null,
        'upstream' => 'http://127.0.0.1:4788',
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->customProxy()->sole();
}

function route_doctor_app_route(Node $node): Route
{
    $app = OrbitApp::query()->create([
        'name' => 'Doctor App '.$node->id,
        'slug' => 'doctor-app-'.$node->id,
        'repository_url' => 'https://example.test/doctor-app.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'checkout_path' => '/srv/doctor/main',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'kind' => RouteKind::App,
        'app_id' => $app->id,
        'node_id' => $node->id,
        'domain' => 'app-'.$node->id.'.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}
