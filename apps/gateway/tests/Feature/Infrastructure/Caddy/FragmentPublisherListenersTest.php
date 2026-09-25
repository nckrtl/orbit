<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Analytics\AnalyticsCaddyPublisher;
use App\Infrastructure\Analytics\AnalyticsCaddySiteRenderer;
use App\Infrastructure\Analytics\AnalyticsFootprint;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\Caddy\Build\NodeCaddyListenerResolver;
use App\Infrastructure\Caddy\CaddyFragmentListeners;
use App\Infrastructure\ProxyCli\ProxyCliCaddyPublisher;
use App\Infrastructure\ProxyCli\ProxyCliCaddySiteRenderer;
use App\Infrastructure\ProxyCli\ProxyCliFootprint;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WebSocket\WebSocketCaddyPublisher;
use App\Infrastructure\WebSocket\WebSocketCaddySiteRenderer;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Symfony\Component\Process\Process;
use Tests\Support\CaddyFragmentNodeHarness;

/*
 * The 2026-09-24 incident on `services`: the Route publisher bound `cli-proxy-api.orbit` to 0.0.0.0 and
 * the ProxyCli publisher copied it, while `reverb.orbit` and `analytics.orbit` bound the WireGuard
 * address. Caddy then sent every WireGuard connection to the WireGuard-bound sites only.
 */

