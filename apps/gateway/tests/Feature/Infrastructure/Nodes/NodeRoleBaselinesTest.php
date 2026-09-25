<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsClickhouseConfigurationManager;
use App\Domain\Analytics\AnalyticsPublicationManager;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsSecretManager;
use App\Domain\Analytics\AnalyticsStorageConnection;
use App\Domain\Analytics\AnalyticsStorageProcessGuard;
use App\Domain\Analytics\PlausibleRuntimeLifecycle;
use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppProd\AppProdCaddyManager;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Metrics\MetricsPublicationManager;
use App\Domain\Metrics\MetricsPublicationReport;
use App\Domain\Metrics\MetricsReconcileDeferral;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\NodeRoleFollowUpReport;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\EffectiveStorageRoots;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Nodes\UbuntuRelease;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketPublicationManager;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Nodes\Roles\AnalyticsRoleBaseline;
use App\Infrastructure\Nodes\Roles\AppDevRoleBaseline;
use App\Infrastructure\Nodes\Roles\AppProdRoleBaseline;
use App\Infrastructure\Nodes\Roles\DatabaseRoleBaseline;
use App\Infrastructure\Nodes\Roles\GatewayRoleBaseline;
use App\Infrastructure\Nodes\Roles\IngressRoleBaseline;
use App\Infrastructure\Nodes\Roles\MetricsRoleBaseline;
use App\Infrastructure\Nodes\Roles\NativeRoleBaselineConverger;
use App\Infrastructure\Nodes\Roles\NodeRoleOperatingSystemGuard;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Nodes\Roles\RouterRoleBaseline;
use App\Infrastructure\Nodes\Roles\VpnRoleBaseline;
use App\Infrastructure\Nodes\Roles\WebSocketRoleBaseline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WebSocket\NativeWebSocketRuntimeLifecycle;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;
use App\Models\Process;
use Illuminate\Support\Facades\Log;

it('converges and removes only app development role-owned infrastructure', function (): void {
    expect(class_exists(AppDevRoleBaseline::class))->toBeTrue();

    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::AppDev);
    $baseline = app_dev_role_baseline($events);

    $baseline->converge($node, $assignment);
    $baseline->remove($node, $assignment, purgeData: true);

    expect($events)->toBe([
        "dns:{$node->id}",
        'ssh:caddy-source',
        'ssh:app-dev',
        'ssh:'.RuntimeHibernation::MarkerDirectory,
        'caddy:converge',
        'firewall:converge:app-dev',
        'caddy:remove',
        'firewall:remove:app-dev',
        'dns:none',
    ]);
});

it('rejects stored protected settings before preparing app-dev roots', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::AppDev);
    $node->update([
        'settings' => [
            'apps' => ['path' => '/etc/orbit'],
        ],
    ]);
    $baseline = app_dev_role_baseline($events);

    expect(fn () => $baseline->converge($node, $assignment))
        ->toThrow(ResourceOperationException::class)
        ->and($events)
        ->toBe([]);
});

it('converges only the private DNS record when an app development node is unreachable', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::AppDev);
    $baseline = app_dev_role_baseline($events);

    $baseline->removeUnreachable($node, $assignment);

    expect($events)->toBe(['dns:none']);
});

it('converges and removes only app production role-owned infrastructure', function (): void {
    expect(class_exists(AppProdRoleBaseline::class))->toBeTrue();

    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::AppProd);
    $baseline = app_prod_role_baseline($events);

    $baseline->converge($node, $assignment);
    $baseline->remove($node, $assignment, purgeData: true);

    expect($events)->toBe([
        'ssh:caddy-source',
        'ssh:app-prod',
        'caddy:converge',
        'firewall:converge:app-prod',
        'caddy:remove',
        'firewall:remove:app-prod',
    ]);
});

it('rebuilds shared publications when removing Router from a workload Node', function (RoleName $role): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::Router);
    $node->roles()->create([
        'role' => $role,
        'cluster_id' => $role === RoleName::Ingress ? $node->cluster_id : null,
        'status' => LifecycleStatus::Active,
    ]);

    router_role_baseline($events)->remove($node, $assignment, purgeData: false);

    expect($events)->toBe(['caddy:remove', 'firewall:remove:router']);
    expect($node->roles()->where('role', $role)->where('status', LifecycleStatus::Active)->exists())->toBeTrue();
})->with([RoleName::AppDev, RoleName::AppProd, RoleName::Ingress]);

it('converges removes and dispatches the dedicated Router-only baseline', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::Router, 'router-only');
    $router = router_role_baseline($events);

    $router->converge($node, $assignment);
    $router->remove($node, $assignment, purgeData: false);

    expect($events)->toBe([
        'ssh:caddy-source',
        'ssh:router',
        'caddy:converge',
        'firewall:converge:router',
        'caddy:remove',
        'firewall:remove:router',
    ]);

    $events = [];
    $metricsFleet = Mockery::mock(MetricsFleetReconciler::class);
    $metricsFleet
        ->shouldReceive('reconcile')
        ->times(3)
        ->andReturnUsing(static function () use (&$events): void {
            $events[] = 'metrics';
        });
    $owner = new NodeRoleBaselineClusterRouterOperationLock($events);
    $dispatcher = new NativeRoleBaselineConverger(
        gateway_role_baseline($events),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new MetricsRoleBaseline(
            Mockery::mock(MetricsRuntimeLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsExporterLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsPublicationManager::class)->shouldIgnoreMissing(),
            new MetricsGatewayResolver,
            new MetricsPublicationReport,
            Mockery::mock(MetricsCadvisorLifecycle::class)->shouldIgnoreMissing(),
        ),
        $metricsFleet,
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
        router_role_baseline($events),
        $owner,
    );

    $dispatcher->converge($node, $assignment);
    $dispatcher->remove($node, $assignment, purgeData: false);
    $dispatcher->removeUnreachable($node, $assignment);

    expect($events)->toBe([
        "owner:enter:{$assignment->cluster_id}",
        'guard:gateway',
        'ssh:caddy-source',
        'ssh:router',
        'caddy:converge',
        'firewall:converge:router',
        'metrics',
        "owner:exit:{$assignment->cluster_id}",
        "owner:enter:{$assignment->cluster_id}",
        'caddy:remove',
        'firewall:remove:router',
        'metrics',
        "owner:exit:{$assignment->cluster_id}",
        "owner:enter:{$assignment->cluster_id}",
        'metrics',
        "owner:exit:{$assignment->cluster_id}",
    ]);
});

