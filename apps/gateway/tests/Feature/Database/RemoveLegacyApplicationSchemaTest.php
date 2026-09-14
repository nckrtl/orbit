<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Controllers\Api\AppInstancesController;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route as RouteFacade;

function remove_legacy_application_schema_migration(): object
{
    return require base_path(
        'database/migrations/2026_09_14_230000_drop_legacy_instances_and_workspaces_tables.php',
    );
}

function restore_legacy_application_tables(): void
{
    Schema::create('instances', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('app_id')->constrained()->restrictOnDelete();
        $table->foreignId('node_id')->constrained()->restrictOnDelete();
        $table->string('name');
        $table->string('environment');
        $table->text('checkout_path');
        $table->string('document_root')->default('public');
        $table->string('php_version')->default('8.5');
        $table->string('hostname')->unique();
        $table->string('certificate_mode');
        $table->string('status')->default('provisioning')->index();
        $table->string('failed_step')->nullable();
        $table->string('error_code')->nullable();
        $table->timestamps();
        $table->unique(['app_id', 'name']);
    });

    Schema::create('workspaces', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('instance_id')->constrained()->restrictOnDelete();
        $table->string('name');
        $table->string('branch');
        $table->text('checkout_path');
        $table->string('checkout_path_origin')->nullable();
        $table->string('php_version')->nullable();
        $table->string('hostname')->unique();
        $table->string('status')->default('provisioning')->index();
        $table->string('failed_step')->nullable();
        $table->string('error_code')->nullable();
        $table->timestamps();
        $table->unique(['instance_id', 'name']);
    });
}

/** @return array{OrbitApp, Node, AppInstance, Route, Process, array<string, mixed>} */
function operator_prepared_supported_graph(): array
{
    $node = Node::query()->create([
        'name' => 'app-dev',
        'tld' => 'app-dev.orbit',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.10',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'git@github.com:acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $appInstance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/acme/default',
        'source_layout' => 'worktree',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'acme.app-dev.orbit',
        'status' => RouteStatus::Pending,
        'publication' => RoutePublication::Private,
        'provenance' => RouteProvenance::Generated,
    ]);
    $route->targets()->create([
        'app_instance_id' => $appInstance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);
    $route = $route->refresh();
    $process = Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $appInstance->id,
        'name' => 'queue',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => $appInstance->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'never',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);

    return [$app, $node, $appInstance, $route, $process, supported_legacy_schema_snapshot($app, $node, $appInstance, $route, $process)];
}

/** @return array<string, mixed> */
function supported_legacy_schema_snapshot(
    OrbitApp $app,
    Node $node,
    AppInstance $appInstance,
    Route $route,
    Process $process,
): array {
    return [
        'app' => $app->fresh()->only(['id', 'name', 'slug', 'repository_url', 'default_branch', 'root']),
        'node' => $node->fresh()->only(['id', 'name', 'tld', 'status', 'user', 'wireguard_ip']),
        'app_instance' => $appInstance->fresh()->only([
            'id',
            'app_id',
            'node_id',
            'name',
            'checkout_path',
            'source_layout',
            'status',
        ]),
        'route' => $route->fresh()->only(['id', 'app_id', 'node_id', 'hostname', 'status']),
        'route_targets' => $route->targets()->orderBy('position')->pluck('app_instance_id')->all(),
        'process' => $process->fresh()->only(['id', 'owner_type', 'owner_id', 'name', 'runtime', 'status']),
    ];
}

it('leaves a fresh Gateway schema without leftover Instance or Workspace tables or columns', function (): void {
    expect(Schema::hasTable('instances'))
        ->toBeFalse()
        ->and(Schema::hasTable('workspaces'))
        ->toBeFalse()
        ->and(Schema::hasTable('apps'))
        ->toBeTrue()
        ->and(Schema::hasTable('app_instances'))
        ->toBeTrue()
        ->and(Schema::hasTable('routes'))
        ->toBeTrue()
        ->and(Schema::hasTable('processes'))
        ->toBeTrue();

    foreach (['apps', 'app_instances', 'nodes', 'routes', 'route_targets', 'processes'] as $table) {
        expect(Schema::hasColumn($table, 'instance_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn($table, 'workspace_id'))
            ->toBeFalse()
            ->and(Schema::hasColumn($table, 'certificate_mode'))
            ->toBeFalse()
            ->and(Schema::hasColumn($table, 'checkout_path_origin'))
            ->toBeFalse();
    }
});

it('drops leftover tables on an ordinary update and keeps supported records unchanged', function (): void {
    [$app, $node, $appInstance, $route, $process, $before] = operator_prepared_supported_graph();

    restore_legacy_application_tables();
    $legacyInstanceId = DB::table('instances')->insertGetId([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'legacy',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/legacy/acme',
        'hostname' => 'legacy.app-dev.orbit',
        'certificate_mode' => 'orbit-ca',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('workspaces')->insert([
        'instance_id' => $legacyInstanceId,
        'name' => 'feature',
        'branch' => 'feature',
        'checkout_path' => '/srv/orbit/legacy/acme/feature',
        'checkout_path_origin' => 'derived',
        'hostname' => 'feature.legacy.app-dev.orbit',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Schema::hasTable('instances'))
        ->toBeTrue()
        ->and(Schema::hasTable('workspaces'))
        ->toBeTrue()
        ->and(Schema::hasColumn('workspaces', 'checkout_path_origin'))
        ->toBeTrue();

    remove_legacy_application_schema_migration()->up();

    expect(Schema::hasTable('instances'))
        ->toBeFalse()
        ->and(Schema::hasTable('workspaces'))
        ->toBeFalse()
        ->and(supported_legacy_schema_snapshot($app, $node, $appInstance, $route, $process))
        ->toBe($before);
});

it('supplies no conversion command, script, API, SDK operation, or completion gate', function (): void {
    $named = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(static fn (IlluminateRoute $route): bool => is_string($route->getName()))
        ->mapWithKeys(static fn (IlluminateRoute $route): array => [
            $route->getName() => [$route->methods()[0], $route->uri(), $route->getControllerClass()],
        ])
        ->all();

    expect($named)
        ->not
        ->toHaveKeys([
            'instance:convert',
            'workspace:convert',
            'instance:migrate',
            'workspace:migrate',
        ])
        ->and($named['instance:list'] ?? null)
        ->toBe(['GET', 'api/v1/instances', AppInstancesController::class]);

    foreach ($named as [$method, $uri]) {
        expect($uri)
            ->not
            ->toContain('convert')
            ->and($uri)
            ->not
            ->toContain('migrate');
        expect($method)->toBeString();
    }

    expect(class_exists('App\\Actions\\Instances\\ConvertInstanceAction'))
        ->toBeFalse()
        ->and(class_exists('App\\Actions\\Workspaces\\ConvertWorkspaceAction'))
        ->toBeFalse()
        ->and(class_exists('App\\Console\\Commands\\ConvertLegacyApplicationsCommand'))
        ->toBeFalse();
});
