<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\CustomProxyRouteProjector;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetSetStep;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Routes\NativeCustomProxyRouteProjector;
use App\Infrastructure\Routes\NativeRouteRemovalProjector;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/*
 * ADR 0141: removal saves the state change, builds, and only then removes the certificate. Caddy
 * validates every certificate file its Caddyfile names, so a certificate removed while the live
 * Caddyfile still names it breaks every later Caddy publish on that Node. The simulated Nodes below
 * keep a live Caddyfile and certificate set, and refuse a publish whose Caddyfile names a missing
 * certificate, as `caddy validate` does.
 */

beforeEach(function (): void {
    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->removalHome = sys_get_temp_dir().'/orbit-route-removal-order-'.Str::uuid();
    config()->set('orbit.home', $this->removalHome);
    $this->nodes = new CertificateOrderNodes;
    $this->dnsRuns = new CertificateOrderDnsRunner;
    certificate_order_bind_projectors($this->nodes, $this->dnsRuns);
    $this->beast = certificate_order_node('beast', 7, RoleName::AppDev);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->removalHome);
});

describe('Route removal certificate order', function (): void {
    it('withdraws an active custom proxy site before it removes the certificate', function (): void {
        $routeId = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'collector.lab.orbit',
                'node_id' => $this->beast->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->json('data.id');

        expect($this->nodes->names('10.44.0.7', "route-{$routeId}"))->toBeTrue();

        $this->deleteJson("/api/v1/routes/{$routeId}")->assertOk();

        expect(Route::query()->whereKey($routeId)->exists())->toBeFalse()
            ->and($this->nodes->names('10.44.0.7', "route-{$routeId}"))->toBeFalse()
            ->and($this->nodes->hasCertificate('10.44.0.7', "route-{$routeId}"))->toBeFalse()
            ->and($this->nodes->removedWhileNamed)->toBe([])
            ->and($this->nodes->validates('10.44.0.7'))->toBeTrue();
    });

    it('withdraws a failed custom proxy site whose creation published it', function (): void {
        // The creation's Caddy build succeeds and its DNS publication fails, so the Route is failed
        // while its site is live on the Node.
        $this->dnsRuns->failNext = true;
        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'collector.lab.orbit',
                'node_id' => $this->beast->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertStatus(502);
        $route = Route::query()->where('domain', 'collector.lab.orbit')->sole();

        expect($route->status)->toBe(RouteStatus::Failed)
            ->and($route->sites_published)->toBeFalse()
            ->and($this->nodes->names('10.44.0.7', "route-{$route->id}"))->toBeTrue();

        $this->deleteJson("/api/v1/routes/{$route->id}")->assertOk();

        expect(Route::query()->whereKey($route->id)->exists())->toBeFalse()
            ->and($this->nodes->names('10.44.0.7', "route-{$route->id}"))->toBeFalse()
            ->and($this->nodes->hasCertificate('10.44.0.7', "route-{$route->id}"))->toBeFalse()
            ->and($this->nodes->removedWhileNamed)->toBe([])
            ->and($this->nodes->validates('10.44.0.7'))->toBeTrue();
    });

    it('withdraws a targetless Project Route from its Router before it removes the Router certificate', function (): void {
        $cluster = Cluster::query()->create(['name' => 'lab', 'state' => ClusterState::Active]);
        $router = certificate_order_node('router', 20, RoleName::Router, $cluster);
        $app = OrbitApp::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'https://example.test/acme.git',
            'root' => 'public',
        ]);
        $route = Route::query()->create([
            'app_id' => $app->id,
            'cluster_id' => $cluster->id,
            'domain' => 'vacated.acme.test',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        // A vacated Route keeps its domain and serves the unavailable answer from its Router.
        $route->update(['status' => RouteStatus::Active, 'target_set_step' => RouteTargetSetStep::Completed]);
        $this->nodes->issue('10.44.0.20', "route-{$route->id}-router");
        certificate_order_caddy()->converge($router);

        expect($this->nodes->names('10.44.0.20', "route-{$route->id}-router"))->toBeTrue();

        $this->deleteJson("/api/v1/routes/{$route->id}")->assertOk();

        expect(Route::query()->whereKey($route->id)->exists())->toBeFalse()
            ->and($this->nodes->names('10.44.0.20', "route-{$route->id}-router"))->toBeFalse()
            ->and($this->nodes->hasCertificate('10.44.0.20', "route-{$route->id}-router"))->toBeFalse()
            ->and($this->nodes->removedWhileNamed)->toBe([])
            ->and($this->nodes->validates('10.44.0.20'))->toBeTrue();
    });

    it('keeps the certificate and a retryable Route when the Caddy build fails', function (): void {
        $routeId = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'collector.lab.orbit',
                'node_id' => $this->beast->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated()
            ->json('data.id');
        $this->nodes->failCaddy = '10.44.0.7';

        $this->deleteJson("/api/v1/routes/{$routeId}")->assertStatus(502);

        $route = Route::query()->findOrFail($routeId);
        expect($route->status)->toBe(RouteStatus::Failed)
            ->and($route->failed_step)->toBe('caddy')
            ->and($route->sites_published)->toBeFalse()
            ->and($this->nodes->hasCertificate('10.44.0.7', "route-{$routeId}"))->toBeTrue()
            ->and($this->nodes->validates('10.44.0.7'))->toBeTrue();

        $this->nodes->failCaddy = null;
        $this->deleteJson("/api/v1/routes/{$routeId}")->assertOk();

        expect(Route::query()->whereKey($routeId)->exists())->toBeFalse()
            ->and($this->nodes->names('10.44.0.7', "route-{$routeId}"))->toBeFalse()
            ->and($this->nodes->hasCertificate('10.44.0.7', "route-{$routeId}"))->toBeFalse()
            ->and($this->nodes->removedWhileNamed)->toBe([])
            ->and($this->nodes->validates('10.44.0.7'))->toBeTrue();
    });

    it('refuses to remove a certificate that a stored site still names', function (): void {
        $routeId = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'collector.lab.orbit',
                'node_id' => $this->beast->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertCreated()
            ->json('data.id');
        $route = Route::query()->findOrFail($routeId);

        expect(fn () => certificate_order_certificates()->removeCustomProxy($route, $this->beast))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->step)->toBe('certificate-remove')
                    ->and($exception->errorCode)->toBe('app-dev.certificate_in_use');
            });

        expect($this->nodes->hasCertificate('10.44.0.7', "route-{$routeId}"))->toBeTrue()
            ->and($this->nodes->validates('10.44.0.7'))->toBeTrue();
    });
});

