<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\Instances\NativeInstanceRemovalProjector;
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
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\FakePublicRouteEdgeProjector;
use Tests\Support\SshNodeCaddyBuilds;

afterEach(function (): void {
    if (is_string($this->orb181ProjectorHome ?? null)) {
        new Filesystem()->deleteDirectory($this->orb181ProjectorHome);
    }
});

it('serves the exact transient development 503 without an upstream then deletes the final Route', function (): void {
    [$member, $route] = orb181_projector_development_member();
    [$projector, $ssh, $processes] = orb181_removal_projector($this);
    $ssh->failCall = 1;

    expect(fn () => $projector->clearRouteTarget($member))->toThrow(RuntimeConvergenceException::class);

    $configurations = orb181_caddy_configurations($ssh->commands);
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
        ->toBeTrue()
        ->and(
            collect(orb181_caddy_configurations($ssh->commands))
                ->filter(
                    static fn (string $configuration): bool => str_contains(
                        $configuration,
                        'Orbit Route unavailable',
                    ),
                )
                ->count(),
        )
        ->toBe(2)
        ->and(orb181_dns_configurations($processes->invocations)[0] ?? null)
        ->toContain('host-record=dev.acme.test,10.44.0.31');

    $replacement = Route::query()->create([
        'project_id' => $member->project_id,
        'node_id' => $member->node_id,
        'domain' => $route->domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    expect($replacement->domain)->toBe($route->domain);
});

it('withdraws the final Route in a build before it removes the Instance certificate', function (): void {
    [$member, $route] = orb181_projector_development_member();
    [$projector, $ssh] = orb181_removal_projector($this);

    expect($projector->clearRouteTarget($member))->toBe('deleted');

    $scope = "app-instance-{$member->instance_id}";
    $removal = collect($ssh->commands)->search(
        static fn (RemoteCommand $command): bool => is_string($command->input)
            && str_contains($command->input, 'rm -rf -- "$managed_home/.orbit/certificates/$scope"')
            && in_array($scope, $command->arguments, true),
    );
    $published = orb181_caddy_configurations(array_slice($ssh->commands, 0, is_int($removal) ? $removal : 0));

    // The unavailable answer renders from the stored Route, its record, and the open member; clearing
    // the record withdraws it in the build that runs before the certificate is removed.
    $serving = array_keys(array_filter(
        $published,
        static fn (string $configuration): bool => str_contains($configuration, 'dev.acme.test'),
    ));

    expect($removal)->toBeInt()
        ->and($serving)->not->toBeEmpty()
        ->and($published[$serving[0]])->toContain('Orbit Route unavailable')
        ->and(count($published) - 1)->toBeGreaterThan(max($serving))
        ->and(end($published))->not->toContain('dev.acme.test');
});

it('withdraws the second placement of a Route that waits for its placement withdrawal', function (): void {
    [$member, $route] = orb181_projector_development_member();
    // A detach moved the Route off Cluster scope and waits out the grace period before it
    // withdraws the old Router placement.
    $cluster = Cluster::query()->create(['name' => 'waiting', 'state' => ClusterState::Active]);
    $router = orb181_projector_node('old-router', '41', $cluster, RoleName::Router);
    Route::query()->whereKey($route->id)->update([
        'transition_cluster_id' => $cluster->id,
        'replacement_step' => RouteReplacementStep::DatabaseCutover->value,
        'transition_dns_moved_at' => now(),
    ]);
    [$projector, $ssh] = orb181_removal_projector($this);

    expect($projector->clearRouteTarget($member))->toBe('deleted');

    $on = static fn (string $host): array => collect($ssh->commands)
        ->keys()
        ->filter(static fn (int $index): bool => $ssh->connections[$index]->host === $host)
        ->map(static fn (int $index): RemoteCommand => $ssh->commands[$index])
        ->values()
        ->all();
    $removed = static fn (array $commands): array => collect($commands)
        ->filter(static fn (RemoteCommand $command): bool => is_string($command->input)
            && str_contains($command->input, 'rm -rf -- "$managed_home/.orbit/certificates/$scope"'))
        ->map(static fn (RemoteCommand $command): ?string => collect($command->arguments)
            ->first(static fn (string $argument): bool => str_starts_with($argument, 'route-') || str_starts_with($argument, 'app-instance-')))
        ->values()
        ->all();
    $routerConfigurations = orb181_caddy_configurations($on((string) $router->wireguard_ip));

    // The old Router is built without the Route before its certificates are removed.
    expect($routerConfigurations)->not->toBeEmpty()
        ->and(end($routerConfigurations))->not->toContain('dev.acme.test')
        ->and($removed($on((string) $router->wireguard_ip)))->toBe([
            "route-{$route->id}-router",
            "route-{$route->id}-router-hostname-change",
        ])
        ->and($removed($on('10.44.0.31')))->toContain(
            "app-instance-{$member->instance_id}",
            "app-instance-{$member->instance_id}-hostname-change",
        );
});

it('keeps the final Route row until every projection cleanup succeeds', function (): void {
    [$member, $route] = orb181_projector_development_member();
    [$projector, $ssh] = orb181_removal_projector($this);
    $ssh->failCall = 4;

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
    [$member, $route] = orb181_projector_development_member();
    [$projector, $ssh, $processes] = orb181_removal_projector($this);
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
                    "app-instance-{$member->instance_id}",
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
            collect(orb181_caddy_configurations($ssh->commands))
                ->filter(
                    static fn (string $configuration): bool => str_contains($configuration, 'Orbit Route unavailable'),
                )
                ->count(),
        )
        ->toBe(1);
});

it('deletes a final production Route after cleanup without publishing development unavailability', function (): void {
    [$member, $route] = orb183_projector_production_member(shared: false);
    [$projector, $ssh] = orb181_removal_projector($this);

    expect($projector->clearRouteTarget($member))
        ->toBe('deleted')
        ->and(Route::query()->find($route->id))
        ->toBeNull()
        ->and(collect(orb181_caddy_configurations($ssh->commands))
            ->contains(
                static fn (string $configuration): bool => orb181_unavailable_site($configuration),
            ))
        ->toBeFalse();
});

it('finishes development runtime cleanup when PHP-FPM skips another site whose directory is missing', function (): void {
    [$member, $route] = orb181_projector_development_member();
    $route->delete();
    $project = orb181_projector_app('broken');
    $node = Node::query()->findOrFail($member->node_id);
    $broken = orb181_projector_instance($project, $node, 'development', 'broken');
    $brokenRoute = orb181_projector_route($project, $node, null, 'broken.acme.test');
    $brokenRoute->targets()->create(['instance_id' => $broken->id, 'position' => 0]);
    $brokenRoute->update(['status' => RouteStatus::Active, 'sites_published' => true]);
    $broken->update(['status' => InstanceState::Active]);
    [$projector, $ssh] = orb181_removal_projector($this);
    $ssh->phpDiscovery = "missing-directory\t{$broken->checkout_path}\n";

    $projector->cleanupRuntime($member);

    expect(orb181_caddy_configurations($ssh->commands))->not->toBeEmpty()
        ->and(collect($ssh->commands)->contains(
            static fn (RemoteCommand $command): bool => str_contains(
                $command->input ?? '',
                'rm -rf -- "$managed_home/.orbit/certificates/$scope"',
            ),
        ))->toBeTrue();
});

it('skips PHP cleanup but removes Caddy and certificate state for non-PHP production Instances', function (): void {
    [$member, $route, $instance] = orb183_projector_production_member(shared: false);
    $route->delete();
    $instance->update([
        'selected_php_version' => null,
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);
    $phpRuntime = new Orb214RemovalPhpRuntimeManager;
    [$projector, $ssh] = orb181_removal_projector($this, $phpRuntime);

    $projector->cleanupRuntime($member);

    expect($phpRuntime->removed)->toBeEmpty()
        ->and(orb181_caddy_configurations($ssh->commands))->not->toBeEmpty()
        ->and(collect($ssh->commands)->contains(
            static fn (RemoteCommand $command): bool => str_contains(
                $command->input ?? '',
                'rm -rf -- "$managed_home/.orbit/certificates/$scope"',
            ) && in_array("app-instance-{$instance->id}", $command->arguments, strict: true),
        ))->toBeTrue();
});

it('skips PHP-FPM cleanup for a Laravel package with a selected PHP version but no runtime identity', function (): void {
    [$member, , $instance] = orb183_projector_production_member(shared: false);
    $instance->project->update(['type' => ProjectType::LaravelPackage]);
    $instance->update([
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);
    [$projector] = orb181_removal_projector($this, $phpRuntime = new Orb214RemovalPhpRuntimeManager);

    $projector->cleanupRuntime($member);

    expect($phpRuntime->removed)->toBeEmpty();
});

it('refuses PHP production runtime cleanup without its dedicated PHP-FPM service', function (): void {
    [$member, , $instance] = orb183_projector_production_member(shared: false);
    $instance->update([
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);
    [$projector] = orb181_removal_projector($this);

    expect(fn () => $projector->cleanupRuntime($member))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('app-prod.php_service_missing')
                ->and($exception->getMessage())
                ->toBe('The production PHP Instance has no recorded dedicated PHP-FPM service.');
        });
});

it('removes a final public Route edge before deleting the Route and refreshes a surviving public Route', function (): void {
    [$member, $route, $departing, $survivor] = orb183_projector_production_member(shared: false);
    $survivorRoute = orb181_projector_route($departing->project, null, $route->cluster, 'survivor.production.test');
    $survivorRoute->targets()->create(['instance_id' => $survivor->id, 'position' => 0]);
    $survivorRoute->update([
        'status' => RouteStatus::Active,
        'publication' => RoutePublication::Public,
    ]);
    $survivor->update(['status' => InstanceState::Active]);
    $route->update([
        'publication' => RoutePublication::Public,
    ]);
    orb181_projector_node('ingress-final', '87', $route->cluster, RoleName::Ingress);
    $edge = new FakePublicRouteEdgeProjector;
    [$projector] = orb181_removal_projector($this, publicEdge: $edge);

    expect($projector->clearRouteTarget($member))
        ->toBe('deleted')
        ->and(Route::query()->find($route->id))
        ->toBeNull()
        ->and($survivorRoute->refresh()->publication)
        ->toBe(RoutePublication::Public)
        ->and($edge->calls)
        ->toContain('remove-public-edge')
        ->and($edge->calls)
        ->toContain('ingress-firewall');

    expect($projector->clearRouteTarget($member))
        ->toBe('deleted')
        ->and(Route::query()->find($route->id))
        ->toBeNull();
});

it('removes only the dedicated runtime for an associated production Instance', function (): void {
    [$member, , $departing] = orb183_projector_production_member(shared: false);
    $user = "orbit-app-{$departing->project_id}";
    $departing->update([
        'checkout_path' => "/home/{$user}",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'root' => 'public',
    ]);
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($departing->refresh(), '8.5');
    $departing->update($identity->attributes());
    $dedicated = new Orb214RemovalPhpRuntimeManager;
    [$projector, $ssh] = orb181_removal_projector($this, $dedicated);

    $projector->cleanupRuntime($member);

    expect($dedicated->removed)
        ->toBe([$departing->id])
        ->and(collect($ssh->commands)
            ->contains(
                static fn (RemoteCommand $command): bool => str_contains(
                    $command->input ?? '',
                    'orbit-scopes.conf',
                ),
            ))
        ->toBeFalse();
});

it('retries shared and final production cleanup without restoring targets or Routes', function (bool $shared): void {
    [$member, $route, , $survivor] = orb183_projector_production_member($shared);
    [$projector, $ssh, $processes] = orb181_removal_projector($this);
    $processes->failCall = 1;

    expect(fn () => $projector->clearRouteTarget($member))
        ->toThrow(RuntimeConvergenceException::class);
    expect($route->refresh()->targets()->pluck('instance_id')->all())
        ->toBe($shared ? [$survivor->id] : [])
        ->and(Route::query()->find($route->id))
        ->not->toBeNull();

    expect($projector->clearRouteTarget($member))
        ->toBe($shared ? 'retained' : 'deleted')
        ->and($route->targets()->pluck('instance_id')->all())
        ->toBe($shared ? [$survivor->id] : [])
        ->and(Route::query()->find($route->id) instanceof Route)
        ->toBe($shared)
        ->and(collect(orb181_caddy_configurations($ssh->commands))
            ->contains(
                static fn (string $configuration): bool => orb181_unavailable_site($configuration),
            ))
        ->toBeFalse();
})->with(['shared' => true, 'final' => false]);

function orb181_unavailable_site(string $configuration): bool
{
    return str_contains($configuration, 'Orbit Route unavailable')
        && ! str_contains($configuration, 'handle_errors');
}

function expectConnectionHost(?string $host): SshConnection
{
    return new SshConnection(
        host: (string) $host,
        user: 'orbit',
        port: 22,
        identityFile: '/tmp/orbit-test-key',
        knownHostsFile: '/tmp/orbit-test-known-hosts',
        commandTimeout: 900.0,
    );
}

/** @return array{InstanceRemovalMember, Route, Instance, Instance, Node} */
function orb183_projector_production_member(bool $shared): array
{
    $project = orb181_projector_app($shared ? 'production-shared' : 'production-final');
    $cluster = Cluster::query()->create([
        'name' => $shared ? 'production-shared' : 'production-final',
        'state' => 'active',
    ]);
    $router = orb181_projector_node(
        $shared ? 'router-shared' : 'router-final',
        $shared ? '81' : '82',
        $cluster,
        RoleName::Router,
    );
    $departingNode = orb181_projector_node(
        $shared ? 'prod-one-shared' : 'prod-one-final',
        $shared ? '83' : '84',
        $cluster,
        RoleName::AppProd,
    );
    $survivorNode = orb181_projector_node(
        $shared ? 'prod-two-shared' : 'prod-two-final',
        $shared ? '85' : '86',
        $cluster,
        RoleName::AppProd,
    );
    $departing = orb181_projector_instance($project, $departingNode, 'production', 'one');
    $survivor = orb181_projector_instance($project, $survivorNode, 'production', 'two');
    $route = orb181_projector_route(
        $project,
        null,
        $cluster,
        $shared ? 'shared.production.test' : 'final.production.test',
    );
    $route->targets()->create(['instance_id' => $departing->id, 'position' => 0]);

    if ($shared) {
        $route->targets()->create(['instance_id' => $survivor->id, 'position' => 1]);
    }

    $route->update(['status' => RouteStatus::Active]);
    $departing->update(['status' => InstanceState::Active]);

    if ($shared) {
        $survivor->update(['status' => InstanceState::Active]);
    }

    return [orb181_projector_member($departing, $route), $route, $departing, $survivor->load('node'), $router];
}

/** @return array{NativeInstanceRemovalProjector, Orb181RemovalSshExecutor, Orb181RemovalProcessRunner} */
function orb181_removal_projector(
    object $test,
    ?ProductionPhpRuntimeManager $productionPhp = null,
    ?PublicRouteEdgeProjector $publicEdge = null,
): array {
    $ssh = new Orb181RemovalSshExecutor;
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
            return "LEAF\n";
        }

        public function rootCertificate(): string
        {
            return "ROOT\n";
        }
    };
    $sites = new DevelopmentSiteRepository;
    $processes = new Orb181RemovalProcessRunner;
    $home = sys_get_temp_dir().'/orbit-removal-projector-'.Str::uuid();
    $test->orb181ProjectorHome = $home;
    config()->set('orbit.home', $home);

    return [
        new NativeInstanceRemovalProjector(
            new RemoteAppDevCaddyManager(SshNodeCaddyBuilds::over($ssh), $executor),
            new RemoteAppDevCertificateManager($executor, $signer, $accounts),
            new RemoteAppDevPhpFpmManager(
                $sites,
                new DevelopmentPhpFpmConfigRenderer,
                $executor,
                $accounts,
                new RemotePhpPackageManager,
            ),
            new DnsmasqPrivateDnsManager($processes, new DevelopmentDnsConfigRenderer($sites)),
            new RemoteAppDevRouteFirewallManager($executor),
            $productionPhp,
            $publicEdge,
        ),
        $ssh,
        $processes,
    ];
}