describe('the listeners a fragment publisher writes', function (): void {
    it('binds Route and shared sites to the WireGuard and LAN addresses on a Node without ingress', function (): void {
        $node = fragment_listener_node('services', '10.44.0.3', lan: '192.168.1.3');
        fragment_listener_custom_proxy($node, 'cli-proxy-api.orbit');

        $listeners = app(NodeCaddyListenerResolver::class)->fragments($node);

        expect($listeners->routes)->toBe(['10.44.0.3', '192.168.1.3'])
            ->and($listeners->shared)->toBe(['10.44.0.3']);
    });

    it('keeps the wildcard listener on an Ingress Node and joins shared sites to it beside a Route site', function (): void {
        $node = fragment_listener_node('edge', '10.44.0.4', ingress: true);

        expect(app(NodeCaddyListenerResolver::class)->fragments($node))
            ->routes->toBe(['0.0.0.0'])
            ->shared->toBe(['10.44.0.4']);

        fragment_listener_custom_proxy($node, 'cli-proxy-api.orbit');

        expect(app(NodeCaddyListenerResolver::class)->fragments($node))
            ->routes->toBe(['0.0.0.0'])
            ->shared->toBe(['0.0.0.0']);
    });

    it('rejects a listener that is not an IPv4 address', function (): void {
        expect(fn () => new CaddyFragmentListeners(['10.44.0.3'], ['10.44.0.3 0.0.0.0']))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('the listener rewrite', function (): void {
    it('rewrites stale binds of Route and shared fragments and leaves every other listener alone', function (): void {
        $directory = sys_get_temp_dir().'/orbit-listeners-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $fragments = [
            'app-dev.caddy' => "http://unix//run/orbit/route-3.sock {\n    bind unix//run/orbit/route-3.sock\n}\n\n"
                ."https://cli-proxy-api.orbit {\n    bind 0.0.0.0\n    tls /c/cert.pem /c/key.pem\n}\n\n"
                ."shop.example.com {\n    bind 0.0.0.0\n    tls force_automate\n}\n",
            'proxycli.caddy' => "# Managed by Orbit: proxycli\ncollector.cli-proxy-api.orbit {\n    bind 0.0.0.0\n}\n",
            'gateway.caddy' => "gateway.orbit {\n    bind 10.44.0.3\n}\n",
            '00-unmanaged.caddy' => "legacy.test {\n    bind 0.0.0.0\n}\n",
        ];

        foreach ($fragments as $name => $contents) {
            file_put_contents("{$directory}/{$name}", $contents);
        }

        $script = new CaddyFragmentListeners(['10.44.0.3', '192.168.1.3'], ['10.44.0.3'])->script('$directory');
        $run = static function () use ($directory, $script): string {
            $process = new Process(['bash', '-euc', 'directory=$1; '.$script.PHP_EOL.'printf %s "$listeners_rewritten"', 'rewrite', $directory]);
            $process->mustRun();

            return $process->getOutput();
        };

        try {
            expect($run())->toBe('1')
                ->and(file_get_contents("{$directory}/app-dev.caddy"))->toBe(
                    "http://unix//run/orbit/route-3.sock {\n    bind unix//run/orbit/route-3.sock\n}\n\n"
                    ."https://cli-proxy-api.orbit {\n    bind 10.44.0.3 192.168.1.3\n    tls /c/cert.pem /c/key.pem\n}\n\n"
                    ."shop.example.com {\n    bind 0.0.0.0\n    tls force_automate\n}\n",
                )
                ->and(file_get_contents("{$directory}/proxycli.caddy"))->toContain("    bind 10.44.0.3\n")
                ->and(file_get_contents("{$directory}/gateway.caddy"))->toBe($fragments['gateway.caddy'])
                ->and(file_get_contents("{$directory}/00-unmanaged.caddy"))->toBe($fragments['00-unmanaged.caddy'])
                ->and(glob("{$directory}/*.orbit-listeners"))->toBe([])
                ->and($run())->toBe('0');
        } finally {
            array_map(unlink(...), glob("{$directory}/*") ?: []);
            rmdir($directory);
        }
    });

    it('keeps the exact bytes of a fragment whose listeners already follow the rule', function (): void {
        $directory = sys_get_temp_dir().'/orbit-listeners-'.bin2hex(random_bytes(6));
        mkdir($directory);
        // The analytics and ProxyCli renderers end without a final newline.
        $fragment = "# Managed by Orbit: analytics\nanalytics.orbit {\n    bind 10.44.0.3\n}";
        file_put_contents("{$directory}/analytics.caddy", $fragment);
        $script = new CaddyFragmentListeners(['10.44.0.3'], ['10.44.0.3'])->script('$directory');

        try {
            $process = new Process(['bash', '-euc', 'directory=$1; '.$script.PHP_EOL.'printf %s "$listeners_rewritten"', 'rewrite', $directory]);
            $process->mustRun();

            expect($process->getOutput())->toBe('0')
                ->and(file_get_contents("{$directory}/analytics.caddy"))->toBe($fragment);
        } finally {
            array_map(unlink(...), glob("{$directory}/*") ?: []);
            rmdir($directory);
        }
    });
});

describe('fragment publishers on one Node', function (): void {
    beforeEach(function (): void {
        $this->harness = null;

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The publisher programs need GNU coreutils and flock.');
        }

        $this->harness = new CaddyFragmentNodeHarness;
    });

    afterEach(function (): void {
        $this->harness?->cleanup();
    });

    it('converges the mixed listeners of the services incident with any one publisher', function (string $publisher): void {
        $node = fragment_listener_services_node();
        $this->harness->seed(fragment_listener_incident_fragments($node));

        fragment_listener_publishers($this->harness, $node)[$publisher]();

        expect($this->harness->binds())->toBe(fragment_listener_expected_binds('bind 10.44.0.3'));
    })->with(['app-dev', 'websocket', 'analytics', 'proxycli']);

    it('publishes consistent listeners on a Node without ingress in every publisher order', function (): void {
        $node = fragment_listener_services_node();
        $publishers = fragment_listener_publishers($this->harness, $node);

        foreach (fragment_listener_orders(array_keys($publishers)) as $order) {
            $this->harness->seed(fragment_listener_incident_fragments($node));

            foreach ($order as $publisher) {
                $publishers[$publisher]();
            }

            expect($this->harness->binds())->toBe(
                fragment_listener_expected_binds('bind 10.44.0.3'),
                'Order: '.implode(', ', $order),
            );
        }
    });

    it('keeps the public listener on an Ingress Node and joins shared sites to its wildcard listener', function (): void {
        $node = fragment_listener_services_node(ingress: true);
        $publishers = fragment_listener_publishers($this->harness, $node);
        $public = "shop.example.com {\n    bind 0.0.0.0\n    tls force_automate\n    reverse_proxy https://10.44.0.20\n}\n";
        $this->harness->seed([
            ...fragment_listener_incident_fragments($node),
            'app-dev.caddy' => fragment_listener_old_route_fragment($node)."\n".$public,
        ]);

        foreach (['websocket', 'analytics', 'proxycli'] as $publisher) {
            $publishers[$publisher]();
        }

        expect($this->harness->fragments()['app-dev.caddy'])->toContain($public)
            ->and($this->harness->binds())->toBe([
                ...fragment_listener_expected_binds('bind 0.0.0.0'),
                'app-dev.caddy' => ['bind 0.0.0.0', 'bind 0.0.0.0'],
            ]);

        $publishers['app-dev']();

        expect($this->harness->binds())->toBe(fragment_listener_expected_binds('bind 0.0.0.0'));
    });

    it('leaves an unchanged Node alone: no new version and no reload', function (string $publisher): void {
        $node = fragment_listener_services_node();
        $publishers = fragment_listener_publishers($this->harness, $node);
        $this->harness->seed(fragment_listener_incident_fragments($node));

        foreach ($publishers as $publish) {
            $publish();
        }

        $versions = $this->harness->versions();
        $reloads = fragment_listener_reloads($this->harness);

        $publishers[$publisher]();
        $publishers[$publisher]();

        expect($this->harness->versions())->toBe($versions)
            ->and(fragment_listener_reloads($this->harness))->toBe($reloads)
            ->and($this->harness->binds())->toBe(fragment_listener_expected_binds('bind 10.44.0.3'));
    })->with(['app-dev', 'websocket', 'analytics', 'proxycli']);

    it('refuses before the swap when the Node lacks its stored LAN address', function (string $publisher): void {
        $node = fragment_listener_services_node();
        $node->update(['lan_ip' => '192.168.6.30']);
        $this->harness->seed(fragment_listener_incident_fragments($node));
        $live = $this->harness->fragments();

        $refused = null;

        try {
            fragment_listener_publishers($this->harness, $node)[$publisher]();
        } catch (Throwable $exception) {
            $refused = $exception;
        }

        expect($refused)->toBeInstanceOf(Throwable::class)
            ->and($this->harness->lastError())->toContain('Caddy would bind 192.168.6.30, which is not an address on this Node.')
            ->and($this->harness->fragments())->toBe($live)
            ->and(fragment_listener_reloads($this->harness))->toBe(0);

        $this->harness->addresses(['10.44.0.3', '192.168.6.30']);
        fragment_listener_publishers($this->harness, $node)[$publisher]();

        expect($this->harness->binds()['app-dev.caddy'])->toBe(['bind 10.44.0.3 192.168.6.30'])
            ->and($this->harness->binds()['websocket.caddy'])->toBe(['bind 10.44.0.3']);
    })->with(['app-dev', 'websocket']);
});

function fragment_listener_node(string $name, string $wireGuard, ?string $lan = null, bool $ingress = false): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => $wireGuard,
        'lan_ip' => $lan,
        'user' => 'orbit',
    ]);

    if ($ingress) {
        $cluster = Cluster::query()->create(['name' => "{$name}-cluster", 'state' => ClusterState::Active]);
        $node->update(['cluster_id' => $cluster->id]);
        $node->roles()->create(['role' => RoleName::Ingress, 'status' => LifecycleStatus::Active, 'cluster_id' => $cluster->id]);
    }

    return $node;
}

