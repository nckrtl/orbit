<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Data\AppInstances\CreateAppInstanceData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\NativeProductionAppInstanceProvisioner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

beforeEach(function (): void {
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Production app',
        'slug' => 'production-app',
        'repository_url' => 'https://example.test/production.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = Node::query()->create([
        'name' => 'production',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'production.test',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
        'user' => 'orbit',
    ]);
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $this->provisioner = new NativeProductionAppInstanceProvisioner(app(CreateRouteAction::class));
    $this->data = new CreateAppInstanceData(
        appId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'live',
        root: null,
        domain: null,
        branch: null,
    );
});

it('refuses new production placement before user home source environment or Route mutation', function (): void {
    expect(fn () => $this->provisioner->execute($this->data, $this->orbitApp, $this->node, null))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('instance.candidate_required')
                ->and($exception->status)
                ->toBe(409)
                ->and($exception->getMessage())
                ->toBe('New production AppInstances require a candidate. Use instance:clone.');
        });

    expect(AppInstance::query()->exists())
        ->toBeFalse()
        ->and(Route::query()->exists())
        ->toBeFalse();
});

it('refuses a repeat for an existing production Instance before mutation', function (): void {
    $instance = provision_production_active_instance($this->orbitApp, $this->node, 'live');
    $before = $instance->getAttributes();
    $routeBefore = Route::query()->sole()->getAttributes();

    expect(fn () => $this->provisioner->execute($this->data, $this->orbitApp, $this->node, null))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.candidate_required');
        });

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and(Route::query()->sole()->getAttributes())
        ->toBe($routeBefore);
});

it('refuses an incomplete production record without resuming retired creation', function (): void {
    $instance = AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'live',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$this->orbitApp->id}/releases/initial",
        'production_user' => "orbit-app-{$this->orbitApp->id}",
        'production_home' => "/home/orbit-app-{$this->orbitApp->id}",
        'status' => AppInstanceState::Reserved,
    ]);
    $before = $instance->refresh()->getAttributes();

    expect(fn () => $this->provisioner->execute($this->data, $this->orbitApp, $this->node, null))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.candidate_required');
        });

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and(Route::query()->exists())
        ->toBeFalse();
});

it('refuses production creation outside the recorded home release boundary', function (string $path): void {
    $instance = provision_production_active_instance($this->orbitApp, $this->node, 'live');
    $instance->update(['checkout_path' => $instance->production_home.$path]);
    $before = $instance->refresh()->getAttributes();
    expect(fn () => $this->provisioner->execute($this->data, $this->orbitApp, $this->node, null))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('instance.candidate_required');
        });
    expect($instance->refresh()->getAttributes())->toBe($before);
})->with(['/releases/../foreign', '/releases/two/nested', '/releases/.hidden', '/other/release']);

function provision_production_active_instance(OrbitApp $app, Node $node, string $name): AppInstance
{
    $user = "orbit-app-{$app->id}";
    $home = "/home/{$user}";
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'production',
        'checkout_path' => "{$home}/releases/initial",
        'production_user' => $user,
        'production_home' => $home,
        'branch' => $app->default_branch,
        'starting_commit' => str_repeat('c', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => false,
        'production_php_service' => "orbit-{$user}-php8.5-fpm.service",
        'production_php_pool' => "orbit-{$user}",
        'production_php_socket' => "/run/php/{$user}.sock",
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'domain' => "{$name}.{$app->slug}.{$node->tld}",
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'app_instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return $instance->refresh();
}
