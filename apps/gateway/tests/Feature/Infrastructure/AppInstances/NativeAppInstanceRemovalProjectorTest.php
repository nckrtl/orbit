<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
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
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\AppInstances\NativeAppInstanceRemovalProjector;
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
use App\Models\AppInstanceRemoval;
use App\Models\AppInstanceRemovalMember;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

afterEach(function (): void {
    if (is_string($this->orb124ProjectorHome ?? null)) {
        new Filesystem()->deleteDirectory($this->orb124ProjectorHome);
    }
});

it('serves the exact transient development 503 without an upstream then deletes the final Route', function (): void {
    [$member, $route] = orb124_projector_development_member();
    [$projector, $ssh] = orb124_removal_projector($this);
    $ssh->failCall = 2;

    expect(fn () => $projector->clearRouteTarget($member))->toThrow(RuntimeConvergenceException::class);

    $configurations = orb124_caddy_configurations($ssh->commands);
    $unavailable = collect($configurations)->first(
        static fn (string $configuration): bool => str_contains($configuration, 'Orbit Route unavailable'),
    );
    expect(Route::query()->find($route->id))
        ->not->toBeNull()->and($route->refresh()->targets()->count())->toBe(0)->and($unavailable)->toContain(
            'header Cache-Control "no-store"',
        )->toContain('header Content-Type "text/plain; charset=utf-8"')->toContain(
            'respond "Orbit Route unavailable\n" 503',
        )
        ->not->toContain('reverse_proxy', '10.44.0.31');

    expect($projector->clearRouteTarget($member))
        ->toBe('deleted')
        ->and(Route::query()->find($route->id))
        ->toBeNull()
        ->and(collect($ssh->commands)
            ->contains(
                static fn (RemoteCommand $command): bool => in_array(
                    "orbit:route-{$route->id}-lan",
                    $command->arguments,
                    true,
                ),
            ))
        ->toBeTrue();

    $replacement = Route::query()->create([
        'app_id' => $member->app_id,
        'node_id' => $member->node_id,
        'hostname' => $route->hostname,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    expect($replacement->hostname)->toBe($route->hostname);
});

it('keeps the final Route row until every projection cleanup succeeds', function (): void {
    [$member, $route] = orb124_projector_development_member();
    [$projector, $ssh] = orb124_removal_projector($this);
    $ssh->failCall = 5;

    expect(fn () => $projector->clearRouteTarget($member))
        ->toThrow(RuntimeConvergenceException::class);
    expect(Route::query()->find($route->id))
        ->not
        ->toBeNull()
        ->and($route->refresh()->targets()->count())
        ->toBe(0);

    expect($projector->clearRouteTarget($member))
        ->toBe('deleted')
        ->and(Route::query()->find($route->id))
        ->toBeNull();
});

it('resumes final Route cleanup after certificate deletion and a late DNS failure', function (): void {
    [$member, $route] = orb124_projector_development_member();
    [$projector, $ssh, $processes] = orb124_removal_projector($this);
    $processes->failCall = 2;

    expect(fn () => $projector->clearRouteTarget($member))
        ->toThrow(RuntimeConvergenceException::class);
    expect(Route::query()->find($route->id))
        ->not
        ->toBeNull()
        ->and($route->refresh()->targets()->count())
        ->toBe(0)
        ->and(collect($ssh->commands)
            ->contains(
                static fn (RemoteCommand $command): bool => in_array(
                    "app-instance-{$member->app_instance_id}",
                    $command->arguments,
                    true,
                ),
            ))
        ->toBeTrue()
        ->and(count($processes->invocations))
        ->toBe(2);

    expect($projector->clearRouteTarget($member))
        ->toBe('deleted')
        ->and(Route::query()->find($route->id))
        ->toBeNull()
        ->and(count($processes->invocations))
        ->toBe(3)
        ->and(
            collect(orb124_caddy_configurations($ssh->commands))
                ->filter(
                    static fn (string $configuration): bool => str_contains($configuration, 'Orbit Route unavailable'),
                )
                ->count(),
        )
        ->toBe(1);
});

it('removes only the departing shared production target and republishes the ordered remainder', function (): void {
    [$member, $route, $remaining] = orb124_projector_shared_member();
    [$projector, $ssh] = orb124_removal_projector($this);

    expect($projector->clearRouteTarget($member))
        ->toBe('retained')
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Active)
        ->and($route->targets()->pluck('app_instance_id')->all())
        ->toBe([$remaining->id]);

    $routerConfiguration = collect(orb124_caddy_configurations($ssh->commands))
        ->first(
            static fn (string $configuration): bool => str_contains($configuration, 'reverse_proxy'),
        );
    expect($routerConfiguration)
        ->toContain('reverse_proxy https://10.44.0.42')
        ->not->toContain('10.44.0.41', 'Orbit Route unavailable');
});

it('deletes a final production Route after cleaning workload and Router artifacts', function (): void {
    [$member, $route, $departing, $router] = orb124_projector_production_member();
    [$projector, $ssh] = orb124_removal_projector($this);

    expect($projector->clearRouteTarget($member))
        ->toBe('deleted')
        ->and(Route::query()->find($route->id))
        ->toBeNull();

    $hosts = array_values(array_unique(array_map(
        static fn (SshConnection $connection): string => $connection->host,
        $ssh->connections,
    )));
    sort($hosts);
    $expectedHosts = [$departing->node->wireguard_ip, $router->wireguard_ip];
    sort($expectedHosts);
    $arguments = collect($ssh->commands)->pluck('arguments');

    expect($hosts)
        ->toBe($expectedHosts)
        ->and($arguments->contains(
            static fn (array $command): bool => in_array("app-instance-{$departing->id}", $command, true),
        ))
        ->toBeTrue()
        ->and($arguments->contains(
            static fn (array $command): bool => in_array("route-{$route->id}-router", $command, true),
        ))
        ->toBeTrue();
});

/** @return array{NativeAppInstanceRemovalProjector, Orb124RemovalSshExecutor, Orb124RemovalProcessRunner} */
function orb124_removal_projector(object $test): array
{
    $ssh = new Orb124RemovalSshExecutor;
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
            return "LEAF\n";
        }

        public function rootCertificate(): string
        {
            return "ROOT\n";
        }
    };
    $sites = new AppDevSiteRepository;
    $processes = new Orb124RemovalProcessRunner;
    $home = sys_get_temp_dir().'/orbit-removal-projector-'.Str::uuid();
    $test->orb124ProjectorHome = $home;
    config()->set('orbit.home', $home);

    return [
        new NativeAppInstanceRemovalProjector(
            new RemoteAppDevCaddyManager($sites, new AppDevCaddyConfigRenderer, $executor),
            new RemoteAppDevCertificateManager($executor, $signer, $accounts),
            new RemoteAppDevPhpFpmManager(
                $sites,
                new AppDevPhpFpmConfigRenderer,
                $executor,
                $accounts,
                new RemotePhpPackageManager,
            ),
            new DnsmasqPrivateDnsManager($processes, new AppDevDnsConfigRenderer($sites)),
            new RemoteAppDevRouteFirewallManager($executor),
        ),
        $ssh,
        $processes,
    ];
}