function fragment_listener_custom_proxy(Node $node, string $domain): Route
{
    $route = Route::query()->create([
        'kind' => RouteKind::CustomProxy,
        'node_id' => $node->id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:8317']);
    $route->update(['status' => RouteStatus::Active]);

    return $route;
}

/** `services` with a custom proxy Route, `websocket`, `analytics`, and the ProxyCli collector. */
function fragment_listener_services_node(bool $ingress = false): Node
{
    $node = fragment_listener_node('services', '10.44.0.3', ingress: $ingress);
    fragment_listener_custom_proxy($node, 'cli-proxy-api.orbit');
    $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Active]);
    app(ProxyCliState::class)->enable($node->id, 'cache', 'https://cliproxy.test', 'management', 'read', 'control');

    return $node;
}

/** The Route fragment the publisher wrote before this fix: every private site on 0.0.0.0. */
function fragment_listener_old_route_fragment(Node $node): string
{
    return new AppDevCaddyConfigRenderer()->render(new AppDevSiteRepository()->forNode($node), '0.0.0.0');
}

/** @return array<string, string> The live fragments of `services` during the incident. */
function fragment_listener_incident_fragments(Node $node): array
{
    return [
        'app-dev.caddy' => fragment_listener_old_route_fragment($node),
        'websocket.caddy' => str_replace(WebSocketFootprint::CaddyBindPlaceholder, '10.44.0.3', new WebSocketCaddySiteRenderer()->render(8080)),
        'analytics.caddy' => str_replace(AnalyticsFootprint::CaddyBindPlaceholder, '10.44.0.3', new AnalyticsCaddySiteRenderer()->render('10.44.0.3')),
        'proxycli.caddy' => str_replace(ProxyCliFootprint::CaddyBindPlaceholder, '0.0.0.0', new ProxyCliCaddySiteRenderer()->render()),
    ];
}

