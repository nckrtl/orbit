<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsRequester;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\CatalogPrivateDnsAnswerSelector;
use App\Infrastructure\AppDev\InMemoryPrivateDnsAnswerCache;
use App\Infrastructure\AppDev\PrivateDnsAnswerCatalog;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use App\Infrastructure\AppDev\PrivateDnsRequestHandler;
use App\Infrastructure\AppDev\PrivateDnsTransportServer;
use App\Infrastructure\AppDev\WireGuardDnsRequesterResolver;
use App\Models\Node;

it('answers UDP and TCP questions from the actual transport source', function (): void {
    $registered = Node::query()->create([
        'name' => 'udp-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '127.0.0.2',
        'user' => 'orbit',
    ]);
    $server = orb258_transport_server(
        new PrivateDnsAnswerCatalog(
            exact: ['app.cluster.test' => '10.44.0.20'],
            suffixes: [],
        )
            ->withRequesterOverrides(DnsRequester::registered($registered->id, '127.0.0.2')->cacheKey(), [
                'app.cluster.test' => '192.168.10.2',
            ]),
    );

    try {
        $server->start();
        expect($server->listening())->toBeTrue();
        $udpRegistered = orb258_query($server, '127.0.0.2', 'udp');
        $udpUnknown = orb258_query($server, '127.0.0.1', 'udp');
        $tcpRegistered = orb258_query($server, '127.0.0.2', 'tcp');
        $tcpUnknown = orb258_query($server, '127.0.0.1', 'tcp');

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

it('does not let one UDP requester pollute another requesters TCP cache', function (): void {
    $first = Node::query()->create([
        'name' => 'cache-one',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.3',
        'wireguard_ip' => '127.0.0.2',
        'user' => 'orbit',
    ]);
    $second = Node::query()->create([
        'name' => 'cache-two',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.4',
        'wireguard_ip' => '127.0.0.3',
        'user' => 'orbit',
    ]);
    $cache = new InMemoryPrivateDnsAnswerCache;
    $server = orb258_transport_server(
        new PrivateDnsAnswerCatalog(
            exact: ['app.cluster.test' => '10.44.0.20'],
            suffixes: [],
        )
            ->withRequesterOverrides(DnsRequester::registered($first->id, '127.0.0.2')->cacheKey(), [
                'app.cluster.test' => '192.168.10.2',
            ])
            ->withRequesterOverrides(DnsRequester::registered($second->id, '127.0.0.3')->cacheKey(), [
                'app.cluster.test' => '192.168.10.3',
            ]),
        $cache,
    );

    try {
        $server->start();
        $firstUdp = orb258_query($server, '127.0.0.2', 'udp');
        $secondTcp = orb258_query($server, '127.0.0.3', 'tcp');
        $firstTcp = orb258_query($server, '127.0.0.2', 'tcp');
        $cache->flush();
        $secondUdp = orb258_query($server, '127.0.0.3', 'udp');

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

function orb258_transport_server(
    PrivateDnsAnswerCatalog $catalog,
    ?InMemoryPrivateDnsAnswerCache $cache = null,
): PrivateDnsTransportServer {
    return new PrivateDnsTransportServer(
        handler: new PrivateDnsRequestHandler(
            requesters: new WireGuardDnsRequesterResolver,
            selector: new CatalogPrivateDnsAnswerSelector($catalog),
            cache: $cache ?? new InMemoryPrivateDnsAnswerCache,
        ),
    );
}

function orb258_query(PrivateDnsTransportServer $server, string $source, string $transport): string
{
    $query = new PrivateDnsMessageCodec()->encodeQuery('app.cluster.test');

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