final class Orb214RemovalPhpRuntimeManager implements ProductionPhpRuntimeManager
{
    /** @var list<int> */
    public array $removed = [];

    public function converge(Instance $instance): void {}

    public function convergeMonitoring(Instance $instance, bool $enabled): void {}

    public function refreshCache(Instance $instance): void {}

    public function remove(Instance $instance): void
    {
        $this->removed[] = $instance->id;
    }
}

/** @return array{InstanceRemovalMember, Route} */
function orb181_projector_development_member(): array
{
    $project = orb181_projector_app('development');
    $node = orb181_projector_node('app-dev', '31', null, RoleName::AppDev);
    $instance = orb181_projector_instance($project, $node, 'development', 'dev');
    $route = orb181_projector_route($project, $node, null, 'dev.acme.test');
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);

    return [orb181_projector_member($instance, $route), $route];
}

function orb181_projector_app(string $suffix): Project
{
    return Project::query()->create([
        'name' => "Acme {$suffix}",
        'slug' => "acme-{$suffix}",
        'repository_url' => "https://example.test/acme-{$suffix}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
}

function orb181_projector_node(
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
        'cluster_id' => in_array($role, [RoleName::Router, RoleName::Ingress], true) ? $cluster?->id : null,
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function orb181_projector_instance(
    Project $project,
    Node $node,
    string $environment,
    string $name,
): Instance {
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => $environment,
        'checkout_path' => "/home/orbit/apps/{$project->slug}/{$name}",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'status' => InstanceState::SourceResolved,
    ]);
}

function orb181_projector_route(
    Project $project,
    ?Node $node,
    ?Cluster $cluster,
    string $domain,
): Route {
    return Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node?->id,
        'cluster_id' => $cluster?->id,
        'generation_basis_node_id' => $node?->id,
        'domain' => $domain,
        'provenance' => $node instanceof Node ? RouteProvenance::Generated : RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
}

function orb181_projector_member(Instance $instance, Route $route): InstanceRemovalMember
{
    $operation = InstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => false,
        'inventory_digest' => str_repeat('d', 64),
        'total' => 1,
        'status' => InstanceRemovalStatus::Removing,
        'current_step' => InstanceRemovalStep::SourcePreparation,
    ]);
    $member = $operation
        ->members()
        ->create([
            'position' => 0,
            'instance_id' => $instance->id,
            'project_id' => $instance->project_id,
            'node_id' => $instance->node_id,
            'route_id' => $route->id,
            'name' => $instance->name,
            'environment' => $instance->defaultAppEnv(),
            'source_layout' => $instance->source_layout,
            'repository_identity' => $instance->project->repository_identity,
            'checkout_path' => $instance->checkout_path,
            'root' => $instance->effectiveRoot(),
            'branch' => $instance->branch,
            'starting_commit' => $instance->starting_commit,
            'source_commit' => $instance->starting_commit,
            'common_repository_path' => $instance->checkout_path,
            'source_identity' => 'test:1',
            'linked_worktree_paths' => [$instance->checkout_path],
            'source_digest' => str_repeat('e', 64),
        ]);
    $instance->update(['status' => InstanceState::Removing]);
    $member->update(['source_prepared_at' => now()]);

    return $member->refresh();
}

