<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
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
use Illuminate\Support\Facades\DB;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? '';
$arguments = array_slice($argv, 2);
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, 'ORB-183 fixture failed: '.$exception::class."\n");
    exit(70);
});

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

function nodeAddress(Node $node): string
{
    return is_string($node->lan_ip) && $node->lan_ip !== ''
        ? $node->lan_ip
        : (string) $node->wireguard_ip;
}

/** @return array<string, mixed> */
function prepareTopology(): array
{
    $cluster = fixtureCluster();

    DB::transaction(static function () use ($cluster): void {
        foreach (['app-prod', 'app-prod-2'] as $name) {
            $node = fixtureNode($name);

            if ($node->cluster_id !== $cluster->id) {
                $node->update(['cluster_id' => $cluster->id]);
            }
        }
    });

    return topologyEvidence();
}

/** @return array<string, mixed> */
function topologyEvidence(): array
{
    $cluster = fixtureCluster();
    $router = $cluster->routerAssignment()->with('node')->sole()->node;

    return [
        'cluster_id' => $cluster->id,
        'router' => [
            'name' => $router->name,
            'cluster_id' => $router->cluster_id,
            'address' => nodeAddress($router),
        ],
        'nodes' => Node::query()
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(static fn (Node $node): array => [
                'name' => $node->name,
                'cluster_id' => $node->cluster_id,
                'status' => $node->status->value,
                'wireguard_ip' => $node->wireguard_ip,
                'address' => nodeAddress($node),
                'active_app_prod' => $node->roles->contains(
                    static fn ($role): bool => $role->role === RoleName::AppProd
                        && $role->status === LifecycleStatus::Active,
                ),
            ])
            ->all(),
    ];
}

/** @return array<string, mixed> */
function seedProductionRoute(string $prefix, string $hostname, int $count): array
{
    if (preg_match('/\Aorb183-[a-z0-9-]+\z/', $prefix) !== 1
        || preg_match('/\Aorb183-[a-z0-9-]+\.orbit\z/', $hostname) !== 1
        || ! in_array($count, [1, 2], true)
    ) {
        exit(64);
    }

    $app = fixtureApp();
    $cluster = fixtureCluster();
    $nodes = collect([fixtureNode('app-prod'), fixtureNode('app-prod-2')])->take($count)->values();
    $instances = $nodes->map(static function (Node $node, int $position) use ($app, $prefix): AppInstance {
        $name = $prefix.'-'.($position + 1);

        return AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => $name,
            'environment' => 'production',
            'source_layout' => 'checkout',
            'checkout_path' => "/var/www/{$app->slug}/{$name}",
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
        'hostname' => $hostname,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    foreach ($instances as $position => $instance) {
        $route->targets()->create(['app_instance_id' => $instance->id, 'position' => $position]);
    }

    $route->update(['status' => RouteStatus::Active]);
    $instances->each(static fn (AppInstance $instance) => $instance->update([
        'status' => AppInstanceState::Active,
    ]));
    $router = $cluster->routerAssignment()->with('node')->sole()->node;

    return [
        'route_id' => $route->id,
        'hostname' => $route->hostname,
        'router_address' => nodeAddress($router),
        'instances' => $instances->map(static fn (AppInstance $instance): array => [
            'id' => $instance->id,
            'name' => $instance->name,
            'node' => $instance->node->name,
            'address' => nodeAddress($instance->node),
            'checkout_path' => $instance->checkout_path,
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

/** @return array<string, mixed> */
function routeEvidence(int $routeId): array
{
    $route = Route::query()->with('targets.appInstance.node')->find($routeId);

    if (! $route instanceof Route) {
        return ['exists' => false, 'targets' => []];
    }

    return [
        'exists' => true,
        'status' => $route->status->value,
        'hostname' => $route->hostname,
        'targets' => $route->targets->map(static fn ($target): array => [
            'id' => $target->app_instance_id,
            'position' => $target->position,
            'node' => $target->appInstance->node->name,
            'address' => nodeAddress($target->appInstance->node),
        ])->all(),
    ];
}

/** @return array<string, mixed> */
function removalEvidence(string $name): array
{
    $member = AppInstanceRemovalMember::query()
        ->where('name', $name)
        ->with('removal.members')
        ->latest('id')
        ->firstOrFail();
    $removal = $member->removal;
    $instance = AppInstance::query()->find($member->app_instance_id);

    return [
        'operation_id' => $removal->id,
        'status' => $removal->status->value,
        'force' => $removal->force,
        'current_step' => $removal->current_step?->value,
        'failed_step' => $removal->failed_step?->value,
        'error_code' => $removal->error_code,
        'total' => $removal->total,
        'app_instance_exists' => $instance instanceof AppInstance,
        'app_instance_status' => $instance?->status->value,
        'member' => [
            'app_instance_id' => $member->app_instance_id,
            'route_id' => $member->route_id,
            'route_outcome' => $member->route_outcome,
            'source_prepared_at' => $member->source_prepared_at?->format('Y-m-d H:i:s.u'),
            'route_cleared_at' => $member->route_cleared_at?->format('Y-m-d H:i:s.u'),
            'source_finalized_at' => $member->source_finalized_at?->format('Y-m-d H:i:s.u'),
            'runtime_cleaned_at' => $member->runtime_cleaned_at?->format('Y-m-d H:i:s.u'),
            'row_deleted_at' => $member->row_deleted_at?->format('Y-m-d H:i:s.u'),
            'receipt' => $member->finalization_receipt,
        ],
    ];
}

switch ($command) {
    case 'prepare-topology':
        if ($arguments !== []) {
            exit(64);
        }

        echo json_encode(prepareTopology(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        break;

    case 'topology':
        if ($arguments !== []) {
            exit(64);
        }

        echo json_encode(topologyEvidence(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        break;

    case 'seed':
        if (count($arguments) !== 3) {
            exit(64);
        }

        echo json_encode(
            seedProductionRoute($arguments[0], $arguments[1], (int) $arguments[2]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ), PHP_EOL;
        break;

    case 'project':
        if (count($arguments) !== 1) {
            exit(64);
        }

        projectProductionRoute((int) $arguments[0]);
        break;

    case 'route-state':
        if (count($arguments) !== 1) {
            exit(64);
        }

        echo json_encode(routeEvidence((int) $arguments[0]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        break;

    case 'removal-evidence':
        if (count($arguments) !== 1) {
            exit(64);
        }

        echo json_encode(removalEvidence($arguments[0]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        break;

    case 'hostname-free':
        if (count($arguments) !== 1 || Route::query()->where('hostname', $arguments[0])->exists()) {
            exit(65);
        }
        break;

    default:
        fwrite(STDERR, "Unknown ORB-183 fixture command: {$command}\n");
        exit(64);
}
