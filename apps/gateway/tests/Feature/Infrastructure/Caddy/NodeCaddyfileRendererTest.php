<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Herdr\HerdrSessionManagement;
use App\Domain\Nodes\RoleName;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddyfile;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\AppCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\GatewayWebCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\HerdrObserverCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\MetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\ProxyCliCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\ServiceMetricsCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\Gateway\GatewayCaddyConfigRenderer;
use App\Infrastructure\Metrics\MetricsPublicationRenderer;
use App\Infrastructure\Metrics\ServiceMetricsConfigRenderer;
use App\Infrastructure\WebSocket\WebSocketCaddySiteRenderer;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\HerdrSession;
use App\Models\Node;
use App\Models\Route;
use Symfony\Component\Process\ExecutableFinder;

describe('the Node Caddyfile', function (): void {
    it('starts with the Orbit marker line and Orbit global options', function (): void {
        $node = caddy_build_node('empty', '10.44.0.2');

        $caddyfile = caddy_build_renderer()->render($node);

        expect($caddyfile->content)->toBe(NodeCaddyfileRenderer::Marker.PHP_EOL.CaddyGlobalOptions::render())
            ->and($caddyfile->buildable())->toBeTrue()
            ->and($caddyfile->sites)->toBe([]);
    });

    it('renders the same bytes for the same state and names the version by their digest', function (): void {
        $node = caddy_build_node('ws', '10.44.0.2');
        $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);

        $first = caddy_build_renderer()->render($node);
        $second = caddy_build_renderer()->render($node->fresh() ?? $node);

        expect($second->content)->toBe($first->content)
            ->and($second->version)->toBe($first->version)
            ->and($first->version)->toBe(substr(hash('sha256', $first->content), 0, 32));

        $node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Active]);

        expect(caddy_build_renderer()->render($node)->version)->not->toBe($first->version);
    });

    it('turns a site source that cannot render into a build problem', function (): void {
        $node = caddy_build_node('analytics', null);
        $node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Active]);

        $caddyfile = caddy_build_renderer()->render($node);

        expect($caddyfile->buildable())->toBeFalse()
            ->and($caddyfile->problems)->toBe([
                'The analytics site source needs the WireGuard IPv4 address of Node [analytics].',
            ]);
    });

    it('validates as one Caddyfile when a Caddy binary is installed', function (): void {
        if (new ExecutableFinder()->find('caddy') === null) {
            $this->markTestSkipped('Caddy is not installed.');
        }

        $node = caddy_build_node('ws', '10.44.0.2');
        $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        caddy_build_private_route($node, 'shop.test');

        expect(caddy_adapt(caddy_build_renderer()->render($node)->content)->succeeded())->toBeTrue();
    });
});

