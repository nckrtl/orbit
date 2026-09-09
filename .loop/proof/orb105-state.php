<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? '';

if ($command === 'probe') {
    echo OrbitApp::query()->where('slug', 'laravel-typed')->sole()->id."\n";
    exit(0);
}

if ($command === 'seed-migration') {
    $path = $argv[2] ?? '';
    $commit = $argv[3] ?? '';
    $app = OrbitApp::query()->where('slug', 'laravel-typed')->sole();
    $node = Node::query()->where('name', 'app-dev')->sole();
    $cluster = Cluster::query()->where('state', 'active')->sole();
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => '13.x',
        'environment' => 'development',
        'source_layout' => 'checkout',
        'checkout_path' => $path,
        'branch' => '13.x',
        'migration_required' => true,
        'starting_commit' => $commit,
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'orb105-migration.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Active,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    echo json_encode(['id' => $instance->id, 'route_id' => $route->id, 'hostname' => $route->hostname], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

if ($command === 'migration-state') {
    $id = (int) ($argv[2] ?? 0);
    $instance = AppInstance::query()->with('routes')->findOrFail($id);
    $route = $instance->routes->sole();
    echo json_encode([
        'id' => $instance->id,
        'name' => $instance->name,
        'path' => $instance->checkout_path,
        'migration_required' => $instance->migration_required,
        'status' => $instance->status->value,
        'route_id' => $route->id,
        'hostname' => $route->hostname,
    ], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

fwrite(STDERR, "Unknown ORB-105 fixture command.\n");
exit(64);
