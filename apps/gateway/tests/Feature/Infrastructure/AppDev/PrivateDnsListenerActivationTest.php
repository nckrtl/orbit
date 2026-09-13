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
use App\Infrastructure\AppDev\PrivateDnsListenerFactory;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use App\Infrastructure\AppDev\PrivateDnsTransportServer;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\PrivateDnsPublishHarness;

it('activates the requester-aware listener from a published catalog without rewriting LAN into the shared fragment', function (): void {
    $harness = new PrivateDnsPublishHarness;
    [$route, $member] = orb307_published_cluster();
    $harness->putVpnFragment("# Managed by Orbit.\ninterface=orbit\nbind-dynamic\nhost-record=gateway.orbit,10.44.0.1\n");

    try {
        $harness->listenerManager()->converge();
        $records = (string) file_get_contents($harness->recordsPath());
        $vpn = (string) file_get_contents($harness->vpnFragmentPath());
        $unit = (string) file_get_contents($harness->unitPath());

        expect($records)
            ->toContain('host-record='.$route->hostname.',10.44.0.20')
            ->not->toContain('192.168.10.20')
            ->and($vpn)
            ->toContain('listen-address=127.0.0.55', 'bind-interfaces')
            ->not->toContain('interface=orbit', 'bind-dynamic')
            ->and($unit)
            ->toContain('orbit:private-dns-serve')
            ->toContain('--listen=10.44.0.1')
            ->and($harness->serviceCalls())
            ->toContain('restart dnsmasq')
            ->toContain('enable --now orbit-private-dns.service');
    } finally {
        $harness->cleanup();
    }
});

it('picks up a catalog republish without restarting the listener once it is already active', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb307_published_cluster();
    $harness->putVpnFragment("# Managed by Orbit.\nlisten-address=127.0.0.55\nbind-interfaces\n");

    try {
        $manager = $harness->listenerManager();
        $manager->converge();
        $harness->clearServiceLog();
        $harness->markActive();
        $harness->markListenerActive();
        $manager->converge();

        expect($harness->serviceCalls())
            ->toContain('is-active --quiet dnsmasq')
            ->toContain('is-active --quiet orbit-private-dns.service')
            ->not->toContain('restart dnsmasq')
            ->not->toContain('enable --now orbit-private-dns.service');
    } finally {
        $harness->cleanup();
    }
});

it('restores the previous dnsmasq fragment and catalog when listener activation fails', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb307_published_cluster();
    $previousRecords = "# Managed by Orbit.\nhost-record=gateway.orbit,10.44.0.1\n";
    $previousCatalog = "{\"requesters\":{},\"records\":{\"gateway.orbit\":\"10.44.0.1\"},\"suffixes\":{},\"overrides\":{}}\n";
    $previousVpn = "# Managed by Orbit.\ninterface=orbit\nbind-dynamic\n";
    $harness->putRecords($previousRecords);
    $harness->putCatalog($previousCatalog);
    $harness->putVpnFragment($previousVpn);
    $harness->markActive();
    $harness->failListenerStart();

    try {
        expect(fn () => $harness->listenerManager()->converge())
            ->toThrow(RuntimeConvergenceException::class);

        expect(file_get_contents($harness->recordsPath()))
            ->toBe($previousRecords)
            ->and(file_get_contents($harness->catalogPath()))
            ->toBe($previousCatalog)
            ->and(file_get_contents($harness->vpnFragmentPath()))
            ->toBe($previousVpn);
    } finally {
        $harness->cleanup();
    }
});

it('answers UDP and TCP from a file-backed catalog after a republish without restarting the process', function (): void {
    $registered = Node::query()->create([
        'name' => 'file-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '127.0.0.2',
        'user' => 'orbit',
    ]);
    $root = sys_get_temp_dir().'/orbit-listener-catalog-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($root, 0755, true);
    $catalog = $root.'/catalog.json';
    $files->put($catalog, json_encode([
        'requesters' => ['127.0.0.2' => $registered->id],
        'records' => ['commander.test' => '10.44.0.7'],
        'suffixes' => [],
        'overrides' => [
            DnsRequester::registered($registered->id, '127.0.0.2')->cacheKey() => [
                'commander.test' => '192.168.6.20',
            ],
        ],
    ], JSON_THROW_ON_ERROR));
    $server = new PrivateDnsListenerFactory()->make($catalog, '127.0.0.1', 0, '127.0.0.55:53535');

    try {
        $server->start();
        $first = orb307_query($server, '127.0.0.2', 'udp', 'commander.test');
        $unknown = orb307_query($server, '127.0.0.1', 'tcp', 'commander.test');
        $files->put($catalog, json_encode([
            'requesters' => ['127.0.0.2' => $registered->id],
            'records' => ['commander.test' => '10.44.0.7'],
            'suffixes' => [],
            'overrides' => [
                DnsRequester::registered($registered->id, '127.0.0.2')->cacheKey() => [
                    'commander.test' => '192.168.6.21',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        touch($catalog, time() + 2);
        clearstatcache(true, $catalog);
        $reloaded = orb307_query($server, '127.0.0.2', 'udp', 'commander.test');

        expect($first)
            ->toBe('192.168.6.20')
            ->and($unknown)
            ->toBe('10.44.0.7')
            ->and($reloaded)
            ->toBe('192.168.6.21');
    } finally {
        $server->stop();
        $files->deleteDirectory($root);
    }
});

/**
 * @return array{Route, Node}
 */
function orb307_published_cluster(): array
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

function orb307_query(PrivateDnsTransportServer $server, string $source, string $transport, string $name): string
{
    $query = new PrivateDnsMessageCodec()->encodeQuery($name);

    if ($transport === 'tcp') {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        expect($socket)->toBeInstanceOf(Socket::class);
        socket_bind($socket, $source, 0);
        socket_connect($socket, '127.0.0.1', $server->port());
        socket_write($socket, pack('n', strlen($query)).$query);
        $server->serveOnce(1.0);
        $header = socket_read($socket, 2);
        expect($header)->toBeString()->toHaveLength(2);
        $length = unpack('nlen', $header);
        $response = socket_read($socket, $length['len'] ?? 0);
        socket_close($socket);
    } else {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        expect($socket)->toBeInstanceOf(Socket::class);
        socket_bind($socket, $source, 0);
        socket_sendto($socket, $query, strlen($query), 0, '127.0.0.1', $server->port());
        $server->serveOnce(1.0);
        $response = '';
        $from = '';
        $fromPort = 0;
        socket_recvfrom($socket, $response, 4096, 0, $from, $fromPort);
        socket_close($socket);
    }

    $address = unpack('Nip', substr((string) $response, -4));

    return long2ip($address['ip'] ?? 0) ?: '';
}