/** @return array{AppInstanceRemovalMember, Route} */
function orb124_projector_development_member(): array
{
    $app = orb124_projector_app('development');
    $node = orb124_projector_node('app-dev', '31', null, RoleName::AppDev);
    $instance = orb124_projector_instance($app, $node, 'development', 'dev');
    $route = orb124_projector_route($app, $node, null, 'dev.acme.test');
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    return [orb124_projector_member($instance, $route), $route];
}

/** @return array{AppInstanceRemovalMember, Route, AppInstance} */
function orb124_projector_shared_member(): array
{
    $app = orb124_projector_app('production');
    $cluster = Cluster::query()->create(['name' => 'production', 'state' => ClusterState::Active]);
    $departingNode = orb124_projector_node('app-prod-one', '41', $cluster, RoleName::AppProd);
    $remainingNode = orb124_projector_node('app-prod-two', '42', $cluster, RoleName::AppProd);
    orb124_projector_node('router', '43', $cluster, RoleName::Router);
    $departing = orb124_projector_instance($app, $departingNode, 'production', 'one');
    $remaining = orb124_projector_instance($app, $remainingNode, 'production', 'two');
    $route = orb124_projector_route($app, null, $cluster, 'shared.acme.test');
    $route->targets()->create(['app_instance_id' => $departing->id, 'position' => 0]);
    $route->targets()->create(['app_instance_id' => $remaining->id, 'position' => 1]);
    $route->update(['status' => RouteStatus::Active]);
    $departing->update(['status' => AppInstanceState::Active]);
    $remaining->update(['status' => AppInstanceState::Active]);

    return [orb124_projector_member($departing, $route), $route, $remaining];
}

