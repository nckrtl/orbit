<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\Doctor\NativePublicRouteEdgeInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Routes\NativePublicRouteEdgeProjector;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\File;
use Tests\Support\CaddySiteCertificateFixtures;
use Tests\Support\LocalRootShellSshExecutor;

const PUBLIC_EDGE_UFW_ACTIVE = <<<'UFW'
    Status: active

         To                         Action      From
         --                         ------      ----
    [ 1] 80/tcp                     ALLOW IN    Anywhere                   # orbit:ingress-http
    [ 2] 443/tcp                    ALLOW IN    Anywhere                   # orbit:ingress-https
    [ 3] 80/tcp (v6)                ALLOW IN    Anywhere (v6)              # orbit:ingress-http
    [ 4] 443/tcp (v6)               ALLOW IN    Anywhere (v6)              # orbit:ingress-https

    UFW;

beforeEach(function (): void {
    $this->caddy = sys_get_temp_dir().'/orbit-public-edge-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("{$this->caddy}/orbit-versions/v1");
    symlink("{$this->caddy}/orbit-versions/v1/Caddyfile", "{$this->caddy}/Caddyfile");
    $this->ssh = new LocalRootShellSshExecutor("{$this->caddy}/orbit-versions", PUBLIC_EDGE_UFW_ACTIVE);
});

afterEach(function (): void {
    File::deleteDirectory($this->caddy);
});

describe('a separate Ingress', function (): void {
    beforeEach(function (): void {
        $this->router = public_edge_listener();
        $this->forwardingPort = (int) substr((string) stream_socket_get_name($this->router, false), strlen('127.0.0.1:'));
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
        public_edge_node('edge-router', '10.44.0.20', '127.0.0.1', $cluster, [RoleName::Router]);
        $this->ingress = public_edge_node('edge-ingress', '10.44.0.30', '10.10.0.30', $cluster, [RoleName::Ingress]);
        $workload = public_edge_node('edge-workload', '10.44.0.40', '10.10.0.40', $cluster, [RoleName::AppProd]);
        $this->route = public_edge_route($cluster, $workload);
    });

    it('accepts the published reverse proxy to the Router', function (): void {
        public_edge_publish($this->caddy, $this->ingress);

        expect(public_edge_observe($this))->toBe([true, true, true])
            ->and(public_edge_forwarding($this))->toBeTrue();
    });

    it('reports a Router address that the Ingress cannot reach', function (): void {
        public_edge_publish($this->caddy, $this->ingress);
        fclose($this->router);

        expect(public_edge_forwarding($this))->toBeFalse()
            ->and(public_edge_observe($this))->toBe([true, true, true]);
    });

    it('reports a public site that no longer proxies to the Router', function (): void {
        public_edge_publish($this->caddy, $this->ingress, static fn (string $site): string => str_replace(
            'reverse_proxy https://127.0.0.1',
            'reverse_proxy https://10.10.0.99',
            $site,
        ));

        expect(public_edge_observe($this))->toBe([false, true, true]);
    });

    it('reports a missing public HTTPS firewall rule without touching the site checks', function (): void {
        public_edge_publish($this->caddy, $this->ingress);
        $this->ssh->ufwStatus = implode("\n", array_filter(
            explode("\n", PUBLIC_EDGE_UFW_ACTIVE),
            static fn (string $line): bool => ! str_contains($line, 'orbit:ingress-https'),
        ));

        expect(public_edge_observe($this))->toBe([true, true, false]);
    });

    it('reports an inactive firewall', function (): void {
        public_edge_publish($this->caddy, $this->ingress);
        $this->ssh->ufwStatus = "Status: inactive\n";

        expect(public_edge_observe($this))->toBe([true, true, false]);
    });
});

