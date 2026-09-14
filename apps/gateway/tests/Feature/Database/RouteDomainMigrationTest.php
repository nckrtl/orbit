<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Models\Workspace;
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
            'app_instances',
            'app_instance_environment_values'
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

    $app = OrbitApp::query()->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'repository_url' => 'https://example.test/shop.git',
    ]);
    $node = Node::query()->create([
        'name' => 'shop-node',
        'status' => 'active',
        'public_ssh_host' => 'shop-node.test',
        'wireguard_ip' => '10.44.0.80',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/srv/shop',
        'clone_preview_domain' => 'preview.shop.test',
        'registration_route_domain' => 'shop.test',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'domain' => 'shop.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'generation_basis_node_id' => $node->id,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
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
    $app = OrbitApp::query()->create([
        'name' => 'Refuse',
        'slug' => 'refuse',
        'repository_url' => 'https://example.test/refuse.git',
    ]);
    $node = Node::query()->create([
        'name' => 'refuse-node',
        'status' => 'active',
        'public_ssh_host' => 'refuse-node.test',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $routeId = DB::table('routes')->insertGetId([
        'app_id' => $app->id,
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

    expect(fn () => $migration->up())
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

it('migrates leftover Instance and Workspace endpoints without changing their values', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Legacy',
        'slug' => 'legacy',
        'repository_url' => 'https://example.test/legacy.git',
    ]);
    $node = Node::query()->create([
        'name' => 'legacy-node',
        'status' => 'active',
        'public_ssh_host' => 'legacy-node.test',
        'wireguard_ip' => '10.44.0.82',
        'user' => 'orbit',
    ]);
    $leftover = Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/srv/legacy',
        'domain' => 'legacy.app-dev.orbit',
        'certificate_mode' => 'orbit-ca',
        'status' => 'active',
    ]);
    $workspace = Workspace::query()->create([
        'instance_id' => $leftover->id,
        'name' => 'feature',
        'branch' => 'feature',
        'checkout_path' => '/srv/legacy-feature',
        'domain' => 'feature.legacy.app-dev.orbit',
        'status' => 'active',
    ]);

    expect($leftover->refresh()->domain)
        ->toBe('legacy.app-dev.orbit')
        ->and($leftover->node_id)
        ->toBe($node->id)
        ->and($workspace->refresh()->domain)
        ->toBe('feature.legacy.app-dev.orbit')
        ->and(Schema::hasColumn('instances', 'hostname'))
        ->toBeFalse()
        ->and(Schema::hasColumn('workspaces', 'hostname'))
        ->toBeFalse();
});

it('rewrites encrypted environment references to the domain placeholder', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Env',
        'slug' => 'env',
        'repository_url' => 'https://example.test/env.git',
    ]);
    $node = Node::query()->create([
        'name' => 'env-node',
        'status' => 'active',
        'public_ssh_host' => 'env-node.test',
        'wireguard_ip' => '10.44.0.83',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/srv/env',
        'status' => AppInstanceState::Reserved,
    ]);
    $row = AppInstanceEnvironmentValue::query()->create([
        'app_instance_id' => $instance->id,
        'env_key' => 'APP_URL',
        'env_value' => 'https://{{app_instance.domain}}',
    ]);

    expect($row->refresh()->env_value)->toBe('https://{{app_instance.domain}}');
});

it('repairs after an injected failure and retries the domain migration forward', function (): void {
    $migration = route_domain_migration();
    $app = OrbitApp::query()->create([
        'name' => 'Retry',
        'slug' => 'retry',
        'repository_url' => 'https://example.test/retry.git',
    ]);
    $node = Node::query()->create([
        'name' => 'retry-node',
        'status' => 'active',
        'public_ssh_host' => 'retry-node.test',
        'wireguard_ip' => '10.44.0.84',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/srv/retry',
        'status' => AppInstanceState::Reserved,
    ]);
    DB::table('app_instance_environment_values')->insert([
        'app_instance_id' => $instance->id,
        'env_key' => 'APP_URL',
        'env_value' => Crypt::encryptString('https://{{app_instance.domain}}'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration::$injectFailureAfterSchema = static function (): void {
        throw new RuntimeException('injected domain migration failure');
    };

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'injected domain migration failure');

    expect(Schema::hasColumn('routes', 'domain'))
        ->toBeTrue()
        ->and(Schema::hasColumn('routes', 'hostname'))
        ->toBeFalse();

    $stored = DB::table('app_instance_environment_values')
        ->where('app_instance_id', $instance->id)
        ->where('env_key', 'APP_URL')
        ->value('env_value');
    expect(Crypt::decryptString((string) $stored))->toBe('https://{{app_instance.domain}}');

    $migration->up();

    $repaired = DB::table('app_instance_environment_values')
        ->where('app_instance_id', $instance->id)
        ->where('env_key', 'APP_URL')
        ->value('env_value');
    expect(Crypt::decryptString((string) $repaired))->toBe('https://{{app_instance.domain}}');
});

it('exposes no schema rollback for the domain migration', function (): void {
    expect(fn () => route_domain_migration()->down())
        ->toThrow(RuntimeException::class, 'cannot roll back');
});
