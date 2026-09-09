<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Contracts\Console\Kernel;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? '';
$arguments = array_slice($argv, 2);

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, "ORB-172 fixture failed: {$exception->getMessage()}\n");
    exit(70);
});

/** @param array<string, mixed> $value */
function orb172Json(array $value): void
{
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

/** @return array<string, mixed> */
function orb172Cluster(string $name): array
{
    $cluster = Cluster::query()->where('name', $name)->with('nodes')->sole();
    $assignments = NodeRole::query()
        ->where('cluster_id', $cluster->id)
        ->where('role', RoleName::Router)
        ->with('node')
        ->orderBy('id')
        ->get()
        ->map(static fn (NodeRole $assignment): array => [
            'id' => $assignment->id,
            'node' => $assignment->node->name,
            'status' => $assignment->status->value,
            'failed_step' => $assignment->failed_step,
            'error_code' => $assignment->error_code,
        ])
        ->all();

    return [
        'id' => $cluster->id,
        'name' => $cluster->name,
        'state' => $cluster->state->value,
        'tld' => $cluster->tld,
        'nodes' => $cluster->nodes->pluck('name')->sort()->values()->all(),
        'route_count' => $cluster->routes()->count(),
        'assignments' => $assignments,
    ];
}

/** @param list<array{node: string, status: string}> $expected */
function orb172AssertCluster(string $name, array $expected): array
{
    $cluster = orb172Cluster($name);

    if (
        $cluster['state'] !== ClusterState::Inactive->value
        || $cluster['tld'] !== null
        || $cluster['route_count'] !== 0
        || array_map(
            static fn (array $assignment): array => [
                'node' => $assignment['node'],
                'status' => $assignment['status'],
            ],
            $cluster['assignments'],
        ) !== $expected
    ) {
        throw new RuntimeException("Cluster [{$name}] has unexpected Router state.");
    }

    foreach ($cluster['assignments'] as $assignment) {
        if ($assignment['failed_step'] !== null || $assignment['error_code'] !== null) {
            throw new RuntimeException("Cluster [{$name}] contains failed Router evidence.");
        }
    }

    return $cluster;
}

switch ($command) {
    case 'probe':
        if ($arguments !== []) {
            exit(64);
        }

        $nodes = [];
        foreach (['app-prod', 'app-prod-2'] as $name) {
            $node = Node::query()->where('name', $name)->with('roles')->sole();
            $roles = $node->roles
                ->map(static fn (NodeRole $role): string => "{$role->role->value}:{$role->status->value}")
                ->sort()
                ->values()
                ->all();

            if (
                $node->status !== LifecycleStatus::Active
                || $node->cluster_id !== null
                || $roles !== [RoleName::AppProd->value.':'.LifecycleStatus::Active->value]
            ) {
                throw new RuntimeException("Node [{$name}] is not in the declared standalone app-prod state.");
            }

            $nodes[$name] = [
                'id' => $node->id,
                'wireguard_ip' => $node->wireguard_ip,
                'roles' => $roles,
            ];
        }

        orb172Json([
            'nodes' => $nodes,
            'orbit_home' => (string) config('orbit.home'),
        ]);
        break;

    case 'assert':
        if (count($arguments) < 2) {
            exit(64);
        }

        $phase = array_shift($arguments);
        $clusters = match ($phase) {
            'independent-held' => count($arguments) === 2 ? [
                orb172AssertCluster($arguments[0], [
                    ['node' => 'app-prod', 'status' => LifecycleStatus::Provisioning->value],
                ]),
                orb172AssertCluster($arguments[1], [
                    ['node' => 'app-prod-2', 'status' => LifecycleStatus::Active->value],
                ]),
            ] : exit(64),
            'independent-final' => count($arguments) === 2 ? [
                orb172AssertCluster($arguments[0], [
                    ['node' => 'app-prod', 'status' => LifecycleStatus::Active->value],
                ]),
                orb172AssertCluster($arguments[1], [
                    ['node' => 'app-prod-2', 'status' => LifecycleStatus::Active->value],
                ]),
            ] : exit(64),
            'set-set-waiting', 'busy-preserved' => count($arguments) === 1 ? [
                orb172AssertCluster($arguments[0], [
                    ['node' => 'app-prod', 'status' => LifecycleStatus::Provisioning->value],
                ]),
            ] : exit(64),
            'set-set-final' => count($arguments) === 1 ? [
                orb172AssertCluster($arguments[0], [
                    ['node' => 'app-prod-2', 'status' => LifecycleStatus::Active->value],
                ]),
            ] : exit(64),
            'set-clear-active' => count($arguments) === 1 ? [
                orb172AssertCluster($arguments[0], [
                    ['node' => 'app-prod', 'status' => LifecycleStatus::Active->value],
                ]),
            ] : exit(64),
            'cleared' => count($arguments) === 1 ? [
                orb172AssertCluster($arguments[0], []),
            ] : exit(64),
            default => exit(64),
        };

        orb172Json(['phase' => $phase, 'clusters' => $clusters]);
        break;

    case 'restored':
        if ($arguments !== []) {
            exit(64);
        }

        foreach (['app-prod', 'app-prod-2'] as $name) {
            $node = Node::query()->where('name', $name)->with('roles')->sole();
            $roles = $node->roles
                ->map(static fn (NodeRole $role): string => "{$role->role->value}:{$role->status->value}")
                ->sort()
                ->values()
                ->all();

            if (
                $node->status !== LifecycleStatus::Active
                || $node->cluster_id !== null
                || $roles !== [RoleName::AppProd->value.':'.LifecycleStatus::Active->value]
            ) {
                throw new RuntimeException("Node [{$name}] was not restored.");
            }
        }

        if (
            Cluster::query()->where('name', 'like', 'orb172-%')->exists()
            || NodeRole::query()
                ->whereIn('node_id', Node::query()->whereIn('name', ['app-prod', 'app-prod-2'])->select('id'))
                ->where('role', RoleName::Router)
                ->exists()
        ) {
            throw new RuntimeException('ORB-172 temporary Router state remains.');
        }

        orb172Json(['nodes' => ['app-prod', 'app-prod-2'], 'status' => 'restored']);
        break;

    default:
        exit(64);
}