it('does nothing when an app production node is unreachable', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::AppProd);
    $baseline = app_prod_role_baseline($events);

    $baseline->removeUnreachable($node, $assignment);

    expect($events)->toBe([]);
});

it('converges and removes the gateway role while VPN removal stays protected', function (): void {
    expect(class_exists(GatewayRoleBaseline::class))
        ->toBeTrue()
        ->and(class_exists(VpnRoleBaseline::class))
        ->toBeTrue();

    $events = [];
    [$gatewayNode, $gatewayAssignment] = role_baseline_models(RoleName::Gateway, name: 'gateway-role');
    [$vpnNode, $vpnAssignment] = role_baseline_models(RoleName::Vpn, name: 'vpn-role');
    $vpnAssignment->update(['status' => LifecycleStatus::Active]);
    $firewall = baseline_firewall($events);
    $ssh = baseline_ssh($events);
    $gateway = new GatewayRoleBaseline(
        $firewall,
        baseline_dns($events),
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor($ssh, baseline_keys(), baseline_known_hosts()),
    );
    $vpn = new VpnRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        $ssh,
        baseline_keys(),
        baseline_known_hosts(),
        $firewall,
        baseline_account_resolver(),
    );

    $gateway->converge($gatewayNode, $gatewayAssignment);
    $vpn->converge($vpnNode, $vpnAssignment);

    expect($events)->toBe([
        'ssh:caddy-source',
        'ssh:caddy',
        'firewall:converge:gateway',
        'dns:none',
        'ssh:'.GatewayPrivateDnsResolver::DROP_IN,
        'ssh:vpn',
        'firewall:converge:vpn',
    ])->and(NodeAccess::query()->where('consumer_node_id', $gatewayNode->id)->pluck('serving_node_id')->all())
        ->toBe([$vpnNode->id]);

    $gateway->remove($gatewayNode, $gatewayAssignment, purgeData: false);
    $gateway->removeUnreachable($gatewayNode, $gatewayAssignment);

    expect($events)->toBe([
        'ssh:caddy-source',
        'ssh:caddy',
        'firewall:converge:gateway',
        'dns:none',
        'ssh:'.GatewayPrivateDnsResolver::DROP_IN,
        'ssh:vpn',
        'firewall:converge:vpn',
        'ssh:'.GatewayPrivateDnsResolver::DROP_IN,
        'firewall:remove:gateway',
        'dns:none',
        'dns:none',
    ]);
    expect(fn () => $vpn->remove($vpnNode, $vpnAssignment, purgeData: false))
        ->toThrow(NodeRoleValidationException::class);
    expect(fn () => $vpn->removeUnreachable($vpnNode, $vpnAssignment))
        ->toThrow(NodeRoleValidationException::class, 'The VPN role cannot be removed.');
});

it('uses the node user for VPN prerequisite SSH connections', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::Vpn, 'vpn-user');
    $node->update(['user' => 'nckrtl']);

    $baseline = new VpnRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new class($events) implements SshExecutor
        {
            /** @param list<string> $events */
            public function __construct(
                private array &$events,
            ) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->events[] = "ssh-user:{$connection->user}";

                return new CommandResult(0, '', '', 1, false);
            }
        },
        baseline_keys(),
        baseline_known_hosts(),
        baseline_firewall($events),
        baseline_account_resolver(),
    );

    $baseline->converge($node, $assignment);

    expect($events)->toBe(['ssh-user:nckrtl', 'firewall:converge:vpn']);
});

