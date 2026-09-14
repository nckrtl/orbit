<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentServerEndpoint;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use Illuminate\Support\Collection;

it('proxies the reserved development-server path to loopback on a development site', function (): void {
    $configuration = new AppDevCaddyConfigRenderer()->render(collect([
        development_server_site('tasks.commander.test', '/home/orbit/apps/tasks'),
    ]));

    $socket = 'php_fastcgi unix//run/php/orbit-app-instance-6.sock';

    expect($configuration)
        ->toContain('https://tasks.commander.test {')
        ->toContain('path /__orbit/vite /__orbit/vite/*')
        ->toContain('uri strip_prefix /__orbit/vite')
        ->toContain('reverse_proxy 127.0.0.1:5173')
        ->toContain($socket)
        ->toContain('root /dev/shm/orbit/hibernation')
        ->toContain('try_files /app-instance-6.awake')
        ->toContain('handle @orbit_asleep')
        ->toContain('uri /api/v1/runtime-activations/app-instance/6')
        ->toContain('output file /data/caddy/orbit/hibernation/app-instance-6.log')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('tls_trust_pool')
        ->not->toContain('not file /dev/shm/orbit/hibernation/app-instance-6.awake')
        ->not->toContain('/etc/caddy/orbit-certificates/app-instance-6/current/root.pem')
        ->not->toContain('reverse_proxy https://');
    expect(mb_strpos($configuration, 'handle @orbit_asleep'))
        ->toBeInt()
        ->toBeLessThan((int) mb_strpos($configuration, 'handle @orbit_vite'))
        ->and(mb_strpos($configuration, 'handle @orbit_vite'))
        ->toBeInt()
        ->toBeLessThan((int) mb_strpos($configuration, $socket));
});

it('shows the Orbit wake page before Caddy proxies a sleeping site', function (): void {
    $configuration = new AppDevCaddyConfigRenderer()->render(collect([
        development_server_site('tasks.commander.test', '/home/orbit/apps/tasks'),
    ]));

    expect($configuration)
        ->toContain("not file {\n        root /dev/shm/orbit/hibernation\n        try_files /app-instance-6.awake")
        ->toContain('handle @orbit_asleep')
        ->toContain('forward_auth https://gateway.orbit')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('tls_trust_pool')
        ->not->toContain('not file /dev/shm/orbit/hibernation/app-instance-6.awake');
    expect(mb_strpos($configuration, 'handle @orbit_asleep'))
        ->toBeInt()
        ->toBeLessThan((int) mb_strpos($configuration, 'handle @orbit_vite'));
});

it('adapts hibernation wake on Caddy 2.6 with a nested awake-marker matcher', function (): void {
    $configuration = new AppDevCaddyConfigRenderer()->render(collect([
        development_server_site('tasks.commander.test', '/home/orbit/apps/tasks'),
    ]));

    $result = caddy_adapt($configuration);

    expect($result->succeeded())
        ->toBeTrue()
        ->and($result->stdout)
        ->toContain('/dev/shm/orbit/hibernation')
        ->toContain('/app-instance-6.awake')
        ->toContain('/usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('tls_trust_pool');
});

it('keeps two development sites isolated on the same loopback port', function (): void {
    $configuration = new AppDevCaddyConfigRenderer()->render(collect([
        development_server_site('alpha.example.test', '/home/orbit/apps/alpha', nodeId: 12, scope: 'app-instance-6'),
        development_server_site('beta.example.test', '/home/orbit/apps/beta', nodeId: 13, scope: 'app-instance-7'),
    ]));

    expect(mb_substr_count($configuration, 'https://alpha.example.test {'))
        ->toBe(1)
        ->and(mb_substr_count($configuration, 'https://beta.example.test {'))
        ->toBe(1)
        ->and(mb_substr_count($configuration, 'reverse_proxy 127.0.0.1:5173'))
        ->toBe(2)
        ->and($configuration)
        ->toContain('root * /home/orbit/apps/alpha/public')
        ->toContain('root * /home/orbit/apps/beta/public')
        ->toContain('try_files /app-instance-6.awake')
        ->toContain('try_files /app-instance-7.awake')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('tls_trust_pool')
        ->not->toContain('current/root.pem')
        ->not->toContain('reverse_proxy https://');
});

it('does not attach the development-server handle to production, proxy, or unavailable sites', function (): void {
    $production = new AppDevSite(
        nodeId: 1,
        nodeAddress: '10.44.0.10',
        scope: 'app-instance-1',
        checkoutPath: '/var/www/acme/current',
        documentRoot: 'public',
        phpVersion: '8.5',
        domain: 'acme.example.test',
        environment: 'production',
        productionPhpSocket: '/run/php/orbit-acme.sock',
    );
    $proxy = new AppDevSite(
        nodeId: 9,
        nodeAddress: '10.44.0.7',
        scope: 'route-4-router',
        checkoutPath: '',
        documentRoot: '',
        phpVersion: null,
        domain: 'tasks.commander.test',
        upstreamAddresses: ['10.44.0.10'],
    );
    $unavailable = new AppDevSite(
        nodeId: 9,
        nodeAddress: '10.44.0.7',
        scope: 'route-4-router',
        checkoutPath: '',
        documentRoot: '',
        phpVersion: null,
        domain: 'gone.commander.test',
        unavailable: true,
    );

    $renderer = new AppDevCaddyConfigRenderer;
    $productionConfig = $renderer->render(new Collection([$production]));
    $proxyConfig = $renderer->render(new Collection([$proxy]));
    $unavailableConfig = $renderer->render(new Collection([$unavailable]));

    expect($productionConfig)
        ->not->toContain('forward_auth')
        ->not->toContain('orbit_asleep');
    expect($proxyConfig)
        ->not->toContain('forward_auth');
    expect($unavailableConfig)
        ->not->toContain('forward_auth');

    expect($productionConfig)
        ->not->toContain('/__orbit/vite')
        ->not->toContain('reverse_proxy 127.0.0.1:5173')
        ->and($proxyConfig)
        ->toContain('reverse_proxy https://10.44.0.10')
        ->not->toContain('reverse_proxy 127.0.0.1:5173')
        ->not->toContain('/__orbit/vite')
        ->and($unavailableConfig)
        ->toContain('Orbit Route unavailable')
        ->not->toContain('/__orbit/vite')
        ->not->toContain('reverse_proxy');
});

it('exposes one origin URL and loopback upstream for frontend configuration', function (): void {
    expect(DevelopmentServerEndpoint::origin('tasks.commander.test'))
        ->toBe('https://tasks.commander.test/__orbit/vite')
        ->and(DevelopmentServerEndpoint::upstream())
        ->toBe('127.0.0.1:5173')
        ->and(DevelopmentServerEndpoint::PATH)
        ->toBe('/__orbit/vite')
        ->and(DevelopmentServerEndpoint::PORT)
        ->toBe(5173);
});

function development_server_site(
    string $domain,
    string $checkoutPath,
    int $nodeId = 12,
    string $scope = 'app-instance-6',
): AppDevSite {
    return new AppDevSite(
        nodeId: $nodeId,
        nodeAddress: '10.44.0.10',
        scope: $scope,
        checkoutPath: $checkoutPath,
        documentRoot: 'public',
        phpVersion: '8.5',
        domain: $domain,
    );
}
