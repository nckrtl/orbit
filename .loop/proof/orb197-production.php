<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
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
$statePath = '/home/orbit/.orbit/orb197-production.json';

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, "ORB-197 fixture failed: {$exception->getMessage()}\n");
    exit(70);
});

/** @return array<string, array{repository:string,root:string}> */
function definitions(): array
{
    $names = [
        'create',
        'initial',
        'missing',
        'generated',
        'nonphp',
        'cluster',
        'laravel',
        'safety-home',
        'safety-existing',
        'safety-root',
        'unresolved',
        'ownership',
        'retry',
        'retry-recovery',
        'active',
        'remove',
        'remove-inactive',
    ];
    $definitions = [];

    foreach ($names as $name) {
        $definitions["orb197-{$name}"] = [
            'repository' => "https://localhost/orb197/{$name}.git",
            'root' => 'public',
        ];
    }

    return $definitions;
}

/** @return array<string, mixed> */
function readState(string $path): array
{
    $value = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);

    if (! is_array($value)) {
        throw new RuntimeException('The fixture state is invalid.');
    }

    return $value;
}

/** @param array<string, mixed> $value */
function writeJson(array $value): void
{
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

/** @return array<string, mixed> */
function currentState(string $path): array
{
    $state = readState($path);

    foreach (($state['apps'] ?? []) as $slug => $id) {
        if (! is_string($slug) || ! is_int($id) || ! OrbitApp::query()->whereKey($id)->exists()) {
            throw new RuntimeException('A fixture App is missing.');
        }
    }

    return $state;
}

/** @param array<string, mixed> $state */
function appId(array $state, string $slug): int
{
    $id = $state['apps'][$slug] ?? null;

    if (! is_int($id)) {
        throw new InvalidArgumentException("Unknown fixture App [{$slug}].");
    }

    return $id;
}

/** @param array<string, mixed> $state */
function nodeId(array $state, string $name): int
{
    $id = $state['nodes'][$name]['id'] ?? null;

    if (! is_int($id)) {
        throw new InvalidArgumentException("Unknown fixture Node [{$name}].");
    }

    return $id;
}

/** @param array<string, mixed> $state */
function appEvidence(array $state, string $slug): array
{
    $app = OrbitApp::query()->findOrFail(appId($state, $slug));
    $instances = AppInstance::query()
        ->with('routes.targets')
        ->where('app_id', $app->id)
        ->orderBy('id')
        ->get();

    return [
        'app' => $app->getAttributes(),
        'instances' => $instances->map(static fn (AppInstance $instance): array => [
            ...$instance->getAttributes(),
            'effective_root' => $instance->effectiveRoot(),
            'routes' => $instance->routes->map(static fn (Route $route): array => [
                ...$route->getAttributes(),
                'targets' => $route->targets->map->getAttributes()->all(),
            ])->all(),
        ])->all(),
        'route_count' => Route::query()->where('app_id', $app->id)->count(),
    ];
}

switch ($command) {
    case 'setup':
        if ($arguments !== []) {
            exit(64);
        }

        $nodes = [];
        foreach (['app-prod', 'app-prod-2'] as $name) {
            $node = Node::query()->where('name', $name)->sole();
            if (! $node->roles()->where('role', 'app-prod')->where('status', 'active')->exists()) {
                throw new RuntimeException("Node [{$name}] has no active app-prod role.");
            }
            $node->update(['tld' => $name === 'app-prod-2' ? 'prodtest' : null, 'cluster_id' => null]);
            $nodes[$name] = [
                'id' => $node->id,
                'wireguard_ip' => $node->wireguard_ip,
            ];
        }

        $apps = [];
        foreach (definitions() as $slug => $definition) {
            $app = OrbitApp::query()->where('slug', $slug)->first();
            if (! $app instanceof OrbitApp) {
                $app = OrbitApp::query()->create([
                    'name' => $slug,
                    'slug' => $slug,
                    'repository_url' => $definition['repository'],
                    'default_branch' => 'main',
                    'root' => $definition['root'],
                ]);
            }
            if (
                $app->repository_url !== $definition['repository']
                || $app->default_branch !== 'main'
                || $app->root !== $definition['root']
            ) {
                throw new RuntimeException("Fixture App [{$slug}] has drifted.");
            }
            $apps[$slug] = $app->id;
        }

        $cluster = Cluster::query()->firstOrCreate(
            ['name' => 'orb197-cluster'],
            ['tld' => null, 'state' => ClusterState::Active],
        );
        $cluster->update(['state' => ClusterState::Active, 'tld' => null]);
        $state = ['apps' => $apps, 'nodes' => $nodes, 'cluster_id' => $cluster->id];
        file_put_contents(
            $statePath,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL,
        );
        chmod($statePath, 0600);
        writeJson($state);
        break;

    case 'state':
        if ($arguments !== []) {
            exit(64);
        }
        writeJson(currentState($statePath));
        break;

    case 'app':
        if (count($arguments) !== 1) {
            exit(64);
        }
        $state = currentState($statePath);
        $app = OrbitApp::query()->findOrFail(appId($state, $arguments[0]));
        writeJson($app->getAttributes());
        break;

    case 'node':
        if (count($arguments) !== 1) {
            exit(64);
        }
        $state = currentState($statePath);
        $node = Node::query()->findOrFail(nodeId($state, $arguments[0]));
        writeJson($node->getAttributes());
        break;

    case 'inspect':
        if (count($arguments) !== 1) {
            exit(64);
        }
        writeJson(appEvidence(currentState($statePath), $arguments[0]));
        break;

    case 'cluster':
        if (count($arguments) !== 1 || ! in_array($arguments[0], ['on', 'off'], true)) {
            exit(64);
        }
        $state = currentState($statePath);
        $node = Node::query()->findOrFail(nodeId($state, 'app-prod-2'));
        $node->update(['cluster_id' => $arguments[0] === 'on' ? $state['cluster_id'] : null]);
        writeJson(['cluster_id' => $node->refresh()->cluster_id]);
        break;

    case 'rename':
        if (count($arguments) !== 2) {
            exit(64);
        }
        $state = currentState($statePath);
        $app = OrbitApp::query()->findOrFail(appId($state, $arguments[0]));
        $app->update(['slug' => $arguments[1], 'name' => $arguments[1]]);
        writeJson($app->refresh()->getAttributes());
        break;

    case 'source-checkpoint-fault':
        if (count($arguments) !== 2 || ! in_array($arguments[0], ['on', 'off'], true)) {
            exit(64);
        }
        DB::unprepared('DROP TRIGGER IF EXISTS orb197_source_checkpoint_fault');
        if ($arguments[0] === 'on') {
            $state = currentState($statePath);
            $appId = appId($state, $arguments[1]);
            DB::unprepared(<<<SQL
                CREATE TRIGGER orb197_source_checkpoint_fault
                BEFORE UPDATE OF provisioning_step ON app_instances
                WHEN NEW.app_id = {$appId} AND NEW.provisioning_step = 'source-prepared'
                BEGIN
                    SELECT RAISE(ABORT, 'orb197 source checkpoint fault');
                END
                SQL);
        }
        writeJson(['source_checkpoint_fault' => $arguments[0]]);
        break;

    case 'inactive-membership':
        if (count($arguments) !== 1 || ! in_array($arguments[0], ['on', 'off'], true)) {
            exit(64);
        }
        $state = currentState($statePath);
        $node = Node::query()->findOrFail(nodeId($state, 'app-prod-2'));
        $cluster = Cluster::query()->findOrFail($state['cluster_id']);
        if ($arguments[0] === 'on') {
            $cluster->update(['state' => ClusterState::Inactive]);
            $node->update(['cluster_id' => $cluster->id]);
        } else {
            $node->update(['cluster_id' => null]);
            $cluster->update(['state' => ClusterState::Active]);
        }
        writeJson([
            'node_id' => $node->id,
            'node_cluster_id' => $node->refresh()->cluster_id,
            'cluster_state' => $cluster->refresh()->state->value,
        ]);
        break;

    case 'assert-removed':
        if (count($arguments) !== 1) {
            exit(64);
        }
        $state = currentState($statePath);
        $id = appId($state, $arguments[0]);
        if (AppInstance::query()->where('app_id', $id)->exists() || Route::query()->where('app_id', $id)->exists()) {
            throw new RuntimeException('Production removal left owned database rows.');
        }
        writeJson(['removed' => true]);
        break;

    default:
        exit(64);
}
