<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsRequester;
use App\Domain\AppDev\PrivateDnsUpstream;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\CatalogPrivateDnsAnswerSelector;
use App\Infrastructure\AppDev\InMemoryPrivateDnsAnswerCache;
use App\Infrastructure\AppDev\PrivateDnsAnswerCatalog;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use App\Infrastructure\AppDev\PrivateDnsRequestHandler;
use App\Infrastructure\AppDev\PrivateDnsTransportServer;
use App\Infrastructure\AppDev\SocketPrivateDnsUpstream;
use App\Models\Node;
use Tests\Support\LoopbackRequesters;
use Tests\Support\RegisteredNodeDnsRequesterResolver;

it('answers UDP and TCP questions from the actual transport source', function (): void {
    $sources = LoopbackRequesters::detect();
    $registered = Node::query()->create([
        'name' => 'udp-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => $sources->first,
        'user' => 'orbit',
    ]);
    $server = orb258_transport_server(
        new PrivateDnsAnswerCatalog(
            exact: ['app.cluster.test' => '10.44.0.20'],
            suffixes: [],
        )
            ->withRequesterOverrides(DnsRequester::registered($registered->id, $sources->first)->cacheKey(), [
                'app.cluster.test' => '192.168.10.2',
            ]),
        listenAddress: $sources->listen,
    );

    try {
        $server->start();
        expect($server->listening())->toBeTrue();
        $udpRegistered = orb258_query($server, $sources, $sources->first, 'udp');
        $udpUnknown = orb258_query($server, $sources, $sources->second, 'udp');
        $tcpRegistered = orb258_query($server, $sources, $sources->first, 'tcp');
        $tcpUnknown = orb258_query($server, $sources, $sources->second, 'tcp');

        expect($udpRegistered)
            ->toBe('192.168.10.2')
            ->and($udpUnknown)
            ->toBe('10.44.0.20')
            ->and($tcpRegistered)
            ->toBe('192.168.10.2')
            ->and($tcpUnknown)
            ->toBe('10.44.0.20');
    } finally {
        $server->stop();
    }

    expect($server->listening())->toBeFalse();
});

it('keeps answering catalog names after a public-name forward whose upstream never replies', function (): void {
    $silent = stream_socket_server('udp://127.0.0.1:0', $error, $message, STREAM_SERVER_BIND);
    expect($silent)->toBeResource();
    $name = stream_socket_get_name($silent, false);
    expect($name)->toBeString();
    $port = (int) substr($name, strrpos($name, ':') + 1);
    $server = orb258_transport_server(
        catalog: new PrivateDnsAnswerCatalog(
            exact: ['gateway.orbit' => '10.44.0.1'],
            suffixes: [],
        ),
        upstream: new SocketPrivateDnsUpstream('127.0.0.1', $port, 0.2),
        ioTimeoutSeconds: 0.2,
    );

    try {
        $server->start();
        $public = orb313_open_udp_query($server, '127.0.0.1', 'example.com');
        $started = hrtime(true);
        $server->serveOnce(1.0);
        $forwardElapsed = (hrtime(true) - $started) / 1_000_000_000;
        $catalog = orb313_open_udp_query($server, '127.0.0.1', 'gateway.orbit');
        $server->serveOnce(1.0);

        expect($forwardElapsed)
            ->toBeLessThan(1.0)
            ->and(orb313_read_udp_address($catalog))
            ->toBe('10.44.0.1');
        socket_close($public);
    } finally {
        $server->stop();
        fclose($silent);
    }
});

it('drains every queued UDP query in one wake', function (): void {
    $sources = LoopbackRequesters::detect();
    $server = orb258_transport_server(new PrivateDnsAnswerCatalog(
        exact: ['gateway.orbit' => '10.44.0.1'],
        suffixes: [],
    ), listenAddress: $sources->listen);

    try {
        $server->start();
        $first = orb313_open_udp_query($server, $sources->first, 'gateway.orbit', $sources);
        $second = orb313_open_udp_query($server, $sources->second, 'gateway.orbit', $sources);
        $server->serveOnce(1.0);

        expect(orb313_read_udp_address($first))
            ->toBe('10.44.0.1')
            ->and(orb313_read_udp_address($second))
            ->toBe('10.44.0.1');
    } finally {
        $server->stop();
    }
});

it('does not let a stalled TCP client block a later UDP catalog query', function (): void {
    $server = orb258_transport_server(
        catalog: new PrivateDnsAnswerCatalog(
            exact: ['gateway.orbit' => '10.44.0.1'],
            suffixes: [],
        ),
        ioTimeoutSeconds: 0.2,
    );

    try {
        $server->start();
        $stalled = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        expect($stalled)->toBeInstanceOf(Socket::class);
        socket_connect($stalled, '127.0.0.1', $server->port());
        $started = hrtime(true);
        $server->serveOnce(1.0);
        $stalledElapsed = (hrtime(true) - $started) / 1_000_000_000;
        $catalog = orb313_open_udp_query($server, '127.0.0.1', 'gateway.orbit');
        $server->serveOnce(1.0);

        expect($stalledElapsed)
            ->toBeLessThan(1.0)
            ->and(orb313_read_udp_address($catalog))
            ->toBe('10.44.0.1');
        socket_close($stalled);
    } finally {
        $server->stop();
    }
});