describe('site sources', function (): void {
    it('renders the Gateway web site on the gateway Node, bound to its WireGuard address', function (): void {
        $node = caddy_build_node('gateway', '10.44.0.1');
        $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $expected = new GatewayCaddyConfigRenderer()->render('gateway.orbit', '10.44.0.1', '/srv/gateway', '/srv/web');

        $caddyfile = caddy_build_renderer()->render($node);

        expect($caddyfile->content)->toContain("# orbit: gateway gateway.orbit\n".rtrim($expected)."\n")
            ->and($caddyfile->sites[0]->listener)->toBe(CaddyListenerRule::WireGuard)
            ->and($caddyfile->sites[0]->hosts)->toBe(['gateway.orbit', '10.44.0.1']);
    });

    it('renders metrics.orbit on the gateway Node for the Metrics Node', function (): void {
        $gateway = caddy_build_node('gateway', '10.44.0.1');
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $metrics = caddy_build_node('metrics', '10.44.0.5');
        $metrics->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Provisioning]);

        $caddyfile = caddy_build_renderer()->render($gateway);

        expect($caddyfile->content)->toContain(rtrim(new MetricsPublicationRenderer()->caddy('10.44.0.5', '10.44.0.1')))
            ->and(caddy_build_renderer()->render($metrics)->content)->not->toContain('metrics.orbit');
    });

    it('renders the service metrics scrape site on a selected Ingress Node', function (): void {
        $metrics = caddy_build_node('metrics', '10.44.0.5');
        $metrics->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
        $cluster = Cluster::query()->create(['name' => 'production', 'state' => ClusterState::Active]);
        $ingress = caddy_build_node('edge', '10.44.0.6');
        $ingress->update(['cluster_id' => $cluster->id]);
        $ingress->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
        $ingress->roles()->create(['role' => RoleName::Ingress, 'status' => LifecycleStatus::Active, 'cluster_id' => $cluster->id]);
        $app = OrbitApp::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://example.test/shop.git', 'root' => 'public']);
        $instance = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $ingress->id,
            'name' => 'default',
            'environment' => 'production',
            'checkout_path' => '/var/www/shop',
            'root' => 'public',
            'status' => AppInstanceState::Active,
        ]);
        $route = Route::query()->create([
            'app_id' => $app->id,
            'cluster_id' => $cluster->id,
            'domain' => 'shop.example.test',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
        $route->update(['publication' => RoutePublication::Public, 'status' => RouteStatus::Active]);
        $renderer = new NodeCaddyfileRenderer([app(ServiceMetricsCaddySiteSource::class)]);

        $caddyfile = $renderer->render($ingress);

        expect($caddyfile->content)->toContain(rtrim(new ServiceMetricsConfigRenderer()->caddy('10.44.0.6', '10.44.0.5')))
            ->and($caddyfile->sites[0]->port)->toBe(9103)
            ->and($renderer->render($metrics)->sites)->toBe([]);
    });

    it('renders workload and Router sites of a private Route with the existing renderer', function (): void {
        $router = caddy_build_node('router', '10.44.0.1');
        [$instance, $route] = caddy_build_private_route($router, 'shop.test');
        $workload = $instance->node;
        $renderer = new AppDevCaddyConfigRenderer;
        $repository = new AppDevSiteRepository;

        $routerFile = caddy_build_renderer()->render($router);
        $workloadFile = caddy_build_renderer()->render($workload);

        expect($routerFile->content)->toContain(
            "# orbit: app-dev route-{$route->id}-router\n".rtrim($renderer->render($repository->forNode($router), '10.44.0.1'))."\n",
        )
            ->and($workloadFile->content)->toContain(
                "# orbit: app-dev app-instance-{$instance->id}\n".rtrim($renderer->render($repository->forNode($workload), '10.44.0.30'))."\n",
            )
            ->and($routerFile->sites[0]->listener)->toBe(CaddyListenerRule::Wildcard);
    });

    it('classifies public, production, and composed sites and keeps unix listeners', function (): void {
        $source = new AppCaddySiteSource(new AppDevSiteRepository, new AppDevCaddyConfigRenderer);

        $sites = $source->fromSites(collect([
            caddy_build_site('shop.example.com', 'route-1-ingress', publicListener: true),
            caddy_build_site('shop.test', 'app-instance-2', environment: 'production'),
            caddy_build_site('pool.test', 'route-3-router', localUnixUpstream: 'unix//run/orbit/route-3-local.sock'),
        ]));

        expect(array_map(static fn (CaddySite $site): array => [$site->source, $site->name, $site->listener], $sites))->toBe([
            ['app-dev', 'route-3-router', CaddyListenerRule::Wildcard],
            ['ingress', 'route-1-ingress', CaddyListenerRule::Public],
            ['app-prod', 'app-instance-2', CaddyListenerRule::Wildcard],
        ])
            ->and($sites[0]->unixSockets)->toBe(['unix//run/orbit/route-3-local.sock'])
            ->and($sites[1]->body)->toContain('tls force_automate');
    });

    it('renders the websocket, analytics, and ProxyCli sites on their Node', function (): void {
        $node = caddy_build_node('services', '10.44.0.7');
        $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        $node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Active]);
        app(ProxyCliState::class)->enable($node->id, 'cache', 'https://cliproxy.test', 'management', 'read', 'control');

        $content = caddy_build_renderer()->render($node)->content;

        expect($content)
            ->toContain("reverb.orbit {\n    bind 10.44.0.7\n")
            ->toContain("analytics.orbit {\n    bind 10.44.0.7\n")
            ->toContain("collector.proxycli.orbit {\n    bind 10.44.0.7\n")
            ->not->toContain('__ORBIT_');
    });

    it('renders a Herdr observer while it is published and not being removed', function (): void {
        $node = caddy_build_node('agents', '10.44.0.8');
        $published = caddy_build_herdr_session($node, 'main', 'published');
        caddy_build_herdr_session($node, 'failed', 'failed');
        $removing = caddy_build_herdr_session($node, 'leaving', 'published');
        $removing->update(['status' => LifecycleStatus::Removing]);

        $caddyfile = caddy_build_renderer()->render($node);

        expect(array_map(static fn (CaddySite $site): string => $site->name, $caddyfile->sites))->toBe(['main'])
            ->and($caddyfile->content)->toContain("https://{$published->observer_hostname} {\n    bind 10.44.0.8\n");
    });

    it('renders a role while it converges, is active, or failed a reconvergence, but not while it is removed', function (): void {
        $node = caddy_build_node('ws', '10.44.0.2');
        $role = $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Provisioning]);
        $serves = static fn (): bool => str_contains(caddy_build_renderer()->render($node)->content, 'reverb.orbit');

        expect($serves())->toBeTrue();
        $role->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'converge:websocket-caddy']);
        expect($serves())->toBeTrue();
        $role->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'websocket-caddy']);
        expect($serves())->toBeFalse();
        $role->update(['status' => LifecycleStatus::Removing, 'failed_step' => null]);
        expect($serves())->toBeFalse();
    });
});

