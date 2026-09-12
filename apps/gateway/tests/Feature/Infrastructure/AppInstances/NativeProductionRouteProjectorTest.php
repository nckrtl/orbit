<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Domain\AppInstances\ProductionReleaseLayout;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppInstances\NativeProductionRouteProjector;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Str;

it('projects a production workload through a remote Router over LAN without public infrastructure', function (): void {
    [$appInstance, $route, $workload, $router] = orb199_production_route_models(
        workloadLan: '10.10.0.10',
        routerLan: '10.10.0.20',
    );
    [$projector, $ssh, $processes, $productionPhp, $firewall] = orb199_production_route_projector();

    $projector->prepareRuntime($appInstance, $route);
    $projector->prepareCertificate($appInstance, $route);
    $projector->prepareFirewall($appInstance);
    $projector->prepareWorkloadCaddy($appInstance, $route);
    $projector->prepareRouterCertificate($appInstance, $route);
    $projector->prepareRouteFirewall($appInstance, $route);
    $projector->verifyWorkload($appInstance, $route);
    $projector->prepareRouterCaddy($appInstance, $route);
    $projector->prepareDns($route);

    $arguments = collect($ssh->commands)->flatMap(
        static fn (array $entry): array => $entry['command']->arguments,
    );
    $firewallCommand = collect($ssh->commands)
        ->pluck('command')
        ->first(static fn (RemoteCommand $command): bool => ($command->arguments[1] ?? null) === 'ufw');
    $leaf = collect($ssh->commands)
        ->pluck('command')
        ->first(static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true));
    $sites = new AppDevSiteRepository;
    $renderer = new AppDevCaddyConfigRenderer;
    $workloadConfiguration = $renderer->render($sites->forNode($workload, $route));
    $routerConfiguration = $renderer->render($sites->forNode($router, $route));
    $dnsConfiguration = new AppDevDnsConfigRenderer($sites)->render(pendingRoute: $route);
    $publishedInputs = collect($ssh->commands)->pluck('command.input')->filter();

    expect($productionPhp->converged)->toBe([$appInstance->id])
        ->and($firewall->converged)->toBe([[$workload->id, RoleName::AppProd, 'orbit']])
        ->and($arguments)->toContain("app-instance-{$appInstance->id}", "route-{$route->id}-router")
        ->and($firewallCommand?->arguments)->toBe([
            'sudo',
            'ufw',
            'allow',
            'in',
            'proto',
            'tcp',
            'from',
            '10.10.0.20',
            'to',
            '10.10.0.10',
            'port',
            '443',
            'comment',
            "orbit:route-{$route->id}-lan",
        ])
        ->and($leaf?->arguments)->toBe([
            'timeout',
            '10',
            'openssl',
            's_client',
            '-connect',
            '10.10.0.10:443',
            '-servername',
            $route->hostname,
            '-verify_return_error',
        ])
        ->and($workloadConfiguration)->toContain(
            "php_fastcgi unix//run/php/orbit-app-{$appInstance->app_id}.sock",
            'resolve_root_symlink',
            "tls /etc/caddy/orbit-certificates/app-instance-{$appInstance->id}/current/cert.pem",
        )
        ->and($routerConfiguration)->toContain(
            'reverse_proxy https://10.10.0.10',
            "header_up Host {$route->hostname}",
            "tls_server_name {$route->hostname}",
            "tls /etc/caddy/orbit-certificates/route-{$route->id}-router/current/cert.pem",
        )
        ->and($publishedInputs->contains(
            static fn (string $input): bool => str_contains($input, base64_encode($workloadConfiguration)),
        ))->toBeTrue()
        ->and($publishedInputs->contains(
            static fn (string $input): bool => str_contains($input, base64_encode($routerConfiguration)),
        ))->toBeTrue()
        ->and($dnsConfiguration)->toContain("host-record={$route->hostname},{$router->wireguard_ip}")
        ->and($processes->invocations)->toHaveCount(1)
        ->and(json_encode($ssh->commands, JSON_THROW_ON_ERROR))->not->toContain('ingress', 'acme');
});