it('passes a nondefault managed account into every baseline prerequisite command', function (): void {
    $events = [];
    $account = new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
    [$vpnNode, $vpnAssignment] = role_baseline_models(RoleName::Vpn, name: 'vpn-managed-account');
    [$appDevNode, $appDevAssignment] = role_baseline_models(RoleName::AppDev, name: 'app-dev-managed-account');
    [$appProdNode, $appProdAssignment] = role_baseline_models(RoleName::AppProd, name: 'app-prod-managed-account');
    $accounts = baseline_account_resolver($account);
    $ssh = new class($events) implements SshExecutor
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->events[] = json_encode($command->arguments, JSON_THROW_ON_ERROR);

            return new CommandResult(0, '', '', 1, false);
        }
    };

    new VpnRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        $ssh,
        baseline_keys(),
        baseline_known_hosts(),
        baseline_firewall($events),
        $accounts,
    )->converge($vpnNode, $vpnAssignment);

    new AppDevRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor($ssh, baseline_keys(), baseline_known_hosts()),
        new class implements AppDevCaddyManager
        {
            public function converge(Node $node): void {}

            public function remove(Node $node): void {}
        },
        baseline_firewall($events),
        new class implements PrivateDnsManager
        {
            public function converge(?Node $pendingNode = null): void {}
        },
        $accounts,
        new NodeSettingsNormalizer,
        new class implements NodeStorageRootPreparer
        {
            public function inspect(
                Node $node,
                ManagedUserAccount $account,
                StoragePath $path,
            ): void {}

            public function prepare(
                Node $node,
                ManagedUserAccount $account,
                EffectiveStorageRoots $roots,
            ): void {}
        },
        new ConfiguredStoragePathValidator(
            new NodeSettingsNormalizer,
            new StorageRootResolver(
                new NodeSettingsNormalizer,
                new ProtectedPathCatalog,
            ),
            new ProtectedPathCatalog,
        ),
    )->converge($appDevNode, $appDevAssignment);

    new AppProdRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new AppProdSshExecutor($ssh, baseline_keys(), baseline_known_hosts()),
        new class implements AppProdCaddyManager
        {
            public function converge(Node $node): void {}

            public function remove(Node $node): void {}
        },
        baseline_firewall($events),
        $accounts,
    )->converge($appProdNode, $appProdAssignment);

    expect($events)
        ->toContain(
            '["sudo","bash","-seu","--","vpn","nckrtl","nckrtl","\/srv\/users\/nckrtl","ubuntu","Node operating system [unknown\/unknown] is not supported.","1","resolute","dnsmasq","openssl"]',
            '["sudo","bash","-seu","--","app-dev","nckrtl","nckrtl","\/srv\/users\/nckrtl","ubuntu","Node operating system [unknown\/unknown] is not supported.","1","resolute","acl","attr","caddy","composer","docker.io","git","openssl","php-curl","php-xml","unzip"]',
            '["sudo","bash","-seu","--","app-prod","nckrtl","nckrtl","\/srv\/users\/nckrtl","ubuntu","Node operating system [unknown\/unknown] is not supported.","1","resolute","acl","attr","caddy","composer","docker.io","git","openssl","php-curl","php-xml","unzip"]',
        );
});

it('converges Docker prerequisites and leaves them installed on remove', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::Database, 'database-role');
    $baseline = database_role_baseline($events);

    $baseline->converge($node, $assignment);
    $baseline->remove($node, $assignment, purgeData: true);
    $baseline->removeUnreachable($node, $assignment);

    expect($events)->toBe(['ssh:database']);
});

it('converges database beside router without rewriting routing or node processes', function (): void {
    $events = [];
    [$node, $routerAssignment] = role_baseline_models(RoleName::Router, 'shared-router-database');
    $databaseAssignment = $node->roles()->create([
        'role' => RoleName::Database,
        'status' => 'provisioning',
    ]);
    $process = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => 'valkey',
        'runtime' => 'docker',
        'working_directory' => '/data',
        'runtime_config' => [
            'image' => 'valkey/valkey:8',
            'command' => ['valkey-server'],
            'ports' => ['127.0.0.1:6379:6379'],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => 'running',
        'status' => 'active',
    ]);
    $processSnapshot = $process->only([
        'id',
        'owner_type',
        'owner_id',
        'name',
        'runtime',
        'runtime_config',
        'restart_policy',
        'desired_state',
        'status',
    ]);
    $routerSnapshot = $routerAssignment->only(['id', 'node_id', 'cluster_id', 'role', 'status']);
    $metricsFleet = Mockery::mock(MetricsFleetReconciler::class);
    $metricsFleet
        ->shouldReceive('reconcile')
        ->once()
        ->andReturnUsing(static function () use (&$events): void {
            $events[] = 'metrics';
        });
    $dispatcher = new NativeRoleBaselineConverger(
        gateway_role_baseline($events),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new MetricsRoleBaseline(
            Mockery::mock(MetricsRuntimeLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsExporterLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsPublicationManager::class)->shouldIgnoreMissing(),
            new MetricsGatewayResolver,
            new MetricsPublicationReport,
            Mockery::mock(MetricsCadvisorLifecycle::class)->shouldIgnoreMissing(),
        ),
        $metricsFleet,
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
        router_role_baseline($events),
        new NodeRoleBaselineClusterRouterOperationLock($events),
        database_role_baseline($events),
    );

    $dispatcher->converge($node, $databaseAssignment);

    expect($events)
        ->toBe(['guard:gateway', 'ssh:database', 'metrics'])
        ->and($process->fresh()->only(array_keys($processSnapshot)))
        ->toBe($processSnapshot)
        ->and($routerAssignment->fresh()->only(array_keys($routerSnapshot)))
        ->toBe($routerSnapshot);
});

it('refuses database convergence without a WireGuard address', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::Database, 'database-unaddressed');
    $node->update(['wireguard_ip' => null]);

    expect(fn () => database_role_baseline($events)->converge($node, $assignment))
        ->toThrow(NodeRoleOperationException::class, 'has no WireGuard address.')
        ->and($events)
        ->toBe([]);
});

it('installs pinned Caddy before the gateway role firewall and stops when it cannot', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::Gateway, name: 'gateway-caddy');
    $ssh = new class($events) implements SshExecutor
    {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;
            $this->events[] = 'ssh:caddy-source';

            return new CommandResult(1, '', 'The caddy candidate does not come from the pinned Orbit source.', 1, false);
        }
    };
    $gateway = new GatewayRoleBaseline(
        baseline_firewall($events),
        baseline_dns($events),
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor($ssh, baseline_keys(), baseline_known_hosts()),
    );

    expect(fn () => $gateway->converge($node, $assignment))
        ->toThrow(function (NodeRoleOperationException $exception): void {
            expect($exception->step)->toBe('caddy-package-source')
                ->and($exception->errorCode)->toBe('node_role.convergence_failed')
                ->and($exception->underlyingErrorCode)->toBe('gateway.caddy_install_failed')
                ->and($exception->getMessage())->toBe('Gateway role step [caddy-package-source] failed on node [gateway-caddy].')
                ->and($exception->result?->exitCode)->toBe(1);
        })
        ->and($events)->toBe(['ssh:caddy-source'])
        ->and($ssh->commands[0]->arguments)
        ->toBe(['sudo', 'bash', '-seu', '--', ...CaddyPackageSourceProgram::arguments()])
        ->and($ssh->commands[0]->input)
        ->toBe(CaddyPackageSourceProgram::render());
});