describe('an Ingress whose role is converging', function (): void {
    beforeEach(function (): void {
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
        public_edge_node('edge-router', '10.44.0.20', '10.10.0.20', $cluster, [RoleName::Router]);
        $this->ingress = public_edge_node('edge-ingress', '10.44.0.30', '10.10.0.30', $cluster, [RoleName::Ingress]);
        $workload = public_edge_node('edge-workload', '10.44.0.40', '10.10.0.40', $cluster, [RoleName::AppProd]);
        $this->route = public_edge_route($cluster, $workload);
    });

    it('keeps the public site in a Node Caddy build while the role serves', function (
        LifecycleStatus $status,
        ?string $failedStep,
        bool $serves,
    ): void {
        $this->ingress->roles()->where('role', RoleName::Ingress)->update([
            'status' => $status,
            'failed_step' => $failedStep,
            'error_code' => $failedStep === null ? null : 'node_role.convergence_failed',
        ]);

        $caddyfile = app(NodeCaddyfileRenderer::class)->render($this->ingress)->content;

        expect(str_contains($caddyfile, "{$this->route->domain} {"))->toBe($serves);
    })->with([
        'active' => [LifecycleStatus::Active, null, true],
        'converging' => [LifecycleStatus::Provisioning, null, true],
        'failed convergence' => [LifecycleStatus::Failed, 'converge:caddy-config', true],
        'being removed' => [LifecycleStatus::Removing, null, false],
    ]);

    it('starts a public activation only on an active Ingress', function (
        LifecycleStatus $status,
        ?string $failedStep,
        bool $starts,
    ): void {
        $this->ingress->roles()->where('role', RoleName::Ingress)->update([
            'status' => $status,
            'failed_step' => $failedStep,
            'error_code' => $failedStep === null ? null : 'node_role.convergence_failed',
        ]);
        $eligibility = new PublicRouteEligibility;

        expect($eligibility->canStartActivation($this->route->refresh()))->toBe($starts)
            ->and($eligibility->canActivate($this->route))->toBe($status !== LifecycleStatus::Removing);
    })->with([
        'active' => [LifecycleStatus::Active, null, true],
        'converging' => [LifecycleStatus::Provisioning, null, false],
        'failed convergence' => [LifecycleStatus::Failed, 'converge:caddy-config', false],
        'being removed' => [LifecycleStatus::Removing, null, false],
    ]);
});

describe('an Ingress that runs the workload while the Router is on another Node', function (): void {
    beforeEach(function (): void {
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
        $this->router = public_edge_node('gateway', '10.44.0.1', null, $cluster, [RoleName::Router]);
        $this->ingress = public_edge_node('app-prod', '10.44.0.3', null, $cluster, [RoleName::Ingress, RoleName::AppProd]);
        $this->route = public_edge_route($cluster, $this->ingress);
    });

    it('builds one public site for the host that serves the local workload', function (): void {
        $ingress = app(NodeCaddyfileRenderer::class)->render($this->ingress);
        $domain = $this->route->domain;

        expect($ingress->problems)->toBe([])
            ->and(substr_count($ingress->content, "{$domain} {"))->toBe(1)
            ->and($ingress->content)
            ->toContain("# orbit: ingress route-{$this->route->id}-ingress\n{$domain} {\n    bind 0.0.0.0 10.44.0.3\n    tls force_automate")
            ->toContain('php_fastcgi unix//run/php/orbit-app-')
            ->not->toContain("https://{$domain} {")
            ->not->toContain('reverse_proxy https://10.44.0.1');
    });

    it('lets the Router reach the public site through the Node system roots', function (): void {
        $router = app(NodeCaddyfileRenderer::class)->render($this->router)->content;
        $block = strstr((string) strstr($router, "# orbit: app-dev route-{$this->route->id}-router"), '# orbit:', true) ?: (string) strstr($router, "# orbit: app-dev route-{$this->route->id}-router");

        expect($block)->toContain('reverse_proxy https://10.44.0.3')
            ->not->toContain('tls_trusted_ca_certs');
    });

    it('builds the private workload site again once the Route is private', function (): void {
        $this->route->update(['publication' => RoutePublication::Private, 'replacement_step' => null]);
        $ingress = app(NodeCaddyfileRenderer::class)->render($this->ingress)->content;

        expect($ingress)->toContain("https://{$this->route->domain} {")
            ->not->toContain('tls force_automate');
    });

    it('builds the Router with the Ingress when the public edge activates', function (): void {
        $builds = app(NodeCaddyBuilds::class);

        app(NativePublicRouteEdgeProjector::class)->activatePublicHandler($this->route);

        expect($builds->built)->toBe(['app-prod', 'gateway']);
    });

    it('accepts the published site', function (): void {
        public_edge_publish_build($this->caddy, $this->ingress);

        expect(public_edge_observe($this))->toBe([true, true, true]);
    });
});

