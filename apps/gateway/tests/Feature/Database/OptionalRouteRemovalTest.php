<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

it('upgrades Route absence admission and preserves nullable branch and source evidence', function (): void {
    $migration = optional_route_removal_migration();
    $migration->down();
    [$instance, $operation, $attributes] = optional_route_removal_fixture(false);

    expect(fn () => $operation->members()->create($attributes))->toThrow(QueryException::class);
    $migration->up();
    $member = $operation->members()->create($attributes);

    expect($member->route_id)->toBeNull();
    expect($member->branch)->toBeNull();
    expect($member->source_commit)->toBe(str_repeat('a', 40));
    $this->assertModelExists($instance);
    $this->assertDatabaseCount('routes', 0);
});

it('preserves existing routed removal evidence across a rollback and upgrade', function (): void {
    [$instance, $operation, $attributes, $route] = optional_route_removal_fixture(true);
    $member = $operation->members()->create($attributes);
    $before = $member->refresh()->getAttributes();
    $migration = optional_route_removal_migration();

    $migration->down();
    $migration->up();

    expect($member->refresh()->getAttributes())->toBe($before);
    $this->assertModelExists($instance);
    $this->assertModelExists($route);
});

it('refuses rollback while accepted absence evidence exists', function (): void {
    [, $operation, $attributes] = optional_route_removal_fixture(false);
    $member = $operation->members()->create($attributes);

    expect(fn () => optional_route_removal_migration()->down())->toThrow(RuntimeException::class);

    expect($member->refresh()->route_id)->toBeNull();
    $this->assertDatabaseCount('app_instance_removal_members', 1);
});

it('rejects forged absent Route evidence for a routed or required owner', function (ProjectType $type): void {
    [$instance, $operation, $attributes] = optional_route_removal_fixture(true);
    $instance->app->update(['type' => $type]);
    $attributes['route_id'] = null;

    expect(fn () => $operation->members()->create($attributes))->toThrow(QueryException::class);

    $this->assertDatabaseCount('app_instance_removal_members', 0);
})->with([ProjectType::LaravelApp, ProjectType::LaravelPackage, ProjectType::Monorepo]);

it('rejects absent Route evidence when the Project requires a Route even if no target exists', function (): void {
    [$instance, $operation, $attributes] = optional_route_removal_fixture(false);
    $instance->app->update(['type' => ProjectType::LaravelApp]);

    expect(fn () => $operation->members()->create($attributes))->toThrow(QueryException::class);

    $this->assertDatabaseCount('app_instance_removal_members', 0);
});

it('keeps absent Route identity immutable and rejects mismatched or premature outcomes', function (array $changes): void {
    [, $operation, $attributes] = optional_route_removal_fixture(false);
    $member = $operation->members()->create($attributes);

    expect(fn () => $member->update($changes))->toThrow(QueryException::class);

    expect($member->refresh()->route_id)->toBeNull();
    expect($member->route_cleared_at)->toBeNull();
})->with([
    'changed identity' => [['route_id' => 987]],
    'deleted without identity' => [['route_cleared_at' => '2026-09-22 12:00:00', 'route_outcome' => 'deleted']],
    'unprepared source' => [['route_cleared_at' => '2026-09-22 12:00:00', 'route_outcome' => 'absent']],
    'unknown result' => [['route_cleared_at' => '2026-09-22 12:00:00', 'route_outcome' => 'unknown']],
]);

it('records an absent outcome only for absent evidence after source preparation', function (bool $routed): void {
    [$instance, $operation, $attributes] = optional_route_removal_fixture($routed);
    $member = $operation->members()->create($attributes);
    $instance->update(['status' => AppInstanceState::Removing]);
    $member->update(['source_prepared_at' => now()]);

    if ($routed) {
        $route = Route::query()->findOrFail($member->route_id);
        $route->targets()->delete();
        $route->delete();
        expect(fn () => $member->update(['route_cleared_at' => now(), 'route_outcome' => 'absent']))->toThrow(QueryException::class);
    } else {
        $member->update(['route_cleared_at' => now(), 'route_outcome' => 'absent']);
        expect($member->refresh()->route_outcome)->toBe('absent');
    }
})->with([false, true]);

it('refuses to report a deleted Route for prepared absent evidence', function (): void {
    [$instance, $operation, $attributes] = optional_route_removal_fixture(false);
    $member = $operation->members()->create($attributes);
    $instance->update(['status' => AppInstanceState::Removing]);
    $member->update(['source_prepared_at' => now()]);

    expect(fn () => $member->update(['route_cleared_at' => now(), 'route_outcome' => 'deleted']))->toThrow(QueryException::class);

    expect($member->refresh()->route_outcome)->toBeNull();
});

it('refuses an absent checkpoint when a target appeared after acceptance', function (): void {
    [$instance, $operation, $attributes] = optional_route_removal_fixture(false);
    $member = $operation->members()->create($attributes);
    $instance->update(['status' => AppInstanceState::Removing]);
    $member->update(['source_prepared_at' => now()]);
    $route = optional_route_removal_route($instance);

    expect(fn () => $member->update(['route_cleared_at' => now(), 'route_outcome' => 'absent']))->toThrow(QueryException::class);

    $this->assertModelExists($route);
    expect($route->targets()->count())->toBe(1);
});

function optional_route_removal_migration(): Migration
{
    return require database_path('migrations/2026_09_22_105133_allow_absent_routes_in_app_instance_removal.php');
}

/** @return array{AppInstance, AppInstanceRemoval, array<string, mixed>, ?Route} */
function optional_route_removal_fixture(bool $routed): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Optional removal', 'slug' => 'optional-removal', 'type' => ProjectType::LaravelPackage,
        'repository_url' => 'https://example.test/optional-removal.git', 'default_branch' => 'main', 'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'optional-removal', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => 'removal.example.test', 'wireguard_ip' => '10.44.72.10',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id, 'node_id' => $node->id, 'name' => 'dev', 'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/optional-removal/dev', 'source_layout' => 'checkout',
        'branch' => null, 'starting_commit' => str_repeat('a', 40), 'status' => AppInstanceState::Active,
    ]);
    $route = $routed ? optional_route_removal_route($instance) : null;
    $operation = AppInstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instance->id, 'requested_name' => $instance->name,
        'force' => false, 'inventory_digest' => str_repeat('d', 64), 'total' => 1,
        'status' => AppInstanceRemovalStatus::Removing, 'current_step' => AppInstanceRemovalStep::SourcePreparation,
    ]);
    $attributes = [
        'position' => 0, 'app_instance_id' => $instance->id, 'app_id' => $app->id, 'node_id' => $node->id,
        'route_id' => $route?->id, 'name' => $instance->name, 'environment' => 'development', 'source_layout' => 'checkout',
        'repository_identity' => $app->repository_identity, 'checkout_path' => $instance->checkout_path,
        'root' => 'public', 'branch' => null, 'starting_commit' => $instance->starting_commit,
        'source_commit' => $instance->starting_commit, 'common_repository_path' => $instance->checkout_path,
        'source_identity' => 'fixture:1', 'linked_worktree_paths' => [$instance->checkout_path], 'source_digest' => str_repeat('e', 64),
    ];

    return [$instance, $operation, $attributes, $route];
}

function optional_route_removal_route(AppInstance $instance): Route
{
    $route = Route::query()->create([
        'app_id' => $instance->app_id, 'node_id' => $instance->node_id,
        'domain' => 'optional-removal.example.test', 'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);

    return $route;
}
