<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;

beforeEach(function (): void {
    $this->operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->operator = $this->markAsGateway($this->operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->fakeRepositoryBranches();
});

it('serves the same Project on /projects and /apps', function (): void {
    $created = $this->postJson('/api/v1/projects', [
        'slug' => 'acme',
        'type' => 'laravel-package',
        'repository_url' => 'https://github.com/acme/support.git',
        'default_branch' => 'main',
        'root' => 'src',
    ])->assertCreated();

    expect($created->json('data.type'))->toBe('laravel-package');

    $this->getJson('/api/v1/projects/'.$created->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.slug', 'acme')
        ->assertJsonPath('data.type', 'laravel-package');

    $this->getJson('/api/v1/apps/'.$created->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.slug', 'acme')
        ->assertJsonPath('data.type', 'laravel-package');
});

it('defaults omitted type to laravel-app on the compatibility surface', function (): void {
    $this->postJson('/api/v1/apps', [
        'slug' => 'shop',
        'repository_url' => 'https://github.com/acme/shop.git',
        'default_branch' => 'main',
        'root' => 'public',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'laravel-app');
});

it('activates a laravel-package Instance without a Route', function (): void {
    $project = OrbitApp::query()->create([
        'name' => 'support',
        'slug' => 'support',
        'type' => ProjectType::LaravelPackage,
        'repository_url' => 'https://github.com/acme/support.git',
        'default_branch' => 'main',
        'root' => 'src',
    ]);
    $node = Node::query()->create([
        'name' => 'dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'wireguard_ip' => '10.44.0.80',
    ]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);

    $instance = AppInstance::query()->create([
        'app_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/var/orbit/apps/support/default',
        'status' => AppInstanceState::SourceResolved,
    ]);

    $instance->update(['status' => AppInstanceState::Active]);

    expect($instance->refresh()->status)
        ->toBe(AppInstanceState::Active)
        ->and($instance->requiresRoute())
        ->toBeFalse()
        ->and($instance->routes()->count())
        ->toBe(0);
});

it('refuses a type change to laravel-app while an active Instance has no Route', function (): void {
    $project = OrbitApp::query()->create([
        'name' => 'support',
        'slug' => 'support',
        'type' => ProjectType::LaravelPackage,
        'repository_url' => 'https://github.com/acme/support.git',
        'default_branch' => 'main',
        'root' => 'src',
    ]);
    $node = Node::query()->create([
        'name' => 'dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/var/orbit/apps/support/work',
        'status' => AppInstanceState::Active,
    ]);

    $this->patchJson('/api/v1/projects/'.$project->id, [
        'type' => 'laravel-app',
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project.type_requires_route');

    expect($project->refresh()->type)->toBe(ProjectType::LaravelPackage)
        ->and($instance->refresh()->status)->toBe(AppInstanceState::Active);
});

it('normalizes APP_ENV and APP_DEBUG on existing app-prod Instances', function (): void {
    $project = OrbitApp::query()->create([
        'name' => 'shop',
        'slug' => 'shop',
        'repository_url' => 'https://github.com/acme/shop.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'prod',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.82',
        'wireguard_ip' => '10.44.0.82',
    ]);
    $node->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'web',
        'environment' => 'production',
        'checkout_path' => '/home/orbit-app-1/releases/initial',
        'production_user' => 'orbit-app-1',
        'production_home' => '/home/orbit-app-1',
        'status' => AppInstanceState::Active,
    ]);
    $instance->environmentValues()->create([
        'env_key' => 'APP_ENV',
        'env_value' => 'local',
    ]);
    $instance->environmentValues()->create([
        'env_key' => 'APP_KEY',
        'env_value' => 'kept-secret',
    ]);

    $migration = require base_path(
        'database/migrations/2026_09_20_210000_add_project_type_and_normalize_app_prod_mode.php',
    );
    $removalRoutes = require base_path('database/migrations/2026_09_22_105133_allow_absent_routes_in_app_instance_removal.php');
    $removalRoutes->down();
    $migration->down();
    $migration->up();
    $removalRoutes->up();

    expect($project->refresh()->type)->toBe(ProjectType::LaravelApp)
        ->and($instance->environmentValues()->where('env_key', 'APP_ENV')->sole()->env_value)
        ->toBe('production')
        ->and($instance->environmentValues()->where('env_key', 'APP_DEBUG')->sole()->env_value)
        ->toBe('false')
        ->and($instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)
        ->toBe('kept-secret');
});

it('classifies the Orbit repository as a monorepo during upgrade', function (): void {
    $orbit = OrbitApp::query()->create([
        'name' => 'Orbit',
        'slug' => 'orbit',
        'repository_url' => 'https://github.com/nckrtl/orbit.git',
        'default_branch' => 'main',
        'root' => 'apps/gateway/public',
    ]);

    $migration = require base_path(
        'database/migrations/2026_09_20_210000_add_project_type_and_normalize_app_prod_mode.php',
    );
    $removalRoutes = require base_path('database/migrations/2026_09_22_105133_allow_absent_routes_in_app_instance_removal.php');
    $removalRoutes->down();
    $migration->down();
    $migration->up();
    $removalRoutes->up();

    expect($orbit->refresh()->type)->toBe(ProjectType::Monorepo);
});

it('exposes project_id beside app_id on Instance payloads', function (): void {
    $project = OrbitApp::query()->create([
        'name' => 'shop',
        'slug' => 'shop',
        'type' => ProjectType::LaravelApp,
        'repository_url' => 'https://github.com/acme/shop.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.83',
        'wireguard_ip' => '10.44.0.83',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/var/orbit/apps/shop/default',
        'status' => AppInstanceState::Reserved,
    ]);

    $this->getJson('/api/v1/instances/'.$instance->id)
        ->assertOk()
        ->assertJsonPath('data.app_id', $project->id)
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.app.slug', 'shop')
        ->assertJsonPath('data.project.slug', 'shop')
        ->assertJsonPath('data.project.type', 'laravel-app');
});

it('accepts project_id as the Instance owner and refuses a mismatched app_id', function (): void {
    $project = OrbitApp::query()->create([
        'name' => 'shop',
        'slug' => 'shop',
        'type' => ProjectType::LaravelApp,
        'repository_url' => 'https://github.com/acme/shop.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $other = OrbitApp::query()->create([
        'name' => 'other',
        'slug' => 'other',
        'type' => ProjectType::LaravelPackage,
        'repository_url' => 'https://github.com/acme/other.git',
        'default_branch' => 'main',
        'root' => 'src',
    ]);
    $node = Node::query()->create([
        'name' => 'dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.84',
        'wireguard_ip' => '10.44.0.84',
    ]);

    $this->postJson('/api/v1/instances', [
        'node_id' => $node->id,
        'name' => 'preview',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.project_id.0', 'Supply project_id or app_id.');

    $this->postJson('/api/v1/instances', [
        'project_id' => $project->id,
        'app_id' => $other->id,
        'node_id' => $node->id,
        'name' => 'preview',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.project_id.0', 'project_id and app_id must name the same Project.');
});
