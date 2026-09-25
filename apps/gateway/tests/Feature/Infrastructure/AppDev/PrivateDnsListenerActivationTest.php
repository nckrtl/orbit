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
use App\Infrastructure\AppDev\PrivateDnsListenerRelease;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use App\Infrastructure\AppDev\PrivateDnsSocketBinder;
use App\Infrastructure\AppDev\PrivateDnsTransportServer;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\PrivateDnsPublishHarness;

it('activates the listener from an installed release behind its socket without rewriting LAN into the shared fragment', function (): void {
    $harness = new PrivateDnsPublishHarness;
    [$route, $member] = orb307_published_cluster();
    $harness->putVpnFragment("# Managed by Orbit.\ninterface=orbit\nbind-dynamic\nhost-record=gateway.orbit,10.44.0.1\n");

    try {
        $harness->listenerManager()->converge();
        $records = (string) file_get_contents($harness->recordsPath());
        $vpn = (string) file_get_contents($harness->vpnFragmentPath());
        $unit = (string) file_get_contents($harness->unitPath());
        $socket = (string) file_get_contents($harness->socketPath());
        $release = PrivateDnsListenerRelease::fromGateway();

        $calls = $harness->serviceCalls();
        $socketAt = array_search('start orbit-private-dns.socket', $calls, true);
        $startAt = array_search('start orbit-private-dns.service', $calls, true);
        $restartAt = array_search('restart dnsmasq', $calls, true);

        expect($records)
            ->toContain('host-record='.$route->domain.',10.44.0.20')
            ->not->toContain('192.168.10.20')
            ->and($vpn)
            ->toContain('listen-address=127.0.0.55', 'bind-interfaces')
            ->not->toContain('interface=orbit', 'bind-dynamic')
            ->and($unit)
            ->toContain($harness->releasesPath().'/'.$release->id().'/serve.php')
            ->toContain('--listen=10.44.0.1')
            ->toContain('Sockets=orbit-private-dns.socket')
            ->not->toContain('artisan')
            ->and($socket)
            ->toContain('ListenDatagram=10.44.0.1:53', 'ListenStream=10.44.0.1:53', 'FreeBind=yes')
            ->and(array_keys(iterator_to_array(new FilesystemIterator($harness->releasesPath()))))
            ->toBe([$harness->releasesPath().'/'.$release->id()])
            ->and(file_get_contents($harness->releasesPath().'/'.$release->id().'/app/Infrastructure/AppDev/PrivateDnsListenerProcess.php'))
            ->toBe($release->files()['app/Infrastructure/AppDev/PrivateDnsListenerProcess.php'])
            ->and($socketAt)->toBeInt()
            ->and($startAt)->toBeInt()
            ->and($restartAt)->toBeInt()
            ->and($socketAt)->toBeLessThan($startAt)
            ->and($startAt)->toBeLessThan($restartAt)
            ->and(trim((string) file_get_contents($harness->loadedPath())))
            ->toBe(hash_file('sha256', $harness->catalogPath()))
            ->and($harness->socketProbes())
            ->toContain('-4 -ulpnH src 10.44.0.1:53')
            ->toContain('-4 -tlnpH src 10.44.0.1:53')
            ->and($harness->confDirectoryEntries())
            ->toEqualCanonicalizing(['orbit-records.conf', 'orbit-vpn.conf'])
            ->and($harness->confDirectoryListenAddressFiles())
            ->toBe(['orbit-vpn.conf']);
    } finally {
        $harness->cleanup();
    }
});

it('hands the address from a listener that binds it itself to the socket unit', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb307_published_cluster();
    $harness->putVpnFragment("# Managed by Orbit.\nlisten-address=127.0.0.55\nbind-interfaces\n");
    file_put_contents($harness->unitPath(), "[Service]\nExecStart=/usr/bin/php8.5 /home/orbit/orbit/apps/gateway/artisan orbit:private-dns-serve\n");
    $harness->markActive();
    $harness->markListenerActive();

    try {
        $harness->listenerManager()->converge();
        $calls = $harness->serviceCalls();

        expect(array_search('stop orbit-private-dns.service', $calls, true))
            ->toBeLessThan(array_search('start orbit-private-dns.socket', $calls, true))
            ->and(array_search('start orbit-private-dns.socket', $calls, true))
            ->toBeLessThan(array_search('start orbit-private-dns.service', $calls, true))
            ->and(file_get_contents($harness->unitPath()))->toContain('serve.php');
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
        $manager->converge();
        orb_catalog_change();
        $manager->converge();

        expect($harness->serviceCalls())
            ->toContain('is-active --quiet orbit-private-dns.socket')
            ->not->toContain('restart orbit-private-dns.service')
            ->not->toContain('start orbit-private-dns.service')
            ->not->toContain('stop orbit-private-dns.service')
            ->not->toContain('stop orbit-private-dns.socket')
            ->and(trim((string) file_get_contents($harness->loadedPath())))
            ->toBe(hash_file('sha256', $harness->catalogPath()))
            ->and(file_get_contents($harness->catalogPath()))->toContain('10.44.0.99');
    } finally {
        $harness->cleanup();
    }
});