it('routes the private domain on the Gateway machine to the configured VPN DNS address', function (
    ?string $configuredDnsServer,
    string $expectedAddress,
): void {
    $events = [];
    [$vpnNode, $vpnAssignment] = role_baseline_models(RoleName::Vpn, name: 'vpn-dns-holder');
    $vpnAssignment->update(['status' => LifecycleStatus::Active]);
    [$node, $assignment] = role_baseline_models(RoleName::Gateway, name: 'gateway-dns');
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', dnsServer: $configuredDnsServer, domain: 'mesh');
    $ssh = gateway_resolver_ssh($events, failResolver: false);
    $gateway = new GatewayRoleBaseline(
        baseline_firewall($events),
        baseline_dns($events),
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor($ssh, baseline_keys(), baseline_known_hosts()),
    );

    $gateway->converge($node, $assignment);

    $resolver = collect($ssh->commands)->last();
    expect($vpnNode->wireguard_ip)->toBe('10.44.0.2')
        ->and($resolver->arguments)->toBe(['sudo', 'bash', '-seu', '--', GatewayPrivateDnsResolver::DROP_IN])
        ->and($resolver->input)->toBe(new GatewayPrivateDnsResolver()->convergeScript($expectedAddress, 'mesh'))
        ->and(array_slice($events, -2))->toBe(['dns:none', 'ssh:resolver']);
})->with([
    'the VPN Node address' => [null, '10.44.0.2'],
    'the configured VPN DNS server' => ['10.44.0.53', '10.44.0.53'],
]);

it('keeps the gateway role converged, logs the underlying code, and reports a follow-up when the resolver step fails', function (): void {
    Log::spy();
    $events = [];
    [$vpnNode, $vpnAssignment] = role_baseline_models(RoleName::Vpn, name: 'vpn-dns-holder');
    $vpnAssignment->update(['status' => LifecycleStatus::Active]);
    [$node, $assignment] = role_baseline_models(RoleName::Gateway, name: 'gateway-dns');
    $followUps = new NodeRoleFollowUpReport;
    $gateway = new GatewayRoleBaseline(
        baseline_firewall($events),
        baseline_dns($events),
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor(gateway_resolver_ssh($events, failResolver: true), baseline_keys(), baseline_known_hosts()),
        followUps: $followUps,
    );

    $gateway->converge($node, $assignment);

    expect($events)->toContain('ssh:resolver')
        ->and($followUps->take())->toBe(
            'The Gateway machine does not route the private domain to Orbit VPN DNS (vpn.dns_resolver_failed). '
            .'The gateway role stays active. Fix the cause, then run `orbit node:role:add gateway-dns gateway --converge` again.',
        )
        ->and($followUps->take())->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(static fn (string $message, array $context): bool => $context === [
            'node' => 'gateway-dns',
            'error_code' => 'vpn.dns_resolver_failed',
            'exit_code' => 1,
        ]);
});

it('reports no follow-up when the resolver step succeeds', function (): void {
    $events = [];
    [, $vpnAssignment] = role_baseline_models(RoleName::Vpn, name: 'vpn-dns-holder');
    $vpnAssignment->update(['status' => LifecycleStatus::Active]);
    [$node, $assignment] = role_baseline_models(RoleName::Gateway, name: 'gateway-dns');
    $followUps = new NodeRoleFollowUpReport;
    $gateway = new GatewayRoleBaseline(
        baseline_firewall($events),
        baseline_dns($events),
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor(gateway_resolver_ssh($events, failResolver: false), baseline_keys(), baseline_known_hosts()),
        followUps: $followUps,
    );

    $gateway->converge($node, $assignment);

    expect($events)->toContain('ssh:resolver')
        ->and($followUps->take())->toBeNull();
});

it('fails gateway role removal before any other step when the resolver drop-in cannot be removed', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::Gateway, name: 'gateway-dns');
    $gateway = new GatewayRoleBaseline(
        baseline_firewall($events),
        baseline_dns($events),
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor(gateway_resolver_ssh($events, failResolver: true), baseline_keys(), baseline_known_hosts()),
    );

    expect(static fn () => $gateway->remove($node, $assignment, purgeData: false))
        ->toThrow(static function (RuntimeConvergenceException $exception): void {
            expect($exception->step)->toBe('gateway-private-dns-resolver')
                ->and($exception->errorCode)->toBe('vpn.dns_resolver_failed');
        })
        ->and($events)->toBe(['ssh:resolver']);
});

it('skips the resolver step while no Node holds an active vpn role', function (): void {
    $events = [];
    // A vpn assignment that is still provisioning does not serve VPN DNS yet.
    role_baseline_models(RoleName::Vpn, name: 'vpn-provisioning');
    [$node, $assignment] = role_baseline_models(RoleName::Gateway, name: 'gateway-dns');
    $ssh = gateway_resolver_ssh($events, failResolver: false);
    $gateway = new GatewayRoleBaseline(
        baseline_firewall($events),
        baseline_dns($events),
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor($ssh, baseline_keys(), baseline_known_hosts()),
    );

    $gateway->converge($node, $assignment);

    expect($events)->not->toContain('ssh:resolver')
        ->and(end($events))->toBe('dns:none');
});

