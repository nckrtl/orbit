<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\QueryException;

it('permits activation only after one Route association exists', function (): void {
    [$instance, $route] = app_instance_route_constraint_fixture();

    expect(fn () => $instance->update(['status' => AppInstanceState::Active]))
        ->toThrow(QueryException::class);

    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $instance->update(['status' => AppInstanceState::Active]);

    expect($instance->refresh()->status)->toBe(AppInstanceState::Active);
});

it('enforces global AppInstance Route uniqueness at the database boundary', function (): void {
    [$instance, $first] = app_instance_route_constraint_fixture();
    $first->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $second = Route::query()->create([
        'app_id' => $instance->app_id,
        'node_id' => $instance->node_id,
        'hostname' => 'second.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    expect(fn () => $second->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]))
        ->toThrow(QueryException::class);
});

it('does not let association deletion strand an active AppInstance', function (): void {
    [$instance, $route] = app_instance_route_constraint_fixture();
    $target = $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    expect(fn () => $target->delete())->toThrow(QueryException::class);
});

/** @return array{AppInstance, Route} */
function app_instance_route_constraint_fixture(): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Constraint',
        'slug' => 'constraint',
        'repository_url' => 'https://example.test/constraint.git',
        'main_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'constraint-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.50',
        'wireguard_ip' => '10.44.0.50',
        'tld' => 'test',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'checkout_path' => '/srv/orbit/apps/constraint/main',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'hostname' => 'constraint.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    return [$instance, $route];
}