/** @return array{AppInstanceRemovalMember, Route, AppInstance, Node} */
function orb124_projector_production_member(): array
{
    $app = orb124_projector_app('production-final');
    $cluster = Cluster::query()->create(['name' => 'production-final', 'state' => ClusterState::Active]);
    $node = orb124_projector_node('app-prod-final', '51', $cluster, RoleName::AppProd);
    $router = orb124_projector_node('router-final', '52', $cluster, RoleName::Router);
    $instance = orb124_projector_instance($app, $node, 'production', 'final');
    $route = orb124_projector_route($app, null, $cluster, 'final.acme.test');
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    return [orb124_projector_member($instance, $route), $route, $instance, $router];
}

function orb124_projector_app(string $suffix): OrbitApp
{
    return OrbitApp::query()->create([
        'name' => "Acme {$suffix}",
        'slug' => "acme-{$suffix}",
        'repository_url' => "https://example.test/acme-{$suffix}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
}

function orb124_projector_node(
    string $name,
    string $suffix,
    ?Cluster $cluster,
    RoleName $role,
): Node {
    $node = Node::query()->create([
        'cluster_id' => $cluster?->id,
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$suffix}",
        'wireguard_ip' => "10.44.0.{$suffix}",
        'lan_ip' => "10.44.0.{$suffix}",
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'cluster_id' => $role === RoleName::Router ? $cluster?->id : null,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function orb124_projector_instance(
    OrbitApp $app,
    Node $node,
    string $environment,
    string $name,
): AppInstance {
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => $environment,
        'checkout_path' => "/home/orbit/apps/{$app->slug}/{$name}",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::SourceResolved,
    ]);
}

function orb124_projector_route(
    OrbitApp $app,
    ?Node $node,
    ?Cluster $cluster,
    string $hostname,
): Route {
    return Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node?->id,
        'cluster_id' => $cluster?->id,
        'generation_basis_node_id' => $node?->id,
        'hostname' => $hostname,
        'provenance' => $node instanceof Node ? RouteProvenance::Generated : RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
}

function orb124_projector_member(AppInstance $instance, Route $route): AppInstanceRemovalMember
{
    $operation = AppInstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => false,
        'inventory_digest' => str_repeat('d', 64),
        'total' => 1,
        'status' => AppInstanceRemovalStatus::Removing,
        'current_step' => AppInstanceRemovalStep::SourcePreparation,
    ]);
    $member = $operation
        ->members()
        ->create([
            'position' => 0,
            'app_instance_id' => $instance->id,
            'app_id' => $instance->app_id,
            'node_id' => $instance->node_id,
            'route_id' => $route->id,
            'name' => $instance->name,
            'environment' => $instance->environment,
            'source_layout' => $instance->source_layout,
            'repository_identity' => $instance->app->repository_identity,
            'checkout_path' => $instance->checkout_path,
            'root' => dirname($instance->checkout_path),
            'branch' => $instance->branch,
            'starting_commit' => $instance->starting_commit,
            'common_repository_path' => $instance->checkout_path,
            'source_identity' => 'test:1',
            'linked_worktree_paths' => [$instance->checkout_path],
            'source_digest' => str_repeat('e', 64),
        ]);
    $instance->update(['status' => AppInstanceState::Removing]);

    return $member;
}

/** @param list<RemoteCommand> $commands @return list<string> */
function orb124_caddy_configurations(array $commands): array
{
    $configurations = [];

    foreach ($commands as $command) {
        if (! is_string($command->input)) {
            continue;
        }

        if (preg_match("/printf '%s' '([^']+)' \\| base64 --decode/", $command->input, $matches) !== 1) {
            continue;
        }

        $decoded = base64_decode($matches[1], true);

        if (is_string($decoded)) {
            $configurations[] = $decoded;
        }
    }

    return $configurations;
}

final class Orb124RemovalSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    public ?int $failCall = null;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;

        if ($this->failCall === count($this->commands)) {
            return new CommandResult(1, '', 'injected failure', 1, false);
        }

        return new CommandResult(0, '', '', 1, false);
    }
}

final class Orb124RemovalProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public ?int $failCall = null;

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        if (count($this->invocations) === $this->failCall) {
            return new CommandResult(1, '', 'injected DNS failure', 1, false);
        }

        return new CommandResult(0, '', '', 1, false);
    }
}