it('does not let one UDP requester pollute another requesters TCP cache', function (): void {
    $sources = LoopbackRequesters::detect();
    $first = Node::query()->create([
        'name' => 'cache-one',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.3',
        'wireguard_ip' => $sources->first,
        'user' => 'orbit',
    ]);
    $second = Node::query()->create([
        'name' => 'cache-two',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.4',
        'wireguard_ip' => $sources->second,
        'user' => 'orbit',
    ]);
    $cache = new InMemoryPrivateDnsAnswerCache;
    $server = orb258_transport_server(
        new PrivateDnsAnswerCatalog(
            exact: ['app.cluster.test' => '10.44.0.20'],
            suffixes: [],
        )
            ->withRequesterOverrides(DnsRequester::registered($first->id, $sources->first)->cacheKey(), [
                'app.cluster.test' => '192.168.10.2',
            ])
            ->withRequesterOverrides(DnsRequester::registered($second->id, $sources->second)->cacheKey(), [
                'app.cluster.test' => '192.168.10.3',
            ]),
        $cache,
        listenAddress: $sources->listen,
    );

    try {
        $server->start();
        $firstUdp = orb258_query($server, $sources, $sources->first, 'udp');
        $secondTcp = orb258_query($server, $sources, $sources->second, 'tcp');
        $firstTcp = orb258_query($server, $sources, $sources->first, 'tcp');
        $cache->flush();
        $secondUdp = orb258_query($server, $sources, $sources->second, 'udp');

        expect($firstUdp)
            ->toBe('192.168.10.2')
            ->and($secondTcp)
            ->toBe('192.168.10.3')
            ->and($firstTcp)
            ->toBe('192.168.10.2')
            ->and($secondUdp)
            ->toBe('192.168.10.3');
    } finally {
        $server->stop();
    }
});

it('refuses an explicit port whose TCP side is taken and releases its UDP socket', function (): void {
    $taken = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
    expect($taken)->toBeResource();
    $name = (string) stream_socket_get_name($taken, false);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    $server = new PrivateDnsTransportServer(
        handler: new PrivateDnsRequestHandler(
            requesters: new RegisteredNodeDnsRequesterResolver,
            selector: new CatalogPrivateDnsAnswerSelector(new PrivateDnsAnswerCatalog(exact: [], suffixes: [])),
            cache: new InMemoryPrivateDnsAnswerCache,
        ),
        port: $port,
    );

    try {
        expect(fn () => $server->start())->toThrow(RuntimeException::class);
        expect($server->port())->toBe($port);
        $udp = stream_socket_server('udp://127.0.0.1:'.$port, $error, $message, STREAM_SERVER_BIND);
        expect($udp)->toBeResource();
        fclose($udp);
    } finally {
        fclose($taken);
    }
});

function orb258_transport_server(
    PrivateDnsAnswerCatalog $catalog,
    ?InMemoryPrivateDnsAnswerCache $cache = null,
    ?PrivateDnsUpstream $upstream = null,
    float $ioTimeoutSeconds = 2.0,
    string $listenAddress = '127.0.0.1',
): PrivateDnsTransportServer {
    return new PrivateDnsTransportServer(
        handler: new PrivateDnsRequestHandler(
            requesters: new RegisteredNodeDnsRequesterResolver,
            selector: new CatalogPrivateDnsAnswerSelector($catalog),
            cache: $cache ?? new InMemoryPrivateDnsAnswerCache,
            upstream: $upstream,
        ),
        listenAddress: $listenAddress,
        ioTimeoutSeconds: $ioTimeoutSeconds,
    );
}

function orb313_open_udp_query(
    PrivateDnsTransportServer $server,
    string $source,
    string $name,
    ?LoopbackRequesters $sources = null,
): Socket {
    $query = new PrivateDnsMessageCodec()->encodeQuery($name);

    if ($sources instanceof LoopbackRequesters) {
        $socket = $sources->socket($source, SOCK_DGRAM);
        $destination = $sources->destination;
    } else {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        expect($socket)->toBeInstanceOf(Socket::class);
        socket_bind($socket, $source, 0);
        $destination = '127.0.0.1';
    }

    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
    socket_sendto($socket, $query, strlen($query), 0, $destination, $server->port());

    return $socket;
}

function orb313_read_udp_address(Socket $socket): string
{
    $response = '';
    $from = '';
    $fromPort = 0;
    socket_recvfrom($socket, $response, 4096, 0, $from, $fromPort);
    socket_close($socket);
    $address = unpack('Nip', substr((string) $response, -4));

    return long2ip($address['ip'] ?? 0) ?: '';
}

function orb258_query(
    PrivateDnsTransportServer $server,
    LoopbackRequesters $sources,
    string $source,
    string $transport,
): string {
    $query = new PrivateDnsMessageCodec()->encodeQuery('app.cluster.test');

    if ($transport === 'tcp') {
        $socket = $sources->socket($source, SOCK_STREAM);
        socket_connect($socket, $sources->destination, $server->port());
        socket_write($socket, pack('n', strlen($query)).$query);
        $server->serveOnce(1.0);
        $header = socket_read($socket, 2);
        expect($header)->toBeString()->toHaveLength(2);
        $length = unpack('nlen', $header);
        $response = socket_read($socket, $length['len'] ?? 0);
        socket_close($socket);
    } else {
        $socket = $sources->socket($source, SOCK_DGRAM);
        socket_sendto($socket, $query, strlen($query), 0, $sources->destination, $server->port());
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
