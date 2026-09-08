<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
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
$arguments = array_slice($argv, offset: 2);

set_exception_handler(static function (Throwable $exception): never {
    $code = $exception instanceof RuntimeConvergenceException ? $exception->errorCode : 'fixture.command_failed';
    fwrite(STDERR, "ORB-178 fixture failed: {$code}: {$exception->getMessage()}\n");
    exit(70);
});

function fixture_app(): OrbitApp
{
    return OrbitApp::query()->where('slug', 'laravel-typed')->sole();
}

function fixture_node(): Node
{
    return Node::query()->where('name', 'app-dev')->sole();
}

function fixture_cluster(): Cluster
{
    return Cluster::query()->where('state', 'active')->sole();
}

function fixture_instance(string $name): AppInstance
{
    return AppInstance::query()->where('name', $name)->sole();
}

function seed_instance(
    string $name,
    string $layout,
    string $branch,
    string $commit,
    ?string $checkoutPath = null,
): AppInstance {
    $app = fixture_app();
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => fixture_node()->id,
        'name' => $name,
        'environment' => 'development',
        'source_layout' => $layout,
        'checkout_path' => $checkoutPath ?? "/home/orbit/apps/{$app->slug}/{$name}",
        'root' => null,
        'branch' => $branch,
        'starting_commit' => $commit,
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::SourceResolved,
    ]);

    $routeModel = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => fixture_cluster()->id,
        'hostname' => "{$name}.orbit",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $routeModel->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);

    return $instance->refresh();
}

/** @return array<string, mixed> */
function instance_state(string $name): array
{
    $instance = fixture_instance($name);
    $instance->load('routes.targets');

    return [
        'attributes' => $instance->getAttributes(),
        'routes' => $instance->routes->map(static fn(Route $route): array => [
            'attributes' => $route->getAttributes(),
            'targets' => $route->targets->map(static fn($target): array => $target->getAttributes())->all(),
        ])->all(),
    ];
}

function cleanup_instances(array $names): void
{
    $instances = AppInstance::query()->whereIn('name', $names)->with('routes.targets')->get();

    foreach ($instances as $instance) {
        foreach ($instance->routes as $route) {
            $route->targets()->delete();
            $route->delete();
        }

        $instance->delete();
    }

    Route::query()
        ->whereIn('hostname', array_map(static fn(string $name): string => "{$name}.orbit", $names))
        ->each(static function (Route $route): void {
            $route->targets()->delete();
            $route->delete();
        });
}

function mutate_source(AppInstance $instance, string $mutation, string $commit): void
{
    $script = match ($mutation) {
        'replacement' => <<<'BASH'
            checkout=$1
            branch=$2
            commit=$3
            original="/home/orbit/${branch}-original"
            test ! -e "$original"
            mv -- "$checkout" "$original"
            git clone --local --no-checkout /home/orbit/apps/laravel-typed/e2e-dev "$checkout" >/dev/null
            git -C "$checkout" remote set-url origin https://github.com/laravel/laravel.git
            git -C "$checkout" checkout -b "$branch" "$commit" >/dev/null
            BASH,
        'origin' => <<<'BASH'
            git -C "$1" remote set-url origin https://github.com/laravel/framework.git
            BASH,
        'equivalent-origin' => <<<'BASH'
            git -C "$1" remote set-url origin git@github.com:laravel/laravel.git
            BASH,
        default => throw new InvalidArgumentException('Unknown mutation.'),
    };
    app(AppDevSshExecutor::class)->execute(
        $instance->node,
        new RemoteCommand(arguments: [
            'bash',
            '-seu',
            '--',
            $instance->checkout_path,
            $instance->branch,
            $commit,
        ], input: $script),
        step: 'orb-178-proof-mutation',
        errorCode: 'orb-178.proof_mutation_failed',
    );
}

