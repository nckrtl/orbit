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
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
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
        'provisioning_step' => $instance->provisioning_step,
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

if ($command === 'set-migration-rename-interruption') {
    $id = (int) ($argv[2] ?? 0);
    $destination = $argv[3] ?? '';
    $instance = AppInstance::query()->with('routes')->findOrFail($id);
    $route = $instance->routes->sole();
    $recovery = $instance->registration_migration_recovery;
    $fields = [
        'name',
        'source_layout',
        'checkout_path',
        'root',
        'branch',
        'branch_override',
        'migration_required',
        'starting_commit',
        'selected_php_version',
        'source_is_laravel',
        'provisioning_step',
        'status',
    ];
    $recoveryOriginal = is_array($recovery) ? $recovery['app_instance'] ?? null : null;
    $recoveryRoute = is_array($recovery) ? $recovery['route'] ?? null : null;
    $recoveryPlanned = is_array($recovery) ? $recovery['planned'] ?? null : null;
    $retainedRecoveryIsValid = is_array($recoveryOriginal)
        && array_diff($fields, array_keys($recoveryOriginal)) === []
        && ($recoveryOriginal['name'] ?? null) === $instance->name
        && ($recoveryOriginal['checkout_path'] ?? null) === $instance->checkout_path
        && is_array($recoveryRoute)
        && ($recoveryRoute['id'] ?? null) === $route->id
        && ($recoveryRoute['hostname'] ?? null) === $route->hostname
        && ($recoveryRoute['provenance'] ?? null) === $route->provenance->value
        && is_array($recoveryPlanned)
        && ($recoveryPlanned['name'] ?? null) === 'default'
        && ($recoveryPlanned['checkout_path'] ?? null) === $destination;
    $rolledBackBoundary = $recovery === null
        && in_array($instance->registration_relocation_state, ['reserved', 'relocated'], strict: true);
    $retainedBoundary = $instance->registration_relocation_state === 'relocating'
        && $retainedRecoveryIsValid;

    if (
        ! $instance->migration_required
        || $instance->registration_request_id === null
        || ! $instance->registration_primary
        || $instance->registration_authoritative_path !== $instance->registration_original_path
        || $instance->checkout_path !== $instance->registration_original_path
        || $instance->failed_step !== 'registration'
        || $destination !== '/home/orbit/apps/laravel-typed/default'
        || ! $rolledBackBoundary && ! $retainedBoundary
    ) {
        fwrite(STDERR, "Invalid ORB-105 migration interruption boundary.\n");
        exit(64);
    }

    if ($recovery === null) {
        $original = [];

        foreach ($fields as $field) {
            $original[$field] = $instance->getRawOriginal($field);
        }

        $recovery = [
            'app_instance' => $original,
            'route' => [
                'id' => $route->id,
                'hostname' => $route->hostname,
                'provenance' => $route->provenance->value,
            ],
            'planned' => [
                'name' => 'default',
                'checkout_path' => $destination,
            ],
        ];
    }

    $instance->update([
        'registration_migration_recovery' => $recovery,
        'registration_relocation_state' => 'relocating',
        'registration_authoritative_path' => $instance->registration_original_path,
        'registration_source_device' => null,
        'registration_source_inode' => null,
    ]);
    echo "ok\n";
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
        'source_device' => $instance->registration_source_device,
        'source_inode' => $instance->registration_source_inode,
        'source_digest' => $instance->registration_source_digest,
        'commit' => $instance->starting_commit,
        'branch' => $instance->branch,
        'detached' => $instance->registration_detached,
        'status' => $instance->status->value,
        'provisioning_step' => $instance->provisioning_step,
        'completed' => $instance->registration_completed_at !== null,
    ], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

if ($command === 'set-registration-incomplete') {
    $id = (int) ($argv[2] ?? 0);
    $instance = AppInstance::query()->findOrFail($id);

    if (
        $instance->registration_request_id === null
        || $instance->registration_completed_at === null
        || $instance->status !== AppInstanceState::Active
        || $instance->provisioning_step !== 'active'
    ) {
        fwrite(STDERR, "Invalid ORB-105 published registration boundary.\n");
        exit(64);
    }

    $instance->update(['registration_completed_at' => null]);
    echo "ok\n";
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
        || (
            ! str_starts_with($authoritativePath, '/dev/shm/orb105-')
            && ! str_starts_with($authoritativePath, '/home/orbit/orb105-')
        )
        || $authoritativePath !== $instance->registration_original_path
    ) {
        fwrite(STDERR, "Invalid ORB-105 relocation checkpoint.\n");
        exit(64);
    }

    $instance->update([
        'registration_relocation_state' => $state,
        'registration_authoritative_path' => $authoritativePath,
        'registration_source_device' => null,
        'registration_source_inode' => null,
    ]);
    echo "ok\n";
    exit(0);
}

