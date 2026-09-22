<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeRoleAction;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\NodeRoleValidationException;
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
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Support\Facades\Schema;

it('lists AppInstance Route sites without leftover Instance or Workspace tables', function (): void {
    [$node, $app] = leftover_runtime_models();
    $appInstance = leftover_runtime_app_instance($node, $app);
    leftover_runtime_route($appInstance, 'shop.app-dev.orbit');

    $sites = new AppDevSiteRepository()->forNode($node);

    expect($sites->pluck('scope')->all())
        ->toBe(["app-instance-{$appInstance->id}"])
        ->and(Schema::hasTable('instances'))
        ->toBeFalse()
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

    expect(new AppProdSiteRepository()->forNode($node))
        ->toBeEmpty()
        ->and(new AppProdSiteRepository()->hasLivePublicFootprint($node))
        ->toBeFalse()
        ->and(new AppProdSiteRepository()->requiresPublicFirewall($node))
        ->toBeFalse()
        ->and(Schema::hasTable('instances'))
        ->toBeFalse()
        ->and(Schema::hasTable('workspaces'))
        ->toBeFalse();
});

it('retires Orbit-owned public app-prod 80/443 rules without restoring ingress rules', function (): void {
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
        ->toBeEmpty();
});

it('refuses app-dev role removal without changing its AppInstance or Process', function (): void {
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
    $assignment = $node->roles()->where('role', RoleName::AppDev)->sole();
    $originalAssignment = $assignment->refresh()->getRawOriginal();
    $originalInstance = $appInstance->refresh()->getRawOriginal();
    $originalProcess = $process->refresh()->getRawOriginal();

    expect(fn () => app(RemoveNodeRoleAction::class)->execute($node, RoleName::AppDev, force: true))
        ->toThrow(function (NodeRoleValidationException $exception): void {
            expect($exception->details)->toBe([
                'reason' => 'app_instances_attached',
                'role' => RoleName::AppDev->value,
            ]);
        });

    expect($assignment->refresh()->getRawOriginal())->toBe($originalAssignment);
    expect($appInstance->refresh()->getRawOriginal())->toBe($originalInstance);
    expect($process->refresh()->getRawOriginal())->toBe($originalProcess);
});

/**
 * @return array{Node, OrbitApp}
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
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'git@example.test:acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return [$node, $app];
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