function source_state(AppInstance $instance, string $mutation): string
{
    $paths = [$instance->checkout_path];

    if ($mutation === 'replacement') {
        $paths[] = "/home/orbit/{$instance->branch}-original";
    }

    $result = app(AppDevSshExecutor::class)->execute(
        $instance->node,
        new RemoteCommand(arguments: ['bash', '-seu', '--', ...$paths], input: <<<'BASH'
            for path in "$@"; do
                case "$path" in
                    /home/orbit/apps/laravel-typed/orb178-[a-z0-9-]*|/home/orbit/orb178-[a-z0-9-]*-original) ;;
                    *) exit 64 ;;
                esac
                test -e "$path" || test -L "$path"
                printf '%s\n' "$path"
                find -L "$path" -xdev -printf '%P\t%y\t%m\t%u\t%g\t%s\t%T@\n' -exec sha256sum {} \; 2>/dev/null \
                    | LC_ALL=C sort \
                    | sha256sum \
                    | cut -d ' ' -f 1
            done
            BASH),
        step: 'orb-178-proof-source-state',
        errorCode: 'orb-178.proof_source_state_failed',
    );

    return $result->stdout;
}

switch ($command) {
    case 'setup':
        if ($arguments !== []) {
            exit(64);
        }

        fixture_app();
        fixture_node();
        fixture_cluster();
        break;

    case 'seed':
        if (count($arguments) < 4 || count($arguments) > 5) {
            exit(64);
        }

        $instance = seed_instance(
            $arguments[0],
            $arguments[1],
            $arguments[2],
            $arguments[3],
            ($arguments[4] ?? '') === '' ? null : $arguments[4],
        );
        echo json_encode(['id' => $instance->id], JSON_THROW_ON_ERROR), PHP_EOL;
        break;

    case 'state':
        if (count($arguments) !== 1) {
            exit(64);
        }

        echo json_encode(instance_state($arguments[0]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        break;

    case 'missing':
        if (count($arguments) !== 1) {
            exit(64);
        }

        if (AppInstance::query()->where('name', $arguments[0])->exists()) {
            exit(65);
        }
        break;

    case 'cleanup':
        if ($arguments === []) {
            exit(64);
        }

        cleanup_instances($arguments);
        break;

    case 'inspect':
        if (count($arguments) !== 2) {
            exit(64);
        }

        $inventory = app(DevelopmentAppInstanceSourceRemoval::class)->inspect(
            fixture_instance($arguments[0]),
            $arguments[1] === '1',
        );
        echo
            json_encode([
                'checkout_path' => $inventory->checkoutPath,
                'linked_worktree_paths' => $inventory->linkedWorktreePaths,
                'digest' => $inventory->digest,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            PHP_EOL
        ;
        break;

    case 'race':
        if (count($arguments) !== 3) {
            exit(64);
        }

        [$name, $mutation, $forceValue] = $arguments;
        $force = $forceValue === '1';
        $instance = fixture_instance($name)->load(['app', 'node']);
        $removal = app(DevelopmentAppInstanceSourceRemoval::class);
        $inventory = $removal->inspect($instance, $force);
        mutate_source($instance, $mutation, $inventory->startingCommit);

        if ($mutation === 'equivalent-origin') {
            $removal->remove($instance, $inventory, $force);
            cleanup_instances([$name]);
            break;
        }

        $beforeSourceState = source_state($instance, $mutation);

        try {
            $removal->remove($instance, $inventory, $force);
        } catch (RuntimeConvergenceException $exception) {
            $expected = $force ? 'instance.force_failed' : 'instance.remove_refused';

            if ($exception->errorCode !== $expected) {
                throw $exception;
            }

            if ($beforeSourceState !== source_state($instance, $mutation)) {
                throw new RuntimeConvergenceException(
                    step: 'orb-178-proof-source-state',
                    errorCode: 'orb-178.proof_source_state_changed',
                    message: "ORB-178 race changed source state: {$name} {$mutation}",
                );
            }

            break;
        }

        fwrite(STDERR, "ORB-178 race was not refused: {$name} {$mutation}\n");
        exit(65);

    default:
        fwrite(STDERR, "Unknown ORB-178 fixture command: {$command}\n");
        exit(64);
}
