<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppInstances\NativeDevelopmentRouteProjector;
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
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('uses one local workload site when Router and workload roles share a Node', function (): void {
    [$appInstance, $route, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
    $sites = new AppDevSiteRepository;
    $nodeSites = $sites->forNode($node, $route);
    $configuration = new AppDevCaddyConfigRenderer()->render($nodeSites);
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        $projector->converge($appInstance, $route);

        $arguments = collect($ssh->commands)
            ->flatMap(
                static fn (RemoteCommand $command): array => $command->arguments,
            );
        expect($nodeSites)
            ->toHaveCount(1)
            ->and($nodeSites->sole()->isProxy())
            ->toBeFalse()
            ->and(mb_substr_count($configuration, "https://{$route->hostname}"))
            ->toBe(1)
            ->and($configuration)
            ->toContain("php_fastcgi unix//run/php/orbit-app-instance-{$appInstance->id}.sock")
            ->not->toContain('reverse_proxy')->and($arguments)->toContain("app-instance-{$appInstance->id}")
            ->not->toContain("route-{$route->id}-router", 'ufw', 's_client')->and($processes->invocations)->toHaveCount(
                1,
            );
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('renders old and candidate hostname sites with separate certificate scopes before DNS cutover', function (): void {
    [$appInstance, $route, $workload, $router] = orb127_route_projection_models();
    $appInstance->update(['status' => AppInstanceState::Active]);
    $route->update(['status' => RouteStatus::Active]);
    $route->refresh()->load(['targets.appInstance.app', 'targets.appInstance.node', 'cluster.routerAssignment.node']);
    $candidate = clone $route;
    $candidate->hostname = 'next.acme.test';
    $sites = new AppDevSiteRepository;

    $workloadSites = $sites->forNode($workload, additionalRoute: $candidate);
    $routerSites = $sites->forNode($router, additionalRoute: $candidate);
    $dns = new AppDevDnsConfigRenderer($sites)->render(additionalRoute: $candidate);

    expect($workloadSites->pluck('hostname')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($workloadSites->map->certificateDirectory()->all())
        ->toBe([
            "/etc/caddy/orbit-certificates/app-instance-{$appInstance->id}/current",
            "/etc/caddy/orbit-certificates/app-instance-{$appInstance->id}-hostname-change/current",
        ])
        ->and($routerSites->pluck('hostname')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($routerSites->map->certificateDirectory()->all())
        ->toBe([
            "/etc/caddy/orbit-certificates/route-{$route->id}-router/current",
            "/etc/caddy/orbit-certificates/route-{$route->id}-router-hostname-change/current",
        ])
        ->and($dns)
        ->toContain(
            "host-record=feature.acme.test,{$router->wireguard_ip}",
            "host-record=next.acme.test,{$router->wireguard_ip}",
        );
});

it('hydrates only requested workload and Router routes while global inventory stays complete', function (): void {
    [$pendingInstance, $pendingRoute, $workload, $router] = orb127_route_projection_models();
    $activeInstance = AppInstance::query()->create([
        'app_id' => $pendingInstance->app_id,
        'node_id' => $workload->id,
        'name' => 'active',
        'checkout_path' => '/home/orbit/apps/acme/active',
        'root' => 'public',
        'selected_php_version' => '8.5',
        'status' => 'source_resolved',
    ]);
    $activeRoute = Route::query()->create([
        'app_id' => $pendingInstance->app_id,
        'cluster_id' => $pendingRoute->cluster_id,
        'hostname' => 'active.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $activeRoute->targets()->create(['app_instance_id' => $activeInstance->id, 'position' => 0]);
    $activeRoute->update(['status' => RouteStatus::Active]);
    $activeInstance->update(['status' => 'active']);
    $failedInstance = AppInstance::query()->create([
        'app_id' => $pendingInstance->app_id,
        'node_id' => $workload->id,
        'name' => 'failed',
        'checkout_path' => '/home/orbit/apps/acme/failed',
        'root' => 'public',
        'selected_php_version' => '8.5',
        'status' => 'source_resolved',
    ]);
    $failedRoute = Route::query()->create([
        'app_id' => $pendingInstance->app_id,
        'cluster_id' => $pendingRoute->cluster_id,
        'hostname' => 'failed.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $failedRoute->targets()->create(['app_instance_id' => $failedInstance->id, 'position' => 0]);
    $failedRoute->update([
        'status' => RouteStatus::Failed,
        'failed_step' => 'runtime',
        'error_code' => 'app-dev.runtime_failed',
    ]);
    $unrelatedNode = Node::query()->create([
        'name' => 'unrelated-workload',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
        'user' => 'orbit',
    ]);
    $unrelatedInstance = AppInstance::query()->create([
        'app_id' => $pendingInstance->app_id,
        'node_id' => $unrelatedNode->id,
        'name' => 'unrelated',
        'checkout_path' => '/home/orbit/apps/acme/unrelated',
        'root' => 'public',
        'selected_php_version' => '8.5',
        'status' => 'source_resolved',
    ]);
    $unrelatedRoute = Route::query()->create([
        'app_id' => $pendingInstance->app_id,
        'node_id' => $unrelatedNode->id,
        'hostname' => 'unrelated.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $unrelatedRoute->targets()->create(['app_instance_id' => $unrelatedInstance->id, 'position' => 0]);
    $unrelatedRoute->update(['status' => RouteStatus::Active]);
    $unrelatedInstance->update(['status' => 'active']);
    $sites = new AppDevSiteRepository;
    $globalSites = $sites->all($pendingRoute);
    $retrievedRoutes = collect();
    $retrievedInstances = collect();
    Event::listen(
        'eloquent.retrieved: '.Route::class,
        static function (Route $retrieved) use ($retrievedRoutes): void {
            $retrievedRoutes->push($retrieved->id);
        },
    );
    Event::listen(
        'eloquent.retrieved: '.AppInstance::class,
        static function (AppInstance $retrieved) use ($retrievedInstances): void {
            $retrievedInstances->push($retrieved->id);
        },
    );

    $workloadSites = $sites->forNode($workload, $pendingRoute);

    $siteIdentity = static fn (AppDevSite $site): array => [
        $site->scope,
        $site->hostname,
        $site->nodeAddress,
        $site->upstreamAddresses,
    ];
    expect($workloadSites->map($siteIdentity)->all())
        ->toBe($globalSites->where('nodeId', $workload->id)->values()->map($siteIdentity)->all())
        ->and($workloadSites->pluck('scope')->all())
        ->toHaveCount(2)
        ->toContain("app-instance-{$pendingInstance->id}", "app-instance-{$activeInstance->id}")
        ->and($workloadSites->pluck('hostname'))
        ->not->toContain($failedRoute->hostname, $unrelatedRoute->hostname)->and($retrievedRoutes)->toContain(
            $pendingRoute->id,
            $activeRoute->id,
        )
        ->not->toContain($failedRoute->id, $unrelatedRoute->id)->and($retrievedInstances)->toContain(
            $pendingInstance->id,
            $activeInstance->id,
        )
        ->not->toContain($failedInstance->id, $unrelatedInstance->id)->and($globalSites->pluck('hostname'))->toContain(
            $pendingRoute->hostname,
            $activeRoute->hostname,
            $unrelatedRoute->hostname,
        )
        ->not->toContain($failedRoute->hostname);

    $routerSites = $sites->forNode($router, $pendingRoute);

    expect($routerSites->map($siteIdentity)->all())
        ->toBe($globalSites->where('nodeId', $router->id)->values()->map($siteIdentity)->all())
        ->and($routerSites->pluck('scope')->all())
        ->toHaveCount(2)
        ->toContain("route-{$pendingRoute->id}-router", "route-{$activeRoute->id}-router");
});

it('projects a dedicated Router over reachable LAN with separate keys and preserved TLS identity', function (): void {
    [$appInstance, $route, $workload, $router] = orb127_route_projection_models(
        workloadLan: '10.10.0.10',
        routerLan: '10.10.0.20',
    );
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        $projector->converge($appInstance, $route);

        $arguments = collect($ssh->commands)
            ->flatMap(
                static fn (RemoteCommand $command): array => $command->arguments,
            );
        $firewall = collect($ssh->commands)
            ->first(
                static fn (RemoteCommand $command): bool => ($command->arguments[1] ?? null) === 'ufw',
            );
        $leaf = collect($ssh->commands)
            ->first(
                static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true),
            );
        $routerConfiguration = new AppDevCaddyConfigRenderer()->render(
            new AppDevSiteRepository()->forNode($router, $route),
        );

        expect($arguments)
            ->toContain("app-instance-{$appInstance->id}", "route-{$route->id}-router")
            ->and($firewall?->arguments)
            ->toBe([
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
            ->and($leaf?->arguments)
            ->toBe([
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
            ->and($routerConfiguration)
            ->toContain(
                'reverse_proxy https://10.10.0.10',
                "header_up Host {$route->hostname}",
                "tls_server_name {$route->hostname}",
                "tls /etc/caddy/orbit-certificates/route-{$route->id}-router/current/cert.pem",
            )
            ->and(collect($ssh->commands)
                ->contains(
                    static fn (RemoteCommand $command): bool => str_contains(
                        $command->input ?? '',
                        base64_encode($routerConfiguration),
                    ),
                ))
            ->toBeTrue()
            ->and($processes->invocations)
            ->toHaveCount(1)
            ->and($workload->id)
            ->not->toBe($router->id);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('retains active workload and Router sites while publishing a second Route on the same Router', function (): void {
    [$firstInstance, $firstRoute, $workload, $router] = orb127_route_projection_models(phpVersion: '8.5');
    $firstInstance->update(['status' => AppInstanceState::Active]);
    $firstRoute->update(['status' => RouteStatus::Active]);
    $secondInstance = AppInstance::query()->create([
        'app_id' => $firstInstance->app_id,
        'node_id' => $workload->id,
        'name' => 'second',
        'checkout_path' => '/home/orbit/apps/acme/second',
        'root' => 'public',
        'branch' => 'second',
        'starting_commit' => str_repeat('b', 40),
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::SourceResolved,
    ]);
    $secondRoute = Route::query()->create([
        'app_id' => $firstRoute->app_id,
        'cluster_id' => $firstRoute->cluster_id,
        'hostname' => 'second.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $secondRoute
        ->targets()
        ->create([
            'app_instance_id' => $secondInstance->id,
            'position' => 0,
        ]);
    [$projector, $ssh, , $home] = orb127_route_projector();

    try {
        $projector->converge($secondInstance, $secondRoute);

        $sites = new AppDevSiteRepository;
        $renderer = new AppDevCaddyConfigRenderer;
        $workloadConfiguration = $renderer->render($sites->forNode($workload, $secondRoute));
        $routerConfiguration = $renderer->render($sites->forNode($router, $secondRoute));
        $publishedInputs = collect($ssh->commands)->pluck('input')->filter();

        expect($workloadConfiguration)
            ->toContain(
                "/etc/caddy/orbit-certificates/app-instance-{$firstInstance->id}/current/cert.pem",
                "/etc/caddy/orbit-certificates/app-instance-{$secondInstance->id}/current/cert.pem",
            )
            ->and($routerConfiguration)
            ->toContain(
                "/etc/caddy/orbit-certificates/route-{$firstRoute->id}-router/current/cert.pem",
                "/etc/caddy/orbit-certificates/route-{$secondRoute->id}-router/current/cert.pem",
            )
            ->and($publishedInputs->contains(
                static fn (string $input): bool => str_contains($input, base64_encode($workloadConfiguration)),
            ))
            ->toBeTrue()
            ->and($publishedInputs->contains(
                static fn (string $input): bool => str_contains($input, base64_encode($routerConfiguration)),
            ))
            ->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('uses WireGuard only when the workload has no configured LAN address', function (): void {
    [$appInstance, $route] = orb127_route_projection_models();
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        $projector->converge($appInstance, $route);

        $leaf = collect($ssh->commands)
            ->first(
                static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true),
            );
        expect($leaf?->arguments)
            ->toContain('10.44.0.10:443')
            ->and(collect($ssh->commands)
                ->contains(
                    static fn (RemoteCommand $command): bool => ($command->arguments[1] ?? null) === 'ufw',
                ))
            ->toBeFalse()
            ->and($processes->invocations)
            ->toHaveCount(1);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('refuses configured LAN without a Router LAN address before leaf validation or DNS', function (): void {
    [$appInstance, $route] = orb127_route_projection_models(workloadLan: '10.10.0.10');
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        expect(fn () => $projector->converge($appInstance, $route))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->step)
                    ->toBe('route-address')
                    ->and($exception->errorCode)
                    ->toBe('route.lan_unreachable');
            })
            ->and(collect($ssh->commands)
                ->contains(
                    static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true),
                ))
            ->toBeFalse()
            ->and($processes->invocations)
            ->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('refuses an invalid workload leaf before Router Caddy or DNS publication', function (): void {
    [$appInstance, $route] = orb127_route_projection_models();
    [$projector, $ssh, $processes, $home] = orb127_route_projector(
        static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true),
    );

    try {
        expect(fn () => $projector->converge($appInstance, $route))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect($exception->step)
                    ->toBe('workload-certificate')
                    ->and($exception->errorCode)
                    ->toBe('app-dev.workload_certificate_invalid');
            })
            ->and($processes->invocations)
            ->toBeEmpty()
            ->and(collect($ssh->commands)
                ->filter(
                    static fn (RemoteCommand $command): bool => str_contains(
                        $command->input ?? '',
                        'caddy validate --config "$candidate/Caddyfile"',
                    ),
                ))
            ->toHaveCount(1);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('reports runtime certificate firewall and DNS publication boundaries before activation', function (
    string $boundary,
    string $expectedStep,
    string $expectedCode,
): void {
    [$appInstance, $route] = orb127_route_projection_models(
        workloadLan: $boundary === 'firewall' ? '10.10.0.10' : null,
        routerLan: $boundary === 'firewall' ? '10.10.0.20' : null,
        phpVersion: $boundary === 'runtime' ? '8.5' : null,
    );
    $failure = match ($boundary) {
        'runtime' => static fn (RemoteCommand $command): bool => str_contains(
            $command->input ?? '',
            'for path in "$php_root"/*/fpm/pool.d/orbit-scopes.conf',
        ),
        'certificate' => static fn (RemoteCommand $command): bool => (
            ($command->arguments[3] ?? null) === "app-instance-{$appInstance->id}"
        ),
        'firewall' => static fn (RemoteCommand $command): bool => ($command->arguments[1] ?? null) === 'ufw',
        'dns' => null,
    };
    [$projector, , $processes, $home] = orb127_route_projector($failure, failDns: $boundary === 'dns');

    try {
        expect(fn () => $projector->converge($appInstance, $route))
            ->toThrow(function (RuntimeConvergenceException $exception) use ($expectedStep, $expectedCode): void {
                expect($exception->step)
                    ->toBe($expectedStep)
                    ->and($exception->errorCode)
                    ->toBe($expectedCode);
            });
        expect($processes->invocations)->toHaveCount($boundary === 'dns' ? 1 : 0);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
})->with([
    'runtime' => ['runtime', 'php-fpm-discover', 'app-dev.php_fpm_discovery_failed'],
    'certificate' => ['certificate', 'certificate-request', 'app-dev.certificate_request_failed'],
    'firewall' => ['firewall', 'route-firewall', 'app-dev.route_firewall_failed'],
    'publication' => ['dns', 'private-dns', 'app-dev.dns_config_failed'],
]);

/** @return array{AppInstance, Route, Node, Node} */
function orb127_route_projection_models(
    bool $coLocated = false,
    ?string $workloadLan = null,
    ?string $routerLan = null,
    ?string $phpVersion = null,
): array {
    $cluster = Cluster::query()->create([
        'name' => 'routing-'.Str::lower(Str::random(8)),
        'state' => ClusterState::Active,
    ]);
    $workload = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'workload-'.Str::lower(Str::random(8)),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'lan_ip' => $workloadLan,
        'user' => 'orbit',
    ]);
    $workload
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $router = $coLocated
        ? $workload
        : Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => 'router-'.Str::lower(Str::random(8)),
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.20',
            'wireguard_ip' => '10.44.0.20',
            'lan_ip' => $routerLan,
            'user' => 'orbit',
        ]);
    $router
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::lower(Str::random(8)),
        'repository_url' => 'https://example.test/acme.git',
        'root' => 'public',
    ]);
    $appInstance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $workload->id,
        'name' => 'feature',
        'checkout_path' => '/home/orbit/apps/acme/feature',
        'root' => 'public',
        'branch' => 'feature',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => $phpVersion,
        'status' => 'source_resolved',
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'feature.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route
        ->targets()
        ->create([
            'app_instance_id' => $appInstance->id,
            'position' => 0,
        ]);

    return [$appInstance, $route, $workload, $router];
}

/** @return array{NativeDevelopmentRouteProjector, Orb127RouteSshExecutor, Orb127RouteProcessRunner, string} */
function orb127_route_projector(?\Closure $failSsh = null, bool $failDns = false): array
{
    $ssh = new Orb127RouteSshExecutor($failSsh);
    $keys = new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
    $executor = new AppDevSshExecutor($ssh, $keys, $knownHosts);
    $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    $accounts = new class($account) implements ManagedUserAccountResolver {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $signer = new class implements LeafCertificateSigner {
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
    $processes = new Orb127RouteProcessRunner($failDns);
    $home = sys_get_temp_dir().'/orbit-route-projector-'.Str::uuid();
    config()->set('orbit.home', $home);
    $projector = new NativeDevelopmentRouteProjector(
        new RemoteAppDevPhpFpmManager(
            $sites,
            new AppDevPhpFpmConfigRenderer,
            $executor,
            $accounts,
            new RemotePhpPackageManager,
        ),
        new RemoteAppDevCertificateManager($executor, $signer, $accounts),
        new RemoteAppDevCaddyManager($sites, new AppDevCaddyConfigRenderer, $executor),
        new DnsmasqPrivateDnsManager($processes, new AppDevDnsConfigRenderer($sites)),
        $executor,
    );

    return [$projector, $ssh, $processes, $home];
}

final class Orb127RouteSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function __construct(
        private readonly ?Closure $failure = null,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        if ($this->failure instanceof Closure && ($this->failure)($command) === true) {
            return new CommandResult(1, '', 'injected failure', 1, false);
        }

        return new CommandResult(0, '', '', 1, false);
    }
}

final class Orb127RouteProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public function __construct(
        private readonly bool $fail,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        return $this->fail
            ? new CommandResult(1, '', 'injected failure', 1, false)
            : new CommandResult(0, '', '', 1, false);
    }
}