it('dispatches every assignment to its code-defined baseline', function (): void {
    expect(class_exists(NativeRoleBaselineConverger::class))->toBeTrue();

    $events = [];
    $firewall = baseline_firewall($events);
    $ssh = baseline_ssh($events);
    $metricsFleet = Mockery::mock(MetricsFleetReconciler::class);
    $metricsFleet->shouldReceive('reconcile')->times(8);
    $dispatcher = new NativeRoleBaselineConverger(
        new GatewayRoleBaseline(
            $firewall,
            baseline_dns($events),
            new NodeRolePrerequisiteCommandFactory,
            new AppDevSshExecutor($ssh, baseline_keys(), baseline_known_hosts()),
        ),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            $ssh,
            baseline_keys(),
            baseline_known_hosts(),
            $firewall,
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new MetricsRoleBaseline(
            Mockery::mock(MetricsRuntimeLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsExporterLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsPublicationManager::class)->shouldIgnoreMissing(),
            new MetricsGatewayResolver,
            new MetricsPublicationReport,
            Mockery::mock(MetricsCadvisorLifecycle::class)->shouldIgnoreMissing(),
        ),
        $metricsFleet,
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
        database: database_role_baseline($events),
        websocket: websocket_role_baseline($events),
        analytics: analytics_role_baseline(),
    );

    foreach (role_baseline_roles() as $role) {
        [$node, $assignment] = role_baseline_models($role, "dispatch-{$role->value}");

        if ($role === RoleName::Gateway) {
            $assignment->update(['status' => 'active']);
        }

        $dispatcher->converge($node, $assignment);

        if ($role === RoleName::AppProd) {
            $dispatcher->remove($node, $assignment, purgeData: false);
        }
    }

    expect($events)->toContain(
        'firewall:converge:gateway',
        'ssh:vpn',
        'ssh:caddy-source',
        'ssh:app-dev',
        'ssh:app-prod',
        'ssh:database',
        'ssh:websocket',
    );
});

it('installs Caddy for an Ingress-only Node, and on removal builds the Node and closes public HTTP but keeps Caddy', function (): void {
    $events = [];
    [$node, $assignment] = role_baseline_models(RoleName::Ingress, 'ingress-only');
    $metricsFleet = Mockery::mock(MetricsFleetReconciler::class);
    $metricsFleet
        ->shouldReceive('reconcile')
        ->times(3)
        ->andReturnUsing(static function () use (&$events): void {
            $events[] = 'metrics';
        });
    $dispatcher = new NativeRoleBaselineConverger(
        gateway_role_baseline($events),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new MetricsRoleBaseline(
            Mockery::mock(MetricsRuntimeLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsExporterLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsPublicationManager::class)->shouldIgnoreMissing(),
            new MetricsGatewayResolver,
            new MetricsPublicationReport,
            Mockery::mock(MetricsCadvisorLifecycle::class)->shouldIgnoreMissing(),
        ),
        $metricsFleet,
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
        ingress: ingress_role_baseline($events),
    );

    $dispatcher->converge($node, $assignment);
    $dispatcher->remove($node, $assignment, purgeData: false);
    $dispatcher->removeUnreachable($node, $assignment);

    expect($events)->toBe([
        'guard:gateway',
        'ssh:caddy-source',
        'ssh:ingress',
        'caddy:converge',
        'metrics',
        'caddy:remove',
        'firewall:remove:ingress',
        'metrics',
        'metrics',
    ]);
});

it('dispatches removeUnreachable to the matching baseline and skips fleet reconciliation for Metrics', function (): void {
    $events = [];
    $metricsFleet = Mockery::mock(MetricsFleetReconciler::class);
    $metricsFleet->shouldReceive('reconcile')->times(2);
    $dispatcher = new NativeRoleBaselineConverger(
        gateway_role_baseline($events),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new MetricsRoleBaseline(
            Mockery::mock(MetricsRuntimeLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsExporterLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsPublicationManager::class)->shouldIgnoreMissing(),
            new MetricsGatewayResolver,
            new MetricsPublicationReport,
            Mockery::mock(MetricsCadvisorLifecycle::class)->shouldIgnoreMissing(),
        ),
        $metricsFleet,
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
    );

    [$appDevNode, $appDevAssignment] = role_baseline_models(RoleName::AppDev, 'unreachable-app-dev');
    [$appProdNode, $appProdAssignment] = role_baseline_models(RoleName::AppProd, 'unreachable-app-prod');
    [$metricsNode, $metricsAssignment] = role_baseline_models(RoleName::Metrics, 'unreachable-metrics');

    $dispatcher->removeUnreachable($appDevNode, $appDevAssignment);
    $dispatcher->removeUnreachable($appProdNode, $appProdAssignment);
    $dispatcher->removeUnreachable($metricsNode, $metricsAssignment);

    expect($events)->toBe(['dns:none']);
});

it('republishes private DNS when the dispatcher sheds an unreachable gateway role', function (): void {
    $events = [];
    $metricsFleet = Mockery::mock(MetricsFleetReconciler::class)->shouldIgnoreMissing();
    $dispatcher = new NativeRoleBaselineConverger(
        gateway_role_baseline($events),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new MetricsRoleBaseline(
            Mockery::mock(MetricsRuntimeLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsExporterLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsPublicationManager::class)->shouldIgnoreMissing(),
            new MetricsGatewayResolver,
            new MetricsPublicationReport,
            Mockery::mock(MetricsCadvisorLifecycle::class)->shouldIgnoreMissing(),
        ),
        $metricsFleet,
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
    );

    [$gatewayNode, $gatewayAssignment] = role_baseline_models(RoleName::Gateway, 'unreachable-gateway');
    $dispatcher->removeUnreachable($gatewayNode, $gatewayAssignment);

    expect($events)->toBe(['dns:none']);
});

