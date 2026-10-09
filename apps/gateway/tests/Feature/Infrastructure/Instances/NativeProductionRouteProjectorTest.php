<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Data\Routes\CreateRouteData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Instances\ProductionReleaseLayout;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Instances\NativeProductionRouteProjector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Str;
use Tests\Support\SshNodeCaddyBuilds;

it('creates an Instance Route with the native production projector on a separate Router', function (): void {
    [$source, $oldRoute] = orb199_production_route_models(workloadLan: '10.10.0.10', routerLan: '10.10.0.20');
    $oldRoute->targets()->delete();
    $oldRoute->delete();
    $source->delete();
    $instance = Instance::query()->create([
        'project_id' => $source->project_id,
        'node_id' => $source->node_id,
        'name' => 'created',
        'environment' => 'production',
        'checkout_path' => $source->checkout_path,
        'app_overrides' => fixture_app_overrides('public'),
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'production_user' => $source->production_user,
        'production_home' => $source->production_home,
        'production_php_service' => $source->production_php_service,
        'production_php_pool' => $source->production_php_pool,
        'production_php_socket' => $source->production_php_socket,
        'status' => InstanceState::Active,
    ]);
    [$projector, $ssh] = orb199_production_route_projector();
    app()->instance(ProductionRouteProjector::class, $projector);
    app()->instance(ProductionCloneRouteProjector::class, $projector);

    $route = app(CreateRouteAction::class)->executeForRouteCreate(new CreateRouteData(
        domain: 'created.prod.orbit',
        publication: RoutePublication::Private,
        instanceId: $instance->id,
    ))['route'];

    $commands = collect($ssh->commands)->pluck('command');
    $routerCertificateIndex = $commands->search(static fn (RemoteCommand $command): bool => in_array("route-{$route->id}-router", $command->arguments, true));
    $routerSiteIndex = $commands->search(static fn (RemoteCommand $command): bool => str_contains(SshNodeCaddyBuilds::pushed($command) ?? '', "route-{$route->id}-router/current/cert.pem"));
    expect($route->status)->toBe(RouteStatus::Active)
        ->and($route->targets->sole()->instance_id)->toBe($instance->id)
        ->and($routerCertificateIndex)->not->toBeFalse()
        ->and($routerSiteIndex)->not->toBeFalse()
        ->and($routerCertificateIndex)->toBeLessThan($routerSiteIndex)
        ->and($commands->contains(static fn (RemoteCommand $command): bool => ($command->arguments[1] ?? null) === 'ufw'))->toBeTrue()
        ->and($commands->contains(static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true)))->toBeTrue();
});

it('refuses to project production without its dedicated PHP-FPM service', function (): void {
    [$instance, $route] = orb199_production_route_models(
        workloadLan: '10.10.0.10',
        routerLan: '10.10.0.20',
    );
    $instance->update([
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);
    [$projector] = orb199_production_route_projector();

    expect(fn () => $projector->prepareRuntime($instance, $route))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-prod.php_service_missing')
                ->and($exception->getMessage())
                ->toBe('The production Instance has no recorded dedicated PHP-FPM service.');
        });
});

