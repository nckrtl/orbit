<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\Schema;

it('lists Instance Route sites without leftover Instance or Workspace tables', function (): void {
    [$node, $project] = leftover_runtime_models();
    $instance = leftover_runtime_app_instance($node, $project);
    leftover_runtime_route($instance, 'shop.app-dev.orbit');

    $sites = new DevelopmentSiteRepository()->forNode($node);

    expect($sites->pluck('scope')->all())
        ->toBe(["app-instance-{$instance->id}-app-web"])
        ->and(Schema::hasTable('instances'))
        ->toBeTrue()
        ->and(Schema::hasTable('workspaces'))
        ->toBeFalse();
});

it('treats leftover Instance and Workspace checkouts as unmanaged for overlap and production inventory', function (): void {
    [$node] = leftover_runtime_models();

    new ManagedCheckoutOverlap()->assertAvailable(
        $node->id,
        StoragePath::parse('/srv/legacy/acme'),
        'instance.path_taken',
    );
    new ManagedCheckoutOverlap()->assertAvailable(
        $node->id,
        StoragePath::parse('/srv/legacy/acme/feature'),
        'instance.path_taken',
    );

    expect(Schema::hasTable('instances'))
        ->toBeTrue()
        ->and(Schema::hasTable('workspaces'))
        ->toBeFalse();
});

it('retires Orbit-owned public app-prod 80/443 rules and ignores leftover runtime dependents', function (): void {
    [$node] = leftover_runtime_models();
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

it('keeps Instance-owned Processes independent of leftover Instance owners', function (): void {
    [$node, $project] = leftover_runtime_models();
    $instance = leftover_runtime_app_instance($node, $project);
    $process = Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'never',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);

    expect($process->refresh()->owner_type)
        ->toBe(Instance::MorphAlias)
        ->and(app(NodeRoleDependencyInspector::class)->inspect($node, RoleName::AppDev)->processIds)
        ->toBeEmpty();
});

/**
 * @return array{Node, Project}
 */
function leftover_runtime_models(): array
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
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'git@example.test:acme.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);

    return [$node, $project];
}

function leftover_runtime_app_instance(Node $node, Project $project): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/acme/default',
        'selected_php_version' => '8.5',
        'app_overrides' => fixture_app_overrides('public'),
        'status' => InstanceState::Active,
    ]);
}

function leftover_runtime_route(Instance $instance, string $domain): Route
{
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}