describe('listener selection', function (): void {
    it('binds first-row sites to the WireGuard and LAN addresses on a Node without ingress', function (): void {
        $caddyfile = caddy_build_compose(
            [caddy_build_rendered_site(CaddyListenerRule::Wildcard, host: 'shop.test', source: 'app-dev')],
            lan: '192.168.1.9',
        );

        expect($caddyfile->content)->toContain("shop.test {\n    bind 10.44.0.9 192.168.1.9\n")
            ->and($caddyfile->content)->not->toContain('0.0.0.0');
    });

    it('binds first-row sites to every address on an Ingress Node', function (): void {
        $caddyfile = caddy_build_compose(
            [caddy_build_rendered_site(CaddyListenerRule::Wildcard, host: 'shop.test', source: 'app-dev')],
            lan: '192.168.1.9',
            ingress: true,
        );

        expect($caddyfile->content)->toContain("shop.test {\n    bind 0.0.0.0\n");
    });

    it('binds a shared site to the WireGuard address when no wildcard site shares its port', function (): void {
        $site = caddy_build_rendered_site(CaddyListenerRule::Shared);

        expect(caddy_build_compose([$site])->content)->toContain('bind 10.44.0.9');
    });

    it('keeps a shared site on WireGuard beside first-row sites on a Node without ingress', function (): void {
        $caddyfile = caddy_build_compose([
            caddy_build_rendered_site(CaddyListenerRule::Wildcard, host: 'shop.test', source: 'app-dev'),
            caddy_build_rendered_site(CaddyListenerRule::Shared),
        ]);

        expect($caddyfile->content)->toContain("reverb.orbit {\n    bind 10.44.0.9\n")
            ->and($caddyfile->buildable())->toBeTrue();
    });

    it('binds a shared site to every address beside first-row sites on an Ingress Node', function (): void {
        $caddyfile = caddy_build_compose([
            caddy_build_rendered_site(CaddyListenerRule::Wildcard, host: 'shop.test', source: 'app-dev'),
            caddy_build_rendered_site(CaddyListenerRule::Shared),
        ], ingress: true);

        expect($caddyfile->content)->toContain("reverb.orbit {\n    bind 0.0.0.0")
            ->and($caddyfile->buildable())->toBeTrue();
    });

    it('keeps a shared site on WireGuard beside public Ingress sites', function (): void {
        $caddyfile = caddy_build_compose([
            caddy_build_rendered_site(CaddyListenerRule::Public, host: 'shop.example.com', source: 'ingress'),
            caddy_build_rendered_site(CaddyListenerRule::Shared),
        ], ingress: true);

        expect($caddyfile->content)->toContain("reverb.orbit {\n    bind 10.44.0.9");
    });

    it('lets a WireGuard-only site share port 443 with public Ingress sites', function (): void {
        $caddyfile = caddy_build_compose([
            caddy_build_rendered_site(CaddyListenerRule::Public, host: 'shop.example.com', source: 'ingress'),
            caddy_build_rendered_site(CaddyListenerRule::WireGuard, host: 'gateway.orbit', source: 'gateway'),
        ], ingress: true);

        expect($caddyfile->buildable())->toBeTrue();
    });

    it('builds a Gateway that is also the Router, with the Router sites on its WireGuard and LAN addresses', function (): void {
        $gateway = caddy_build_node('gateway', '10.44.0.1');
        $gateway->update(['lan_ip' => '192.168.1.1']);
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $gateway->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        [, $route] = caddy_build_private_route($gateway, 'shop.test');

        $caddyfile = caddy_build_renderer()->render($gateway->fresh() ?? $gateway);

        expect($caddyfile->problems)->toBe([])
            ->and($caddyfile->content)
            ->toContain("gateway.orbit, 10.44.0.1 {\n    bind 10.44.0.1\n")
            ->toContain("# orbit: app-dev route-{$route->id}-router\nhttps://shop.test {\n    bind 10.44.0.1 192.168.1.1\n")
            ->toContain("reverb.orbit {\n    bind 10.44.0.1\n")
            ->not->toContain('0.0.0.0');
    });

    it('refuses a WireGuard-only site beside a first-row site on an Ingress Node and names both', function (): void {
        $gateway = caddy_build_node('gateway', '10.44.0.1');
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        [, $route] = caddy_build_private_route($gateway, 'shop.test');
        $gateway->roles()->create(['role' => RoleName::Ingress, 'status' => LifecycleStatus::Active, 'cluster_id' => $route->cluster_id]);

        $caddyfile = caddy_build_renderer()->render($gateway);

        expect($caddyfile->buildable())->toBeFalse()
            ->and($caddyfile->problems)->toBe([
                'The gateway site gateway.orbit binds the WireGuard address on port 443, which the '
                ."app-dev site route-{$route->id}-router serves on 0.0.0.0 on this Ingress Node. The app-dev site would be unreachable over WireGuard.",
            ]);
    });

    it('allows a WireGuard-only site beside a first-row site on another port of an Ingress Node', function (): void {
        $caddyfile = caddy_build_compose([
            caddy_build_rendered_site(CaddyListenerRule::Wildcard, host: 'shop.test', source: 'app-dev'),
            caddy_build_rendered_site(CaddyListenerRule::WireGuard, host: '10.44.0.9', source: 'service-metrics', port: 9103),
        ], ingress: true);

        expect($caddyfile->buildable())->toBeTrue();
    });
});

