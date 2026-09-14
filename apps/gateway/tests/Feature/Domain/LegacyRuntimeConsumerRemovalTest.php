<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppProd\AppProdSiteRepository;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;

it('lists AppInstance Route sites without reading leftover Instance or Workspace rows', function (): void {
    [$node, $app, $legacy, $workspace] = leftover_runtime_models();
    $appInstance = leftover_runtime_app_instance($node, $app);
    leftover_runtime_route($appInstance, 'shop.app-dev.orbit');
    $retrieved = ['instance' => [], 'workspace' => []];
    Event::listen('eloquent.retrieved: '.Instance::class, function (Instance $row) use (&$retrieved): void {
        $retrieved['instance'][] = $row->id;
    });
    Event::listen('eloquent.retrieved: '.Workspace::class, function (Workspace $row) use (&$retrieved): void {
        $retrieved['workspace'][] = $row->id;
    });

    $sites = new AppDevSiteRepository()->forNode($node);

    expect($sites->pluck('scope')->all())
        ->toBe(["app-instance-{$appInstance->id}"])
        ->and($retrieved)
        ->toBe(['instance' => [], 'workspace' => []])
        ->and($legacy->refresh()->checkout_path)
        ->toBe('/srv/legacy/acme')
        ->and($workspace->refresh()->checkout_path)
        ->toBe('/srv/legacy/acme/feature');
});

it('treats leftover Instance and Workspace checkouts as unmanaged for overlap and production inventory', function (): void {
    [$node, $app, $legacy, $workspace] = leftover_runtime_models();

    new ManagedCheckoutOverlap()->assertAvailable(
        $node->id,
        StoragePath::parse($legacy->checkout_path),
        'instance.path_taken',
    );
    new ManagedCheckoutOverlap()->assertAvailable(
        $node->id,
        StoragePath::parse($workspace->checkout_path),
        'instance.path_taken',
    );

    expect(new AppProdSiteRepository()->forNode($node))
        ->toBeEmpty()
        ->and(new AppProdSiteRepository()->hasLivePublicFootprint($node))
        ->toBeFalse()
        ->and(new AppProdSiteRepository()->requiresPublicFirewall($node))
        ->toBeFalse()
        ->and($legacy->refresh()->exists)
        ->toBeTrue()
        ->and($workspace->refresh()->exists)
        ->toBeTrue();
});

it('retires Orbit-owned public app-prod 80/443 rules and ignores leftover runtime dependents', function (): void {
    [$node] = leftover_runtime_models(certificateMode: CertificateMode::Acme);
    $node->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $catalog = new NodeFirewallRuleCatalog;

    expect($catalog->forRole($node, RoleName::AppProd))
        ->toBeEmpty()
        ->and(collect($catalog->retiredForRole($node, RoleName::AppProd))->map(fn ($rule) => $rule->shape->comment)->all())
        ->toContain('orbit:app-prod-http', 'orbit:app-prod-https')
        ->and($catalog->forRole($node, RoleName::Ingress))
        ->toBeEmpty()
        ->and(app(NodeRoleDependencyInspector::class)->inspect($node, RoleName::AppProd)->summaries)
        ->toBeEmpty();
});

it('keeps AppInstance-owned Processes independent of leftover Instance owners', function (): void {
    [$node, $app] = leftover_runtime_models();
    $appInstance = leftover_runtime_app_instance($node, $app);
    $process = Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $appInstance->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => $appInstance->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'never',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);

    expect($process->refresh()->owner_type)
        ->toBe(AppInstance::class)
        ->and(app(NodeRoleDependencyInspector::class)->inspect($node, RoleName::AppDev)->processIds)
        ->toBeEmpty();
});

/**
 * @return array{Node, OrbitApp, Instance, Workspace}
 */
function leftover_runtime_models(CertificateMode $certificateMode = CertificateMode::OrbitCa): array
{
    $node = Node::query()->create([
        'name' => 'leftover-runtime',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'app-dev.orbit',
        'public_ssh_host' => '192.0.2.80',
        'wireguard_ip' => '10.44.0.80',
    ]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'git@example.test:acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $legacy = Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'legacy',
        'environment' => $certificateMode === CertificateMode::Acme ? 'production' : 'development',
        'checkout_path' => '/srv/legacy/acme',
        'domain' => 'legacy.app-dev.orbit',
        'certificate_mode' => $certificateMode,
        'status' => LifecycleStatus::Active,
    ]);
    $workspace = Workspace::query()->create([
        'instance_id' => $legacy->id,
        'name' => 'feature',
        'branch' => 'feature',
        'checkout_path' => '/srv/legacy/acme/feature',
        'domain' => 'feature.legacy.app-dev.orbit',
        'status' => LifecycleStatus::Active,
    ]);

    return [$node, $app, $legacy, $workspace];
}

function leftover_runtime_app_instance(Node $node, OrbitApp $app): AppInstance
{
    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/acme/default',
        'selected_php_version' => '8.5',
        'root' => 'public',
        'status' => AppInstanceState::Active,
    ]);
}

function leftover_runtime_route(AppInstance $appInstance, string $domain): Route
{
    $route = Route::query()->create([
        'app_id' => $appInstance->app_id,
        'node_id' => $appInstance->node_id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'app_instance_id' => $appInstance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}
