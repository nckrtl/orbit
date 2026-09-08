<?php

declare(strict_types=1);

use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? '';
$arguments = array_slice($argv, 2);

function fixtureApp(): OrbitApp
{
    return OrbitApp::query()->where('slug', 'laravel-typed')->sole();
}

function fixtureNode(string $name): Node
{
    return Node::query()->where('name', $name)->sole();
}

function fixtureCluster(): Cluster
{
    return Cluster::query()->where('state', 'active')->sole();
}

function fixtureInstance(string $name): AppInstance
{
    return AppInstance::query()->where('name', $name)->sole();
}

function seedDevelopmentInstance(
    string $name,
    string $layout,
    string $branch,
    string $commit,
    ?string $hostname = null,
): AppInstance {
    $app = fixtureApp();
    $node = fixtureNode('app-dev');
    $cluster = fixtureCluster();
    $hostname ??= "{$name}.orbit";
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'development',
        'source_layout' => $layout,
        'checkout_path' => "/home/orbit/apps/{$app->slug}/{$name}",
        'root' => null,
        'branch' => $branch,
        'starting_commit' => $commit,
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => $hostname,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);

    return $instance->refresh();
}

function projectDevelopmentInstances(array $names): void
{
    $instances = AppInstance::query()->whereIn('name', $names)->with(['node', 'routes'])->get();

    if ($instances->count() !== count($names)) {
        exit(65);
    }

    $certificates = app(RemoteAppDevCertificateManager::class);

    foreach ($instances as $instance) {
        $certificates->convergeAppInstance($instance, $instance->routes->sole());
    }

    $node = fixtureNode('app-dev');
    app(RemoteAppDevPhpFpmManager::class)->converge($node);
    app(RemoteAppDevCaddyManager::class)->converge($node);
    app(DnsmasqPrivateDnsManager::class)->converge();
}

function cleanupActiveInstances(array $names): void
{
    $instances = AppInstance::query()->whereIn('name', $names)->with('routes.targets')->get();

    foreach ($instances as $instance) {
        if ($instance->status === AppInstanceState::Removing) {
            exit(65);
        }

        $instance->update(['status' => AppInstanceState::SourceResolved]);

        foreach ($instance->routes as $route) {
            $route->update(['status' => RouteStatus::Pending]);
            $route->targets()->delete();
            $route->delete();
        }

        $instance->delete();
    }
}

function removalEvidence(string $name): array
{
    $member = AppInstanceRemovalMember::query()
        ->where('name', $name)
        ->with('removal.members')
        ->latest('id')
        ->firstOrFail();
    $removal = $member->removal;

    return [
        'operation_id' => $removal->id,
        'status' => $removal->status->value,
        'force' => $removal->force,
        'current_step' => $removal->current_step?->value,
        'failed_step' => $removal->failed_step?->value,
        'error_code' => $removal->error_code,
        'total' => $removal->total,
        'members' => $removal->members
            ->sortBy('position')
            ->map(static fn (AppInstanceRemovalMember $value): array => [
                'name' => $value->name,
                'layout' => $value->source_layout,
                'position' => $value->position,
                'route_outcome' => $value->route_outcome,
                'source_prepared_at' => $value->source_prepared_at?->format('Y-m-d H:i:s.u'),
                'route_cleared_at' => $value->route_cleared_at?->format('Y-m-d H:i:s.u'),
                'source_finalized_at' => $value->source_finalized_at?->format('Y-m-d H:i:s.u'),
                'runtime_cleaned_at' => $value->runtime_cleaned_at?->format('Y-m-d H:i:s.u'),
                'row_deleted_at' => $value->row_deleted_at?->format('Y-m-d H:i:s.u'),
                'receipt' => $value->finalization_receipt,
                'member_id' => $value->id,
                'checkout_path' => $value->checkout_path,
                'root' => $value->root,
                'common_repository_path' => $value->common_repository_path,
                'source_layout' => $value->source_layout,
                'source_digest' => $value->source_digest,
                'source_identity' => $value->source_identity,
                'common_repository_path' => $value->common_repository_path,
            ])
            ->values()
            ->all(),
    ];
}

function seedProductionRoute(): array
{
    $app = fixtureApp();
    $cluster = fixtureCluster();
    $nodes = collect([fixtureNode('app-prod'), fixtureNode('app-prod-2')]);
    $nodes->each(static fn (Node $node) => $node->update(['cluster_id' => $cluster->id]));
    $instances = $nodes->values()->map(static function (Node $node, int $position) use ($app): AppInstance {
        return AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => 'orb124-prod-'.($position + 1),
            'environment' => 'production',
            'source_layout' => 'checkout',
            'checkout_path' => '/srv/orbit/orb124-prod-'.($position + 1),
            'root' => 'public',
            'branch' => '13.x',
            'starting_commit' => str_repeat((string) ($position + 1), 40),
            'selected_php_version' => '8.5',
            'status' => AppInstanceState::SourceResolved,
        ]);
    });
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'hostname' => 'orb124-shared.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    foreach ($instances as $position => $instance) {
        $route->targets()->create(['app_instance_id' => $instance->id, 'position' => $position]);
    }

    $route->update(['status' => RouteStatus::Active]);
    $instances->each(static fn (AppInstance $instance) => $instance->update(['status' => AppInstanceState::Active]));
    $router = $cluster->routerAssignment()->with('node')->sole()->node;

    return [
        'route_id' => $route->id,
        'hostname' => $route->hostname,
        'router_ip' => $router->wireguard_ip,
        'instances' => $instances->map(static fn (AppInstance $instance): array => [
            'id' => $instance->id,
            'name' => $instance->name,
            'node' => $instance->node->name,
        ])->all(),
    ];
}

