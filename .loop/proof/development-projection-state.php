<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevPhpFpmManager;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
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
    fwrite(STDERR, "ORB-173 fixture failed: {$exception->getMessage()}\n");
    exit(70);
});

/** @return list<string> */
function orb173Names(string $case): array
{
    if (! in_array($case, ['same', 'different'], true)) {
        exit(64);
    }

    return ["orb173-{$case}-a", "orb173-{$case}-b"];
}

/** @return list<string> */
function orb173Hostnames(string $case): array
{
    return array_map(static fn (string $name): string => "{$name}.orbit", orb173Names($case));
}

/** @param array<string, mixed> $value */
function orb173Json(array $value): void
{
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

function orb173RemoveRows(string $case): void
{
    $names = orb173Names($case);
    $hostnames = orb173Hostnames($case);

    DB::transaction(static function () use ($names, $hostnames): void {
        AppInstance::query()->whereIn('name', $names)->update([
            'status' => AppInstanceState::Reserved,
        ]);

        $routes = Route::query()->whereIn('hostname', $hostnames)->get();

        foreach ($routes as $route) {
            $route->update(['status' => RouteStatus::Pending]);
            $route->targets()->delete();
            $route->delete();
        }

        AppInstance::query()->whereIn('name', $names)->delete();
    });
}

/** @return array<string, mixed> */
function orb173Evidence(string $case): array
{
    $instances = AppInstance::query()
        ->whereIn('name', orb173Names($case))
        ->with(['node.cluster.routerAssignment.node', 'routes'])
        ->orderBy('name')
        ->get();

    if ($instances->count() !== 2) {
        throw new RuntimeException("ORB-173 {$case} instances are incomplete.");
    }

    return [
        'case' => $case,
        'instances' => $instances->map(static function (AppInstance $instance): array {
            $route = $instance->routes->sole();
            $router = $instance->node->cluster?->routerAssignment?->node;

            return [
                'name' => $instance->name,
                'node' => $instance->node->name,
                'instance_status' => $instance->status->value,
                'route_status' => $route->status->value,
                'hostname' => $route->hostname,
                'router' => $router?->name,
                'router_address' => $router?->wireguard_ip,
            ];
        })->all(),
    ];
}

switch ($command) {
    case 'probe':
        if ($arguments !== []) {
            exit(64);
        }

        $appDev = Node::query()->where('name', 'app-dev')->with('cluster.routerAssignment.node')->sole();
        $appProd2 = Node::query()->where('name', 'app-prod-2')->sole();
        $router = $appDev->cluster?->routerAssignment?->node;

        if (! $router instanceof Node) {
            throw new RuntimeException('The standard app-dev Cluster has no Router.');
        }

        orb173Json([
            'app_dev' => ['id' => $appDev->id, 'name' => $appDev->name],
            'cluster' => ['id' => $appDev->cluster?->id, 'name' => $appDev->cluster?->name],
            'router' => ['id' => $router->id, 'name' => $router->name],
            'app_prod_2' => ['id' => $appProd2->id, 'name' => $appProd2->name],
        ]);
        break;

    case 'seed':
        if (count($arguments) !== 3) {
            exit(64);
        }

        [$case, $firstNodeName, $secondNodeName] = $arguments;
        $names = orb173Names($case);
        $hostnames = orb173Hostnames($case);
        $nodes = [
            Node::query()->where('name', $firstNodeName)->sole(),
            Node::query()->where('name', $secondNodeName)->sole(),
        ];
        $app = OrbitApp::query()->where('slug', 'laravel-typed')->sole();
        orb173RemoveRows($case);

        DB::transaction(static function () use ($app, $case, $names, $hostnames, $nodes): void {
            foreach ($names as $position => $name) {
                $node = $nodes[$position];
                $cluster = $node->cluster()->with('routerAssignment.node')->first();

                if (! $cluster instanceof Cluster || ! $cluster->routerAssignment?->node instanceof Node) {
                    throw new RuntimeException("Node [{$node->name}] has no routed Cluster.");
                }

                $instance = AppInstance::query()->create([
                    'app_id' => $app->id,
                    'node_id' => $node->id,
                    'name' => $name,
                    'environment' => 'development',
                    'source_layout' => AppInstanceSourceLayout::Checkout,
                    'checkout_path' => "/home/orbit/apps/laravel-typed/{$name}",
                    'root' => 'public',
                    'branch' => '13.x',
                    'starting_commit' => str_repeat((string) ($position + ($case === 'same' ? 1 : 3)), 40),
                    'selected_php_version' => null,
                    'status' => AppInstanceState::SourceResolved,
                ]);
                $route = Route::query()->create([
                    'app_id' => $app->id,
                    'cluster_id' => $cluster->id,
                    'hostname' => $hostnames[$position],
                    'provenance' => RouteProvenance::Explicit,
                    'publication' => RoutePublication::Private,
                    'status' => RouteStatus::Pending,
                ]);
                $route->targets()->create([
                    'app_instance_id' => $instance->id,
                    'position' => 0,
                ]);
            }
        });
        orb173Json(orb173Evidence($case));
        break;

    case 'pending':
        if (count($arguments) !== 1) {
            exit(64);
        }

        $instance = AppInstance::query()->where('name', $arguments[0])->with('routes')->sole();
        $route = $instance->routes->sole();

        if ($instance->status !== AppInstanceState::SourceResolved || $route->status !== RouteStatus::Pending) {
            throw new RuntimeException("Contending AppInstance [{$instance->name}] advanced before release.");
        }

        orb173Json(['name' => $instance->name, 'status' => 'pending']);
        break;

    case 'evidence':
        if (count($arguments) !== 1) {
            exit(64);
        }

        $evidence = orb173Evidence($arguments[0]);

        foreach ($evidence['instances'] as $instance) {
            if (
                $instance['instance_status'] !== AppInstanceState::Active->value
                || $instance['route_status'] !== RouteStatus::Active->value
                || ! is_string($instance['router_address'])
            ) {
                throw new RuntimeException('ORB-173 projection did not reach Active state.');
            }
        }

        orb173Json($evidence);
        break;

    case 'cleanup':
        if (count($arguments) !== 1) {
            exit(64);
        }

        $case = $arguments[0];
        $nodes = AppInstance::query()
            ->whereIn('name', orb173Names($case))
            ->with('node.cluster.routerAssignment.node')
            ->get()
            ->flatMap(static function (AppInstance $instance): array {
                $router = $instance->node->cluster?->routerAssignment?->node;

                return array_filter([$instance->node, $router]);
            })
            ->unique('id')
            ->values();
        $workloads = AppInstance::query()
            ->whereIn('name', orb173Names($case))
            ->with('node')
            ->get()
            ->pluck('node')
            ->unique('id')
            ->values();
        orb173RemoveRows($case);

        foreach ($workloads as $node) {
            app(RemoteAppDevPhpFpmManager::class)->converge($node);
        }

        foreach ($nodes as $node) {
            app(RemoteAppDevCaddyManager::class)->converge($node);
        }

        app(DnsmasqPrivateDnsManager::class)->converge();
        orb173Json(['case' => $case, 'status' => 'clean']);
        break;

    case 'restored':
        if ($arguments !== []) {
            exit(64);
        }

        $node = Node::query()->where('name', 'app-prod-2')->with(['roles', 'cluster'])->sole();
        $roles = $node->roles->pluck('role')->map(static fn (RoleName $role): string => $role->value)->sort()->values()->all();

        if (
            $node->status !== LifecycleStatus::Active
            || $node->cluster_id !== null
            || $roles !== [RoleName::AppProd->value]
            || AppInstance::query()->where('name', 'like', 'orb173-%')->exists()
            || Route::query()->where('hostname', 'like', 'orb173-%')->exists()
        ) {
            throw new RuntimeException('The extended Node or ORB-173 records were not restored.');
        }

        orb173Json(['node' => $node->name, 'roles' => $roles, 'status' => 'restored']);
        break;

    default:
        exit(64);
}