it('projects a production workload through a remote Router over LAN without public infrastructure', function (): void {
    [$instance, $route, $workload, $router] = orb199_production_route_models(
        workloadLan: '10.10.0.10',
        routerLan: '10.10.0.20',
    );
    [$projector, $ssh, $processes, $productionPhp, $firewall] = orb199_production_route_projector();

    $projector->prepareRuntime($instance, $route);
    $projector->prepareCertificate($instance, $route);
    $projector->prepareFirewall($instance);
    $projector->prepareWorkloadCaddy($instance, $route);
    $projector->prepareRouterCertificate($instance, $route);
    $projector->prepareRouteFirewall($instance, $route);
    $projector->verifyWorkload($instance, $route);
    $projector->prepareRouterCaddy($instance, $route);
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
    $workloadConfiguration = app(NodeCaddyfileRenderer::class)->render($workload)->content;
    $routerConfiguration = app(NodeCaddyfileRenderer::class)->render($router)->content;
    $dnsConfiguration = new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository)->render();
    $pushed = collect($ssh->commands)
        ->map(static fn (array $entry): ?string => SshNodeCaddyBuilds::pushed($entry['command']))
        ->filter();

    expect($productionPhp->converged)->toBe([$instance->id])
        ->and($firewall->converged)->toBe([[$workload->id, RoleName::AppProd, 'orbit']])
        ->and($arguments)->toContain("app-instance-{$instance->id}", "route-{$route->id}-router")
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
            $route->domain,
            '-verify_return_error',
        ])
        ->and($workloadConfiguration)->toContain(
            "php_fastcgi unix//run/php/orbit-app-{$instance->project_id}.sock",
            'resolve_root_symlink',
            "tls /etc/caddy/orbit-certificates/app-instance-{$instance->id}/current/cert.pem",
        )
        ->and($routerConfiguration)->toContain(
            'reverse_proxy https://10.10.0.10',
            "header_up Host {$route->domain}",
            "tls_server_name {$route->domain}",
            "tls /etc/caddy/orbit-certificates/route-{$route->id}-router/current/cert.pem",
        )
        ->and($pushed->contains($workloadConfiguration))->toBeTrue()
        ->and($pushed->contains($routerConfiguration))->toBeTrue()
        ->and(collect($ssh->commands)->pluck('command')->contains(
            static fn (RemoteCommand $command): bool => str_contains((string) $command->input, 'fragments/*.caddy') || str_contains((string) $command->input, 'app-dev.caddy'),
        ))->toBeFalse()
        ->and($dnsConfiguration)->toContain("host-record={$route->domain},{$router->wireguard_ip}")
        ->and($processes->invocations)->toHaveCount(1)
        ->and(json_encode($ssh->commands, JSON_THROW_ON_ERROR))->not->toContain('ingress', 'acme');
});

it('uses WireGuard without a Route-specific firewall rule when workload LAN is absent', function (): void {
    [$instance, $route] = orb199_production_route_models();
    [$projector, $ssh] = orb199_production_route_projector();

    $projector->prepareRouteFirewall($instance, $route);
    $projector->verifyWorkload($instance, $route);

    $leaf = collect($ssh->commands)
        ->pluck('command')
        ->first(static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true));
    expect($leaf?->arguments)->toContain('10.44.0.10:443')
        ->and(collect($ssh->commands)->pluck('command')->contains(
            static fn (RemoteCommand $command): bool => ($command->arguments[1] ?? null) === 'ufw',
        ))->toBeFalse();
});

it('uses one local production site when Router and workload share a Node', function (): void {
    [$instance, $route, $node] = orb199_production_route_models(coLocated: true);
    [$projector, $ssh, $processes] = orb199_production_route_projector();

    $projector->prepareRouterCertificate($instance, $route);
    $projector->prepareRouteFirewall($instance, $route);
    $projector->verifyWorkload($instance, $route);
    $projector->prepareWorkloadCaddy($instance, $route);
    $projector->prepareRouterCaddy($instance, $route);
    $projector->prepareDns($route);

    $sites = new DevelopmentSiteRepository()->forNode($node, $route);
    $configuration = new DevelopmentCaddyConfigRenderer()->render($sites);
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
    [$instance, $route] = orb199_production_route_models(
        workloadLan: $case === 'LAN' ? '10.10.0.10' : null,
        withRouter: $case !== 'Router',
    );
    [$projector] = orb199_production_route_projector();

    $operation = $case === 'LAN'
        ? fn () => $projector->prepareRouteFirewall($instance, $route)
        : fn () => $projector->prepareRouterCertificate($instance, $route);

    expect($operation)->toThrow(function (RuntimeConvergenceException $exception) use ($case): void {
        expect([$exception->step, $exception->errorCode])->toBe($case === 'LAN'
            ? ['route-address', 'route.lan_unreachable']
            : ['router', 'cluster.router_required']);
    });
})->with(['LAN', 'Router']);