describe('duplicate addresses', function (): void {
    it('refuses two sites with the same address and names both sources', function (): void {
        $gateway = caddy_build_node('gateway', '10.44.0.1');
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        caddy_build_node('metrics-a', '10.44.0.5')->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
        caddy_build_node('metrics-b', '10.44.0.6')->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);

        $caddyfile = caddy_build_renderer()->render($gateway);

        expect($caddyfile->problems)->toBe([
            'The metrics site metrics.orbit and the metrics site metrics.orbit both serve metrics.orbit:443 on 10.44.0.1.',
        ]);
    });

    it('refuses a public Ingress site and a private site for one domain on one Node', function (): void {
        $source = new AppCaddySiteSource(new AppDevSiteRepository, new AppDevCaddyConfigRenderer);
        $sites = $source->fromSites(collect([
            caddy_build_site('Shop.example.com', 'route-1-ingress', publicListener: true),
            caddy_build_site('shop.example.com', 'route-1-router'),
        ]));

        expect(caddy_build_compose($sites, ingress: true)->problems)->toBe([
            'The ingress site route-1-ingress and the app-dev site route-1-router both serve shop.example.com:443 on 0.0.0.0.',
        ]);
    });

    it('treats the same domain on different listeners as different addresses', function (): void {
        $caddyfile = caddy_build_compose([
            caddy_build_rendered_site(CaddyListenerRule::WireGuard, host: 'app.test', source: 'gateway', port: 8443),
            caddy_build_rendered_site(CaddyListenerRule::Public, host: 'app.test', source: 'ingress', port: 8443),
        ], ingress: true);

        expect($caddyfile->buildable())->toBeTrue();
    });

    it('refuses two sites on one unix socket', function (): void {
        $source = new AppCaddySiteSource(new AppDevSiteRepository, new AppDevCaddyConfigRenderer);
        $sites = $source->fromSites(collect([
            caddy_build_site('a.test', 'route-3-router', localUnixUpstream: 'unix//run/orbit/route-3-local.sock'),
            caddy_build_site('b.test', 'route-4-router', localUnixUpstream: 'unix//run/orbit/route-3-local.sock'),
        ]));

        expect(caddy_build_compose($sites)->problems)->toBe([
            'The app-dev site route-3-router and the app-dev site route-4-router both serve unix//run/orbit/route-3-local.sock.',
        ]);
    });
});

