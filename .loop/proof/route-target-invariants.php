<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteTarget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

$command = $argv[1] ?? '';
$statePath = '/home/orbit/.orbit/orb187-route-invariants.json';

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, "ORB-187 fixture failed: {$exception->getMessage()}\n");
    exit(70);
});

/** @return list<string> */
function fixtureNames(): array
{
    return ['orb187-primary', 'orb187-replacement', 'orb187-owned'];
}

/** @return list<string> */
function fixtureHostnames(): array
{
    return [
        'orb187-primary.orbit',
        'orb187-owned.orbit',
        'orb187-requested.orbit',
        'orb187-empty.orbit',
    ];
}

function removeFixtureRows(): void
{
    AppInstance::query()
        ->whereIn('name', fixtureNames())
        ->update(['status' => AppInstanceState::Reserved]);

    $routes = Route::query()->whereIn('hostname', fixtureHostnames())->get();

    foreach ($routes as $route) {
        $route->targets()->delete();
        $route->delete();
    }

    AppInstance::query()->whereIn('name', fixtureNames())->delete();
}

/** @return array<string, mixed> */
function fixtureSnapshot(): array
{
    return [
        'instances' => AppInstance::query()
            ->whereIn('name', fixtureNames())
            ->orderBy('id')
            ->get()
            ->map(static fn (AppInstance $instance): array => $instance->getAttributes())
            ->all(),
        'routes' => Route::query()
            ->whereIn('hostname', fixtureHostnames())
            ->orderBy('id')
            ->get()
            ->map(static fn (Route $route): array => $route->getAttributes())
            ->all(),
        'targets' => RouteTarget::query()
            ->whereIn('route_id', Route::query()->whereIn('hostname', fixtureHostnames())->select('id'))
            ->orderBy('id')
            ->get()
            ->map(static fn (RouteTarget $target): array => $target->getAttributes())
            ->all(),
    ];
}

/** @return array<string, mixed> */
function readState(string $path): array
{
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($value)) {
        exit(65);
    }

    return $value;
}

/** @param array<string, mixed> $value */
function writeJson(array $value): void
{
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

/** @param array<string, int> $identity */
function createTargetedRoute(array $identity, string $key, int $instanceId): Route
{
    $route = Route::query()->create([
        'app_id' => $identity['app_id'],
        'node_id' => $identity['node_id'],
        'cluster_id' => null,
        'generation_basis_node_id' => null,
        'hostname' => "orb187-{$key}.orbit",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instanceId, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

switch ($command) {
    case 'setup':
        $state = DB::transaction(static function (): array {
            removeFixtureRows();
            $app = OrbitApp::query()->where('slug', 'laravel-typed')->sole();
            $node = Node::query()->where('name', 'app-dev')->sole();
            $instances = collect(fixtureNames())->mapWithKeys(
                static function (string $name) use ($app, $node): array {
                    $instance = AppInstance::query()->create([
                        'app_id' => $app->id,
                        'node_id' => $node->id,
                        'name' => $name,
                        'environment' => 'development',
                        'source_layout' => 'checkout',
                        'checkout_path' => "/home/orbit/apps/laravel-typed/{$name}",
                        'root' => 'public',
                        'branch' => '13.x',
                        'starting_commit' => str_repeat((string) (strlen($name) % 9 + 1), 40),
                        'selected_php_version' => '8.5',
                        'status' => AppInstanceState::Active,
                    ]);

                    return [$name => $instance];
                },
            );
            $identity = ['app_id' => $app->id, 'node_id' => $node->id];
            $primary = createTargetedRoute($identity, 'primary', $instances['orb187-primary']->id);
            $owned = createTargetedRoute($identity, 'owned', $instances['orb187-owned']->id);
            $requested = Route::query()->create([
                'app_id' => $app->id,
                'node_id' => $node->id,
                'hostname' => 'orb187-requested.orbit',
                'provenance' => RouteProvenance::Explicit,
                'publication' => RoutePublication::Private,
                'status' => RouteStatus::Pending,
            ]);
            $empty = Route::query()->create([
                'app_id' => $app->id,
                'node_id' => $node->id,
                'hostname' => 'orb187-empty.orbit',
                'provenance' => RouteProvenance::Explicit,
                'publication' => RoutePublication::Private,
                'status' => RouteStatus::Pending,
            ]);

            return [
                'primary_instance_id' => $instances['orb187-primary']->id,
                'replacement_instance_id' => $instances['orb187-replacement']->id,
                'owned_instance_id' => $instances['orb187-owned']->id,
                'primary_route_id' => $primary->id,
                'owned_route_id' => $owned->id,
                'requested_route_id' => $requested->id,
                'empty_route_id' => $empty->id,
                'snapshot' => fixtureSnapshot(),
            ];
        });
        file_put_contents(
            $statePath,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL,
        );
        chmod($statePath, 0600);
        writeJson($state);
        break;

    case 'identity':
        $state = readState($statePath);
        unset($state['snapshot']);
        writeJson($state);
        break;

    case 'assert-unchanged':
        $state = readState($statePath);

        if (($state['snapshot'] ?? null) !== fixtureSnapshot()) {
            fwrite(STDERR, "ORB-187 fixture state changed unexpectedly.\n");
            exit(65);
        }

        writeJson(['status' => 'unchanged']);
        break;

    case 'prepare-cleanup':
        $state = readState($statePath);

        if (($state['snapshot'] ?? null) !== fixtureSnapshot()) {
            exit(65);
        }

        DB::transaction(static function (): void {
            AppInstance::query()
                ->whereIn('name', fixtureNames())
                ->update(['status' => AppInstanceState::Reserved]);
            Route::query()
                ->whereIn('hostname', ['orb187-primary.orbit', 'orb187-owned.orbit'])
                ->update(['status' => RouteStatus::Pending]);
        });
        writeJson(['status' => 'cleanup_ready']);
        break;

    case 'cleanup':
        if (Route::query()->whereIn('hostname', fixtureHostnames())->exists()) {
            fwrite(STDERR, "ORB-187 fixture Routes remain after API cleanup.\n");
            exit(65);
        }

        AppInstance::query()->whereIn('name', fixtureNames())->delete();
        unlink($statePath);
        writeJson(['status' => 'clean']);
        break;

    default:
        exit(64);
}