it('refuses an invalid workload leaf before Router Caddy and DNS publication', function (): void {
    [$instance, $route] = orb199_production_route_models();
    [$projector, $ssh, $processes] = orb199_production_route_projector(
        static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true),
    );

    expect(function () use ($projector, $instance, $route): void {
        $projector->prepareWorkloadCaddy($instance, $route);
        $projector->prepareRouterCertificate($instance, $route);
        $projector->prepareRouteFirewall($instance, $route);
        $projector->verifyWorkload($instance, $route);
        $projector->prepareRouterCaddy($instance, $route);
        $projector->prepareDns($route);
    })->toThrow(function (RuntimeConvergenceException $exception): void {
        expect([$exception->step, $exception->errorCode])
            ->toBe(['workload-certificate', 'app-dev.workload_certificate_invalid']);
    });

    $caddyPublications = collect($ssh->commands)->filter(
        static fn (array $entry): bool => SshNodeCaddyBuilds::pushed($entry['command']) !== null,
    );
    expect($processes->invocations)->toBeEmpty()
        ->and($caddyPublications)->toHaveCount(1)
        ->and($caddyPublications->sole()['connection']->host)->toBe('10.44.0.10');
});

/** @return array{Instance, Route, Node, Node} */
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
    $project = Project::query()->create([
        'name' => 'Production route',
        'slug' => 'production-route-'.Str::lower(Str::random(8)),
        'repository_url' => 'https://example.test/production-route.git',
        'apps' => fixture_apps('public'),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $workload->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$project->id}/releases/initial",
        'app_overrides' => fixture_app_overrides('public'),
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'production_user' => "orbit-app-{$project->id}",
        'production_home' => "/home/orbit-app-{$project->id}",
        'production_php_service' => "php8.5-fpm-orbit-app-{$project->id}.service",
        'production_php_pool' => "orbit-app-{$project->id}",
        'production_php_socket' => "/run/php/orbit-app-{$project->id}.sock",
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'domain' => 'preview.prod.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);

    return [$instance, $route, $workload, $router];
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
    $executor = new DevelopmentSshExecutor($ssh, $keys, $knownHosts);
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
        public function sign(string $domain, string $certificateRequest): string
        {
            return "LEAF CERTIFICATE\n";
        }

        public function rootCertificate(): string
        {
            return "ROOT CERTIFICATE\n";
        }
    };
    $sites = new DevelopmentSiteRepository;
    $processes = new Orb199ProductionRouteProcessRunner;
    $productionPhp = new Orb199ProductionPhpRuntime;
    $firewall = new Orb199ProductionFirewall;
    $projector = new NativeProductionRouteProjector(
        $productionPhp,
        new RemoteAppDevCertificateManager($executor, $signer, $accounts),
        $firewall,
        new RemoteAppDevCaddyManager(SshNodeCaddyBuilds::over($ssh), $executor),
        new DnsmasqPrivateDnsManager($processes, new DevelopmentDnsConfigRenderer($sites)),
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

    public function converge(Instance $instance): void
    {
        $this->converged[] = $instance->id;
    }

    public function convergeMonitoring(Instance $instance, bool $enabled): void {}

    public function refreshCache(Instance $instance): void {}

    public function remove(Instance $instance): void {}
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

    public function restorePublicSsh(Node $node, string $managedUser): void {}

    public function trustWireGuardMembers(Node $node, string $managedUser): void {}
}

final class Orb199ProductionReleaseLayout implements ProductionReleaseLayout
{
    public function validateCurrent(Instance $instance): void {}

    public function clearCurrent(Instance $instance): void {}
}