function caddy_build_renderer(): NodeCaddyfileRenderer
{
    return new NodeCaddyfileRenderer([
        new GatewayWebCaddySiteSource(new GatewayCaddyConfigRenderer, app(VpnSettings::class), '/srv/gateway', '/srv/web'),
        new MetricsCaddySiteSource,
        app(ServiceMetricsCaddySiteSource::class),
        new AppCaddySiteSource(new AppDevSiteRepository, new AppDevCaddyConfigRenderer),
        new WebSocketCaddySiteSource(new WebSocketCaddySiteRenderer, 8080),
        new AnalyticsCaddySiteSource,
        app(ProxyCliCaddySiteSource::class),
        new HerdrObserverCaddySiteSource,
    ]);
}

/** @param list<CaddySite> $sites */
function caddy_build_compose(array $sites, ?string $lan = null, bool $ingress = false): NodeCaddyfile
{
    $source = new readonly class($sites) implements NodeCaddySiteSource
    {
        /** @param list<CaddySite> $sites */
        public function __construct(private array $sites) {}

        public function sites(Node $node): array
        {
            return $this->sites;
        }
    };
    $node = caddy_build_node('composed', '10.44.0.9');
    $node->update(['lan_ip' => $lan]);

    if ($ingress) {
        $cluster = Cluster::query()->create(['name' => 'composed', 'state' => ClusterState::Active]);
        $node->update(['cluster_id' => $cluster->id]);
        $node->roles()->create(['role' => RoleName::Ingress, 'status' => LifecycleStatus::Active, 'cluster_id' => $cluster->id]);
    }

    return new NodeCaddyfileRenderer([$source])->render($node);
}

function caddy_build_rendered_site(
    CaddyListenerRule $listener,
    string $host = 'reverb.orbit',
    string $source = 'websocket',
    int $port = 443,
): CaddySite {
    return new CaddySite(
        source: $source,
        name: $host,
        listener: $listener,
        hosts: [$host],
        port: $port,
        body: "{$host} {\n    bind __BIND__\n}\n",
        bindPlaceholder: '__BIND__',
    );
}

function caddy_build_site(
    string $domain,
    string $scope,
    bool $publicListener = false,
    string $environment = 'development',
    ?string $localUnixUpstream = null,
): AppDevSite {
    return new AppDevSite(
        nodeId: 1,
        nodeAddress: '10.44.0.1',
        scope: $scope,
        checkoutPath: '',
        documentRoot: '',
        phpVersion: null,
        domain: $domain,
        upstreamAddresses: ['10.44.0.3'],
        environment: $environment,
        publicListener: $publicListener,
        localUnixUpstream: $localUnixUpstream,
    );
}

function caddy_build_node(string $name, ?string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
}

/** @return array{AppInstance, Route} */
function caddy_build_private_route(Node $router, string $domain): array
{
    $cluster = Cluster::query()->create(['name' => "{$domain}-cluster", 'state' => ClusterState::Active]);
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create(['role' => RoleName::Router, 'status' => LifecycleStatus::Active, 'cluster_id' => $cluster->id]);
    $workload = caddy_build_node("{$domain}-workload", '10.44.0.30');
    $workload->update(['cluster_id' => $cluster->id]);
    $workload->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $app = OrbitApp::query()->create(['name' => $domain, 'slug' => str_replace('.', '-', $domain), 'repository_url' => "https://example.test/{$domain}.git", 'root' => 'public']);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'default',
        'checkout_path' => "/home/orbit/apps/{$domain}",
        'root' => 'public',
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return [$instance->fresh('node') ?? $instance, $route];
}

function caddy_build_herdr_session(Node $node, string $session, string $observerStatus): HerdrSession
{
    return HerdrSession::query()->create([
        'node_id' => $node->id,
        'session' => $session,
        'user' => 'orbit',
        'management' => HerdrSessionManagement::Managed,
        'observer_port' => 7400 + HerdrSession::query()->count(),
        'observer_hostname' => "{$session}.herdr.orbit",
        'observer_status' => $observerStatus,
        'status' => LifecycleStatus::Active,
        'publish_observer' => true,
    ]);
}