function certificate_order_node(string $name, int $octet, RoleName $role, ?Cluster $cluster = null): Node
{
    $node = Node::query()->create([
        'cluster_id' => $cluster?->id,
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$octet}",
        'wireguard_ip' => "10.44.0.{$octet}",
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'cluster_id' => $role === RoleName::Router ? $cluster?->id : null,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function certificate_order_bind_projectors(CertificateOrderNodes $nodes, CertificateOrderDnsRunner $dnsRuns): void
{
    $executor = new AppDevSshExecutor(
        $nodes,
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
    $sites = new AppDevSiteRepository;
    $caddy = new RemoteAppDevCaddyManager($sites, new AppDevCaddyConfigRenderer, $executor);
    $certificates = new RemoteAppDevCertificateManager(
        $executor,
        new class implements LeafCertificateSigner
        {
            public function sign(string $domain, string $certificateRequest): string
            {
                return "LEAF\n";
            }

            public function rootCertificate(): string
            {
                return "ROOT\n";
            }
        },
        new class implements ManagedUserAccountResolver
        {
            public function resolve(Node $node): ManagedUserAccount
            {
                return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
            }
        },
    );
    $dns = new DnsmasqPrivateDnsManager($dnsRuns, new AppDevDnsConfigRenderer($sites));

    app()->instance(RemoteAppDevCaddyManager::class, $caddy);
    app()->instance(RemoteAppDevCertificateManager::class, $certificates);
    app()->instance(
        CustomProxyRouteProjector::class,
        new NativeCustomProxyRouteProjector($certificates, $caddy, $dns),
    );
    app()->instance(
        RouteRemovalProjector::class,
        new NativeRouteRemovalProjector($dns, $certificates, $caddy, new RemoteAppDevRouteFirewallManager($executor)),
    );
}

function certificate_order_caddy(): RemoteAppDevCaddyManager
{
    return app(RemoteAppDevCaddyManager::class);
}

function certificate_order_certificates(): RemoteAppDevCertificateManager
{
    return app(RemoteAppDevCertificateManager::class);
}

/**
 * Nodes keyed by WireGuard address. Each keeps its live `app-dev.caddy` and its certificates, and
 * refuses a Caddy publish that names a missing certificate.
 */
final class CertificateOrderNodes implements SshExecutor
{
    /** @var array<string, string> */
    public array $live = [];

    /** @var array<string, array<string, true>> */
    public array $certificates = [];

    /** @var list<string> */
    public array $removedWhileNamed = [];

    public ?string $failCaddy = null;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $host = $connection->host;
        $input = is_string($command->input) ? $command->input : '';

        if (preg_match("/printf '%s' '([A-Za-z0-9+\\/=]*)' \\| base64 --decode \\|/", $input, $matches) === 1) {
            $configuration = (string) base64_decode($matches[1], true);

            if ($host === $this->failCaddy || ! $this->allNamedExist($host, $configuration)) {
                return new CommandResult(1, '', 'caddy validate failed', 1, false);
            }

            $this->live[$host] = $configuration;

            return new CommandResult(0, '', '', 1, false);
        }

        if (str_contains($input, "printf 'CURRENT\\n'")) {
            $this->issue($host, (string) $command->arguments[3]);

            return new CommandResult(0, "CURRENT\n", '', 1, false);
        }

        if (str_contains($input, 'sudo rm -rf -- "/etc/caddy/orbit-certificates/$scope"')) {
            $scope = (string) $command->arguments[3];

            if ($this->names($host, $scope)) {
                $this->removedWhileNamed[] = "{$host}:{$scope}";
            }

            unset($this->certificates[$host][$scope]);
        }

        return new CommandResult(0, '', '', 1, false);
    }

    public function issue(string $host, string $scope): void
    {
        $this->certificates[$host][$scope] = true;
    }

    public function hasCertificate(string $host, string $scope): bool
    {
        return isset($this->certificates[$host][$scope]);
    }

    public function names(string $host, string $scope): bool
    {
        return in_array($scope, $this->namedScopes($this->live[$host] ?? ''), true);
    }

    public function validates(string $host): bool
    {
        return $this->allNamedExist($host, $this->live[$host] ?? '');
    }

    private function allNamedExist(string $host, string $configuration): bool
    {
        foreach ($this->namedScopes($configuration) as $scope) {
            if (! $this->hasCertificate($host, $scope)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function namedScopes(string $configuration): array
    {
        preg_match_all('#/etc/caddy/orbit-certificates/([^/\s]+)/current/cert\.pem#', $configuration, $matches);

        return array_values(array_unique($matches[1]));
    }
}

final class CertificateOrderDnsRunner implements ProcessRunner
{
    public bool $failNext = false;

    public function run(ProcessInvocation $invocation): CommandResult
    {
        if ($this->failNext) {
            $this->failNext = false;

            return new CommandResult(1, '', 'injected DNS failure', 1, false);
        }

        return new CommandResult(0, '', '', 1, false);
    }
}