/** @param list<RemoteCommand> $commands @return list<string> */
function orb181_caddy_configurations(array $commands): array
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

/** @param list<ProcessInvocation> $invocations @return list<string> */
function orb181_dns_configurations(array $invocations): array
{
    $configurations = [];

    foreach ($invocations as $invocation) {
        if (! is_string($invocation->input)) {
            continue;
        }

        if (preg_match("/printf '%s' '([^']+)' \\| base64 --decode/", $invocation->input, $matches) !== 1) {
            continue;
        }

        $decoded = base64_decode($matches[1], true);

        if (is_string($decoded)) {
            $configurations[] = $decoded;
        }
    }

    return $configurations;
}

final class Orb181RemovalSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    public ?int $failCall = null;

    public bool $certificatePresent = true;

    public ?string $phpDiscovery = null;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;

        if ($this->failCall === count($this->commands)) {
            return new CommandResult(1, '', 'injected failure', 1, false);
        }

        if (
            is_string($this->phpDiscovery)
            && is_string($command->input)
            && str_contains($command->input, 'base64 --wrap=0 -- "$path"')
        ) {
            return new CommandResult(0, $this->phpDiscovery, '', 1, false);
        }

        if (is_string($command->input) && str_contains($command->input, "printf 'PRESENT\\n'")) {
            return new CommandResult(0, $this->certificatePresent ? "PRESENT\n" : "ABSENT\n", '', 1, false);
        }

        if (
            is_string($command->input)
            && str_contains(
                $command->input,
                'rm -rf -- "$managed_home/.orbit/certificates/$scope"',
            )
        ) {
            $this->certificatePresent = false;
        }

        return new CommandResult(0, '', '', 1, false);
    }
}

final class Orb181RemovalProcessRunner implements ProcessRunner
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
