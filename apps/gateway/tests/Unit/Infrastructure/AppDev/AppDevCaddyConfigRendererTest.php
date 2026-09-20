<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentServerEndpoint;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

it('adapts hibernation wake with a nested awake-marker matcher', function (): void {
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

it('binds a public Ingress proxy without an https prefix and preserves forwarded identity', function (): void {
    $configuration = new AppDevCaddyConfigRenderer()->render(collect([
        new AppDevSite(
            nodeId: 3,
            nodeAddress: '10.44.0.3',
            scope: 'route-9-ingress',
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: 'shop.example.test',
            upstreamAddresses: ['10.10.0.20'],
            certificateScope: 'route-9-ingress',
            publicListener: true,
            preserveForwardedIdentity: true,
        ),
    ]));

    expect($configuration)
        ->toContain('shop.example.test {')
        ->toContain('reverse_proxy https://10.10.0.20')
        ->toContain('header_up Host shop.example.test')
        ->toContain('header_up X-Forwarded-Proto https')
        ->toContain('header_up X-Forwarded-For {remote_host}')
        ->toContain('header_up X-Forwarded-Host shop.example.test')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->not->toContain('https://shop.example.test {');
});

it('measures a development site without waking it or counting the request as activity', function (): void {
    $configuration = new AppDevCaddyConfigRenderer()->render(collect([
        new AppDevSite(
            nodeId: 3,
            nodeAddress: '10.44.0.7',
            scope: 'app-instance-28',
            checkoutPath: '/fast/apps/dlf/best-practices',
            documentRoot: 'public',
            phpVersion: '8.5',
            domain: 'best-practices.dlf.test',
        ),
    ]));

    // Two rules, and losing either one breaks hibernation silently: a probe that reaches
    // forward_auth starts the Processes the sweep halted, and a probe that reaches the access
    // log keeps resetting the mtime the sweep reads as activity.
    expect($configuration)
        ->toContain('@orbit_probe header '.RuntimeHibernation::ProbeHeader.' 1')
        ->toContain('log_skip @orbit_probe')
        ->toContain('not header '.RuntimeHibernation::ProbeHeader.' 1');

    $asleep = Str::between($configuration, '@orbit_asleep {', '}');

    expect($asleep)->toContain('not header '.RuntimeHibernation::ProbeHeader.' 1');
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

it('publishes a production pool with round-robin, no replay, a 10s cooldown, and 503 on connection failure', function (): void {
    $configuration = new AppDevCaddyConfigRenderer()->render(collect([
        new AppDevSite(
            nodeId: 9,
            nodeAddress: '10.44.0.7',
            scope: 'route-4-router',
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: 'pool.example.test',
            upstreamAddresses: ['10.10.0.61', '10.10.0.62'],
        ),
    ]));

    expect($configuration)
        ->toContain('reverse_proxy https://10.10.0.61 https://10.10.0.62')
        ->toContain('lb_policy round_robin')
        ->toContain('lb_retries 0')
        ->toContain('fail_duration 10s')
        ->toContain('tls_server_name pool.example.test')
        ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
        ->toContain('@orbit_unavailable `{err.status_code} == 502`')
        ->toContain('respond "Orbit Route unavailable\n" 503')
        ->toContain('https://pool.example.test {')
        ->not->toContain('least_conn')
        ->not->toContain('{err.status_code} == 500');
});

it('composes a Router-local unix upstream with a remote HTTPS target without a self-proxy loop', function (): void {
    $configuration = new AppDevCaddyConfigRenderer()->render(collect([
        new AppDevSite(
            nodeId: 9,
            nodeAddress: '10.44.0.7',
            scope: 'route-4-router',
            checkoutPath: '/var/www/acme/current',
            documentRoot: 'public',
            phpVersion: '8.5',
            domain: 'pool.example.test',
            upstreamAddresses: ['10.10.0.62'],
            environment: 'production',
            productionUser: 'orbit-acme',
            productionHome: '/var/www/acme',
            appSlug: 'acme',
            productionPhpSocket: '/run/php/orbit-acme.sock',
            localUnixUpstream: 'unix//run/orbit/route-4-local.sock',
        ),
    ]));

    expect($configuration)
        ->toContain('http://unix//run/orbit/route-4-local.sock {')
        ->toContain('bind unix//run/orbit/route-4-local.sock')
        ->toContain('reverse_proxy unix//run/orbit/route-4-local.sock https://10.10.0.62')
        ->toContain('lb_policy round_robin')
        ->toContain('lb_retries 0')
        ->toContain('fail_duration 10s')
        ->not->toContain('https://unix/')
        ->not->toContain('reverse_proxy https://127.0.0.1')
        ->not->toContain('https://10.44.0.7');
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

it('proxies a local HTTP custom proxy site without Host rewrite or an https hop', function (): void {
    $configuration = new AppDevCaddyConfigRenderer()->render(collect([
        new AppDevSite(
            nodeId: 4,
            nodeAddress: '10.44.0.4',
            scope: 'route-11',
            checkoutPath: '',
            documentRoot: '',
            phpVersion: null,
            domain: 'executor.orbit',
            certificateScope: 'route-11',
            localHttpUpstream: '127.0.0.1:4788',
        ),
    ]));

    expect($configuration)
        ->toContain('https://executor.orbit {')
        ->toContain('reverse_proxy 127.0.0.1:4788 {')
        ->toContain('flush_interval -1')
        ->not->toContain('header_up Host')
        ->not->toContain('reverse_proxy https://')
        ->not->toContain('tls_server_name')
        ->not->toContain('tls_trusted_ca_certs');
});

it('proxies the reserved Agentation path only when a port is assigned', function (): void {
    $without = new AppDevCaddyConfigRenderer()->render(collect([
        development_server_site('commander.test', '/apps/commander'),
    ]));
    $with = new AppDevCaddyConfigRenderer()->render(collect([
        new AppDevSite(
            nodeId: 12,
            nodeAddress: '10.44.0.10',
            scope: 'app-instance-6',
            checkoutPath: '/apps/commander',
            documentRoot: 'public',
            phpVersion: '8.5',
            domain: 'commander.test',
            agentationPort: 4747,
        ),
    ]));

    expect($without)
        ->not->toContain('/__orbit/agentation')
        ->and($with)
        ->toContain('path /__orbit/agentation /__orbit/agentation/*')
        ->toContain('uri strip_prefix /__orbit/agentation')
        ->toContain('reverse_proxy 127.0.0.1:4747')
        ->and(caddy_adapt($with)->succeeded())
        ->toBeTrue();
});

it('keeps assigned Vite endpoints separate and preserves their base path', function (): void {
    $sites = collect([
        new AppDevSite(nodeId: 1, nodeAddress: '10.44.0.2', scope: 'app-instance-10', checkoutPath: '/apps/first', documentRoot: 'public', phpVersion: null, domain: 'first.test', vitePort: 5174),
        new AppDevSite(nodeId: 1, nodeAddress: '10.44.0.2', scope: 'app-instance-11', checkoutPath: '/apps/second', documentRoot: 'public', phpVersion: null, domain: 'second.test', vitePort: 5210),
    ]);
    $config = new AppDevCaddyConfigRenderer()->render($sites);
    expect($config)->toContain('reverse_proxy 127.0.0.1:5174')->toContain('reverse_proxy 127.0.0.1:5210')->not->toContain('uri strip_prefix');
    expect(caddy_adapt($config)->succeeded())->toBeTrue();
});

describe('analytics tracking site', function (): void {
    it('proxies only the script and event paths on a Router behind a separate Ingress', function (): void {
        $configuration = new AppDevCaddyConfigRenderer()->render(collect([
            new AppDevSite(
                nodeId: 3,
                nodeAddress: '10.45.0.20',
                scope: 'route-91-router',
                checkoutPath: '',
                documentRoot: '',
                phpVersion: null,
                domain: 'analytics.shop.example.com',
                analyticsUpstream: '10.44.0.40:8000',
                analyticsTrustedProxies: ['10.10.0.30', '10.44.0.30'],
            ),
        ]));

        expect($configuration)->toBe(<<<'CADDY'
            https://analytics.shop.example.com {
                bind 0.0.0.0
                tls /etc/caddy/orbit-certificates/route-91-router/current/cert.pem /etc/caddy/orbit-certificates/route-91-router/current/key.pem
                handle /js/* {
                    reverse_proxy http://10.44.0.40:8000 {
                        trusted_proxies 10.10.0.30 10.44.0.30
                    }
                }
                handle /api/event {
                    reverse_proxy http://10.44.0.40:8000 {
                        trusted_proxies 10.10.0.30 10.44.0.30
                    }
                }
                respond 404
            }

            CADDY);

        $adapted = caddy_adapt($configuration);

        expect($adapted->succeeded())->toBeTrue()
            ->and($adapted->stdout)
            ->toContain('"/js/*"')
            ->toContain('"/api/event"')
            ->toContain('"status_code":404')
            ->toContain('"trusted_proxies":["10.10.0.30","10.44.0.30"]');
    });

    it('serves the public listener itself when the Router is also the Ingress', function (): void {
        $configuration = new AppDevCaddyConfigRenderer()->render(collect([
            new AppDevSite(
                nodeId: 3,
                nodeAddress: '10.45.0.20',
                scope: 'route-91-ingress',
                checkoutPath: '',
                documentRoot: '',
                phpVersion: null,
                domain: 'analytics.shop.example.com',
                certificateScope: 'route-91-ingress',
                publicListener: true,
                analyticsUpstream: '10.44.0.40:8000',
            ),
        ]));

        expect($configuration)->toBe(<<<'CADDY'
            analytics.shop.example.com {
                bind 0.0.0.0
                tls /etc/caddy/orbit-certificates/route-91-ingress/current/cert.pem /etc/caddy/orbit-certificates/route-91-ingress/current/key.pem
                handle /js/* {
                    reverse_proxy http://10.44.0.40:8000
                }
                handle /api/event {
                    reverse_proxy http://10.44.0.40:8000
                }
                respond 404
            }

            CADDY)
            ->and(caddy_adapt($configuration)->succeeded())->toBeTrue();
    });
});
