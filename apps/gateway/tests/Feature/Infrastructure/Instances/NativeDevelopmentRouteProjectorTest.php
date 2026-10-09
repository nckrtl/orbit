<?php

declare(strict_types=1);

use App\Actions\Clusters\SetClusterRouterAction;
use App\Actions\Routes\ConvergeRouteAction;
use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\SetRouteTargetAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\DevelopmentSourceAccess;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessEnvironmentProjection;
use App\Domain\Processes\ProcessPresets;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Routes\ClusterRouterReplacementProjector;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\NativeDevelopmentProjectionOperationLock;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Infrastructure\AppDev\RemoteAppDevRouteFirewallManager;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Instances\NativeDevelopmentRouteProjector;
use App\Infrastructure\Instances\NativeDevelopmentSourceAccess;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Routes\NativeClusterRouterReplacementProjector;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\FakeClusterRouterDnsSelectionReconciler;
use Tests\Support\IncusRuntimeWorkspace;
use Tests\Support\SshNodeCaddyBuilds;

it('uses one local workload site when Router and workload roles share a Node', function (): void {
    [$instance, $route, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
    $sites = new DevelopmentSiteRepository;
    $route->publishSites();
    $nodeSites = $sites->forNode($node);
    $configuration = new DevelopmentCaddyConfigRenderer()->render($nodeSites);
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        $projector->converge($instance, $route);

        $arguments = collect($ssh->commands)
            ->flatMap(
                static fn (RemoteCommand $command): array => $command->arguments,
            );
        expect($nodeSites)
            ->toHaveCount(1)
            ->and($nodeSites->sole()->isProxy())
            ->toBeFalse()
            ->and(mb_substr_count($configuration, "https://{$route->domain}"))
            ->toBe(1)
            ->and($configuration)
            ->toContain("php_fastcgi unix//run/php/orbit-{$instance->id}-web.sock")
            ->not->toContain('reverse_proxy 127.0.0.1:5173')
            ->toContain('forward_auth')
            ->toContain('/api/v1/runtime-activations/app-instance/'.$instance->id)
            ->toContain('handle @orbit_asleep')
            ->toContain('root /dev/shm/orbit/hibernation')
            ->toContain('try_files /app-instance-'.$instance->id.'.awake')
            ->toContain('tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt')
            ->not->toContain('tls_trust_pool')
            ->not->toContain("orbit-certificates/app-instance-{$instance->id}-app-web/current/root.pem")
            ->not->toContain('reverse_proxy https://')->and($arguments)->toContain("app-instance-{$instance->id}-app-web")
            ->not->toContain("route-{$route->id}-router", 'ufw', 's_client')->and($processes->invocations)->toHaveCount(
                1,
            );
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('renders old and candidate hostname sites with separate certificate scopes before DNS cutover', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);
    $replacement = orb_pending_domain_change($route, $instance, RouteReplacementStep::RouterCaddy);
    $sites = new DevelopmentSiteRepository;

    $workloadSites = $sites->forNode($workload);
    $routerSites = $sites->forNode($router);
    $dns = new DevelopmentDnsConfigRenderer($sites)->render();

    expect($workloadSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($workloadSites->map->certificateDirectory()->all())
        ->toBe([
            "/etc/caddy/orbit-certificates/app-instance-{$instance->id}-app-web/current",
            "/etc/caddy/orbit-certificates/app-instance-{$instance->id}-hostname-change-app-web/current",
        ])
        ->and($routerSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($routerSites->map->certificateDirectory()->all())
        ->toBe([
            "/etc/caddy/orbit-certificates/route-{$route->id}-router/current",
            "/etc/caddy/orbit-certificates/route-{$replacement->id}-router-hostname-change/current",
        ])
        ->and($dns)
        ->toContain(
            "host-record=feature.acme.test,{$router->wireguard_ip}",
            "host-record=next.acme.test,{$router->wireguard_ip}",
        );
});

it('retires a transferred generated Route without inventing an old Router certificate', function (): void {
    [$instance, $sourceRoute, $source] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
    $destination = Node::query()->create([
        'name' => 'transfer-destination', 'cluster_id' => $source->cluster_id,
        'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.30', 'public_ssh_host' => '192.0.2.30', 'user' => 'orbit',
    ]);
    $destination->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $replacement = Route::query()->create([
        'project_id' => $instance->project_id, 'cluster_id' => $source->cluster_id,
        'generation_basis_node_id' => $destination->id,
        'domain' => 'transferred.acme.test', 'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending,
        'replaces_route_id' => $sourceRoute->id, 'replacement_step' => RouteReplacementStep::DatabaseCutover,
    ]);
    $replacement->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $replacement->update(['status' => RouteStatus::Active]);
    $sourceRoute->update([
        'status' => RouteStatus::Retiring, 'replaced_by_route_id' => $replacement->id,
        'replacement_step' => RouteReplacementStep::DatabaseCutover,
    ]);
    $transfer = orb368_projection_transfer($instance, $sourceRoute, $replacement, $source, $destination, $source);
    $sites = new DevelopmentSiteRepository;
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        expect($sites->forNode($source)->pluck('domain')->all())->toBe(['transferred.acme.test']);
        expect(new DevelopmentCaddyConfigRenderer()->render($sites->forNode($source)))
            ->toContain("/route-{$replacement->id}-router/current/cert.pem")
            ->not->toContain("/route-{$sourceRoute->id}-router/current/cert.pem", 'feature.acme.test');
        expect(new DevelopmentDnsConfigRenderer($sites)->render())
            ->toContain('host-record=transferred.acme.test,10.44.0.10')
            ->not->toContain('feature.acme.test');

        $projector->converge($instance->refresh(), $replacement);
        $ssh->commands = [];
        $ssh->hosts = [];
        $projector->retireSource($transfer);

        $deletions = collect($ssh->commands)->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'sudo rm -rf -- "/etc/caddy/orbit-certificates/$scope"'));
        expect($deletions->map(static fn (RemoteCommand $command): string => $command->arguments[3])->values()->all())
            ->toBe(["app-instance-{$instance->id}-app-web", "app-instance-{$instance->id}-hostname-change-app-web", "route-{$sourceRoute->id}-router"]);
        $firstDeletion = $deletions->keys()->first();
        $caddyHosts = collect($ssh->commands)->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'orbit-caddy-build-result'))
            ->keys()->map(fn (int $index): string => $ssh->hosts[$index])->unique()->sort()->values()->all();
        expect($caddyHosts)->toBe(['10.44.0.10', '10.44.0.30']);
        expect(collect($ssh->commands)->take($firstDeletion)->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'orbit-caddy-build-result'))->count())->toBe(2);
        $projector->retireSource($transfer);
        expect($sourceRoute->refresh()->status)->toBe(RouteStatus::Retiring);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('per-app transfer retires every owned app certificate without deleting still-used Router certificates', function (bool $sourceWebUsesAppIdentity): void {
    [$instance, $webRoute, $source, $router] = orb127_route_projection_models();
    $webRoute->update(['status' => RouteStatus::Active]);
    $instance->project->update(['apps' => [
        ['name' => 'web', 'type' => 'laravel-app', 'path' => 'apps/web', 'web_root' => 'public'],
        ['name' => 'docs', 'type' => 'laravel-app', 'path' => 'apps/docs', 'web_root' => 'public'],
    ]]);
    $instance->refresh()->update(['app_overrides' => [], 'app_runtime' => [
        'web' => ['php_version' => '8.5', 'laravel' => true], 'docs' => ['php_version' => '8.5', 'laravel' => true],
    ]]);
    $docsRoute = Route::query()->create([
        'app' => 'docs', 'project_id' => $instance->project_id, 'cluster_id' => $source->cluster_id,
        'domain' => 'docs.acme.test', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending,
    ]);
    $docsRoute->targets()->create(['app' => 'docs', 'instance_id' => $instance->id, 'position' => 0]);
    $docsRoute->update(['status' => RouteStatus::Active]);
    $destination = Node::query()->create([
        'name' => 'transfer-destination', 'cluster_id' => $source->cluster_id, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.30', 'public_ssh_host' => '192.0.2.30', 'user' => 'orbit',
    ]);
    $destination->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $transfer = orb368_projection_transfer($instance, $webRoute, $webRoute, $source, $destination, $router);
    $webEntry = $transfer->app_journal['web'];
    $webEntry['source_app_identity'] = $sourceWebUsesAppIdentity;
    $transfer->update(['app_journal' => ['web' => $webEntry, 'docs' => [
        'source_route_id' => $docsRoute->id, 'destination_route_id' => $docsRoute->id, 'destination_domain' => $docsRoute->domain,
        'source_router_node_id' => $router->id, 'source_app_identity' => true, 'imported_environment_keys' => [],
    ]]]);
    [$projector, $ssh, , $home] = orb127_route_projector();
    try {
        $projector->retireSource($transfer);
        $deletions = collect($ssh->commands)->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'sudo rm -rf -- "/etc/caddy/orbit-certificates/$scope"'));
        expect($deletions->map(static fn (RemoteCommand $command): string => $command->arguments[3])->values()->all())->toBe([
            "app-instance-{$instance->id}".($sourceWebUsesAppIdentity ? '-app-web' : ''), "app-instance-{$instance->id}-hostname-change".($sourceWebUsesAppIdentity ? '-app-web' : ''),
            "app-instance-{$instance->id}-app-docs", "app-instance-{$instance->id}-hostname-change-app-docs",
        ]);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
})->with([true, false]);