it('propagates the VPN removeUnreachable rejection through the dispatcher', function (): void {
    $events = [];
    $metricsFleet = Mockery::mock(MetricsFleetReconciler::class)->shouldIgnoreMissing();
    $dispatcher = new NativeRoleBaselineConverger(
        gateway_role_baseline($events),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new MetricsRoleBaseline(
            Mockery::mock(MetricsRuntimeLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsExporterLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsPublicationManager::class)->shouldIgnoreMissing(),
            new MetricsGatewayResolver,
            new MetricsPublicationReport,
            Mockery::mock(MetricsCadvisorLifecycle::class)->shouldIgnoreMissing(),
        ),
        $metricsFleet,
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
    );

    [$vpnNode, $vpnAssignment] = role_baseline_models(RoleName::Vpn, 'unreachable-vpn');

    expect(fn () => $dispatcher->removeUnreachable($vpnNode, $vpnAssignment))
        ->toThrow(NodeRoleValidationException::class);
});

it('checks the remote operating system before every role convergence', function (): void {
    $events = [];
    $metricsFleet = Mockery::mock(MetricsFleetReconciler::class)->shouldIgnoreMissing();
    $dispatcher = new NativeRoleBaselineConverger(
        gateway_role_baseline($events),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new MetricsRoleBaseline(
            Mockery::mock(MetricsRuntimeLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsExporterLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsPublicationManager::class)->shouldIgnoreMissing(),
            new MetricsGatewayResolver,
            new MetricsPublicationReport,
            Mockery::mock(MetricsCadvisorLifecycle::class)->shouldIgnoreMissing(),
        ),
        $metricsFleet,
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
        database: database_role_baseline($events),
        websocket: websocket_role_baseline($events),
        analytics: analytics_role_baseline(),
    );

    foreach (role_baseline_roles() as $role) {
        [$node, $assignment] = role_baseline_models($role, "guard-{$role->value}");
        if ($role === RoleName::Gateway) {
            $assignment->update(['status' => 'active']);
        }
        $dispatcher->converge($node, $assignment);
    }

    expect($events)->toBe([
        'guard:gateway',
        'ssh:caddy-source',
        'ssh:caddy',
        'firewall:converge:gateway',
        'dns:none',
        'guard:vpn',
        'ssh:vpn',
        'firewall:converge:vpn',
        'guard:app-dev',
        'dns:3',
        'ssh:caddy-source',
        'ssh:app-dev',
        'ssh:'.RuntimeHibernation::MarkerDirectory,
        'caddy:converge',
        'firewall:converge:app-dev',
        'guard:app-prod',
        'ssh:caddy-source',
        'ssh:app-prod',
        'caddy:converge',
        'firewall:converge:app-prod',
        'guard:unknown',
        'guard:database',
        'ssh:database',
        'guard:unknown',
        'ssh:caddy-source',
        'ssh:websocket',
        'ssh:orbit',
        'guard:unknown',
    ]);
});

it('stops baseline convergence when the remote operating system guard fails', function (): void {
    $events = [];
    $metricsFleet = Mockery::mock(MetricsFleetReconciler::class)->shouldIgnoreMissing();
    [$node, $assignment] = role_baseline_models(RoleName::AppDev, 'guard-failure');
    $dispatcher = new NativeRoleBaselineConverger(
        gateway_role_baseline($events),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new MetricsRoleBaseline(
            Mockery::mock(MetricsRuntimeLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsExporterLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsPublicationManager::class)->shouldIgnoreMissing(),
            new MetricsGatewayResolver,
            new MetricsPublicationReport,
            Mockery::mock(MetricsCadvisorLifecycle::class)->shouldIgnoreMissing(),
        ),
        $metricsFleet,
        new NodeRoleOperatingSystemGuard(
            new class($events) implements SshExecutor
            {
                /** @param list<string> $events */
                public function __construct(
                    private array &$events,
                ) {}

                public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
                {
                    $this->events[] = 'guard:app-dev';

                    return new CommandResult(
                        1,
                        '',
                        UbuntuRelease::unsupportedText(),
                        1,
                        false,
                    );
                }
            },
            baseline_keys(),
            baseline_known_hosts(),
        ),
    );

    expect(fn () => $dispatcher->converge($node, $assignment))
        ->toThrow(NodeRoleOperationException::class);
    expect($events)->toBe(['guard:app-dev']);
});

/** @param list<string> $events */
function baseline_guard_ssh(array &$events): SshExecutor
{
    return new class($events) implements SshExecutor
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $role = $command->arguments[0] === 'bash'
                ? match ($connection->host) {
                    '10.44.0.2' => 'gateway',
                    '10.44.0.3' => 'vpn',
                    '10.44.0.4' => 'app-dev',
                    '10.44.0.5' => 'app-prod',
                    '10.44.0.7' => 'database',
                    default => 'unknown',
                }
            : 'unknown';
            $this->events[] = "guard:{$role}";

            return new CommandResult(0, "ID=ubuntu\nVERSION_CODENAME=resolute\n", '', 1, false);
        }
    };
}

/** @return array{Node, NodeRole} */
function role_baseline_models(RoleName $role, string $name = 'role-node'): array
{
    $address = '10.44.0.'.(Node::query()->count() + 2);
    $cluster = in_array($role, [RoleName::Router, RoleName::Ingress], strict: true)
        ? Cluster::query()->create(['name' => "{$name}-cluster"])
        : null;
    $node = Node::query()->create([
        'cluster_id' => $cluster?->id,
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $address,
    ]);
    $assignment = $node->roles()->create([
        'cluster_id' => $cluster?->id,
        'role' => $role,
        'status' => 'provisioning',
    ]);

    return [$node, $assignment];
}

/** @return list<RoleName> */
function role_baseline_roles(): array
{
    return array_values(array_filter(
        RoleName::cases(),
        static fn (RoleName $role): bool => ! in_array($role, [RoleName::Router, RoleName::Ingress], strict: true),
    ));
}

