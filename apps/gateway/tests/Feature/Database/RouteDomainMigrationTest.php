<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function route_domain_migration(): Migration
{
    return require base_path(
        'database/migrations/2026_09_14_220000_replace_application_hostnames_with_route_domains.php',
    );
}

function route_domain_migration_schema(): array
{
    return collect(DB::select(<<<'SQL'
        SELECT type, name, tbl_name, sql
        FROM sqlite_master
        WHERE tbl_name IN (
            'routes',
            'route_targets',
            'instances',
            'workspaces',
            'instances',
            'instance_environment_values'
        )
        ORDER BY type, name
        SQL))
        ->map(static fn (object $entry): array => (array) $entry)
        ->all();
}

it('preserves populated application endpoints and migrates them to domain', function (): void {
    expect(Schema::hasColumns('routes', ['domain', 'replaces_route_id', 'replaced_by_route_id', 'replacement_step']))
        ->toBeTrue()
        ->and(Schema::hasColumns('routes', [
            'hostname',
            'replaces_route_id',
            'replaced_by_route_id',
            'replacement_step',
            'replacement_step',
        ]))
        ->toBeFalse();

    $project = Project::query()->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'repository_url' => 'https://example.test/shop.git',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => 'shop-node',
        'status' => 'active',
        'public_ssh_host' => 'shop-node.test',
        'wireguard_ip' => '10.44.0.80',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/srv/shop',
        'clone_preview_domain' => 'preview.shop.test',
        'registration_route_domain' => 'shop.test',
        'status' => InstanceState::Active,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'shop.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'generation_basis_node_id' => $node->id,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    expect($route->refresh()->domain)
        ->toBe('shop.test')
        ->and($instance->refresh()->clone_preview_domain)
        ->toBe('preview.shop.test')
        ->and($instance->registration_route_domain)
        ->toBe('shop.test')
        ->and(fn () => $route->update(['domain' => 'other.test']))
        ->toThrow(QueryException::class);
});

it('refuses before schema mutation when a Route hostname change is incomplete', function (): void {
    $migration = route_domain_migration();
    if (! Schema::hasColumn('routes', 'hostname_change_target')) {
        Schema::table('routes', static function ($table): void {
            $table->string('hostname_change_target', 253)->nullable();
        });
    }
    $project = Project::query()->create([
        'name' => 'Refuse',
        'slug' => 'refuse',
        'repository_url' => 'https://example.test/refuse.git',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => 'refuse-node',
        'status' => 'active',
        'public_ssh_host' => 'refuse-node.test',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $routeId = DB::table('routes')->insertGetId([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'refuse.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('routes')->where('id', $routeId)->update([
        'hostname_change_target' => 'next.refuse.test',
    ]);
    $schemaBefore = route_domain_migration_schema();
    $domainBefore = DB::table('routes')->where('id', $routeId)->value('domain');

    expect(fn () => run_legacy_schema_migration($migration, 'up'))
        ->toThrow(
            RuntimeException::class,
            "Cannot migrate application hostnames to domains while a Route hostname change is incomplete: {$routeId}.",
        );
    expect(route_domain_migration_schema())
        ->toBe($schemaBefore)
        ->and(DB::table('routes')->where('id', $routeId)->value('domain'))
        ->toBe($domainBefore)
        ->and(DB::table('routes')->where('id', $routeId)->value('hostname_change_target'))
        ->toBe('next.refuse.test');

    DB::table('routes')->where('id', $routeId)->update(['hostname_change_target' => null]);
});

it('skips leftover Instance and Workspace endpoint columns after schema retirement', function (): void {
    expect(Schema::hasTable('instances'))
        ->toBeTrue()
        ->and(Schema::hasTable('workspaces'))
        ->toBeFalse()
        ->and(class_exists('App\\Models\\AppInstance', false))
        ->toBeFalse()
        ->and(class_exists('App\\Models\\Workspace'))
        ->toBeFalse();

    run_legacy_schema_migration(route_domain_migration(), 'up');

    expect(Schema::hasTable('instances'))
        ->toBeTrue()
        ->and(Schema::hasTable('workspaces'))
        ->toBeFalse()
        ->and(Schema::hasColumn('routes', 'domain'))
        ->toBeTrue()
        ->and(Schema::hasColumn('routes', 'hostname'))
        ->toBeFalse();
});

it('rewrites encrypted environment references to the domain placeholder', function (): void {
    $project = Project::query()->create([
        'name' => 'Env',
        'slug' => 'env',
        'repository_url' => 'https://example.test/env.git',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => 'env-node',
        'status' => 'active',
        'public_ssh_host' => 'env-node.test',
        'wireguard_ip' => '10.44.0.83',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/srv/env',
        'status' => InstanceState::Reserved,
    ]);
    $row = InstanceEnvironmentValue::query()->create([
        'instance_id' => $instance->id,
        'env_key' => 'APP_URL',
        'env_value' => 'https://{{instance.domain}}',
    ]);

    expect($row->refresh()->env_value)->toBe('https://{{instance.domain}}');
});

it('repairs after an injected failure and retries the domain migration forward', function (): void {
    $migration = route_domain_migration();
    $project = Project::query()->create([
        'name' => 'Retry',
        'slug' => 'retry',
        'repository_url' => 'https://example.test/retry.git',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => 'retry-node',
        'status' => 'active',
        'public_ssh_host' => 'retry-node.test',
        'wireguard_ip' => '10.44.0.84',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/srv/retry',
        'status' => InstanceState::Reserved,
    ]);
    DB::table('instance_environment_values')->insert([
        'instance_id' => $instance->id,
        'env_key' => 'APP_URL',
        'env_value' => Crypt::encryptString('https://{{instance.domain}}'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration::$injectFailureAfterSchema = static function (): void {
        throw new RuntimeException('injected domain migration failure');
    };

    expect(fn () => run_legacy_schema_migration($migration, 'up'))
        ->toThrow(RuntimeException::class, 'injected domain migration failure');

    expect(Schema::hasColumn('routes', 'domain'))
        ->toBeTrue()
        ->and(Schema::hasColumn('routes', 'hostname'))
        ->toBeFalse();

    $stored = DB::table('instance_environment_values')
        ->where('instance_id', $instance->id)
        ->where('env_key', 'APP_URL')
        ->value('env_value');
    expect(Crypt::decryptString((string) $stored))->toBe('https://{{instance.domain}}');

    run_legacy_schema_migration($migration, 'up');

    $repaired = DB::table('instance_environment_values')
        ->where('instance_id', $instance->id)
        ->where('env_key', 'APP_URL')
        ->value('env_value');
    expect(Crypt::decryptString((string) $repaired))->toBe('https://{{instance.domain}}');
});

it('exposes no schema rollback for the domain migration', function (): void {
    expect(fn () => run_legacy_schema_migration(route_domain_migration(), 'down'))
        ->toThrow(RuntimeException::class, 'cannot roll back');
});