function projectProductionRoute(int $routeId): void
{
    $route = Route::query()
        ->with(['targets.appInstance.node', 'cluster.routerAssignment.node'])
        ->findOrFail($routeId);
    $certificates = app(RemoteAppDevCertificateManager::class);
    $php = app(RemoteAppDevPhpFpmManager::class);
    $caddy = app(RemoteAppDevCaddyManager::class);

    foreach ($route->targets as $target) {
        $instance = $target->appInstance;
        $certificates->convergeAppInstance($instance, $route);
        $php->converge($instance->node);
        $caddy->converge($instance->node);
    }

    $router = $route->cluster?->routerAssignment?->node;

    if (! $router instanceof Node) {
        exit(65);
    }

    $certificates->convergeRouteRouter($route, $router);
    $caddy->converge($router);
    app(DnsmasqPrivateDnsManager::class)->converge();
}

function completeRemovalRouteStep(string $name): void
{
    $member = AppInstanceRemovalMember::query()
        ->where('name', $name)
        ->whereNull('route_cleared_at')
        ->sole();
    $outcome = app(\App\Domain\AppInstances\Removal\AppInstanceRemovalProjector::class)
        ->clearRouteTarget($member);
    $member->update([
        'route_cleared_at' => now(),
        'route_outcome' => $outcome,
    ]);
}

function productionEvidence(int $routeId, int $departingId, int $remainingId): array
{
    $route = Route::query()->with('targets')->findOrFail($routeId);

    return [
        'route_status' => $route->status->value,
        'targets' => $route->targets->pluck('app_instance_id')->all(),
        'departing_exists' => AppInstance::query()->whereKey($departingId)->exists(),
        'remaining_exists' => AppInstance::query()->whereKey($remainingId)->exists(),
    ];
}

switch ($command) {
    case 'seed-dev':
        if (count($arguments) < 4 || count($arguments) > 5) {
            exit(64);
        }

        $instance = seedDevelopmentInstance(
            $arguments[0],
            $arguments[1],
            $arguments[2],
            $arguments[3],
            $arguments[4] ?? null,
        );
        echo json_encode([
            'id' => $instance->id,
            'name' => $instance->name,
            'path' => $instance->checkout_path,
            'route_id' => $instance->routes()->sole()->id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        break;

    case 'project-dev':
        if ($arguments === []) {
            exit(64);
        }

        projectDevelopmentInstances($arguments);
        break;

    case 'instance-state':
        if (count($arguments) !== 1) {
            exit(64);
        }

        $instance = fixtureInstance($arguments[0]);
        $instance->load('routes.targets');
        echo json_encode([
            'id' => $instance->id,
            'status' => $instance->status->value,
            'path' => $instance->checkout_path,
            'routes' => $instance->routes->map(static fn (Route $route): array => [
                'id' => $route->id,
                'hostname' => $route->hostname,
                'status' => $route->status->value,
                'targets' => $route->targets->pluck('app_instance_id')->all(),
            ])->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        break;

    case 'cleanup-active':
        if ($arguments === []) {
            exit(64);
        }

        cleanupActiveInstances($arguments);
        break;

    case 'removal-evidence':
        if (count($arguments) !== 1) {
            exit(64);
        }

        echo json_encode(removalEvidence($arguments[0]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        break;

    case 'hostname-free':
        if (count($arguments) !== 1) {
            exit(64);
        }

        if (Route::query()->where('hostname', $arguments[0])->exists()) {
            exit(65);
        }

        break;

    case 'seed-production':
        if ($arguments !== []) {
            exit(64);
        }

        echo json_encode(seedProductionRoute(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        break;

    case 'production-evidence':
        if (count($arguments) !== 3) {
            exit(64);
        }

        echo json_encode(
            productionEvidence((int) $arguments[0], (int) $arguments[1], (int) $arguments[2]),
            JSON_THROW_ON_ERROR,
        ), PHP_EOL;
        break;

    case 'project-production':
        if (count($arguments) !== 1) {
            exit(64);
        }

        projectProductionRoute((int) $arguments[0]);
        break;

    case 'complete-removal-route-step':
        if (count($arguments) !== 1) {
            exit(64);
        }

        completeRemovalRouteStep($arguments[0]);
        break;

    case 'reset-production-nodes':
        if ($arguments !== []) {
            exit(64);
        }

        foreach (['app-prod', 'app-prod-2'] as $name) {
            fixtureNode($name)->update(['cluster_id' => null]);
        }

        break;

    default:
        fwrite(STDERR, "Unknown ORB-124 fixture command: {$command}\n");
        exit(64);
}
