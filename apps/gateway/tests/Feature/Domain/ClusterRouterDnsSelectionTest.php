<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsRequester;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\CatalogPrivateDnsAnswerSelector;
use App\Infrastructure\AppDev\InMemoryPrivateDnsAnswerCache;
use App\Infrastructure\AppDev\PrivateDnsAnswerCatalog;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use App\Infrastructure\AppDev\PrivateDnsRequestHandler;
use Tests\Support\RegisteredNodeDnsRequesterResolver;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;

it('returns the Router LAN address to an eligible Cluster member for the TLD and Cluster-scoped Routes', function (): void {
    [$route, $outside, $eligible] = orb260_cluster_routes();
    $catalog = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->catalog();
    $handler = orb260_handler($catalog);
    $codec = new PrivateDnsMessageCodec;

    expect(orb260_answer($handler->handle((string) $eligible->wireguard_ip, $codec->encodeQuery($route->domain))))
        ->toBe('192.168.10.20')
        ->and(orb260_answer($handler->handle((string) $eligible->wireguard_ip, $codec->encodeQuery('site.cluster.test'))))
        ->toBe('192.168.10.20')
        ->and(orb260_answer($handler->handle((string) $eligible->wireguard_ip, $codec->encodeQuery($outside->domain))))
        ->toBe('192.168.10.20');
});

it('returns the Router WireGuard address when any LAN-selection condition is absent', function (string $source): void {
    [$route, $outside] = orb260_cluster_routes();
    $catalog = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->catalog();
    $handler = orb260_handler($catalog);
    $codec = new PrivateDnsMessageCodec;

    expect(orb260_answer($handler->handle($source, $codec->encodeQuery($route->domain))))
        ->toBe('10.44.0.20')
        ->and(orb260_answer($handler->handle($source, $codec->encodeQuery('site.cluster.test'))))
        ->toBe('10.44.0.20')
        ->and(orb260_answer($handler->handle($source, $codec->encodeQuery($outside->domain))))
        ->toBe('10.44.0.20');
})->with([
    'vpn-only member' => ['10.44.0.12'],
    'other Cluster member' => ['10.44.0.15'],
    'unregistered source' => ['10.44.0.99'],
    'LAN address is not identity' => ['192.168.10.10'],
]);

it('ignores a caller-supplied EDNS identity when selecting a Router address', function (): void {
    [$route, , $eligible] = orb260_cluster_routes();
    $handler = orb260_handler(new AppDevDnsConfigRenderer(new AppDevSiteRepository)->catalog());
    $codec = new PrivateDnsMessageCodec;
    $claimed = $codec->encodeQuery($route->domain, ednsClientSubnet: (string) $eligible->wireguard_ip);

    expect(orb260_answer($handler->handle('10.44.0.99', $claimed)))
        ->toBe('10.44.0.20')
        ->and(orb260_answer($handler->handle((string) $eligible->wireguard_ip, $codec->encodeQuery($route->domain, ednsClientSubnet: '10.44.0.99'))))
        ->toBe('192.168.10.20');
});

it('keeps interleaved eligible and ineligible answers isolated across cache flush', function (): void {
    [$route, , $eligible] = orb260_cluster_routes();
    $cache = new InMemoryPrivateDnsAnswerCache;
    $handler = orb260_handler(new AppDevDnsConfigRenderer(new AppDevSiteRepository)->catalog(), $cache);
    $codec = new PrivateDnsMessageCodec;
    $query = fn (): string => $codec->encodeQuery($route->domain);

    $lan = $handler->handle((string) $eligible->wireguard_ip, $query());
    $vpn = $handler->handle('10.44.0.12', $query());
    $lanAgain = $handler->handle((string) $eligible->wireguard_ip, $query());
    $cache->flush();
    $vpnAfterRestart = $handler->handle('10.44.0.12', $query());
    $lanAfterRestart = $handler->handle((string) $eligible->wireguard_ip, $query());

    expect(orb260_answer($lan))
        ->toBe('192.168.10.20')
        ->and(orb260_answer($vpn))
        ->toBe('10.44.0.20')
        ->and(orb260_answer($lanAgain))
        ->toBe('192.168.10.20')
        ->and(orb260_answer($vpnAfterRestart))
        ->toBe('10.44.0.20')
        ->and(orb260_answer($lanAfterRestart))
        ->toBe('192.168.10.20');
});

it('leaves Node-scoped Routes and control-plane names on their established addresses', function (): void {
    orb260_cluster_routes();
    $solo = Node::query()->create([
        'name' => 'solo-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'solo.test',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
        'lan_ip' => '192.168.40.40',
        'wireguard_public_key' => 'solo-key',
        'user' => 'orbit',
    ]);
    $solo->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $app = OrbitApp::query()->create([
        'name' => 'Solo',
        'slug' => 'solo',
        'repository_url' => 'https://example.test/solo.git',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $solo->id,
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/solo',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('c', 40),
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $solo->id,
        'domain' => 'solo.app.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
        'wireguard_public_key' => 'gateway-key',
    ]);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $metrics = Node::query()->create([
        'name' => 'metrics',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.3',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
        'wireguard_public_key' => 'metrics-key',
    ]);
    $metrics->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $eligible = Node::query()->where('name', 'lan-member')->sole();
    $handler = orb260_handler(new AppDevDnsConfigRenderer(new AppDevSiteRepository)->catalog());
    $codec = new PrivateDnsMessageCodec;

    expect(orb260_answer($handler->handle((string) $eligible->wireguard_ip, $codec->encodeQuery('solo.app.test'))))
        ->toBe('10.44.0.40')
        ->and(orb260_answer($handler->handle((string) $eligible->wireguard_ip, $codec->encodeQuery('site.solo.test'))))
        ->toBe('10.44.0.40')
        ->and(orb260_answer($handler->handle((string) $eligible->wireguard_ip, $codec->encodeQuery('gateway.orbit'))))
        ->toBe('10.44.0.1')
        ->and(orb260_answer($handler->handle((string) $eligible->wireguard_ip, $codec->encodeQuery('metrics.orbit'))))
        ->toBe('10.44.0.1');
});