if ($command === 'registration-set-count') {
    $paths = array_slice($argv, 2);
    $instances = AppInstance::query()->whereIn('registration_original_path', $paths)->get();
    echo json_encode([
        'instances' => $instances->count(),
        'instance_rows' => $instances->map(static fn (AppInstance $instance): array => [
            'id' => $instance->id,
            'path' => $instance->checkout_path,
            'status' => $instance->status->value,
            'relocation_state' => $instance->registration_relocation_state,
            'authoritative_path' => $instance->registration_authoritative_path,
            'source_device' => $instance->registration_source_device,
            'source_inode' => $instance->registration_source_inode,
            'failed_step' => $instance->failed_step,
            'error_code' => $instance->error_code,
        ])->values()->all(),
        'routes' => Route::query()
            ->whereHas('targets', static fn ($query) => $query->whereIn('app_instance_id', $instances->modelKeys()))
            ->count(),
    ], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

if ($command === 'seed-owned-active') {
    $path = $argv[2] ?? '';
    $commit = $argv[3] ?? '';

    if (! str_starts_with($path, '/home/orbit/orb105-') || preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
        fwrite(STDERR, "Invalid ORB-105 owned AppInstance source.\n");
        exit(64);
    }

    $app = OrbitApp::query()->where('slug', 'laravel-typed')->sole();
    $node = Node::query()->where('name', 'app-dev')->sole();
    $cluster = Cluster::query()->where('state', 'active')->sole();
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'orb105-owned-new',
        'environment' => 'development',
        'source_layout' => 'checkout',
        'checkout_path' => $path,
        'branch' => 'orb105-owned-new',
        'starting_commit' => $commit,
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'generation_basis_node_id' => $node->id,
        'hostname' => 'orb105-owned-new.laravel-typed.orbit',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    echo json_encode(['id' => $instance->id, 'route_id' => $route->id], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

if ($command === 'owned-state') {
    $identifier = $argv[2] ?? '';
    $instance = AppInstance::query()->with('routes')
        ->when(
            ctype_digit($identifier),
            static fn ($query) => $query->whereKey((int) $identifier),
            static fn ($query) => $query->where('checkout_path', $identifier),
        )
        ->sole();
    $route = $instance->routes->sole();
    echo json_encode([
        'id' => $instance->id,
        'path' => $instance->checkout_path,
        'status' => $instance->status->value,
        'provisioning_step' => $instance->provisioning_step,
        'registration_request_id' => $instance->registration_request_id,
        'registration_original_path' => $instance->registration_original_path,
        'route_id' => $route->id,
        'route_status' => $route->status->value,
        'hostname' => $route->hostname,
    ], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

if ($command === 'delete-owned-active') {
    $identifier = $argv[2] ?? '';
    $instance = AppInstance::query()
        ->when(
            ctype_digit($identifier),
            static fn ($query) => $query->whereKey((int) $identifier),
            static fn ($query) => $query->where('checkout_path', $identifier),
        )
        ->sole();

    if (
        $instance->name !== 'orb105-owned-new'
        || ! str_starts_with($instance->checkout_path, '/home/orbit/orb105-')
        || $instance->registration_request_id !== null
        || $instance->registration_original_path !== null
    ) {
        fwrite(STDERR, "Invalid ORB-105 owned AppInstance cleanup.\n");
        exit(64);
    }

    $routes = Route::query()
        ->where('hostname', 'orb105-owned-new.laravel-typed.orbit')
        ->get();
    $instance->delete();
    $routes->each(static fn (Route $route) => $route->delete());
    echo "ok\n";
    exit(0);
}

if ($command === 'seed-overlap-owner') {
    $path = $argv[2] ?? '';

    if (! str_starts_with($path, '/home/orbit/orb105-')) {
        fwrite(STDERR, "Invalid ORB-105 managed overlap path.\n");
        exit(64);
    }

    $app = OrbitApp::query()->where('slug', 'laravel-typed')->sole();
    $node = Node::query()->where('name', 'app-dev')->sole();
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'orb105-overlap-owner',
        'environment' => 'development',
        'source_layout' => 'checkout',
        'checkout_path' => $path,
        'status' => AppInstanceState::Reserved,
    ]);
    echo json_encode(['id' => $instance->id], JSON_THROW_ON_ERROR)."\n";
    exit(0);
}

if ($command === 'delete-overlap-owner') {
    $id = (int) ($argv[2] ?? 0);
    $instance = AppInstance::query()->findOrFail($id);

    if ($instance->name !== 'orb105-overlap-owner' || $instance->status !== AppInstanceState::Reserved) {
        fwrite(STDERR, "Invalid ORB-105 managed overlap owner.\n");
        exit(64);
    }

    $instance->delete();
    echo "ok\n";
    exit(0);
}

fwrite(STDERR, "Unknown ORB-105 fixture command.\n");
exit(64);