it('restarts a stale listener behind its socket when it does not confirm a changed catalog', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb307_published_cluster();
    $harness->putVpnFragment("# Managed by Orbit.\nlisten-address=127.0.0.55\nbind-interfaces\n");

    try {
        $manager = $harness->listenerManager();
        $manager->converge();
        $harness->staleListener();
        $harness->clearServiceLog();
        orb_catalog_change();
        $manager->converge();

        expect($harness->serviceCalls())
            ->toContain('restart orbit-private-dns.service')
            ->not->toContain('stop orbit-private-dns.socket')
            ->not->toContain('stop orbit-private-dns.service')
            ->and(trim((string) file_get_contents($harness->loadedPath())))
            ->toBe(hash_file('sha256', $harness->catalogPath()));
    } finally {
        $harness->cleanup();
    }
});

it('restarts a listener that confirmed another catalog once, then keeps it', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb307_published_cluster();
    $harness->putVpnFragment("# Managed by Orbit.\nlisten-address=127.0.0.55\nbind-interfaces\n");

    try {
        $manager = $harness->listenerManager();
        $manager->converge();
        // A rollback to code that never writes the confirmation leaves the newer listener's file behind.
        $harness->listenerNeverConfirms();
        $harness->putLoaded(hash('sha256', 'another catalog').PHP_EOL);
        $harness->clearServiceLog();
        $manager->converge();
        $manager->converge();

        expect(array_count_values($harness->serviceCalls())['restart orbit-private-dns.service'] ?? 0)->toBe(1)
            ->and(file_exists($harness->loadedPath()))->toBeFalse();
    } finally {
        $harness->cleanup();
    }
});

it('installs a new release and restarts the listener behind its socket when the listener code changes', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb307_published_cluster();
    $harness->putVpnFragment("# Managed by Orbit.\nlisten-address=127.0.0.55\nbind-interfaces\n");
    $previous = $harness->releasesPath().'/0123456789abcdef';
    $older = $harness->releasesPath().'/fedcba9876543210';

    try {
        $manager = $harness->listenerManager();
        $manager->converge();
        mkdir($previous, 0755, true);
        mkdir($older, 0755, true);
        $unit = str_replace(PrivateDnsListenerRelease::fromGateway()->id(), '0123456789abcdef', (string) file_get_contents($harness->unitPath()));
        file_put_contents($harness->unitPath(), $unit);
        $harness->clearServiceLog();
        $manager->converge();

        expect($harness->serviceCalls())
            ->toContain('restart orbit-private-dns.service')
            ->not->toContain('stop orbit-private-dns.socket')
            ->not->toContain('stop orbit-private-dns.service')
            ->and(file_get_contents($harness->unitPath()))->toContain(PrivateDnsListenerRelease::fromGateway()->id())
            ->and(is_dir($previous))->toBeTrue()
            ->and(is_dir($older))->toBeFalse();
    } finally {
        $harness->cleanup();
    }
});

it('restores the previous units and catalog when a stale listener fails to restart', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb307_published_cluster();
    $harness->putVpnFragment("# Managed by Orbit.\nlisten-address=127.0.0.55\nbind-interfaces\n");

    try {
        $manager = $harness->listenerManager();
        $manager->converge();
        $previousCatalog = (string) file_get_contents($harness->catalogPath());
        $previousUnit = (string) file_get_contents($harness->unitPath());
        $harness->staleListener();
        $harness->failListenerRestart();
        orb_catalog_change();

        expect(fn () => $manager->converge())->toThrow(RuntimeConvergenceException::class);
        expect(file_get_contents($harness->catalogPath()))->toBe($previousCatalog)
            ->and(file_get_contents($harness->unitPath()))->toBe($previousUnit);
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
            ->toBe($previousVpn)
            ->and($harness->confDirectoryEntries())
            ->toEqualCanonicalizing(['orbit-records.conf', 'orbit-vpn.conf'])
            ->and($harness->confDirectoryListenAddressFiles())
            ->toBe([]);
    } finally {
        $harness->cleanup();
    }
});