/** @return array<string, list<string>> */
function fragment_listener_expected_binds(string $bind): array
{
    return [
        'analytics.caddy' => [$bind],
        'app-dev.caddy' => [$bind],
        'proxycli.caddy' => [$bind],
        'websocket.caddy' => [$bind],
    ];
}

/** @return array<string, Closure(): void> Each publisher as the Gateway runs it on the Node. */
function fragment_listener_publishers(CaddyFragmentNodeHarness $harness, Node $node): array
{
    $listeners = static fn (): CaddyFragmentListeners => app(NodeCaddyListenerResolver::class)->fragments($node);

    return [
        'app-dev' => static fn () => fragment_listener_route_manager($harness)->converge($node),
        'websocket' => static fn () => $harness->run(new WebSocketCaddyPublisher()->command(
            new WebSocketCaddySiteRenderer()->render(8080),
            '8080',
            $listeners(),
        )),
        'analytics' => static fn () => $harness->run(new AnalyticsCaddyPublisher()->command(
            new AnalyticsCaddySiteRenderer()->render('10.44.0.3'),
            '8000',
            $listeners(),
        )),
        'proxycli' => static fn () => $harness->run(new ProxyCliCaddyPublisher()->command(
            new ProxyCliCaddySiteRenderer()->render(),
            '8317',
            $listeners(),
        )),
    ];
}

/** The Route publisher with an in-process projection lock, so it never waits on another test worker. */
function fragment_listener_route_manager(CaddyFragmentNodeHarness $harness): RemoteAppDevCaddyManager
{
    return new RemoteAppDevCaddyManager(
        new AppDevSiteRepository,
        new AppDevCaddyConfigRenderer,
        new AppDevSshExecutor($harness, app(SshKeyProvider::class), app(KnownHostsStore::class)),
        projection: new class implements DevelopmentProjectionOperationLock
        {
            public function run(Closure $operation): mixed
            {
                return $operation();
            }
        },
    );
}

/**
 * @param  list<string>  $items
 * @return list<list<string>>
 */
function fragment_listener_orders(array $items): array
{
    if (count($items) <= 1) {
        return [$items];
    }

    $orders = [];

    foreach ($items as $index => $item) {
        $rest = $items;
        unset($rest[$index]);

        foreach (fragment_listener_orders(array_values($rest)) as $order) {
            $orders[] = [$item, ...$order];
        }
    }

    return $orders;
}

function fragment_listener_reloads(CaddyFragmentNodeHarness $harness): int
{
    return count(array_filter($harness->serviceCalls(), static fn (string $call): bool => str_starts_with($call, 'reload-or-restart')));
}