it('uses WireGuard without a Route-specific firewall rule when workload LAN is absent', function (): void {
    [$appInstance, $route] = orb199_production_route_models();
    [$projector, $ssh] = orb199_production_route_projector();

    $projector->prepareRouteFirewall($appInstance, $route);
    $projector->verifyWorkload($appInstance, $route);

    $leaf = collect($ssh->commands)
        ->pluck('command')
        ->first(static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true));
    expect($leaf?->arguments)->toContain('10.44.0.10:443')
        ->and(collect($ssh->commands)->pluck('command')->contains(
            static fn (RemoteCommand $command): bool => ($command->arguments[1] ?? null) === 'ufw',
        ))->toBeFalse();
});

it('uses one local production site when Router and workload share a Node', function (): void {
    [$appInstance, $route, $node] = orb199_production_route_models(coLocated: true);
    [$projector, $ssh, $processes] = orb199_production_route_projector();

    $projector->prepareRouterCertificate($appInstance, $route);
    $projector->prepareRouteFirewall($appInstance, $route);
    $projector->verifyWorkload($appInstance, $route);
    $projector->prepareWorkloadCaddy($appInstance, $route);
    $projector->prepareRouterCaddy($appInstance, $route);
    $projector->prepareDns($route);

    $sites = new AppDevSiteRepository()->forNode($node, $route);
    $configuration = new AppDevCaddyConfigRenderer()->render($sites);
    $arguments = collect($ssh->commands)->flatMap(
        static fn (array $entry): array => $entry['command']->arguments,
    );
    expect($sites)->toHaveCount(1)
        ->and($sites->sole()->isProxy())->toBeFalse()
        ->and($configuration)->toContain('resolve_root_symlink')->not->toContain('reverse_proxy')
        ->and($arguments)->not->toContain("route-{$route->id}-router", 'ufw', 's_client')
        ->and($processes->invocations)->toHaveCount(1);
});

it('refuses incompatible LAN and missing Router placement with bounded errors', function (string $case): void {
    [$appInstance, $route] = orb199_production_route_models(
        workloadLan: $case === 'LAN' ? '10.10.0.10' : null,
        withRouter: $case !== 'Router',
    );
    [$projector] = orb199_production_route_projector();

    $operation = $case === 'LAN'
        ? fn () => $projector->prepareRouteFirewall($appInstance, $route)
        : fn () => $projector->prepareRouterCertificate($appInstance, $route);

    expect($operation)->toThrow(function (RuntimeConvergenceException $exception) use ($case): void {
        expect([$exception->step, $exception->errorCode])->toBe($case === 'LAN'
            ? ['route-address', 'route.lan_unreachable']
            : ['router', 'cluster.router_required']);
    });
})->with(['LAN', 'Router']);

it('refuses an invalid workload leaf before Router Caddy and DNS publication', function (): void {
    [$appInstance, $route] = orb199_production_route_models();
    [$projector, $ssh, $processes] = orb199_production_route_projector(
        static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true),
    );

    expect(function () use ($projector, $appInstance, $route): void {
        $projector->prepareWorkloadCaddy($appInstance, $route);
        $projector->prepareRouterCertificate($appInstance, $route);
        $projector->prepareRouteFirewall($appInstance, $route);
        $projector->verifyWorkload($appInstance, $route);
        $projector->prepareRouterCaddy($appInstance, $route);
        $projector->prepareDns($route);
    })->toThrow(function (RuntimeConvergenceException $exception): void {
        expect([$exception->step, $exception->errorCode])
            ->toBe(['workload-certificate', 'app-dev.workload_certificate_invalid']);
    });

    $caddyPublications = collect($ssh->commands)->filter(
        static fn (array $entry): bool => str_contains(
            $entry['command']->input ?? '',
            'caddy validate --config "$candidate/Caddyfile"',
        ),
    );
    expect($processes->invocations)->toBeEmpty()
        ->and($caddyPublications)->toHaveCount(1)
        ->and($caddyPublications->sole()['connection']->host)->toBe('10.44.0.10');
});