it('restores the previous working dnsmasq VPN fragment when the listener is not bound after cutover', function (): void {
    $harness = new PrivateDnsPublishHarness;
    orb307_published_cluster();
    $previousRecords = "# Managed by Orbit.\nhost-record=gateway.orbit,10.44.0.1\n";
    $previousCatalog = "{\"requesters\":{},\"records\":{\"gateway.orbit\":\"10.44.0.1\"},\"suffixes\":{},\"overrides\":{}}\n";
    $previousVpn = "# Managed by Orbit.\ninterface=orbit\nbind-dynamic\n";
    $harness->putRecords($previousRecords);
    $harness->putCatalog($previousCatalog);
    $harness->putVpnFragment($previousVpn);
    $harness->markActive();
    $harness->failListenerBind();

    try {
        expect(fn () => $harness->listenerManager()->converge())
            ->toThrow(RuntimeConvergenceException::class);

        expect(file_get_contents($harness->vpnFragmentPath()))
            ->toBe($previousVpn)
            ->and(file_get_contents($harness->recordsPath()))
            ->toBe($previousRecords)
            ->and(file_get_contents($harness->catalogPath()))
            ->toBe($previousCatalog)
            ->and($harness->serviceCalls())
            ->toContain('start orbit-private-dns.service')
            ->toContain('restart dnsmasq')
            ->and($harness->socketProbes())
            ->toContain('-4 -ulpnH src 10.44.0.1:53')
            ->and($harness->confDirectoryEntries())
            ->toEqualCanonicalizing(['orbit-records.conf', 'orbit-vpn.conf'])
            ->and($harness->confDirectoryListenAddressFiles())
            ->toBe([]);
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

it('loads and confirms a republished catalog on an idle tick without a query', function (): void {
    $root = sys_get_temp_dir().'/orbit-listener-idle-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($root, 0755, true);
    $catalog = $root.'/catalog.json';
    $first = "{\"requesters\":{},\"records\":{\"commander.test\":\"10.44.0.7\"},\"suffixes\":{},\"overrides\":{}}\n";
    $second = "{\"requesters\":{},\"records\":{\"commander.test\":\"10.44.0.8\"},\"suffixes\":{},\"overrides\":{}}\n";
    $files->put($catalog, $first);
    $server = new PrivateDnsListenerFactory()->make($catalog, '127.0.0.1', 0, '127.0.0.55:53535');

    try {
        $server->start();
        expect(file_get_contents($catalog.'.loaded'))->toBe(hash('sha256', $first).PHP_EOL);

        $files->put($catalog, $second);
        $server->serveOnce(0.05);

        expect(file_get_contents($catalog.'.loaded'))->toBe(hash('sha256', $second).PHP_EOL)
            ->and(orb307_query($server, '127.0.0.1', 'udp', 'commander.test'))->toBe('10.44.0.8');
    } finally {
        $server->stop();
        $files->deleteDirectory($root);
    }
});

it('retries bind after the previous holder releases the published address and answers UDP and TCP through the cutover', function (): void {
    $root = sys_get_temp_dir().'/orbit-listener-cutover-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($root, 0755, true);
    $catalog = $root.'/catalog.json';
    $files->put($catalog, json_encode([
        'requesters' => [],
        'records' => ['commander.test' => '10.44.0.7'],
        'suffixes' => [],
        'overrides' => [],
    ], JSON_THROW_ON_ERROR));
    $port = orb314_free_port();
    $holder = new Process([
        PHP_BINARY,
        '-r',
        ' $udp = stream_socket_server("udp://127.0.0.1:'.$port.'", $e, $m, STREAM_SERVER_BIND);'
        .' $tcp = stream_socket_server("tcp://127.0.0.1:'.$port.'", $e, $m);'
        .' fwrite(STDOUT, "held\n");'
        .' usleep(400000);',
    ]);
    $listener = new Process([
        PHP_BINARY,
        '-r',
        'require '.var_export(base_path('vendor/autoload.php'), true).';'
        .'$server = (new App\Infrastructure\AppDev\PrivateDnsListenerFactory)->make('
        .var_export($catalog, true).', "127.0.0.1", '.$port.', "127.0.0.55:1");'
        .'(new App\Infrastructure\AppDev\PrivateDnsSocketBinder(8.0, 0.05))->bind($server);'
        .'fwrite(STDOUT, "bound\n");'
        .'while ($server->listening()) { $server->serveOnce(0.2); }',
    ]);

    try {
        $holder->start();
        expect(orb314_wait_until(static fn (): bool => str_contains($holder->getOutput(), 'held'), 2.0))->toBeTrue();
        expect(orb314_port_refuses('127.0.0.1', $port))->toBeFalse();

        $listener->start();
        $bound = orb314_wait_until(static fn (): bool => str_contains($listener->getOutput(), 'bound'), 8.0);
        expect($listener->getErrorOutput()."\n".$listener->getOutput())->toContain('bound');
        expect($bound)->toBeTrue();
        expect($holder->isRunning())->toBeFalse();
        expect($listener->isRunning())->toBeTrue();

        $udp = orb314_dig('127.0.0.1', $port, 'commander.test', 'udp');
        $tcp = orb314_dig('127.0.0.1', $port, 'commander.test', 'tcp');

        expect($udp)
            ->toBe('10.44.0.7')
            ->and($tcp)
            ->toBe('10.44.0.7');
    } finally {
        if ($listener->isRunning()) {
            $listener->stop(0.5);
        }
        if ($holder->isRunning()) {
            $holder->stop(0.5);
        }
        $files->deleteDirectory($root);
    }
});

it('does not treat a bind retry as success while the published address stays occupied', function (): void {
    $root = sys_get_temp_dir().'/orbit-listener-busy-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($root, 0755, true);
    $catalog = $root.'/catalog.json';
    $files->put($catalog, "{\"requesters\":{},\"records\":{\"commander.test\":\"10.44.0.7\"},\"suffixes\":{},\"overrides\":{}}\n");
    $port = orb314_free_port();
    $holderUdp = stream_socket_server('udp://127.0.0.1:'.$port, $udpError, $udpMessage, STREAM_SERVER_BIND);
    $holderTcp = stream_socket_server('tcp://127.0.0.1:'.$port, $tcpError, $tcpMessage);
    $server = new PrivateDnsListenerFactory()->make($catalog, '127.0.0.1', $port, '127.0.0.55:1');

    try {
        expect($holderUdp)->toBeResource()->and($holderTcp)->toBeResource();
        expect(fn () => new PrivateDnsSocketBinder(timeoutSeconds: 0.2, intervalSeconds: 0.02)->bind($server))
            ->toThrow(RuntimeException::class);
        expect($server->listening())->toBeFalse();
    } finally {
        if (is_resource($holderUdp)) {
            fclose($holderUdp);
        }
        if (is_resource($holderTcp)) {
            fclose($holderTcp);
        }
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
        'domain' => 'app.cluster.test',
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

function orb314_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    expect($socket)->toBeResource();
    $name = stream_socket_get_name($socket, false);
    expect($name)->toBeString();
    fclose($socket);

    return (int) substr($name, strrpos($name, ':') + 1);
}

function orb314_wait_until(callable $ready, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if ($ready()) {
            return true;
        }

        usleep(50_000);
    }

    return $ready();
}

function orb314_port_refuses(string $address, int $port): bool
{
    $socket = @stream_socket_client('tcp://'.$address.':'.$port, $error, $message, 0.2);
    if (is_resource($socket)) {
        fclose($socket);

        return false;
    }

    return $error === 111 || str_contains($message, 'Connection refused');
}

function orb314_port_answers(string $address, int $port): bool
{
    return ! orb314_port_refuses($address, $port);
}

function orb314_dig(string $server, int $port, string $name, string $transport): string
{
    $command = [
        'dig',
        '+time=2',
        '+tries=1',
        '+short',
        '-p',
        (string) $port,
        '@'.$server,
        $name,
        'A',
    ];
    if ($transport === 'tcp') {
        $command[] = '+tcp';
    }

    $process = new Process($command);
    $process->run();
    if (! $process->isSuccessful()) {
        expect($process->getErrorOutput()."\n".$process->getOutput())->toBe('');
    }

    return trim($process->getOutput());
}

/**
 * Registers one more requester, which changes the published catalog.
 */
function orb_catalog_change(): void
{
    Node::query()->create([
        'name' => 'catalog-change',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.99',
        'wireguard_ip' => '10.44.0.99',
        'user' => 'orbit',
    ]);
}
