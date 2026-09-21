<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Infrastructure\Routes\NativeRouteRemovalProjector;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;

beforeEach(function (): void {
    $this->cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
    $this->router = analytics_projection_node('edge-router', '10.44.0.20', '10.10.0.20', $this->cluster, RoleName::Router);
    $this->ingress = analytics_projection_node('edge-ingress', '10.44.0.30', '10.10.0.30', $this->cluster, RoleName::Ingress);
    $this->analytics = analytics_projection_node('services', '10.44.0.40', null, null, RoleName::Analytics);
    $this->route = Route::query()->create([
        'kind' => RouteKind::AnalyticsTracking,
        'cluster_id' => $this->cluster->id,
        'domain' => 'analytics.shop.example.com',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
});

describe('analytics tracking Route sites', function (): void {
    it('renders nothing for a pending tracking Route until it is the Route being converged', function (): void {
        $sites = new AppDevSiteRepository;

        expect($sites->all())->toBeEmpty()
            ->and($sites->forNode($this->router, $this->route)->map->scope->all())
            ->toBe(["route-{$this->route->id}-router"]);
    });

    it('serves only the script and event paths on the Router before publication', function (): void {
        $this->route->update(['status' => RouteStatus::Active]);
        $sites = new AppDevSiteRepository;
        $id = $this->route->id;

        expect($sites->all())->toHaveCount(1)
            ->and($sites->forNode($this->ingress))->toBeEmpty()
            ->and($sites->forNode($this->analytics))->toBeEmpty()
            ->and(new AppDevCaddyConfigRenderer()->render($sites->forNode($this->router)))->toBe(<<<CADDY
                https://analytics.shop.example.com {
                    bind 0.0.0.0
                    tls /etc/caddy/orbit-certificates/route-{$id}-router/current/cert.pem /etc/caddy/orbit-certificates/route-{$id}-router/current/key.pem
                    handle /js/* {
                        reverse_proxy http://10.44.0.40:8000
                    }
                    handle /api/event {
                        reverse_proxy http://10.44.0.40:8000
                    }
                    respond 404
                }

                CADDY);
    });

    it('forwards the whole host from the Ingress and trusts that Ingress for the visitor address', function (): void {
        analytics_projection_publish($this->route);
        $sites = new AppDevSiteRepository;
        $id = $this->route->id;
        $renderer = new AppDevCaddyConfigRenderer;
        $root = AppDevCaddyConfigRenderer::ORBIT_ROOT_CA_PATH;

        expect($renderer->render($sites->forNode($this->router)))->toBe(<<<CADDY
            https://analytics.shop.example.com {
                bind 0.0.0.0
                tls /etc/caddy/orbit-certificates/route-{$id}-router/current/cert.pem /etc/caddy/orbit-certificates/route-{$id}-router/current/key.pem
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

        $ingress = $renderer->render($sites->forNode($this->ingress));

        expect($ingress)
            ->toStartWith("analytics.shop.example.com {\n")
            ->not->toContain("tls /etc/caddy/orbit-certificates/route-{$id}-ingress/current/cert.pem")
            ->toContain('reverse_proxy https://10.10.0.20 {')
            ->toContain('header_up Host analytics.shop.example.com')
            ->toContain('header_up X-Forwarded-For {remote_host}')
            ->toContain('tls_server_name analytics.shop.example.com')
            ->toContain("tls_trusted_ca_certs {$root}")
            ->not->toContain('handle ')
            ->not->toContain('respond 404')
            ->not->toContain('10.44.0.40');
    });

    it('serves the public listener on a Router that is also the Ingress', function (): void {
        $this->ingress->roles()->delete();
        $this->router->roles()->create([
            'cluster_id' => $this->cluster->id,
            'role' => RoleName::Ingress,
            'status' => LifecycleStatus::Active,
        ]);
        analytics_projection_publish($this->route);
        $id = $this->route->id;
        $sites = new AppDevSiteRepository()->all();

        expect($sites)->toHaveCount(1)
            ->and($sites->sole()->nodeId)->toBe($this->router->id)
            ->and(new AppDevCaddyConfigRenderer()->render($sites))->toBe(<<<'CADDY'
                analytics.shop.example.com {
                    bind 0.0.0.0
                    handle /js/* {
                        reverse_proxy http://10.44.0.40:8000
                    }
                    handle /api/event {
                        reverse_proxy http://10.44.0.40:8000
                    }
                    respond 404
                }

                CADDY);
    });

    it('renders no site while no analytics role is active or while the Route retires', function (): void {
        $this->route->update(['status' => RouteStatus::Active]);
        $this->analytics->roles()->update(['status' => LifecycleStatus::Provisioning]);

        expect(new AppDevSiteRepository()->all())->toBeEmpty();

        $this->analytics->roles()->update(['status' => LifecycleStatus::Active]);
        $this->route->update(['status' => RouteStatus::Retiring]);

        expect(new AppDevSiteRepository()->all())->toBeEmpty();
    });

    it('answers private DNS for the host with the Router', function (): void {
        analytics_projection_publish($this->route);

        expect(new AppDevDnsConfigRenderer(new AppDevSiteRepository)->render())
            ->toContain('host-record=analytics.shop.example.com,10.44.0.20')
            ->not->toContain('host-record=analytics.shop.example.com,10.44.0.30');
    });

    it('never treats the tracking site as a PHP workload', function (): void {
        $this->route->update(['status' => RouteStatus::Active]);

        expect(new AppDevSiteRepository()->all()->every(
            static fn (AppDevSite $site): bool => $site->isProxy() && $site->phpVersion === null,
        ))->toBeTrue();
    });
});

describe('analytics tracking Route public edge', function (): void {
    it('verifies the Router as the last hop because the host has no workload', function (): void {
        $override = new IngressSiteRepository()->privateOverride($this->route);
        $artifact = new IngressSiteRepository()->forRoute($this->route);

        expect($override->domain)->toBe('analytics.shop.example.com')
            ->and($override->routerAddress)->toBe('10.10.0.20')
            ->and($override->workloadAddress)->toBe('10.10.0.20')
            ->and($artifact->routerUpstream)->toBe('10.10.0.20')
            ->and($artifact->certificateScope)->toBe("route-{$this->route->id}-ingress");
    });
});

describe('analytics tracking Route removal', function (): void {
    it('removes the Router certificates of the tracking Route', function (): void {
        $ssh = new AnalyticsProjectionSshExecutor;
        $executor = new AppDevSshExecutor(
            $ssh,
            new class implements SshKeyProvider
            {
                public function privateKeyPath(): string
                {
                    return '/tmp/orbit-test-key';
                }

                public function publicKey(): string
                {
                    return 'ssh-ed25519 AAAA';
                }
            },
            new class implements KnownHostsStore
            {
                public function path(): string
                {
                    return '/tmp/orbit-test-known-hosts';
                }

                public function put(string $host, int $port, HostKey $key): void {}
            },
        );
        $accounts = new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
            }
        };
        $signer = new class implements LeafCertificateSigner
        {
            public function sign(string $domain, string $certificateRequest): string
            {
                return "LEAF\n";
            }

            public function rootCertificate(): string
            {
                return "ROOT\n";
            }
        };
        $processes = new class implements ProcessRunner
        {
            public function run(ProcessInvocation $invocation): CommandResult
            {
                return new CommandResult(0, '', '', 1, false);
            }
        };
        $sites = new AppDevSiteRepository;
        $projector = new NativeRouteRemovalProjector(
            new DnsmasqPrivateDnsManager($processes, new AppDevDnsConfigRenderer($sites)),
            new RemoteAppDevCertificateManager($executor, $signer, $accounts),
            new RemoteAppDevCaddyManager($sites, new AppDevCaddyConfigRenderer, $executor),
            new RemoteAppDevRouteFirewallManager($executor),
        );

        $projector->cleanupCertificates($this->route);

        $scopes = array_map(
            static fn (RemoteCommand $command): string => $command->arguments[3],
            $ssh->commands,
        );

        expect($scopes)->toBe(["route-{$this->route->id}-router", "route-{$this->route->id}-router-hostname-change"])
            ->and(array_unique(array_map(static fn (SshConnection $connection): string => $connection->host, $ssh->connections)))
            ->toBe([$this->router->wireguard_ip])
            ->and($ssh->commands[0]->input)->toContain('sudo rm -rf -- "/etc/caddy/orbit-certificates/$scope"');
    });
});

function analytics_projection_node(
    string $name,
    string $wireguardIp,
    ?string $lanIp,
    ?Cluster $cluster,
    RoleName $role,
): Node {
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.'.substr($wireguardIp, strrpos($wireguardIp, '.') + 1),
        'wireguard_ip' => $wireguardIp,
        'lan_ip' => $lanIp,
        'cluster_id' => $cluster?->id,
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'cluster_id' => $cluster?->id,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function analytics_projection_publish(Route $route): void
{
    $route->update([
        'status' => RouteStatus::Active,
        'replacement_step' => RouteReplacementStep::PublicActivated,
    ]);
}

final class AnalyticsProjectionSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;

        return new CommandResult(0, '', '', 1, false);
    }
}