/** @return array{AppInstance, Route, Node, Node} */
function orb199_production_route_models(
    bool $coLocated = false,
    ?string $workloadLan = null,
    ?string $routerLan = null,
    bool $withRouter = true,
): array {
    $cluster = Cluster::query()->create([
        'name' => 'production-routing-'.Str::lower(Str::random(8)),
        'state' => ClusterState::Active,
    ]);
    $workload = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'production-workload-'.Str::lower(Str::random(8)),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'lan_ip' => $workloadLan,
        'user' => 'orbit',
    ]);
    $workload->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $router = $coLocated
        ? $workload
        : Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => 'production-router-'.Str::lower(Str::random(8)),
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.20',
            'wireguard_ip' => '10.44.0.20',
            'lan_ip' => $routerLan,
            'user' => 'orbit',
        ]);
    if ($withRouter) {
        $router->roles()->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
    }
    $app = OrbitApp::query()->create([
        'name' => 'Production route',
        'slug' => 'production-route-'.Str::lower(Str::random(8)),
        'repository_url' => 'https://example.test/production-route.git',
        'root' => 'public',
    ]);
    $appInstance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$app->id}/releases/initial",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'production_user' => "orbit-app-{$app->id}",
        'production_home' => "/home/orbit-app-{$app->id}",
        'production_php_service' => "php8.5-fpm-orbit-app-{$app->id}.service",
        'production_php_pool' => "orbit-app-{$app->id}",
        'production_php_socket' => "/run/php/orbit-app-{$app->id}.sock",
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'preview.prod.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'app_instance_id' => $appInstance->id,
        'position' => 0,
    ]);

    return [$appInstance, $route, $workload, $router];
}

/**
 * @return array{
 *     NativeProductionRouteProjector,
 *     Orb199ProductionRouteSshExecutor,
 *     Orb199ProductionRouteProcessRunner,
 *     Orb199ProductionPhpRuntime,
 *     Orb199ProductionFirewall
 * }
 */
function orb199_production_route_projector(?Closure $failSsh = null): array
{
    $ssh = new Orb199ProductionRouteSshExecutor($failSsh);
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $executor = new AppDevSshExecutor($ssh, $keys, $knownHosts);
    $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    $accounts = new class($account) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $signer = new class implements LeafCertificateSigner
    {
        public function sign(string $hostname, string $certificateRequest): string
        {
            return "LEAF CERTIFICATE\n";
        }

        public function rootCertificate(): string
        {
            return "ROOT CERTIFICATE\n";
        }
    };
    $sites = new AppDevSiteRepository;
    $processes = new Orb199ProductionRouteProcessRunner;
    $productionPhp = new Orb199ProductionPhpRuntime;
    $firewall = new Orb199ProductionFirewall;
    $projector = new NativeProductionRouteProjector(
        $productionPhp,
        new RemoteAppDevPhpFpmManager(
            $sites,
            new AppDevPhpFpmConfigRenderer,
            $executor,
            $accounts,
            new RemotePhpPackageManager,
        ),
        new RemoteAppDevCertificateManager($executor, $signer, $accounts),
        $firewall,
        new RemoteAppDevCaddyManager($sites, new AppDevCaddyConfigRenderer, $executor),
        new DnsmasqPrivateDnsManager($processes, new AppDevDnsConfigRenderer($sites)),
        new Orb199ProductionReleaseLayout,
        $executor,
    );

    return [$projector, $ssh, $processes, $productionPhp, $firewall];
}

final class Orb199ProductionRouteSshExecutor implements SshExecutor
{
    /** @var list<array{connection: SshConnection, command: RemoteCommand}> */
    public array $commands = [];

    public function __construct(
        private readonly ?Closure $failure = null,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = ['connection' => $connection, 'command' => $command];

        if ($this->failure instanceof Closure && ($this->failure)($command) === true) {
            return new CommandResult(1, '', 'injected failure', 1, false);
        }

        return new CommandResult(0, '', '', 1, false);
    }
}

final class Orb199ProductionRouteProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        return new CommandResult(0, '', '', 1, false);
    }
}

final class Orb199ProductionPhpRuntime implements ProductionPhpRuntimeManager
{
    /** @var list<int> */
    public array $converged = [];

    public function converge(AppInstance $appInstance): void
    {
        $this->converged[] = $appInstance->id;
    }

    public function refreshCache(AppInstance $appInstance): void {}

    public function remove(AppInstance $appInstance): void {}
}

final class Orb199ProductionFirewall implements NodeRoleFirewallManager
{
    /** @var list<array{int, RoleName, string}> */
    public array $converged = [];

    public function convergeBase(Node $node, string $managedUser): void {}

    public function converge(Node $node, RoleName $role, string $managedUser): void
    {
        $this->converged[] = [$node->id, $role, $managedUser];
    }

    public function remove(Node $node, RoleName $role, string $managedUser): void {}
}

final class Orb199ProductionReleaseLayout implements ProductionReleaseLayout
{
    public function validateCurrent(AppInstance $appInstance): void {}

    public function clearCurrent(AppInstance $appInstance): void {}
}