it('publishes Cluster TLD wildcards to the Router WireGuard address', function (): void {
    orb260_cluster_routes();

    $configuration = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render();

    expect($configuration)
        ->toContain('address=/.cluster.test/10.44.0.20')
        ->not
        ->toContain('address=/.cluster.test/10.44.0.10')
        ->not
        ->toContain('address=/.cluster.test/192.168.10.20');
});

it('releases an untargeted retiring Route hostname from Cluster Router DNS selection', function (): void {
    [$route, $outside, $eligible] = orb260_cluster_routes();
    $instance = $route->targets->sole()->appInstance;
    $instance->update(['status' => AppInstanceState::Reserved]);
    $route->targets()->delete();
    $route->update(['status' => RouteStatus::Retiring]);

    $catalog = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->catalog();
    $eligibleKey = DnsRequester::registered($eligible->id, (string) $eligible->wireguard_ip)->cacheKey();

    expect($catalog->exact[$route->domain] ?? null)
        ->toBeNull()
        ->and($catalog->overrides[$eligibleKey][$route->domain] ?? null)
        ->toBeNull()
        ->and($catalog->overrides[$eligibleKey][$outside->domain])
        ->toBe('192.168.10.20');
});

it('fills requester catalog overrides for eligible LAN members only', function (): void {
    [$route, $outside, $eligible] = orb260_cluster_routes();
    $catalog = new AppDevDnsConfigRenderer(new AppDevSiteRepository)->catalog();
    $eligibleKey = DnsRequester::registered($eligible->id, (string) $eligible->wireguard_ip)->cacheKey();
    $vpn = Node::query()->where('name', 'vpn-only')->sole();
    $vpnKey = DnsRequester::registered($vpn->id, (string) $vpn->wireguard_ip)->cacheKey();

    expect($catalog->exact[$route->domain])
        ->toBe('10.44.0.20')
        ->and($catalog->suffixes['cluster.test'])
        ->toBe('10.44.0.20')
        ->and($catalog->overrides[$eligibleKey][$route->domain])
        ->toBe('192.168.10.20')
        ->and($catalog->overrides[$eligibleKey]['cluster.test'])
        ->toBe('192.168.10.20')
        ->and($catalog->overrides[$eligibleKey][$outside->domain])
        ->toBe('192.168.10.20')
        ->and($catalog->overrides[$vpnKey] ?? [])
        ->toBe([]);
});

/**
 * @return array{0: Route, 1: Route, 2: Node}
 */
function orb260_cluster_routes(): array
{
    $cluster = Cluster::query()->create([
        'name' => 'lan-dns',
        'tld' => 'cluster.test',
        'state' => ClusterState::Active,
    ]);
    $workload = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'lan-member',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'cluster.test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'lan_ip' => '192.168.10.10',
        'wireguard_public_key' => 'member-key',
        'user' => 'orbit',
    ]);
    $workload->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $router = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'lan-router',
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
    Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'vpn-only',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.12',
        'wireguard_ip' => '10.44.0.12',
        'wireguard_public_key' => 'vpn-key',
        'user' => 'orbit',
    ]);
    $other = Cluster::query()->create([
        'name' => 'other-lan-dns',
        'state' => ClusterState::Active,
    ]);
    Node::query()->create([
        'cluster_id' => $other->id,
        'name' => 'other-member',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.15',
        'wireguard_ip' => '10.44.0.15',
        'lan_ip' => '192.168.15.15',
        'wireguard_public_key' => 'other-key',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Clustered',
        'slug' => 'clustered',
        'repository_url' => 'https://example.test/clustered.git',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/clustered',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('d', 40),
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => 'app.cluster.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $preview = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'preview',
        'checkout_path' => '/home/orbit/apps/clustered-preview',
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('e', 40),
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::Active,
    ]);
    $outside = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => 'other.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $outside->targets()->create(['app_instance_id' => $preview->id, 'position' => 0]);
    $outside->update(['status' => RouteStatus::Active]);

    return [$route->fresh(), $outside->fresh(), $workload->fresh()];
}

function orb260_handler(
    PrivateDnsAnswerCatalog $catalog,
    ?InMemoryPrivateDnsAnswerCache $cache = null,
): PrivateDnsRequestHandler {
    return new PrivateDnsRequestHandler(
        requesters: new RegisteredNodeDnsRequesterResolver,
        selector: new CatalogPrivateDnsAnswerSelector($catalog),
        cache: $cache ?? new InMemoryPrivateDnsAnswerCache,
    );
}

function orb260_answer(string $message): string
{
    $address = unpack('Nip', substr($message, -4));

    return long2ip($address['ip'] ?? 0) ?: '';
}
