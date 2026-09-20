<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @return array<string, mixed> */
function analytics_tracking_route_row(Cluster $cluster, string $domain = 'analytics.shop.example.com'): array
{
    return [
        'kind' => RouteKind::AnalyticsTracking,
        'app_id' => null,
        'node_id' => null,
        'cluster_id' => $cluster->id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ];
}

beforeEach(function (): void {
    $this->cluster = Cluster::query()->create(['name' => 'edge', 'tld' => null, 'state' => ClusterState::Active]);
    $this->node = Node::query()->create([
        'name' => 'prod',
        'status' => 'active',
        'public_ssh_host' => 'prod.test',
        'wireguard_ip' => '10.44.0.90',
    ]);
    $this->instance = AppInstance::query()->create([
        'app_id' => OrbitApp::query()->create([
            'name' => 'Shop',
            'slug' => 'shop',
            'repository_url' => 'https://example.test/shop.git',
        ])->id,
        'node_id' => $this->node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => '/srv/shop',
        'status' => AppInstanceState::Active,
    ]);
});

describe('analytics tracking Route persistence contract', function (): void {
    it('stores a cluster-scoped public tracking Route that becomes active without targets', function (): void {
        expect(Schema::hasColumns('route_analytics_trackings', ['route_id', 'app_instance_id', 'created_at', 'updated_at']))
            ->toBeTrue();

        $route = Route::query()->create(analytics_tracking_route_row($this->cluster));
        $route->analyticsTracking()->create(['app_instance_id' => $this->instance->id]);
        $route->update(['status' => RouteStatus::Active]);

        expect($route->refresh()->isAnalyticsTracking())->toBeTrue()
            ->and($route->status)->toBe(RouteStatus::Active)
            ->and($route->analyticsTracking?->app_instance_id)->toBe($this->instance->id)
            ->and($route->targets)->toBeEmpty();
    });

    it('stores a Node-scoped private tracking Route, the shape behind an edge Orbit does not own', function (): void {
        $route = Route::query()->create([
            ...analytics_tracking_route_row($this->cluster),
            'node_id' => $this->node->id,
            'cluster_id' => null,
            'publication' => RoutePublication::Private,
        ]);
        $route->analyticsTracking()->create(['app_instance_id' => $this->instance->id]);

        expect($route->refresh()->publication)->toBe(RoutePublication::Private)
            ->and($route->node_id)->toBe($this->node->id)
            ->and($route->cluster_id)->toBeNull();
    });

    it('stores a cluster-scoped private tracking Route, the shape beside a private cluster domain', function (): void {
        $route = Route::query()->create([
            ...analytics_tracking_route_row($this->cluster),
            'publication' => RoutePublication::Private,
        ]);

        expect($route->refresh()->publication)->toBe(RoutePublication::Private)
            ->and($route->cluster_id)->toBe($this->cluster->id);
    });

    it('rejects a tracking Route outside its contract', function (array $override): void {
        expect(fn () => Route::query()->create([...analytics_tracking_route_row($this->cluster), ...$override]))
            ->toThrow(QueryException::class, 'Invalid Route persistence contract.');

        expect(Route::query()->count())->toBe(0);
    })->with([
        'both a Node and a cluster' => fn (): array => ['node_id' => $this->node->id],
        'a public Node scope' => fn (): array => ['node_id' => $this->node->id, 'cluster_id' => null],
        'generated provenance' => fn (): array => [
            'provenance' => RouteProvenance::Generated,
            'generation_basis_node_id' => $this->node->id,
        ],
        'an App owner' => fn (): array => ['app_id' => $this->instance->app_id],
    ]);

    it('rejects a tracking Route that turns public on a Node or changes kind', function (array $update): void {
        $route = Route::query()->create(analytics_tracking_route_row($this->cluster));

        expect(fn () => DB::table('routes')->where('id', $route->id)->update($update))
            ->toThrow(QueryException::class, 'Invalid Route persistence contract.');
    })->with([
        'a public Node scope' => fn (): array => ['node_id' => $this->node->id, 'cluster_id' => null],
        'another kind' => fn (): array => ['kind' => 'app', 'app_id' => $this->instance->app_id],
    ]);

    it('rejects a route_targets row on a tracking Route', function (): void {
        $route = Route::query()->create(analytics_tracking_route_row($this->cluster));

        expect(fn () => $route->targets()->create(['app_instance_id' => $this->instance->id, 'position' => 0]))
            ->toThrow(QueryException::class, 'An analytics tracking Route cannot own App instance targets.');

        expect($route->targets()->count())->toBe(0);
    });

    it('keeps rejecting an unknown kind and a targetless active App Route', function (): void {
        expect(fn () => DB::table('routes')->insert([
            ...array_map(
                static fn (mixed $value): mixed => $value instanceof BackedEnum ? $value->value : $value,
                analytics_tracking_route_row($this->cluster),
            ),
            'kind' => 'path_proxy',
        ]))->toThrow(QueryException::class, 'Invalid Route persistence contract.');

        $appRoute = Route::query()->create([
            'app_id' => $this->instance->app_id,
            'cluster_id' => $this->cluster->id,
            'domain' => 'shop.example.com',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Public,
            'status' => RouteStatus::Pending,
        ]);

        expect(fn () => DB::table('routes')->where('id', $appRoute->id)->update(['status' => 'active']))
            ->toThrow(QueryException::class, 'Invalid Route persistence contract.');
    });

    it('removes the tracking row with its Route and with its App instance', function (): void {
        $first = Route::query()->create(analytics_tracking_route_row($this->cluster));
        $first->analyticsTracking()->create(['app_instance_id' => $this->instance->id]);
        $second = Route::query()->create(analytics_tracking_route_row($this->cluster, 'stats.shop.example.com'));
        $second->analyticsTracking()->create(['app_instance_id' => $this->instance->id]);

        $first->delete();
        expect(DB::table('route_analytics_trackings')->pluck('route_id')->all())->toBe([$second->id]);

        $this->instance->delete();
        expect(DB::table('route_analytics_trackings')->count())->toBe(0);
    });
});