/** @param list<string> $events */
function database_role_baseline(array &$events): DatabaseRoleBaseline
{
    return new DatabaseRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        baseline_ssh($events),
        baseline_keys(),
        baseline_known_hosts(),
        baseline_account_resolver(),
    );
}

/**
 * @param  list<string>  $events
 * @return SshExecutor&object{commands: list<RemoteCommand>}
 */
function gateway_resolver_ssh(array &$events, bool $failResolver): SshExecutor
{
    return new class($events, $failResolver) implements SshExecutor
    {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        /** @param list<string> $events */
        public function __construct(
            private array &$events,
            private bool $failResolver,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;
            $resolver = ($command->arguments[4] ?? null) === GatewayPrivateDnsResolver::DROP_IN;
            $this->events[] = $resolver ? 'ssh:resolver' : 'ssh:other';

            return $resolver && $this->failResolver
                ? new CommandResult(1, '', 'Failed to set DNS configuration: Link orbit not known', 1, false)
                : new CommandResult(0, '', '', 1, false);
        }
    };
}

/** @param list<string> $events */
function gateway_role_baseline(array &$events): GatewayRoleBaseline
{
    return new GatewayRoleBaseline(
        baseline_firewall($events),
        baseline_dns($events),
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor(baseline_ssh($events), baseline_keys(), baseline_known_hosts()),
    );
}

/** @param list<string> $events */
function baseline_dns(array &$events): PrivateDnsManager
{
    return new class($events) implements PrivateDnsManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(?Node $pendingNode = null): void
        {
            $this->events[] = 'dns:'.($pendingNode->id ?? 'none');
        }
    };
}

/** @param list<string> $events */
function websocket_role_baseline(array &$events): WebSocketRoleBaseline
{
    $credentials = Mockery::mock(WebSocketCredentialManager::class);
    $credentials->shouldReceive('ensure')->andReturn(
        new WebSocketCredentials('id', 'key', 'secret', 'base64:'.base64_encode('k')),
    );
    $credentials->shouldReceive('purge');

    return new WebSocketRoleBaseline(
        runtime: new NativeWebSocketRuntimeLifecycle(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_account_resolver(),
        ),
        // The publication step reaches real local certificate issuance and DNS
        // convergence, which this dispatch-only suite does not exercise.
        publication: Mockery::mock(WebSocketPublicationManager::class)->shouldIgnoreMissing(),
        credentials: $credentials,
    );
}

/**
 * The analytics baseline adds no dispatch events here. Its storage is created on first use, after every other role's Node has claimed its address.
 */
function analytics_role_baseline(): AnalyticsRoleBaseline
{
    $settings = new class implements AnalyticsRoleSettingsRepository
    {
        private ?AnalyticsRoleSettings $settings = null;

        public function find(Node $node): AnalyticsRoleSettings
        {
            if ($this->settings === null) {
                $storage = analytics_storage_processes();
                $this->settings = new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id);
            }

            return $this->settings;
        }

        public function store(Node $node, AnalyticsRoleSettings $settings): void {}

        public function version(Node $node): string
        {
            return '3.2.1';
        }

        public function storeVersion(Node $node, string $version): void {}

        public function purge(Node $node): void {}
    };

    // The ClickHouse configuration, the Process runtime, and the publication reach a real node, which this dispatch-only
    // suite does not exercise; AnalyticsRoleBaselineTest covers what the baseline asks of them.
    return new AnalyticsRoleBaseline(
        $settings,
        new AnalyticsStorageProcessGuard,
        Mockery::mock(AnalyticsClickhouseConfigurationManager::class)->shouldIgnoreMissing(),
        Mockery::mock(AnalyticsSecretManager::class)->shouldReceive('secretKeyBase')->andReturn(str_repeat('k', 64))->getMock()->shouldIgnoreMissing(),
        new class implements PlausibleRuntimeLifecycle
        {
            public function converge(Node $node, string $version, AnalyticsStorageConnection $storage, string $secretKeyBase): Process
            {
                return new Process;
            }

            public function remove(Node $node): void {}

            public function forget(Node $node): void {}
        },
        Mockery::mock(AnalyticsPublicationManager::class)->shouldIgnoreMissing(),
        Mockery::mock(NodeRoleFirewallManager::class)->shouldIgnoreMissing(),
    );
}

function app_dev_role_baseline(array &$events): AppDevRoleBaseline
{
    $caddy = new class($events) implements AppDevCaddyManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node): void
        {
            $this->events[] = 'caddy:converge';
        }

        public function remove(Node $node): void
        {
            $this->events[] = 'caddy:remove';
        }
    };
    $dns = new class($events) implements PrivateDnsManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(?Node $pendingNode = null): void
        {
            $this->events[] = 'dns:'.($pendingNode->id ?? 'none');
        }
    };

    return new AppDevRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor(baseline_ssh($events), baseline_keys(), baseline_known_hosts()),
        $caddy,
        baseline_firewall($events),
        $dns,
        baseline_account_resolver(),
        new NodeSettingsNormalizer,
        new class implements NodeStorageRootPreparer
        {
            public function inspect(
                Node $node,
                ManagedUserAccount $account,
                StoragePath $path,
            ): void {}

            public function prepare(
                Node $node,
                ManagedUserAccount $account,
                EffectiveStorageRoots $roots,
            ): void {}
        },
        new ConfiguredStoragePathValidator(
            new NodeSettingsNormalizer,
            new StorageRootResolver(
                new NodeSettingsNormalizer,
                new ProtectedPathCatalog,
            ),
            new ProtectedPathCatalog,
        ),
    );
}