describe('an Ingress on the Gateway Node that is also the Router', function (): void {
    beforeEach(function (): void {
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
        $this->ingress = public_edge_node('gateway', '10.44.0.1', null, $cluster, [RoleName::Gateway, RoleName::Router, RoleName::Ingress]);
        CaddySiteCertificateFixtures::recordAll($this->ingress);
        $workload = public_edge_node('app-prod', '10.44.0.3', null, $cluster, [RoleName::AppProd]);
        $this->route = public_edge_route($cluster, $workload);
    });

    it('builds the public site on every address and the WireGuard address, and keeps gateway.orbit on WireGuard', function (): void {
        $caddyfile = app(NodeCaddyfileRenderer::class)->render($this->ingress);

        expect($caddyfile->problems)->toBe([])
            ->and($caddyfile->content)
            ->toContain("gateway.orbit, 10.44.0.1 {\n    bind 10.44.0.1\n    @orbit_outside not remote_ip 10.44.0.0/24\n    abort @orbit_outside\n")
            ->toContain("# orbit: ingress route-{$this->route->id}-ingress\n{$this->route->domain} {\n    bind 0.0.0.0 10.44.0.1\n    tls force_automate");
    });

    it('accepts the public site in the one Caddyfile a Node Caddy build writes', function (): void {
        public_edge_publish_build($this->caddy, $this->ingress);

        expect(public_edge_observe($this))->toBe([true, true, true]);
    });

    it('reports a public site that left the WireGuard address', function (): void {
        public_edge_publish_build($this->caddy, $this->ingress, fn (string $file): string => str_replace(
            "{$this->route->domain} {\n    bind 0.0.0.0 10.44.0.1\n",
            "{$this->route->domain} {\n    bind 0.0.0.0\n",
            $file,
        ));

        expect(public_edge_observe($this)[0])->toBeFalse();
    });
});

