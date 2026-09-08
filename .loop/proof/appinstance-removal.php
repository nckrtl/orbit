<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
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
    $errorCode = $exception instanceof RuntimeConvergenceException || $exception instanceof ResourceOperationException
        ? $exception->errorCode
        : 'fixture.command_failed';
    fwrite(STDERR, "ORB-181 fixture failed: {$errorCode}\n");
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
            ->map(static function (AppInstanceRemovalMember $value): array {
                $route = $value->route_id === null
                    ? null
                    : Route::query()->with('targets')->find($value->route_id);

                return [
                    'name' => $value->name,
                    'layout' => $value->source_layout,
                    'position' => $value->position,
                    'route_id' => $value->route_id,
                    'route_exists' => $route instanceof Route,
                    'route_targets' => $route?->targets->pluck('app_instance_id')->all() ?? [],
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
                ];
            })
            ->values()
            ->all(),
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

    case 'install-final-trigger':
        if ($arguments !== []) {
            exit(64);
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER orb181_fail_final_completion
            BEFORE UPDATE OF status ON app_instance_removals
            WHEN NEW.status = 'completed'
            BEGIN
                SELECT RAISE(ABORT, 'Injected final completion failure.');
            END
            SQL);
        break;

    case 'drop-final-trigger':
        if ($arguments !== []) {
            exit(64);
        }

        DB::unprepared('DROP TRIGGER IF EXISTS orb181_fail_final_completion');
        break;

    default:
        fwrite(STDERR, "Unknown ORB-181 fixture command: {$command}\n");
        exit(64);
}