/** @param list<string> $events */
function app_prod_role_baseline(array &$events): AppProdRoleBaseline
{
    $caddy = new class($events) implements AppProdCaddyManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node): void
        {
            $this->events[] = 'caddy:converge';
        }

        public function remove(Node $node): void
        {
            $this->events[] = 'caddy:remove';
        }
    };

    return new AppProdRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new AppProdSshExecutor(baseline_ssh($events), baseline_keys(), baseline_known_hosts()),
        $caddy,
        baseline_firewall($events),
        baseline_account_resolver(),
    );
}

/** @param list<string> $events */
function router_role_baseline(array &$events): RouterRoleBaseline
{
    $caddy = new class($events) implements AppDevCaddyManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node): void
        {
            $this->events[] = 'caddy:converge';
        }

        public function remove(Node $node): void
        {
            $this->events[] = 'caddy:remove';
        }
    };

    return new RouterRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor(baseline_ssh($events), baseline_keys(), baseline_known_hosts()),
        $caddy,
        baseline_firewall($events),
        baseline_account_resolver(),
    );
}

/** @param list<string> $events */
function ingress_role_baseline(array &$events): IngressRoleBaseline
{
    $caddy = new class($events) implements AppDevCaddyManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function converge(Node $node): void
        {
            $this->events[] = 'caddy:converge';
        }

        public function remove(Node $node): void
        {
            $this->events[] = 'caddy:remove';
        }
    };

    return new IngressRoleBaseline(
        new NodeRolePrerequisiteCommandFactory,
        new AppDevSshExecutor(baseline_ssh($events), baseline_keys(), baseline_known_hosts()),
        $caddy,
        baseline_account_resolver(),
        baseline_firewall($events),
    );
}

function baseline_account_resolver(?ManagedUserAccount $account = null): ManagedUserAccountResolver
{
    return new class($account ?? new ManagedUserAccount('orbit', 'orbit', '/home/orbit')) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
}

final class NodeRoleBaselineClusterRouterOperationLock implements ClusterRouterOperationLock
{
    /** @param list<string> $events */
    public function __construct(
        private array &$events,
    ) {}

    public function run(int $clusterId, Closure $operation): mixed
    {
        $this->events[] = "owner:enter:{$clusterId}";

        try {
            return $operation();
        } finally {
            $this->events[] = "owner:exit:{$clusterId}";
        }
    }
}

/** @param list<string> $events */
function baseline_firewall(array &$events): NodeRoleFirewallManager
{
    return new class($events) implements NodeRoleFirewallManager
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function convergeBase(Node $node, string $managedUser): void
        {
            $this->events[] = 'firewall:base';
        }

        public function converge(Node $node, RoleName $role, string $managedUser): void
        {
            $this->events[] = "firewall:converge:{$role->value}";
        }

        public function remove(Node $node, RoleName $role, string $managedUser): void
        {
            $this->events[] = "firewall:remove:{$role->value}";
        }

        public function restorePublicSsh(Node $node, string $managedUser): void
        {
            $this->events[] = 'firewall:restore-public-ssh';
        }

        public function trustWireGuardMembers(Node $node, string $managedUser): void
        {
            $this->events[] = 'firewall:trust-wireguard-members';
        }
    };
}

/** @param list<string> $events */
function baseline_ssh(array &$events): SshExecutor
{
    return new class($events) implements SshExecutor
    {
        /** @param list<string> $events */
        public function __construct(
            private array &$events,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $label = $command->arguments[4] ?? 'unknown';
            $this->events[] = 'ssh:'.($label === CaddyPackageSourceProgram::SOURCE_URI ? 'caddy-source' : $label);

            return new CommandResult(0, '', '', 1, false);
        }
    };
}

function baseline_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 TEST';
        }
    };
}

function baseline_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}

it('holds back fleet reconciliation during a deferral and reports that one was requested', function (): void {
    $events = [];
    $metricsFleet = Mockery::mock(MetricsFleetReconciler::class);
    $metricsFleet->shouldReceive('reconcile')->once();
    $deferral = new MetricsReconcileDeferral;
    $dispatcher = new NativeRoleBaselineConverger(
        gateway_role_baseline($events),
        new VpnRoleBaseline(
            new NodeRolePrerequisiteCommandFactory,
            baseline_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
            baseline_firewall($events),
            baseline_account_resolver(),
        ),
        app_dev_role_baseline($events),
        app_prod_role_baseline($events),
        new MetricsRoleBaseline(
            Mockery::mock(MetricsRuntimeLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsExporterLifecycle::class)->shouldIgnoreMissing(),
            Mockery::mock(MetricsPublicationManager::class)->shouldIgnoreMissing(),
            new MetricsGatewayResolver,
            new MetricsPublicationReport,
            Mockery::mock(MetricsCadvisorLifecycle::class)->shouldIgnoreMissing(),
        ),
        $metricsFleet,
        new NodeRoleOperatingSystemGuard(
            baseline_guard_ssh($events),
            baseline_keys(),
            baseline_known_hosts(),
        ),
        metricsDeferral: $deferral,
    );

    [$appDevNode, $appDevAssignment] = role_baseline_models(RoleName::AppDev, 'deferred-app-dev');
    [$appProdNode, $appProdAssignment] = role_baseline_models(RoleName::AppProd, 'deferred-app-prod');

    $requested = $deferral->during(function () use ($dispatcher, $appDevNode, $appDevAssignment, $appProdNode, $appProdAssignment): void {
        $dispatcher->removeUnreachable($appDevNode, $appDevAssignment);
        $dispatcher->removeUnreachable($appProdNode, $appProdAssignment);
    });

    expect($requested)->toBeTrue()
        ->and($deferral->during(static function (): void {}))->toBeFalse();

    // Outside a deferral, each change reconciles again.
    $dispatcher->removeUnreachable($appDevNode, $appDevAssignment);
});
