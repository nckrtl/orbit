<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PrivateRouteProjectionObservation;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Doctor\NativePrivateRouteProjectionInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Tests\Support\AppDevFakeSshExecutor;

it('observes private Route projections without application HTTP checks', function (): void {
    [$instance, $route] = private_route_inspector_standalone();
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "caddy=1\ntls=1\ndns=1\nfirewall=1\nlaravel=1\n", '', 1, false),
    ]);

    $observation = private_route_inspector($ssh)->inspect($instance, $route);

    expect($observation)
        ->toEqual(new PrivateRouteProjectionObservation(true, true, true, true, true, true, true))
        ->and($ssh->commands[0]->arguments)
        ->toContain($route->domain)
        ->and(array_slice($ssh->commands[0]->arguments, 0, 3))->toBe(['sudo', 'bash', '-seu'])
        ->and($ssh->commands[0]->input)
        ->toContain('grep -Rqs -- "$domain" "$live" "$fragment_dir"')
        ->toContain('getent ahostsv4')
        ->toContain('APP_URL=')
        ->not->toContain('curl')
        ->not->toContain('wget')
        ->not->toContain('http://')
        ->not->toContain('https://127.0.0.1')
        ->and(json_encode($observation, JSON_THROW_ON_ERROR))
        ->not->toContain($route->domain)
        ->not->toContain((string) $instance->checkout_path);
});

it('reports a bounded private projection mismatch from remote observations', function (): void {
    [$instance, $route] = private_route_inspector_standalone();
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "caddy=0\ntls=0\ndns=0\nfirewall=1\nlaravel=0\n", '', 1, false),
    ]);

    $observation = private_route_inspector($ssh)->inspect($instance, $route);

    expect($observation)->toEqual(new PrivateRouteProjectionObservation(
        true,
        true,
        false,
        false,
        false,
        true,
        false,
    ));
});

it('fails closed when private Route inspection cannot run', function (): void {
    [$instance, $route] = private_route_inspector_standalone();

    expect(fn (): PrivateRouteProjectionObservation => private_route_inspector(
        new AppDevFakeSshExecutor([new CommandResult(1, '', 'permission denied', 1, false)]),
    )->inspect($instance, $route))->toThrow(DoctorInspectionException::class, '');
});

it('inspects Router Caddy on a selected Cluster Route', function (): void {
    [$instance, $route, $router] = private_route_inspector_cluster();
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "caddy=1\ntls=1\ndns=1\nfirewall=1\nlaravel=1\n", '', 1, false),
        new CommandResult(0, "caddy=0\ntls=1\nfirewall=1\n", '', 1, false),
    ]);

    $observation = private_route_inspector($ssh)->inspect($instance, $route);

    expect($observation->routerCaddyMatches)
        ->toBeFalse()
        ->and($observation->workloadCaddyMatches)
        ->toBeTrue()
        ->and($ssh->connections[1]->host)
        ->toBe($router->wireguard_ip)
        ->and($ssh->commands[1]->arguments)
        ->toContain($route->domain)
        ->and(array_slice($ssh->commands[1]->arguments, 0, 3))->toBe(['sudo', 'bash', '-seu']);
});

/** @return array{AppInstance, Route} */
function private_route_inspector_standalone(): array
{
    $app = App::query()->create([
        'name' => 'Private Doctor App',
        'slug' => 'private-doctor-app-'.uniqid(),
        'repository_url' => 'https://git.example.test/acme/private-doctor.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'private-doctor-workload-'.uniqid(),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => private_route_inspector_address(),
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'development',
        'environment' => 'development',
        'checkout_path' => '/srv/users/nckrtl/apps/private-doctor/development',
        'branch' => 'development',
        'starting_commit' => str_repeat('a', 40),
        'source_is_laravel' => true,
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'domain' => 'private-doctor-'.uniqid().'.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return [$instance->fresh(['node', 'app']), $route->fresh()];
}

/** @return array{AppInstance, Route, Node} */
function private_route_inspector_cluster(): array
{
    [$instance, $route] = private_route_inspector_standalone();
    $cluster = Cluster::query()->create([
        'name' => 'private-doctor-cluster-'.uniqid(),
        'state' => ClusterState::Active,
    ]);
    $instance->node->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.20']);
    $router = Node::query()->create([
        'name' => 'private-doctor-router-'.uniqid(),
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.81',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => private_route_inspector_address(),
        'lan_ip' => '10.10.0.21',
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $route->update(['node_id' => null, 'cluster_id' => $cluster->id]);

    return [$instance->fresh(['node', 'app']), $route->fresh(['cluster.routerAssignment.node']), $router];
}

function private_route_inspector(AppDevFakeSshExecutor $ssh): NativePrivateRouteProjectionInspector
{
    return new NativePrivateRouteProjectionInspector(
        new AppDevSshExecutor($ssh, private_route_inspector_keys(), private_route_inspector_hosts()),
        new CommandDeadline,
    );
}

function private_route_inspector_address(): string
{
    static $octet = 80;

    return '10.44.8.'.($octet++);
}

function private_route_inspector_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/doctor-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
}

function private_route_inspector_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/doctor-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}
