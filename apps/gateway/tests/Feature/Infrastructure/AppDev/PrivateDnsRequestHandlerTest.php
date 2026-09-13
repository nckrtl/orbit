<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsRequester;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\CatalogPrivateDnsAnswerSelector;
use App\Infrastructure\AppDev\InMemoryPrivateDnsAnswerCache;
use App\Infrastructure\AppDev\PrivateDnsAnswerCatalog;
use App\Infrastructure\AppDev\PrivateDnsMessageCodec;
use App\Infrastructure\AppDev\PrivateDnsRequestHandler;
use App\Infrastructure\AppDev\WireGuardDnsRequesterResolver;
use App\Models\Node;

it('selects configured answers from the transport source and ignores DNS-content identity', function (): void {
    $registered = Node::query()->create([
        'name' => 'lan-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.11',
        'wireguard_ip' => '10.44.0.11',
        'user' => 'orbit',
    ]);
    $handler = orb258_handler(
        catalog: new PrivateDnsAnswerCatalog(
            exact: ['app.cluster.test' => '10.44.0.20'],
            suffixes: [],
        )->withRequesterOverrides(DnsRequester::registered($registered->id, '10.44.0.11')->cacheKey(), [
            'app.cluster.test' => '192.168.10.20',
        ]),
    );
    $codec = new PrivateDnsMessageCodec;
    $claimed = $codec->encodeQuery('app.cluster.test', ednsClientSubnet: '10.44.0.11');

    $unidentified = $handler->handle('192.0.2.55', $claimed);
    $registeredAnswer = $handler->handle('10.44.0.11', $codec->encodeQuery('app.cluster.test', ednsClientSubnet: '192.0.2.55'));

    expect(orb258_answer_address($unidentified))
        ->toBe('10.44.0.20')
        ->and(orb258_answer_address($registeredAnswer))
        ->toBe('192.168.10.20');
});

it('keeps answers for two sources out of each others server-side cache across interleaved queries and a restart', function (): void {
    $first = Node::query()->create([
        'name' => 'first-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.12',
        'wireguard_ip' => '10.44.0.12',
        'user' => 'orbit',
    ]);
    $second = Node::query()->create([
        'name' => 'second-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.13',
        'wireguard_ip' => '10.44.0.13',
        'user' => 'orbit',
    ]);
    $cache = new InMemoryPrivateDnsAnswerCache;
    $catalog = new PrivateDnsAnswerCatalog(
        exact: ['app.cluster.test' => '10.44.0.20'],
        suffixes: [],
    )
        ->withRequesterOverrides(DnsRequester::registered($first->id, '10.44.0.12')->cacheKey(), [
            'app.cluster.test' => '192.168.10.12',
        ])
        ->withRequesterOverrides(DnsRequester::registered($second->id, '10.44.0.13')->cacheKey(), [
            'app.cluster.test' => '192.168.10.13',
        ]);
    $handler = orb258_handler($catalog, $cache);
    $codec = new PrivateDnsMessageCodec;
    $query = fn (): string => $codec->encodeQuery('app.cluster.test');

    $firstAnswer = $handler->handle('10.44.0.12', $query());
    $secondAnswer = $handler->handle('10.44.0.13', $query());
    $firstAgain = $handler->handle('10.44.0.12', $query());
    $cache->flush();
    $afterRestart = $handler->handle('10.44.0.13', $query());
    $firstAfterRestart = $handler->handle('10.44.0.12', $query());

    expect(orb258_answer_address($firstAnswer))
        ->toBe('192.168.10.12')
        ->and(orb258_answer_address($secondAnswer))
        ->toBe('192.168.10.13')
        ->and(orb258_answer_address($firstAgain))
        ->toBe('192.168.10.12')
        ->and(orb258_answer_address($afterRestart))
        ->toBe('192.168.10.13')
        ->and(orb258_answer_address($firstAfterRestart))
        ->toBe('192.168.10.12');
});

it('returns the established WireGuard answer for every requester until LAN selection is configured', function (): void {
    Node::query()->create([
        'name' => 'vpn-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.14',
        'wireguard_ip' => '10.44.0.14',
        'user' => 'orbit',
    ]);
    $handler = orb258_handler(new PrivateDnsAnswerCatalog(
        exact: [
            'app.cluster.test' => '10.44.0.20',
            'gateway.orbit' => '10.44.0.1',
            'metrics.orbit' => '10.44.0.1',
        ],
        suffixes: ['solo.test' => '10.44.0.40'],
    ));
    $codec = new PrivateDnsMessageCodec;

    expect(orb258_answer_address($handler->handle('10.44.0.14', $codec->encodeQuery('app.cluster.test'))))
        ->toBe('10.44.0.20')
        ->and(orb258_answer_address($handler->handle('10.44.0.99', $codec->encodeQuery('gateway.orbit'))))
        ->toBe('10.44.0.1')
        ->and(orb258_answer_address($handler->handle('10.44.0.14', $codec->encodeQuery('metrics.orbit'))))
        ->toBe('10.44.0.1')
        ->and(orb258_answer_address($handler->handle('10.44.0.14', $codec->encodeQuery('site.solo.test'))))
        ->toBe('10.44.0.40');
});

function orb258_handler(
    PrivateDnsAnswerCatalog $catalog,
    ?InMemoryPrivateDnsAnswerCache $cache = null,
): PrivateDnsRequestHandler {
    return new PrivateDnsRequestHandler(
        requesters: new WireGuardDnsRequesterResolver,
        selector: new CatalogPrivateDnsAnswerSelector($catalog),
        cache: $cache ?? new InMemoryPrivateDnsAnswerCache,
    );
}

function orb258_answer_address(string $message): string
{
    $address = unpack('Nip', substr($message, -4));

    return long2ip($address['ip'] ?? 0) ?: '';
}