describe('an Ingress that shares the Router and the workload', function (): void {
    beforeEach(function (): void {
        $cluster = Cluster::query()->create(['name' => 'prod', 'tld' => 'prod.test', 'state' => ClusterState::Active]);
        $this->ingress = public_edge_node(
            'app-prod',
            '10.44.0.4',
            null,
            $cluster,
            [RoleName::Router, RoleName::Ingress, RoleName::AppProd],
        );
        $this->route = public_edge_route($cluster, $this->ingress);
    });

    it('accepts the composed site that serves the Instance directly', function (): void {
        public_edge_publish($this->caddy, $this->ingress);

        expect(public_edge_observe($this))->toBe([true, true, true])
            ->and(public_edge_forwarding($this))->toBeTrue()
            ->and(file_get_contents("{$this->caddy}/orbit-versions/v1/Caddyfile"))
            ->toContain('php_fastcgi unix//run/php/orbit-app-')
            ->not->toContain('reverse_proxy');
    });

    it('reports a composed site that no longer serves the Instance', function (): void {
        public_edge_publish($this->caddy, $this->ingress, static fn (string $site): string => preg_replace(
            '/^php_fastcgi .*$/m',
            'php_fastcgi unix//run/php/other.sock {',
            $site,
        ) ?? $site);

        expect(public_edge_observe($this))->toBe([false, true, true]);
    });

    it('reports only TLS when the composed site loses certificate automation', function (): void {
        public_edge_publish($this->caddy, $this->ingress, static fn (string $site): string => str_replace(
            "    tls force_automate\n",
            '',
            $site,
        ));

        expect(public_edge_observe($this))->toBe([true, false, true]);
    });

    it('reports a public site that pins an Orbit CA leaf', function (): void {
        $scope = "route-{$this->route->id}-ingress";
        public_edge_publish($this->caddy, $this->ingress, static fn (string $site): string => str_replace(
            "    tls force_automate\n",
            "    tls /etc/caddy/orbit-certificates/{$scope}/current/cert.pem /etc/caddy/orbit-certificates/{$scope}/current/key.pem\n",
            $site,
        ));

        expect(public_edge_observe($this))->toBe([true, false, true]);
    });

    it('accepts a site without certificate automation on a Node that leaves certificate management on', function (): void {
        public_edge_publish(
            $this->caddy,
            $this->ingress,
            static fn (string $site): string => str_replace("    tls force_automate\n", '', $site),
            globalOptions: '',
        );

        expect(public_edge_observe($this))->toBe([true, true, true]);
    });

    it('fails closed when the firewall status cannot be read', function (int $exitCode, string $status): void {
        public_edge_publish($this->caddy, $this->ingress);
        $this->ssh->ufwExitCode = $exitCode;
        $this->ssh->ufwStatus = $status;

        expect(fn () => public_edge_observe($this))->toThrow(DoctorInspectionException::class);
    })->with([
        'failed command' => [1, ''],
        'unrecognised status' => [0, "ERROR: problem running iptables\n"],
    ]);

    it('reports a Node that does not serve the public site at all', function (): void {
        public_edge_publish($this->caddy, $this->ingress, static fn (string $site): string => "# no sites\n");

        expect(public_edge_observe($this))->toBe([false, false, true]);
    });

    it('reads the root-only published Caddy version through sudo', function (): void {
        public_edge_publish($this->caddy, $this->ingress);

        public_edge_observe($this);

        expect(array_slice($this->ssh->commands[0]->arguments, 0, 3))->toBe(['sudo', 'bash', '-seu']);
    });
    it('accepts the composed site in the one Caddyfile a Node Caddy build writes', function (): void {
        public_edge_publish_build($this->caddy, $this->ingress);

        expect(public_edge_observe($this))->toBe([true, true, true])
            ->and(is_dir("{$this->caddy}/orbit-versions/v2/fragments"))->toBeFalse();
    });

    it('reports a composed site that no longer serves the Instance in a build', function (): void {
        public_edge_publish_build($this->caddy, $this->ingress, static fn (string $file): string => preg_replace(
            '/^(\s*)php_fastcgi .*$/m',
            '$1php_fastcgi unix//run/php/other.sock {',
            $file,
        ) ?? $file);

        expect(public_edge_observe($this)[0])->toBeFalse();
    });

    it('reports a build whose public site lost certificate automation', function (): void {
        public_edge_publish_build($this->caddy, $this->ingress, static fn (string $file): string => str_replace(
            "    tls force_automate\n",
            '',
            $file,
        ));

        expect(public_edge_observe($this))->toBe([true, false, true]);
    });
});

it('fails closed when the Node publishes no public site for the Route', function (): void {
    $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
    $ingress = public_edge_node('edge-ingress', '10.44.0.30', null, $cluster, [RoleName::Ingress]);
    $route = public_edge_route($cluster, public_edge_node('edge-workload', '10.44.0.40', null, $cluster, [RoleName::AppProd]));

    expect(fn () => public_edge_inspector($this->ssh, $this->caddy)->inspect($ingress, $route))
        ->toThrow(DoctorInspectionException::class);
});

/** @return array{?bool, ?bool, ?bool} */
function public_edge_observe(object $test): array
{
    $observation = public_edge_inspector($test->ssh, $test->caddy, $test->forwardingPort ?? 443)
        ->inspect($test->ingress, $test->route);

    return [
        $observation->ingressProjectionMatches,
        $observation->publicTlsMatches,
        $observation->firewallMatches,
    ];
}

function public_edge_forwarding(object $test): ?bool
{
    return public_edge_inspector($test->ssh, $test->caddy, $test->forwardingPort ?? 443)
        ->inspect($test->ingress, $test->route)
        ->privateForwardingMatches;
}

