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
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
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
        'branch' => $instance->branch,
        'selected_php_version' => $instance->selected_php_version,
        'source_is_laravel' => $instance->source_is_laravel,
        'failed_step' => $instance->failed_step,
        'error_code' => $instance->error_code,
        'relocation_state' => $instance->registration_relocation_state,
        'authoritative_path' => $instance->registration_authoritative_path,
        'route_id' => $route->id,
        'hostname' => $route->hostname,
        'route_status' => $route->status->value,
        'route_provenance' => $route->provenance->value,
        'route_publication' => $route->publication->value,
        'route_target_instance_id' => $route->targets()->sole()->app_instance_id,
    ], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

if ($command === 'registration-by-original') {
    $path = $argv[2] ?? '';
    $instance = AppInstance::query()
        ->with('routes')
        ->where('registration_original_path', $path)
        ->where('registration_primary', true)
        ->sole();
    $route = $instance->routes->first();
    echo json_encode([
        'id' => $instance->id,
        'request_id' => $instance->registration_request_id,
        'route_id' => $route?->id,
        'path' => $instance->checkout_path,
        'relocation_state' => $instance->registration_relocation_state,
        'authoritative_path' => $instance->registration_authoritative_path,
        'source_digest' => $instance->registration_source_digest,
        'commit' => $instance->starting_commit,
        'branch' => $instance->branch,
        'detached' => $instance->registration_detached,
    ], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

if ($command === 'set-relocation-checkpoint') {
    $id = (int) ($argv[2] ?? 0);
    $state = $argv[3] ?? '';
    $authoritativePath = $argv[4] ?? '';
    $instance = AppInstance::query()->findOrFail($id);

    if (
        $instance->registration_request_id === null
        || $state !== 'relocating'
        || ! str_starts_with($authoritativePath, '/dev/shm/orb105-')
        || $authoritativePath !== $instance->registration_original_path
    ) {
        fwrite(STDERR, "Invalid ORB-105 relocation checkpoint.\n");
        exit(64);
    }

    $instance->update([
        'registration_relocation_state' => $state,
        'registration_authoritative_path' => $authoritativePath,
    ]);
    echo "ok\n";
    exit(0);
}

fwrite(STDERR, "Unknown ORB-105 fixture command.\n");
exit(64);
