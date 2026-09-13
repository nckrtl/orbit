<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsRequester;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\PrivateDnsAnswerCatalog;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Tests\Support\PrivateDnsPublishHarness;

it('publishes records and the requester catalog idempotently', function (): void {
    $harness = new PrivateDnsPublishHarness;
    $node = orb258_published_peer();

    try {
        $manager = $harness->manager();
        $manager->converge();
        $records = file_get_contents($harness->recordsPath());
        $catalog = file_get_contents($harness->catalogPath());
        $manager->converge();

        expect($records)
            ->toBe($harness->recordsPath() !== '' ? file_get_contents($harness->recordsPath()) : '')
            ->toContain('address=/.peer.test/10.44.0.30')
            ->and($catalog)
            ->toBe(file_get_contents($harness->catalogPath()))
            ->and(json_decode((string) $catalog, true)['requesters'] ?? [])
            ->toBe(['10.44.0.30' => $node->id])
            ->and($harness->serviceCalls())
            ->toBe(['restart dnsmasq', 'is-active --quiet dnsmasq']);
    } finally {
        $harness->cleanup();
    }
});

it('leaves the working records and catalog in place when validation fails', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb258_published_peer();
    $previousRecords = "# Managed by Orbit.\nhost-record=gateway.orbit,10.44.0.1\n";
    $previousCatalog = "{\"requesters\":{},\"records\":{\"gateway.orbit\":\"10.44.0.1\"},\"suffixes\":{},\"overrides\":{}}\n";

    try {
        $harness->putRecords($previousRecords);
        $harness->putCatalog($previousCatalog);
        $harness->markActive();
        $harness->failValidation();

        expect(fn () => $harness->manager()->converge())
            ->toThrow(RuntimeConvergenceException::class);

        expect(file_get_contents($harness->recordsPath()))
            ->toBe($previousRecords)
            ->and(file_get_contents($harness->catalogPath()))
            ->toBe($previousCatalog);
    } finally {
        $harness->cleanup();
    }
});

it('restores the previous working service after a restart failure and republishes on retry', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb258_published_peer();
    $previousRecords = "# Managed by Orbit.\nhost-record=gateway.orbit,10.44.0.1\n";
    $previousCatalog = "{\"requesters\":{},\"records\":{\"gateway.orbit\":\"10.44.0.1\"},\"suffixes\":{},\"overrides\":{}}\n";

    try {
        $harness->putRecords($previousRecords);
        $harness->putCatalog($previousCatalog);
        $harness->markActive();
        $harness->failRestart();

        expect(fn () => $harness->manager()->converge())
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->errorCode)->toBe('app-dev.dns_config_failed');
            });

        expect(file_get_contents($harness->recordsPath()))
            ->toBe($previousRecords)
            ->and(file_get_contents($harness->catalogPath()))
            ->toBe($previousCatalog);

        $harness->clearRestartFailure();
        $harness->manager()->converge();
        $expected = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();

        expect(file_get_contents($harness->recordsPath()))
            ->toBe($expected)
            ->and(PrivateDnsAnswerCatalog::fromPublished(
                json_decode((string) file_get_contents($harness->catalogPath()), true) ?? [],
            )->suffixes)
            ->toHaveKey('peer.test');
    } finally {
        $harness->cleanup();
    }
});

it('publishes LAN overrides for eligible Cluster members in the requester catalog', function (): void {
    $harness = new PrivateDnsPublishHarness;
    [$route, $member] = orb260_published_cluster();

    try {
        $harness->manager()->converge();
        $published = json_decode((string) file_get_contents($harness->catalogPath()), true) ?? [];
        $catalog = PrivateDnsAnswerCatalog::fromPublished($published);
        $key = DnsRequester::registered($member->id, (string) $member->wireguard_ip)->cacheKey();

        expect($published['requesters'][(string) $member->wireguard_ip] ?? null)
            ->toBe($member->id)
            ->and($catalog->exact[$route->hostname])
            ->toBe('10.44.0.20')
            ->and($catalog->overrides[$key][$route->hostname])
            ->toBe('192.168.10.20');
    } finally {
        $harness->cleanup();
    }
});

function orb258_published_peer(): Node
{
    $node = Node::query()->create([
        'name' => 'published-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'peer.test',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    return $node;
}

/**
 * @return array{Route, Node}
 */
function orb260_published_cluster(): array
{
    $cluster = Cluster::query()->create([
        'name' => 'published-lan',
        'tld' => 'cluster.test',
        'state' => ClusterState::Active,
    ]);
    $member = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'published-member',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'workload.test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'lan_ip' => '192.168.10.10',
        'wireguard_public_key' => 'member-key',
        'user' => 'orbit',
    ]);
    $member->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $router = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'published-router',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
        'lan_ip' => '192.168.10.20',
        'wireguard_public_key' => 'router-key',
        'user' => 'orbit',
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Published',
        'slug' => 'published',
        'repository_url' => 'https://example.test/published.git',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $member->id,
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/published',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'app.cluster.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return [$route->fresh(), $member->fresh()];
}