/** Listens on a free loopback port that stands in for the Router's HTTPS listener. */
function public_edge_listener(): mixed
{
    $server = stream_socket_server('tcp://127.0.0.1:0');

    if ($server === false) {
        throw new RuntimeException('The test Router listener could not start.');
    }

    return $server;
}

function public_edge_inspector(
    LocalRootShellSshExecutor $ssh,
    string $caddy,
    int $forwardingPort = 443,
): NativePublicRouteEdgeInspector {
    return new NativePublicRouteEdgeInspector(
        new AppDevSshExecutor(
            $ssh,
            new class implements SshKeyProvider
            {
                public function privateKeyPath(): string
                {
                    return '/tmp/public-edge-test-key';
                }

                public function publicKey(): string
                {
                    return 'ssh-ed25519 test';
                }
            },
            new class implements KnownHostsStore
            {
                public function path(): string
                {
                    return '/tmp/public-edge-known-hosts';
                }

                public function put(string $host, int $port, HostKey $key): void {}
            },
        ),
        new CommandDeadline,
        liveCaddyfilePath: "{$caddy}/Caddyfile",
        defaultForwardingPort: $forwardingPort,
    );
}

/**
 * Publishes the Ingress Node's Route sites after the global options block in the one live Caddyfile.
 *
 * @param  (Closure(string): string)|null  $edit
 */
function public_edge_publish(string $caddy, Node $ingress, ?Closure $edit = null, ?string $globalOptions = null): void
{
    $sites = implode(PHP_EOL, array_map(
        static fn (array $block): string => $block['block'],
        array_values(array_filter(
            app(NodeCaddyfileRenderer::class)->render($ingress)->blocks,
            static fn (array $block): bool => $block['source'] !== 'gateway',
        )),
    ));
    file_put_contents(
        "{$caddy}/orbit-versions/v1/Caddyfile",
        ($globalOptions ?? CaddyGlobalOptions::render()).($edit instanceof Closure ? $edit($sites) : $sites),
    );
}

/**
 * Publishes the Ingress Node's whole render as the one versioned Caddyfile a Node Caddy build writes.
 *
 * @param  (Closure(string): string)|null  $edit
 */
function public_edge_publish_build(string $caddy, Node $ingress, ?Closure $edit = null): void
{
    $file = app(NodeCaddyfileRenderer::class)->render($ingress)->content;
    File::ensureDirectoryExists("{$caddy}/orbit-versions/v2");
    file_put_contents("{$caddy}/orbit-versions/v2/Caddyfile", $edit instanceof Closure ? $edit($file) : $file);
    unlink("{$caddy}/Caddyfile");
    symlink("{$caddy}/orbit-versions/v2/Caddyfile", "{$caddy}/Caddyfile");
}

function public_edge_route(Cluster $cluster, Node $workload): Route
{
    static $number = 0;
    $number++;
    $app = OrbitApp::query()->create([
        'name' => "Shop {$number}",
        'slug' => "shop-{$number}",
        'repository_url' => "https://git.example.test/acme/shop-{$number}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $user = "orbit-app-{$app->id}";
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/{$user}/releases/initial",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'production_php_service' => "orbit-{$user}-php8.5-fpm.service",
        'production_php_pool' => "orbit-{$user}",
        'production_php_socket' => "/run/php/{$user}.sock",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => "shop-{$number}.example.com",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update([
        'status' => RouteStatus::Active,
        'replacement_step' => RouteReplacementStep::PublicActivated,
    ]);

    return $route->refresh();
}

/** @param list<RoleName> $roles */
function public_edge_node(string $name, string $wireguardIp, ?string $lanIp, Cluster $cluster, array $roles): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $wireguardIp,
        'wireguard_ip' => $wireguardIp,
        'lan_ip' => $lanIp,
        'cluster_id' => $cluster->id,
        'user' => 'orbit',
    ]);

    foreach ($roles as $role) {
        $node->roles()->create([
            'cluster_id' => in_array($role, [RoleName::Router, RoleName::Ingress], true) ? $cluster->id : null,
            'role' => $role,
            'status' => LifecycleStatus::Active,
        ]);
    }

    return $node;
}