it('preserves a Router certificate still serving the transferred explicit Route', function (): void {
    [$instance, $route, $source, $router] = orb127_route_projection_models();
    $destination = Node::query()->create([
        'name' => 'transfer-destination', 'cluster_id' => $source->cluster_id,
        'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.30', 'public_ssh_host' => '192.0.2.30', 'user' => 'orbit',
    ]);
    $destination->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $route->update(['status' => RouteStatus::Active]);
    $transfer = orb368_projection_transfer($instance, $route, $route, $source, $destination, $router);
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        $projector->retireSource($transfer);
        $deletions = collect($ssh->commands)->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'sudo rm -rf -- "/etc/caddy/orbit-certificates/$scope"'));
        expect($deletions->map(static fn (RemoteCommand $command): string => $command->arguments[3])->values()->all())
            ->toBe(["app-instance-{$instance->id}-app-web", "app-instance-{$instance->id}-hostname-change-app-web"]);
        expect(new DevelopmentSiteRepository()->forNode($router)->map->certificateDirectory()->all())
            ->toBe(["/etc/caddy/orbit-certificates/route-{$route->id}-router/current"]);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('cleans the recorded Router after the source Node changes Cluster membership', function (): void {
    [$instance, $route, $source, $originalRouter] = orb127_route_projection_models();
    $destinationCluster = Cluster::query()->create(['name' => 'destination', 'state' => ClusterState::Active]);
    $destination = Node::query()->create([
        'name' => 'transfer-destination', 'cluster_id' => $destinationCluster->id,
        'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.30', 'public_ssh_host' => '192.0.2.30', 'user' => 'orbit',
    ]);
    $destination->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $destination->roles()->create([
        'cluster_id' => $destinationCluster->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active,
    ]);
    $route->update(['status' => RouteStatus::Active, 'cluster_id' => $destinationCluster->id]);
    $transfer = orb368_projection_transfer($instance, $route, $route, $source, $destination, $originalRouter);
    $source->update(['cluster_id' => $destinationCluster->id]);
    [$projector, $ssh, , $home] = orb127_route_projector();

    try {
        $projector->retireSource($transfer);

        $deletions = collect($ssh->commands)->filter(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'sudo rm -rf -- "/etc/caddy/orbit-certificates/$scope"'));
        expect($deletions->map(fn (RemoteCommand $command, int $index): array => [$ssh->hosts[$index], $command->arguments[3]])->values()->all())->toBe([
            ['10.44.0.10', "app-instance-{$instance->id}-app-web"],
            ['10.44.0.10', "app-instance-{$instance->id}-hostname-change-app-web"],
            ['10.44.0.20', "route-{$route->id}-router"],
        ]);
        expect(new DevelopmentSiteRepository()->forNode($destination)->map->certificateDirectory()->all())
            ->toBe(["/etc/caddy/orbit-certificates/app-instance-{$instance->id}-app-web/current"]);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('keeps source certificates when retirement cannot reconcile Caddy', function (): void {
    [$instance, $route, $source, $router] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $transfer = orb368_projection_transfer($instance, $route, $route, $source, $router, $router);
    [$projector, $ssh, , $home] = orb127_route_projector(
        static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'orbit-caddy-build-result'),
    );

    try {
        expect(fn () => $projector->retireSource($transfer))->toThrow(RuntimeConvergenceException::class);
        expect(collect($ssh->commands)->contains(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'sudo rm -rf -- "/etc/caddy/orbit-certificates/$scope"')))->toBeFalse();
        expect($transfer->refresh()->completed_at)->toBeNull();
        $this->assertModelExists($route);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('preserves the ready hostname candidate across an interrupted DNS publication and ordinary rebuild', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update([
        'status' => InstanceState::Active,
        'source_is_laravel' => false,
    ]);
    $sites = new DevelopmentSiteRepository;

    expect($sites->forNode($workload)->pluck('domain')->all())
        ->toBe(['feature.acme.test']);

    $replacement = Route::query()->create([
        'project_id' => $route->project_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'next.acme.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => RouteReplacementStep::LaravelUrl,
    ]);
    $replacement->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['replaced_by_route_id' => $replacement->id]);

    $workloadSites = $sites->forNode($workload);
    $routerSites = $sites->forNode($router);
    $dns = new DevelopmentDnsConfigRenderer($sites)->render();

    expect($workloadSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($workloadSites->map->certificateDirectory()->all())
        ->toBe([
            "/etc/caddy/orbit-certificates/app-instance-{$instance->id}-app-web/current",
            "/etc/caddy/orbit-certificates/app-instance-{$instance->id}-hostname-change-app-web/current",
        ])
        ->and($routerSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($routerSites->map->certificateDirectory()->all())
        ->toBe([
            "/etc/caddy/orbit-certificates/route-{$route->id}-router/current",
            "/etc/caddy/orbit-certificates/route-{$replacement->id}-router-hostname-change/current",
        ])
        ->and($dns)
        ->toContain(
            "host-record=feature.acme.test,{$router->wireguard_ip}",
            "host-record=next.acme.test,{$router->wireguard_ip}",
        );

    expect($sites->forNode($workload)->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test']);

    // Cutover ends the old name. Both sites share one certificate scope, so serving the retired
    // domain past this point means serving it off the replacement's leaf, which sends Caddy to
    // automatic HTTPS for a private Orbit domain.
    $route->update(['status' => RouteStatus::Retiring, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);
    $replacement->update(['status' => RouteStatus::Active, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);
    expect($sites->forNode($router)->pluck('domain')->sort()->values()->all())->toBe(['next.acme.test']);
    expect(new DevelopmentDnsConfigRenderer($sites)->render())
        ->toContain("host-record=next.acme.test,{$router->wireguard_ip}")
        ->not->toContain("host-record=feature.acme.test,{$router->wireguard_ip}");
});

it('reprojects selected per-app route Process origins through native cleanup and rollback', function (bool $rollback): void {
    [$instance, $route] = orb127_route_projection_models();
    $instance->project->update(['apps' => [
        ['name' => 'web', 'path' => 'apps/web', 'web_root' => 'public', 'type' => 'laravel-app'],
        ['name' => 'docs', 'path' => 'apps/docs', 'web_root' => 'public', 'type' => 'laravel-app'],
    ]]);
    $route->update(['status' => RouteStatus::Active]);
    $sibling = Route::query()->create(['project_id' => $route->project_id, 'app' => 'docs', 'cluster_id' => $route->cluster_id, 'domain' => 'docs.acme.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $sibling->targets()->create(['instance_id' => $instance->id, 'app' => 'docs', 'position' => 0]);
    $sibling->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);
    $replacement = Route::query()->create(['project_id' => $route->project_id, 'app' => 'web', 'cluster_id' => $route->cluster_id, 'domain' => 'next.acme.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending', 'replaces_route_id' => $route->id, 'replacement_step' => RouteReplacementStep::Reserved]);
    $replacement->targets()->create(['instance_id' => $instance->id, 'app' => 'web', 'position' => 0]);
    $route->update(['replaced_by_route_id' => $replacement->id]);
    foreach (['web' => ['vp-dev', 'annotator'], 'docs' => ['vp-dev']] as $app => $presets) {
        foreach ($presets as $preset) {
            Process::query()->create(['owner_type' => 'instance', 'owner_id' => $instance->id, 'app' => $app, 'name' => $app.'-'.$preset, 'runtime' => 'systemd', 'working_directory' => $instance->applicationDirectory($app), 'runtime_config' => ['preset' => $preset, 'command' => ProcessPresets::command($preset)], 'desired_state' => $preset === 'vp-dev' ? 'running' : 'stopped', 'status' => 'active']);
        }
    }
    $instance->recordAppRuntime('web', ['annotator_port' => 4848]);
    $instance->recordAppRuntime('docs', ['annotator_port' => 4849]);
    $before = $instance->processes()->get()->map->getAttributes()->all();
    $instance->load('routes.targets');
    if ($rollback) {
        $replacement->update(['status' => RouteStatus::Failed, 'failed_step' => 'workload-caddy', 'error_code' => 'route.domain_change_failed']);
    } else {
        $route->update(['status' => RouteStatus::Retiring]);
        $replacement->update(['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);
    }
    $units = [];
    $projection = Mockery::mock(ProcessEnvironmentProjection::class);
    $projection->shouldReceive('project')->once()->withArgs(function (Instance $owner, int $except, ?string $app) use (&$units): bool {
        expect($except)->toBe(0)->and($app)->toBe('web');
        foreach ($owner->processes()->where('app', $app)->get() as $process) {
            $units[$process->runtime_config['preset']] = new SystemdProcessRenderer()->render($process, new ProcessTargetResolver()->forInspection($process), new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
        }

        return true;
    });
    app()->instance(ProcessEnvironmentProjection::class, $projection);
    [$projector, $ssh, $processes, $home] = orb127_route_projector();
    try {
        if ($rollback) {
            $projector->rollbackCaddy($instance, $replacement);
        } else {
            orb_domain_change_cleanup($projector, [$instance], $replacement);
        }
        $domain = $rollback ? $route->domain : $replacement->domain;
        expect($units['vp-dev'])->toContain('ORBIT_DEV_SERVER_ORIGIN=https://'.$domain)
            ->and($units['annotator'])->toContain('--allow-origin')->toContain('https://'.$domain)
            ->and(implode('', $units))->not->toContain('docs.acme.test')
            ->and($instance->processes()->get()->map->getAttributes()->all())->toBe($before)
            ->and($sibling->refresh()->domain)->toBe('docs.acme.test');
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
})->with(['forward' => false, 'rollback' => true]);

it('serves every hostname-change site from a certificate the flow wrote for that domain', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active, 'source_is_laravel' => false]);
    $replacement = Route::query()->create([
        'project_id' => $route->project_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'next.acme.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);
    $replacement->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['replaced_by_route_id' => $replacement->id]);
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        $current = $route->refresh();
        $candidate = $replacement->refresh();
        $projector->prepareWorkloadCertificate($instance, $current, $candidate);
        $projector->prepareWorkloadCaddy($instance, $current, $candidate);
        $projector->prepareRouterCertificate($instance, $current, $candidate);
        $projector->prepareFirewallPolicy($instance, $candidate);
        $projector->verifyWorkload($instance, $candidate);
        $projector->prepareRouterCaddy($instance, $current, $candidate);
        $projector->publishDns($current, $candidate);
        $route->update(['status' => RouteStatus::Retiring]);
        $replacement->update([
            'status' => RouteStatus::Activating,
            'replacement_step' => RouteReplacementStep::DatabaseCutover,
        ]);
        orb_domain_change_cleanup($projector, [$instance->refresh()], $replacement);

        [$mismatches, $disk, $served] = orb_hostname_change_certificate_replay(
            $ssh,
            [
                $workload->wireguard_ip => ["app-instance-{$instance->id}-app-web" => 'feature.acme.test'],
                $router->wireguard_ip => ["route-{$route->id}-router" => 'feature.acme.test'],
            ],
        );

        expect($mismatches)->toBe([])
            ->and($served[$workload->wireguard_ip])->toBe(['next.acme.test' => "app-instance-{$instance->id}-app-web"])
            ->and($served[$router->wireguard_ip])->toBe(['next.acme.test' => "route-{$replacement->id}-router"])
            ->and($disk[$workload->wireguard_ip])->toBe(["app-instance-{$instance->id}-app-web" => 'next.acme.test'])
            ->and($disk[$router->wireguard_ip])->toBe(["route-{$replacement->id}-router" => 'next.acme.test']);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('renders a pending replacement only after the step that writes each certificate and never once it failed', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active, 'source_is_laravel' => false]);
    $replacement = orb_pending_domain_change($route, $instance, RouteReplacementStep::Reserved);
    $sites = new DevelopmentSiteRepository;
    $domains = static fn (Node $node): array => $sites->forNode($node)->pluck('domain')->all();

    expect($domains($workload))->toBe(['feature.acme.test'])
        ->and($domains($router))->toBe(['feature.acme.test']);

    $replacement->update(['replacement_step' => RouteReplacementStep::WorkloadCaddy]);

    expect($domains($workload))->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($domains($router))->toBe(['feature.acme.test']);

    $replacement->update(['replacement_step' => RouteReplacementStep::RouterCertificate]);

    expect($domains($router))->toBe(['feature.acme.test', 'next.acme.test']);

    $replacement->update([
        'status' => RouteStatus::Failed,
        'failed_step' => 'dns-publication',
        'error_code' => 'app-dev.dns_failed',
    ]);

    expect($domains($workload))->toBe(['feature.acme.test'])
        ->and($domains($router))->toBe(['feature.acme.test'])
        ->and(new DevelopmentDnsConfigRenderer($sites)->render())->not->toContain('next.acme.test');
});

it('withdraws a failed domain change before removing its certificates and retries it from the start', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models(phpVersion: '8.5');
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active, 'source_is_laravel' => false]);
    [$projector, $ssh, $processes, $home] = orb127_route_projector();
    app()->instance(RouteDomainProjector::class, $projector);
    $disk = [
        $workload->wireguard_ip => ["app-instance-{$instance->id}-app-web" => 'feature.acme.test'],
        $router->wireguard_ip => ["route-{$route->id}-router" => 'feature.acme.test'],
    ];
    // Private DNS publication runs after the Router Caddy step, so the rollback has to withdraw
    // candidate sites on both Nodes.
    $processes->failNext = 1;

    try {
        expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.acme.test'))
            ->toThrow(RuntimeConvergenceException::class);

        [$mismatches, $afterRollback, $served] = orb_hostname_change_certificate_replay($ssh, $disk);

        expect($mismatches)->toBe([])
            ->and($route->refresh()->status)->toBe(RouteStatus::Active)
            ->and($route->replaced_by_route_id)->toBeNull()
            ->and(Route::query()->where('domain', 'next.acme.test')->exists())->toBeFalse()
            ->and($served[$workload->wireguard_ip])->toBe(['feature.acme.test' => "app-instance-{$instance->id}-app-web"])
            ->and($served[$router->wireguard_ip])->toBe(['feature.acme.test' => "route-{$route->id}-router"])
            ->and($afterRollback)->toBe($disk);

        $updated = app(ConvergeRouteAction::class)->execute($route->refresh(), 'next.acme.test');
        [$mismatches, $afterRetry, $served] = orb_hostname_change_certificate_replay($ssh, $disk);

        expect($mismatches)->toBe([])
            ->and($updated->domain)->toBe('next.acme.test')
            ->and($updated->status)->toBe(RouteStatus::Active)
            ->and($served[$router->wireguard_ip])->toBe(['next.acme.test' => "route-{$updated->id}-router"])
            ->and($afterRetry[$router->wireguard_ip])->toBe(["route-{$updated->id}-router" => 'next.acme.test']);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('serves a composed Router pool from the staging Router certificate during a domain change', function (): void {
    [$remote, $route, $workload, $router] = orb127_route_projection_models();
    // A multi-target Route is a production pool on app-prod Nodes of its Cluster.
    $workload->roles()->where('role', RoleName::AppDev->value)->delete();
    $workload->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $router->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $production = static fn (string $name): array => [
        'environment' => 'production',
        'production_home' => "/srv/acme/{$name}",
        'production_user' => 'orbit-acme',
        'source_is_laravel' => false,
    ];
    $remote->update($production('feature'));
    $local = Instance::query()->create([
        'project_id' => $route->project_id,
        'node_id' => $router->id,
        'name' => 'local',
        'checkout_path' => '/srv/acme/local',
        'app_overrides' => fixture_app_overrides('public'),
        'branch' => 'feature',
        'starting_commit' => str_repeat('b', 40),
        ...$production('local'),
    ]);
    // The target on the Router comes first, so its cleanup publishes the composed pool first.
    $route->targets()->delete();
    $route->targets()->create(['instance_id' => $local->id, 'position' => 0]);
    $route->targets()->create(['instance_id' => $remote->id, 'position' => 1]);
    $route->update(['status' => RouteStatus::Active]);
    $remote->update(['status' => InstanceState::Active]);
    $local->update(['status' => InstanceState::Active]);
    $replacement = orb_pending_domain_change($route, $local, RouteReplacementStep::Reserved);
    $replacement->targets()->create(['instance_id' => $remote->id, 'position' => 1]);
    $targets = [$local->refresh(), $remote->refresh()];
    [$projector, $ssh, $processes, $home] = orb127_route_projector();
    $step = static function (RouteReplacementStep $step, Closure $operation) use ($targets, $replacement): void {
        foreach ($targets as $target) {
            $operation($target);
        }

        $replacement->update(['replacement_step' => $step]);
    };

    try {
        $current = $route->refresh();
        $candidate = $replacement->refresh();
        $step(RouteReplacementStep::WorkloadCertificate, fn (Instance $target) => $projector->prepareWorkloadCertificate($target, $current, $candidate));
        $step(RouteReplacementStep::WorkloadCaddy, fn (Instance $target) => $projector->prepareWorkloadCaddy($target, $current, $candidate));
        $step(RouteReplacementStep::RouterCertificate, fn (Instance $target) => $projector->prepareRouterCertificate($target, $current, $candidate));
        $step(RouteReplacementStep::RouterCaddy, fn (Instance $target) => $projector->prepareRouterCaddy($target, $current, $candidate));
        $routerDuringChange = new DevelopmentSiteRepository()->forNode($router)->firstWhere('domain', 'next.acme.test');
        $route->update(['status' => RouteStatus::Retiring]);
        $replacement->update(['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);
        orb_domain_change_cleanup($projector, $targets, $replacement);

        [$mismatches, $disk, $served] = orb_hostname_change_certificate_replay(
            $ssh,
            [
                $workload->wireguard_ip => ["app-instance-{$remote->id}" => 'feature.acme.test'],
                $router->wireguard_ip => [
                    "app-instance-{$local->id}" => 'feature.acme.test',
                    "route-{$route->id}-router" => 'feature.acme.test',
                ],
            ],
        );

        expect($routerDuringChange?->certificateDirectory())
            ->toBe("/etc/caddy/orbit-certificates/route-{$replacement->id}-router-hostname-change/current")
            ->and($mismatches)->toBe([])
            ->and($served[$router->wireguard_ip])->toBe(['next.acme.test' => "route-{$replacement->id}-router"])
            ->and($disk[$router->wireguard_ip])->toBe([
                "app-instance-{$local->id}" => 'next.acme.test',
                "route-{$replacement->id}-router" => 'next.acme.test',
            ]);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('removes the retiring Router certificate from the Router that served it when the change moves Cluster', function (): void {
    [$instance, $route, $workload, $oldRouter] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active, 'source_is_laravel' => false]);
    $cluster = Cluster::query()->create(['name' => 'moved-'.Str::lower(Str::random(8)), 'state' => ClusterState::Active]);
    $newRouter = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-'.Str::lower(Str::random(8)),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
        'user' => 'orbit',
    ]);
    $newRouter->roles()->create(['cluster_id' => $cluster->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active]);
    $replacement = orb_pending_domain_change($route, $instance, RouteReplacementStep::Reserved);
    $replacement->update(['cluster_id' => $cluster->id]);
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        $current = $route->refresh();
        $candidate = $replacement->refresh();
        $projector->prepareWorkloadCertificate($instance, $current, $candidate);
        $replacement->update(['replacement_step' => RouteReplacementStep::WorkloadCertificate]);
        $projector->prepareWorkloadCaddy($instance, $current, $candidate);
        $projector->prepareRouterCertificate($instance, $current, $candidate);
        $replacement->update(['replacement_step' => RouteReplacementStep::RouterCertificate]);
        $projector->prepareRouterCaddy($instance, $current, $candidate);
        $route->update(['status' => RouteStatus::Retiring]);
        $replacement->update(['status' => RouteStatus::Activating, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);
        orb_domain_change_cleanup($projector, [$instance->refresh()], $replacement);

        [$mismatches, $disk, $served] = orb_hostname_change_certificate_replay(
            $ssh,
            [
                $workload->wireguard_ip => ["app-instance-{$instance->id}-app-web" => 'feature.acme.test'],
                $oldRouter->wireguard_ip => ["route-{$route->id}-router" => 'feature.acme.test'],
            ],
        );

        expect($mismatches)->toBe([])
            ->and($served[$oldRouter->wireguard_ip] ?? null)->toBe([])
            ->and($disk[$oldRouter->wireguard_ip])->toBe([])
            ->and($served[$newRouter->wireguard_ip])->toBe(['next.acme.test' => "route-{$replacement->id}-router"])
            ->and($disk[$newRouter->wireguard_ip])->toBe(["route-{$replacement->id}-router" => 'next.acme.test']);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('moves a Route to another Cluster through its stored transition and restores a failed move', function (): void {
    [$instance, $route, $workload, $oldRouter] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active, 'source_is_laravel' => false]);
    [$cluster, $newRouter] = orb_second_cluster_router();
    [$projector, $ssh, $processes, $home] = orb127_route_projector();
    app()->instance(RouteDomainProjector::class, $projector);
    $placement = new RoutePlacement(nodeId: null, clusterId: $cluster->id, effectiveTld: null);
    $disk = [
        $workload->wireguard_ip => ["app-instance-{$instance->id}-app-web" => 'feature.acme.test'],
        $oldRouter->wireguard_ip => ["route-{$route->id}-router" => 'feature.acme.test'],
    ];
    // Private DNS publication runs after the candidate Router build, so the restore has to withdraw
    // the candidate before it removes the candidate certificates.
    $processes->failNext = 1;

    try {
        expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'feature.acme.test', placement: $placement))
            ->toThrow(RuntimeConvergenceException::class);

        [$mismatches, $afterRestore, $served] = orb_hostname_change_certificate_replay($ssh, $disk);
        $restored = $route->refresh();

        expect($mismatches)->toBe([])
            ->and($restored->cluster_id)->toBe($oldRouter->cluster_id)
            ->and($restored->transition_cluster_id)->toBeNull()
            ->and($restored->replacement_step)->toBe(RouteReplacementStep::Reserved)
            ->and($served[$newRouter->wireguard_ip])->toBe([])
            ->and($afterRestore[$newRouter->wireguard_ip])->toBe([])
            ->and($served[$oldRouter->wireguard_ip])->toBe(['feature.acme.test' => "route-{$route->id}-router"]);

        $moved = app(ConvergeRouteAction::class)->execute($restored, 'feature.acme.test', placement: $placement);
        [$mismatches, $afterMove, $served] = orb_hostname_change_certificate_replay($ssh, $disk);

        expect($mismatches)->toBe([])
            ->and($moved->cluster_id)->toBe($cluster->id)
            ->and($moved->transition_cluster_id)->toBeNull()
            ->and($moved->replacement_step)->toBeNull()
            ->and($served[$newRouter->wireguard_ip])->toBe(['feature.acme.test' => "route-{$route->id}-router"])
            ->and($served[$oldRouter->wireguard_ip])->toBe([])
            ->and($afterMove[$newRouter->wireguard_ip])->toBe(["route-{$route->id}-router" => 'feature.acme.test'])
            ->and($afterMove[$oldRouter->wireguard_ip])->toBe([])
            ->and($afterMove[$workload->wireguard_ip])->toBe(["app-instance-{$instance->id}-app-web" => 'feature.acme.test']);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('replaces a Router through its stored router rows and restores a failed replacement', function (): void {
    [$instance, $route, $workload, $oldRouter] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active, 'source_is_laravel' => false]);
    $candidate = orb_router_candidate($oldRouter);
    [$projector, $ssh, $processes, $home] = orb127_route_projector();
    $action = orb_router_replacement_action();
    $disk = [
        $workload->wireguard_ip => ["app-instance-{$instance->id}-app-web" => 'feature.acme.test'],
        $oldRouter->wireguard_ip => ["route-{$route->id}-router" => 'feature.acme.test'],
    ];
    $cluster = Cluster::query()->findOrFail($oldRouter->cluster_id);
    // The DNS publication fails once, after the candidate build served the Router sites.
    $processes->failNext = 1;

    try {
        expect(fn () => $action->execute($cluster, $candidate))->toThrow(RuntimeConvergenceException::class);

        [$mismatches, $afterRestore, $served] = orb_hostname_change_certificate_replay($ssh, $disk);
        $row = NodeRole::query()->where('node_id', $candidate->id)->where('role', RoleName::Router)->sole();

        expect($mismatches)->toBe([])
            ->and($row->status)->toBe(LifecycleStatus::Failed)
            ->and($row->failed_step)->toBe('dns-publication')
            ->and($served[$candidate->wireguard_ip])->toBe([])
            ->and($afterRestore[$candidate->wireguard_ip])->toBe([])
            ->and(new DevelopmentSiteRepository()->forNode($oldRouter)->pluck('domain')->all())->toBe(['feature.acme.test']);

        $action->execute($cluster->refresh(), $candidate);
        [$mismatches, $afterReplacement, $served] = orb_hostname_change_certificate_replay($ssh, $disk);

        expect($mismatches)->toBe([])
            ->and($cluster->refresh()->routerAssignment?->node_id)->toBe($candidate->id)
            ->and(NodeRole::query()->where('node_id', $oldRouter->id)->where('role', RoleName::Router)->exists())->toBeFalse()
            ->and($served[$candidate->wireguard_ip])->toBe(['feature.acme.test' => "route-{$route->id}-router"])
            ->and($served[$oldRouter->wireguard_ip])->toBe([])
            ->and($afterReplacement[$oldRouter->wireguard_ip])->toBe([])
            ->and($afterReplacement[$candidate->wireguard_ip])->toBe(["route-{$route->id}-router" => 'feature.acme.test']);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('serves a crash-left pending domain change from its staging certificate on the replacement Router', function (): void {
    [$instance, $route, $workload, $oldRouter] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active, 'source_is_laravel' => false]);
    // The Gateway stopped after the domain change built the old Router.
    $replacement = orb_pending_domain_change($route, $instance, RouteReplacementStep::RouterCaddy);
    $candidate = orb_router_candidate($oldRouter);
    [$projector, $ssh, $processes, $home] = orb127_route_projector();
    $disk = [
        $workload->wireguard_ip => [
            "app-instance-{$instance->id}-app-web" => 'feature.acme.test',
            "app-instance-{$instance->id}-hostname-change-app-web" => 'next.acme.test',
        ],
        $oldRouter->wireguard_ip => [
            "route-{$route->id}-router" => 'feature.acme.test',
            "route-{$replacement->id}-router-hostname-change" => 'next.acme.test',
        ],
    ];

    try {
        orb_router_replacement_action()->execute(Cluster::query()->findOrFail($oldRouter->cluster_id), $candidate);

        [$mismatches, $after, $served] = orb_hostname_change_certificate_replay($ssh, $disk);

        expect($mismatches)->toBe([])
            ->and($served[$candidate->wireguard_ip])->toBe([
                'feature.acme.test' => "route-{$route->id}-router",
                'next.acme.test' => "route-{$replacement->id}-router-hostname-change",
            ])
            ->and($after[$candidate->wireguard_ip])->toBe([
                "route-{$route->id}-router" => 'feature.acme.test',
                "route-{$replacement->id}-router-hostname-change" => 'next.acme.test',
            ])
            ->and($after[$oldRouter->wireguard_ip])->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('preserves production release sites at the environment synchronization checkpoint', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models();
    $route->update(['status' => RouteStatus::Active]);
    $instance->update([
        'environment' => 'production',
        'checkout_path' => '/srv/acme/releases/one',
        'production_home' => '/srv/acme',
        'production_user' => 'orbit-acme',
        'production_php_socket' => '/run/php/orbit-acme.sock',
        'status' => InstanceState::Active,
        'source_is_laravel' => true,
    ]);
    $workload->roles()->where('role', RoleName::AppDev)->delete();
    $workload->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $instance->unsetRelation('node');
    $replacement = Route::query()->create([
        'project_id' => $route->project_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'next.acme.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => RouteReplacementStep::EnvironmentSynchronized,
    ]);
    $replacement->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['replaced_by_route_id' => $replacement->id]);
    $sites = new DevelopmentSiteRepository;

    $workloadSites = $sites->forNode($workload);
    $routerSites = $sites->forNode($router);

    expect($workloadSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test'])
        ->and($workloadSites->pluck('checkoutPath')->unique()->values()->all())
        ->toBe(['/srv/acme/current'])
        ->and($workloadSites->pluck('productionPhpSocket')->unique()->values()->all())
        ->toBe(['/run/php/orbit-acme.sock'])
        ->and($routerSites->pluck('domain')->all())
        ->toBe(['feature.acme.test', 'next.acme.test']);
});

it('hydrates only requested workload and Router routes while global inventory stays complete', function (): void {
    [$pendingInstance, $pendingRoute, $workload, $router] = orb127_route_projection_models();
    $activeInstance = Instance::query()->create([
        'project_id' => $pendingInstance->project_id,
        'node_id' => $workload->id,
        'name' => 'active',
        'checkout_path' => '/home/orbit/apps/acme/active',
        'app_overrides' => fixture_app_overrides('public'),
        'selected_php_version' => '8.5',
        'status' => 'source_resolved',
    ]);
    $activeRoute = Route::query()->create([
        'project_id' => $pendingInstance->project_id,
        'cluster_id' => $pendingRoute->cluster_id,
        'domain' => 'active.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $activeRoute->targets()->create(['instance_id' => $activeInstance->id, 'position' => 0]);
    $activeRoute->update(['status' => RouteStatus::Active]);
    $activeInstance->update(['status' => 'active']);
    $failedInstance = Instance::query()->create([
        'project_id' => $pendingInstance->project_id,
        'node_id' => $workload->id,
        'name' => 'failed',
        'checkout_path' => '/home/orbit/apps/acme/failed',
        'app_overrides' => fixture_app_overrides('public'),
        'selected_php_version' => '8.5',
        'status' => 'source_resolved',
    ]);
    $failedRoute = Route::query()->create([
        'project_id' => $pendingInstance->project_id,
        'cluster_id' => $pendingRoute->cluster_id,
        'domain' => 'failed.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $failedRoute->targets()->create(['instance_id' => $failedInstance->id, 'position' => 0]);
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
    $unrelatedNode->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $unrelatedInstance = Instance::query()->create([
        'project_id' => $pendingInstance->project_id,
        'node_id' => $unrelatedNode->id,
        'name' => 'unrelated',
        'checkout_path' => '/home/orbit/apps/acme/unrelated',
        'app_overrides' => fixture_app_overrides('public'),
        'selected_php_version' => '8.5',
        'status' => 'source_resolved',
    ]);
    $unrelatedRoute = Route::query()->create([
        'project_id' => $pendingInstance->project_id,
        'node_id' => $unrelatedNode->id,
        'domain' => 'unrelated.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $unrelatedRoute->targets()->create(['instance_id' => $unrelatedInstance->id, 'position' => 0]);
    $unrelatedRoute->update(['status' => RouteStatus::Active]);
    $unrelatedInstance->update(['status' => 'active']);
    $sites = new DevelopmentSiteRepository;
    // Creation stores the publication record before the first build of the pending Route.
    $pendingRoute->publishSites();
    $globalSites = $sites->all();
    $retrievedRoutes = collect();
    $retrievedInstances = collect();
    Event::listen(
        'eloquent.retrieved: '.Route::class,
        static function (Route $retrieved) use ($retrievedRoutes): void {
            $retrievedRoutes->push($retrieved->id);
        },
    );
    Event::listen(
        'eloquent.retrieved: '.Instance::class,
        static function (Instance $retrieved) use ($retrievedInstances): void {
            $retrievedInstances->push($retrieved->id);
        },
    );

    $workloadSites = $sites->forNode($workload);

    $siteIdentity = static fn (DevelopmentSite $site): array => [
        $site->scope,
        $site->domain,
        $site->nodeAddress,
        $site->upstreamAddresses,
    ];
    expect($workloadSites->map($siteIdentity)->all())
        ->toBe($globalSites->where('nodeId', $workload->id)->values()->map($siteIdentity)->all())
        ->and($workloadSites->pluck('scope')->all())
        ->toHaveCount(2)
        ->toContain("app-instance-{$pendingInstance->id}-app-web", "app-instance-{$activeInstance->id}-app-web")
        ->and($workloadSites->pluck('domain'))
        ->not->toContain($failedRoute->domain, $unrelatedRoute->domain)->and($retrievedRoutes)->toContain(
            $pendingRoute->id,
            $activeRoute->id,
        )
        ->not->toContain($failedRoute->id, $unrelatedRoute->id)->and($retrievedInstances)->toContain(
            $pendingInstance->id,
            $activeInstance->id,
        )
        ->not->toContain($failedInstance->id, $unrelatedInstance->id)->and($globalSites->pluck('domain'))->toContain(
            $pendingRoute->domain,
            $activeRoute->domain,
            $unrelatedRoute->domain,
        )
        ->not->toContain($failedRoute->domain);

    $routerSites = $sites->forNode($router);

    expect($routerSites->map($siteIdentity)->all())
        ->toBe($globalSites->where('nodeId', $router->id)->values()->map($siteIdentity)->all())
        ->and($routerSites->pluck('scope')->all())
        ->toHaveCount(2)
        ->toContain("route-{$pendingRoute->id}-router", "route-{$activeRoute->id}-router");
});

it('projects a dedicated Router over reachable LAN with separate keys and preserved TLS identity', function (): void {
    [$instance, $route, $workload, $router] = orb127_route_projection_models(
        workloadLan: '10.10.0.10',
        routerLan: '10.10.0.20',
    );
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        $projector->converge($instance, $route);

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
        $routerConfiguration = app(NodeCaddyfileRenderer::class)->render($router)->content;

        expect($arguments)
            ->toContain("app-instance-{$instance->id}-app-web", "route-{$route->id}-router")
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
                $route->domain,
                '-verify_return_error',
            ])
            ->and($routerConfiguration)
            ->toContain(
                'reverse_proxy https://10.10.0.10',
                "header_up Host {$route->domain}",
                "tls_server_name {$route->domain}",
                "tls /etc/caddy/orbit-certificates/route-{$route->id}-router/current/cert.pem",
            )
            ->and(collect($ssh->commands)
                ->contains(
                    static fn (RemoteCommand $command): bool => SshNodeCaddyBuilds::pushed($command) === $routerConfiguration,
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
    $firstRoute->update(['status' => RouteStatus::Active]);
    $firstInstance->update(['status' => InstanceState::Active]);
    $secondInstance = Instance::query()->create([
        'project_id' => $firstInstance->project_id,
        'node_id' => $workload->id,
        'name' => 'second',
        'checkout_path' => '/home/orbit/apps/acme/second',
        'app_overrides' => fixture_app_overrides('public'),
        'branch' => 'second',
        'starting_commit' => str_repeat('b', 40),
        'selected_php_version' => '8.5',
        'status' => InstanceState::SourceResolved,
    ]);
    $secondRoute = Route::query()->create([
        'project_id' => $firstRoute->project_id,
        'cluster_id' => $firstRoute->cluster_id,
        'domain' => 'second.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $secondRoute
        ->targets()
        ->create([
            'instance_id' => $secondInstance->id,
            'position' => 0,
        ]);
    [$projector, $ssh, , $home] = orb127_route_projector();

    try {
        $projector->converge($secondInstance, $secondRoute);

        $workloadConfiguration = app(NodeCaddyfileRenderer::class)->render($workload)->content;
        $routerConfiguration = app(NodeCaddyfileRenderer::class)->render($router)->content;
        $pushed = collect($ssh->commands)->map(static fn (RemoteCommand $command): ?string => SshNodeCaddyBuilds::pushed($command))->filter();

        expect($workloadConfiguration)
            ->toContain(
                "/etc/caddy/orbit-certificates/app-instance-{$firstInstance->id}-app-web/current/cert.pem",
                "/etc/caddy/orbit-certificates/app-instance-{$secondInstance->id}-app-web/current/cert.pem",
            )
            ->and($routerConfiguration)
            ->toContain(
                "/etc/caddy/orbit-certificates/route-{$firstRoute->id}-router/current/cert.pem",
                "/etc/caddy/orbit-certificates/route-{$secondRoute->id}-router/current/cert.pem",
            )
            ->and($pushed->contains($workloadConfiguration))
            ->toBeTrue()
            ->and($pushed->contains($routerConfiguration))
            ->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('grants source access only to the converging checkout and checkouts nested in it', function (): void {
    [$instance, $route, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
    route_access_served_instance($instance, $route, $node, 'sibling', '/home/orbit/apps/acme/other', releases: true);
    route_access_served_instance($instance, $route, $node, 'prefix', '/home/orbit/apps/acme/feature-two');
    route_access_served_instance($instance, $route, $node, 'nested', '/home/orbit/apps/acme/feature/.worktrees/nested');
    [$projector, $ssh, , $home] = orb127_route_projector();

    try {
        $projector->converge($instance, $route);

        expect(route_access_walked_checkouts($ssh))->toBe(['/home/orbit/apps/acme/feature', '/home/orbit/apps/acme/feature/.worktrees/nested']);
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

describe('setting a development Route target', function (): void {
    it('grants source access to the new target checkout only', function (): void {
        [$instance, $route, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
        $route->publishSites();
        route_access_served_instance($instance, $route, $node, 'sibling', '/home/orbit/apps/acme/other');
        $added = route_access_served_instance($instance, $route, $node, 'added', '/home/orbit/apps/acme/added', routed: false);
        [, $ssh, , $home] = orb127_route_projector();

        try {
            app(SetRouteTargetAction::class)->execute($route, $added->id);

            expect($route->refresh()->targets->sole()->instance_id)->toBe($added->id)
                ->and(route_access_walked_checkouts($ssh))->toBe(['/home/orbit/apps/acme/added']);
        } finally {
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('grants again on a retry and walks nothing for the current target of an active Route', function (): void {
        [$instance, $route, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
        $route->publishSites();
        $added = route_access_served_instance($instance, $route, $node, 'added', '/home/orbit/apps/acme/added', routed: false);
        [, $ssh, , $home] = orb127_route_projector();

        try {
            app(SetRouteTargetAction::class)->execute($route, $added->id);
            $ssh->commands = [];
            app(SetRouteTargetAction::class)->execute($route->refresh(), $added->id);

            expect(route_access_walked_checkouts($ssh))->toBe(['/home/orbit/apps/acme/added']);

            $route->update(['status' => RouteStatus::Active]);
            $ssh->commands = [];
            app(SetRouteTargetAction::class)->execute($route->refresh(), $added->id);

            expect($ssh->commands)->toBe([]);
        } finally {
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('walks nothing while the Route sites are unpublished', function (): void {
        [$instance, $route, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
        $added = route_access_served_instance($instance, $route, $node, 'added', '/home/orbit/apps/acme/added', routed: false);
        [, $ssh, , $home] = orb127_route_projector();

        try {
            app(SetRouteTargetAction::class)->execute($route, $added->id);

            expect($route->refresh()->targets->sole()->instance_id)->toBe($added->id)
                ->and($ssh->commands)->toBe([]);
        } finally {
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('returns projection contention before it changes or announces anything', function (): void {
        [$instance, $route, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
        $route->publishSites();
        $added = route_access_served_instance($instance, $route, $node, 'added', '/home/orbit/apps/acme/added', routed: false);
        [, $ssh, , $home] = orb127_route_projector();
        new Filesystem()->ensureDirectoryExists($home, 0o700);
        $holder = fopen($home.'/.dnsmasq-projections.lock', 'c+');
        flock($holder, LOCK_EX);
        $now = 0.0;
        app()->instance(DevelopmentProjectionOperationLock::class, new NativeDevelopmentProjectionOperationLock(
            orbitHome: $home,
            deadline: app(CommandDeadline::class),
            clock: static function () use (&$now): float {
                return $now += 31.0;
            },
            wait: static function (int $microseconds): void {},
        ));
        Event::fake([RecordBroadcast::class]);

        try {
            expect(fn () => app(SetRouteTargetAction::class)->execute($route, $added->id))
                ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('app-dev.projection_busy')
                    ->and($exception->status)->toBe(409));
            expect($route->refresh()->targets->sole()->instance_id)->toBe($instance->id)
                ->and($ssh->commands)->toBe([]);
            Event::assertNotDispatched(RecordBroadcast::class);
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('stores and announces the target when the grant fails, and a retry of the same target grants', function (): void {
        [$instance, $route, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
        $route->publishSites();
        $added = route_access_served_instance($instance, $route, $node, 'added', '/home/orbit/apps/acme/added', routed: false);
        $fail = true;
        [, $ssh, , $home] = orb127_route_projector(static function (RemoteCommand $command) use (&$fail): bool {
            return $fail && str_contains($command->input ?? '', 'u:caddy:r-X');
        });
        Event::fake([RecordBroadcast::class]);

        try {
            expect(fn () => app(SetRouteTargetAction::class)->execute($route, $added->id))
                ->toThrow(fn (RuntimeConvergenceException $exception) => expect($exception->errorCode)->toBe('app-dev.source_access_failed')
                    ->and($exception->step)->toBe('source-access')
                    ->and($exception->getMessage())->toContain('The Route target is stored.')
                    ->and($exception->getMessage())->toContain('Set the same target on Route [feature.acme.test] again'));
            expect($route->refresh()->targets->sole()->instance_id)->toBe($added->id);
            Event::assertDispatched(
                RecordBroadcast::class,
                fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated && $event->id === $route->id,
            );

            $fail = false;
            $ssh->commands = [];
            app(SetRouteTargetAction::class)->execute($route->refresh(), $added->id);

            expect(route_access_walked_checkouts($ssh))->toBe(['/home/orbit/apps/acme/added']);
        } finally {
            new Filesystem()->deleteDirectory($home);
        }
    });

    it('hands a generated Route target to the replacement Route when the domain changes', function (): void {
        [$instance, $explicit, $node] = orb127_route_projection_models(coLocated: true, phpVersion: '8.5');
        $explicit->cluster->update(['tld' => 'acme.test']);
        $explicit->targets()->delete();
        $explicit->delete();
        $route = Route::query()->create([
            'project_id' => $instance->project_id, 'cluster_id' => $explicit->cluster_id, 'generation_basis_node_id' => $node->id,
            'domain' => "feature.{$instance->project->slug}.acme.test", 'provenance' => RouteProvenance::Generated,
            'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $added = route_access_served_instance($instance, $route, $node, 'added', '/home/orbit/apps/acme/added', routed: false);
        [, $ssh, , $home] = orb127_route_projector();
        Event::fake([RecordBroadcast::class]);

        try {
            $replacement = app(SetRouteTargetAction::class)->execute($route, $added->id);

            expect($replacement->id)->not->toBe($route->id)
                ->and($replacement->domain)->toBe("web.added.{$instance->project->slug}.acme.test")
                ->and($replacement->targets->sole()->instance_id)->toBe($added->id)
                ->and(Route::query()->find($route->id))->toBeNull()
                ->and($ssh->commands)->toBe([]);
            Event::assertDispatched(
                RecordBroadcast::class,
                fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated && $event->id === $replacement->id,
            );
        } finally {
            new Filesystem()->deleteDirectory($home);
        }
    });
});

it('uses WireGuard only when the workload has no configured LAN address', function (): void {
    [$instance, $route] = orb127_route_projection_models();
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        $projector->converge($instance, $route);

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
    [$instance, $route] = orb127_route_projection_models(workloadLan: '10.10.0.10');
    [$projector, $ssh, $processes, $home] = orb127_route_projector();

    try {
        expect(fn () => $projector->converge($instance, $route))
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
    [$instance, $route] = orb127_route_projection_models();
    [$projector, $ssh, $processes, $home] = orb127_route_projector(
        static fn (RemoteCommand $command): bool => in_array('s_client', $command->arguments, true),
    );

    try {
        expect(fn () => $projector->converge($instance, $route))
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
                        'orbit-caddy-build-result',
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
    [$instance, $route] = orb127_route_projection_models(
        workloadLan: $boundary === 'firewall' ? '10.10.0.10' : null,
        routerLan: $boundary === 'firewall' ? '10.10.0.20' : null,
        phpVersion: $boundary === 'runtime' ? '8.5' : null,
    );
    $failure = match ($boundary) {
        'source-access' => static fn (RemoteCommand $command): bool => (
            ($command->arguments[3] ?? null) === $instance->checkout_path
        ),
        'runtime' => static fn (RemoteCommand $command): bool => str_contains(
            $command->input ?? '',
            'for path in "$php_root"/*/fpm/pool.d/orbit-scopes.conf',
        ),
        'certificate' => static fn (RemoteCommand $command): bool => (
            ($command->arguments[3] ?? null) === "app-instance-{$instance->id}-app-web"
        ),
        'firewall' => static fn (RemoteCommand $command): bool => ($command->arguments[1] ?? null) === 'ufw',
        'dns' => null,
    };
    [$projector, $ssh, $processes, $home] = orb127_route_projector($failure, failDns: $boundary === 'dns');

    try {
        expect(fn () => $projector->converge($instance, $route))
            ->toThrow(function (RuntimeConvergenceException $exception) use ($expectedStep, $expectedCode): void {
                expect($exception->step)
                    ->toBe($expectedStep)
                    ->and($exception->errorCode)
                    ->toBe($expectedCode);
            });
        expect($processes->invocations)->toHaveCount($boundary === 'dns' ? 1 : 0);
        if ($boundary === 'source-access') {
            // The certificate request and its publication come first.
            expect($ssh->commands)->toHaveCount(3);
        }
        // The Route is published only once its certificate exists, so a failed certificate step
        // leaves every other build on the Node without its site.
        expect($route->refresh()->sites_published)->toBe($boundary !== 'certificate');
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
})->with([
    'source-access' => ['source-access', 'source-access', 'app-dev.source_access_failed'],
    'runtime' => ['runtime', 'php-fpm-discover', 'app-dev.php_fpm_discovery_failed'],
    'certificate' => ['certificate', 'certificate-request', 'app-dev.certificate_request_failed'],
    'firewall' => ['firewall', 'route-firewall', 'app-dev.route_firewall_failed'],
    'publication' => ['dns', 'private-dns', 'app-dev.dns_config_failed'],
]);

/** @return array{Instance, Route, Node, Node} */
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
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-'.Str::lower(Str::random(8)),
        'repository_url' => 'https://example.test/acme.git',
        'apps' => fixture_apps('public'),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $workload->id,
        'name' => 'feature',
        'checkout_path' => '/home/orbit/apps/acme/feature',
        'app_overrides' => fixture_app_overrides('public'),
        'branch' => 'feature',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => $phpVersion,
        'status' => 'source_resolved',
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'domain' => 'feature.acme.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route
        ->targets()
        ->create([
            'instance_id' => $instance->id,
            'position' => 0,
        ]);

    return [$instance, $route, $workload, $router];
}

/**
 * Replays the recorded SSH commands against a per-Node certificate store, the way `caddy validate`
 * sees the Node. Every published Caddy site must name a certificate scope that is on disk and was
 * issued for that site's domain.
 *
 * @param  array<string, array<string, string>>  $disk  scope => domain per Node address
 * @return array{list<string>, array<string, array<string, string>>, array<string, array<string, string>>}
 */
function orb_hostname_change_certificate_replay(Orb127RouteSshExecutor $ssh, array $disk): array
{
    $mismatches = [];
    $served = [];

    foreach ($ssh->commands as $index => $command) {
        $host = $ssh->hosts[$index];
        $arguments = $command->arguments;

        if (($arguments[1] ?? null) === '-ceu' && str_contains($arguments[2] ?? '', 'orbit-certificates/$scope')) {
            $disk[$host][$arguments[4]] = $arguments[5];

            continue;
        }

        if (str_contains($command->input ?? '', 'sudo rm -rf -- "/etc/caddy/orbit-certificates/$scope"')) {
            unset($disk[$host][$arguments[3]]);

            continue;
        }

        if (
            ! str_contains($command->input ?? '', 'orbit-caddy-build-result')
            || preg_match("/printf '%s' '([A-Za-z0-9+\\/=]*)' \\| base64 --decode/", $command->input ?? '', $encoded) !== 1
        ) {
            continue;
        }

        preg_match_all(
            '#https://(\S+) \{\s+bind [^\n]+\s+tls /etc/caddy/orbit-certificates/([^/]+)/current/cert\.pem#',
            (string) base64_decode($encoded[1], true),
            $sites,
            PREG_SET_ORDER,
        );
        $served[$host] = [];

        foreach ($sites as [, $domain, $scope]) {
            $served[$host][$domain] = $scope;
            $issuedFor = $disk[$host][$scope] ?? 'missing';

            if ($issuedFor !== $domain) {
                $mismatches[] = "{$host}: {$domain} uses {$scope} ({$issuedFor})";
            }
        }
    }

    return [$mismatches, $disk, $served];
}

/** @return array{Cluster, Node} */
function orb_second_cluster_router(): array
{
    $cluster = Cluster::query()->create(['name' => 'moved-'.Str::lower(Str::random(8)), 'state' => ClusterState::Active]);
    $router = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-'.Str::lower(Str::random(8)),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
        'user' => 'orbit',
    ]);
    $router->roles()->create(['cluster_id' => $cluster->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active]);

    return [$cluster, $router];
}

function orb_router_candidate(Node $router): Node
{
    return Node::query()->create([
        'cluster_id' => $router->cluster_id,
        'name' => 'candidate-'.Str::lower(Str::random(8)),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
        'user' => 'orbit',
    ]);
}

/** Runs the real Router replacement projector bound by `orb127_route_projector()`, with no role baseline work. */
function orb_router_replacement_action(): SetClusterRouterAction
{
    return new SetClusterRouterAction(
        new class implements RoleBaselineConverger
        {
            public function converge(Node $node, NodeRole $assignment): void {}

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        },
        app(ClusterRouterOperationLock::class),
        new FakeClusterRouterDnsSelectionReconciler,
        app(ClusterRouterReplacementProjector::class),
    );
}

/**
 * Cleanup issues the live certificates, stores the `cleanup` step, and then builds and removes the
 * staging certificates, as `ConvergeRouteAction` does.
 *
 * @param  list<Instance>  $targets
 */
function orb_domain_change_cleanup(NativeDevelopmentRouteProjector $projector, array $targets, Route $replacement): void
{
    foreach ($targets as $target) {
        $projector->prepareCleanup($target, $replacement->refresh());
    }

    $replacement->update(['replacement_step' => RouteReplacementStep::Cleanup]);

    foreach ($targets as $target) {
        $projector->cleanup($target, $replacement->refresh());
    }
}

function orb_pending_domain_change(Route $route, Instance $instance, RouteReplacementStep $step): Route
{
    $replacement = Route::query()->create([
        'project_id' => $route->project_id,
        'cluster_id' => $route->cluster_id,
        'domain' => 'next.acme.test',
        'provenance' => $route->provenance,
        'publication' => $route->publication,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $route->id,
        'replacement_step' => $step,
    ]);
    $replacement->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['replaced_by_route_id' => $replacement->id]);

    return $replacement;
}

/** @return array{NativeDevelopmentRouteProjector, Orb127RouteSshExecutor, Orb127RouteProcessRunner, string} */
function orb127_route_projector(?Closure $failSsh = null, bool $failDns = false): array
{
    $ssh = new Orb127RouteSshExecutor($failSsh);
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
    $processes = new Orb127RouteProcessRunner($failDns);
    $home = sys_get_temp_dir().'/orbit-route-projector-'.Str::uuid();
    config()->set('orbit.home', $home);
    $certificates = new RemoteAppDevCertificateManager($executor, $signer, $accounts);
    $caddy = new RemoteAppDevCaddyManager(SshNodeCaddyBuilds::over($ssh), $executor);
    $dns = new DnsmasqPrivateDnsManager($processes, new DevelopmentDnsConfigRenderer($sites));
    $sourceAccess = new NativeDevelopmentSourceAccess($executor);
    $projector = new NativeDevelopmentRouteProjector(
        new RemoteAppDevPhpFpmManager(
            $sites,
            new DevelopmentPhpFpmConfigRenderer,
            $executor,
            $accounts,
            new RemotePhpPackageManager,
        ),
        $certificates,
        $caddy,
        $dns,
        $executor,
        $sourceAccess,
    );
    app()->instance(DevelopmentSourceAccess::class, $sourceAccess);
    app()->instance(
        ClusterRouterReplacementProjector::class,
        new NativeClusterRouterReplacementProjector(
            $certificates,
            $caddy,
            new RemoteAppDevRouteFirewallManager($executor),
            $dns,
            $executor,
        ),
    );

    return [$projector, $ssh, $processes, $home];
}

/**
 * An active development Instance on the Route's Node, served by its own active Route unless `$routed` is false.
 */
function route_access_served_instance(
    Instance $instance,
    Route $route,
    Node $node,
    string $name,
    string $checkout,
    bool $releases = false,
    bool $routed = true,
): Instance {
    $other = Instance::query()->create([
        'project_id' => $instance->project_id, 'node_id' => $node->id, 'name' => $name,
        'checkout_path' => $checkout, 'development_release_layout' => $releases, 'app_overrides' => fixture_app_overrides('public'),
        'branch' => $name, 'starting_commit' => str_repeat('b', 40), 'selected_php_version' => '8.5',
        'status' => InstanceState::Active,
    ]);

    if (! $routed) {
        return $other;
    }

    $otherRoute = Route::query()->create([
        'project_id' => $instance->project_id, 'cluster_id' => $route->cluster_id, 'domain' => "{$name}.acme.test",
        'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $otherRoute->targets()->create(['instance_id' => $other->id, 'position' => 0]);
    $otherRoute->publishSites();
    $otherRoute->update(['status' => RouteStatus::Active]);

    return $other;
}

/** @return list<mixed> The checkouts of the one source-access walk the SSH executor recorded. */
function route_access_walked_checkouts(Orb127RouteSshExecutor $ssh): array
{
    $access = collect($ssh->commands)->sole(static fn (RemoteCommand $command): bool => str_contains($command->input ?? '', 'u:caddy:r-X'));

    return collect(array_slice($access->arguments, 3))->chunk(3)->map(static fn ($site): mixed => $site->first())->values()->all();
}

function orb368_projection_transfer(
    Instance $instance,
    Route $sourceRoute,
    Route $destinationRoute,
    Node $source,
    Node $destination,
    Node $sourceRouter,
): InstanceTransfer {
    $transfer = InstanceTransfer::query()->create([
        'instance_id' => $instance->id,
        'source_node_id' => $source->id,
        'source_router_node_id' => $sourceRouter->id,
        'destination_node_id' => $destination->id,
        'destination_name' => $instance->name,
        'destination_path' => '/srv/orbit/apps/acme/feature',
        'destination_domain' => $destinationRoute->domain,
        'source_layout' => 'checkout',
        'source_path' => $instance->checkout_path,
        'source_route_id' => $sourceRoute->id,
        'destination_route_id' => $destinationRoute->id,
        'app_journal' => ['web' => [
            'source_route_id' => $sourceRoute->id, 'destination_route_id' => $destinationRoute->id,
            'destination_domain' => $destinationRoute->domain, 'source_router_node_id' => $sourceRouter->id,
            'source_app_identity' => $instance->usesAppRuntimeIdentity('web'),
            'imported_environment_keys' => [],
        ]],
        'status' => InstanceTransferStatus::InProgress,
        'current_step' => InstanceTransferStep::DestinationActivated,
        'cutover_at' => now(),
    ]);
    $instance->update([
        'node_id' => $destination->id,
        'checkout_path' => $transfer->destination_path,
        'status' => InstanceState::Active,
        'provisioning_step' => 'active',
    ]);

    return $transfer;
}

final class Orb127RouteSshExecutor implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<string> */
    public array $hosts = [];

    public function __construct(
        private readonly ?Closure $failure = null,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $this->hosts[] = $connection->host;

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

    public int $failNext = 0;

    public function __construct(
        private readonly bool $fail,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        if ($this->failNext > 0) {
            $this->failNext--;

            return new CommandResult(1, '', 'injected failure', 1, false);
        }

        return $this->fail
            ? new CommandResult(1, '', 'injected failure', 1, false)
            : new CommandResult(0, '', '', 1, false);
    }
}

it('converges the Project sandbox leaf and private preview through native projection', function (): void {
    $workspace = IncusRuntimeWorkspace::create();
    $workspace->project->update(['type' => 'laravel-app', 'apps' => fixture_apps('public', 'laravel-app')]);
    $workspace->update(['task_workspace_routed' => true, 'status' => InstanceState::SourceResolved]);
    $route = app(CreateRouteAction::class)->ensureForInstance($workspace, null);
    [$projector, $ssh, $processes, $home] = orb127_route_projector();
    try {
        $projector->converge($workspace, $route);
        expect($route->fresh()->sites_published)->toBeTrue();
        expect(collect($ssh->commands)->flatMap(fn (RemoteCommand $command): array => $command->arguments))
            ->toContain('app-instance-'.$workspace->id, 'route-'.$route->id.'-router');
        expect($processes->invocations)->not->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});
