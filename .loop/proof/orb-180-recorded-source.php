<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceRemovalStatus;
use App\Domain\AppInstances\AppInstanceRemovalStep;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceFinalizer;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\AppInstanceRemovalMember;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

function fixtureApp(): OrbitApp
{
    return OrbitApp::query()->where('slug', 'laravel-typed')->sole();
}

function fixtureNode(): Node
{
    return Node::query()->where('name', 'app-dev')->sole();
}

function fixtureCluster(): Cluster
{
    return Cluster::query()->where('state', 'active')->sole();
}

function fixtureInstance(string $name): AppInstance
{
    return AppInstance::query()->where('name', $name)->with(['app', 'node'])->sole();
}

function fixtureMember(string $name): AppInstanceRemovalMember
{
    return AppInstanceRemovalMember::query()
        ->where('name', $name)
        ->with('removal')
        ->latest('id')
        ->sole();
}

function seedInstance(string $name, string $layout, string $branch, string $commit): AppInstance
{
    $app = fixtureApp();

    return AppInstance::query()
        ->create([
            'app_id' => $app->id,
            'node_id' => fixtureNode()->id,
            'name' => $name,
            'environment' => 'development',
            'source_layout' => $layout,
            'checkout_path' => "/home/orbit/apps/{$app->slug}/{$name}",
            'root' => null,
            'branch' => $branch,
            'starting_commit' => $commit,
            'selected_php_version' => '8.5',
            'status' => AppInstanceState::SourceResolved,
        ])
        ->load(['app', 'node']);
}

function recordSource(string $name, bool $force): AppInstanceRemovalMember
{
    $instance = fixtureInstance($name);
    $inventory = app(DevelopmentAppInstanceSourceRemoval::class)->inspect($instance, $force);
    $route = Route::query()->create([
        'app_id' => $instance->app_id,
        'cluster_id' => fixtureCluster()->id,
        'hostname' => "{$name}.orbit",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => AppInstanceState::Active]);
    $removal = AppInstanceRemoval::query()->create([
        'id' => (string) Str::uuid(),
        'requested_app_instance_id' => $instance->id,
        'requested_name' => $instance->name,
        'force' => $force,
        'inventory_digest' => $inventory->digest,
        'total' => 1,
        'status' => AppInstanceRemovalStatus::Removing,
        'current_step' => AppInstanceRemovalStep::SourcePreparation,
    ]);
    $member = $removal
        ->members()
        ->create([
            'position' => 0,
            'app_instance_id' => $instance->id,
            'app_id' => $instance->app_id,
            'node_id' => $instance->node_id,
            'route_id' => $route->id,
            'name' => $instance->name,
            'environment' => $instance->environment,
            'source_layout' => $inventory->layout,
            'repository_identity' => $inventory->repositoryIdentity,
            'checkout_path' => $inventory->checkoutPath,
            'root' => $instance->effectiveRoot(),
            'branch' => $inventory->branch,
            'starting_commit' => $inventory->startingCommit,
            'common_repository_path' => $inventory->commonRepositoryPath,
            'source_identity' => $inventory->sourceIdentity,
            'linked_worktree_paths' => $inventory->linkedWorktreePaths,
            'source_digest' => $inventory->digest,
        ]);
    $instance->update(['status' => AppInstanceState::Removing]);
    app(DevelopmentAppInstanceSourceFinalizer::class)->prepare($member);
    $member->update(['source_prepared_at' => now()]);

    return $member->refresh()->load('removal');
}

/** @return array<string, mixed> */
function memberEvidence(AppInstanceRemovalMember $member): array
{
    $instance = fixtureInstance($member->name)->load('routes.targets');
    $route = $instance->routes->sole();
    $root = dirname(dirname((string) $member->checkout_path));
    $stem = "{$root}/.orbit-removals/{$member->app_instance_removal_id}.{$member->id}";

    return [
        'member_id' => $member->id,
        'operation_id' => $member->app_instance_removal_id,
        'layout' => $member->source_layout,
        'checkout_path' => $member->checkout_path,
        'common_repository_path' => $member->common_repository_path,
        'source_identity' => $member->source_identity,
        'source_digest' => $member->source_digest,
        'branch' => $member->branch,
        'starting_commit' => $member->starting_commit,
        'journal' => "{$stem}.journal",
        'quarantine' => "{$stem}.quarantine",
        'recovery' => "{$stem}.recovery",
        'receipt_path' => "{$stem}.receipt",
        'receipt' => hash(
            'sha256',
            "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
        ),
        'instance_status' => $instance->status->value,
        'route_status' => $route->status->value,
        'route_id' => $route->id,
        'route_target' => $route->targets->sole()->app_instance_id,
    ];
}

function expectConflict(Closure $operation): void
{
    try {
        $operation();
    } catch (RuntimeConvergenceException $exception) {
        if ($exception->errorCode !== 'instance.removal_conflict') {
            throw $exception;
        }

        return;
    }

    throw new RuntimeException('The recorded-source operation was not refused.');
}

function cleanup(array $names): void
{
    $instances = AppInstance::query()->whereIn('name', $names)->with('routes.targets')->get();

    foreach ($instances as $instance) {
        AppInstanceRemoval::query()
            ->where('requested_app_instance_id', $instance->id)
            ->each(
                static function (AppInstanceRemoval $removal): void {
                    $removal->members()->delete();
                    $removal->delete();
                },
            );

        foreach ($instance->routes as $route) {
            $route->targets()->delete();
            $route->delete();
        }

        $instance->delete();
    }
}

function runFixture(array $argv): void
{
    $command = $argv[1] ?? '';
    $arguments = array_slice($argv, 2);

    switch ($command) {
        case 'setup':
            if ($arguments !== []) {
                exit(64);
            }
            fixtureApp();
            fixtureNode();
            fixtureCluster();
            break;

        case 'seed':
            if (count($arguments) !== 4) {
                exit(64);
            }
            $instance = seedInstance($arguments[0], $arguments[1], $arguments[2], $arguments[3]);
            echo json_encode(['id' => $instance->id], JSON_THROW_ON_ERROR), PHP_EOL;
            break;

        case 'record':
            if (count($arguments) !== 2) {
                exit(64);
            }
            echo
                json_encode(
                    memberEvidence(recordSource($arguments[0], $arguments[1] === '1')),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
                PHP_EOL
            ;
            break;

        case 'state':
            if (count($arguments) !== 1) {
                exit(64);
            }
            echo
                json_encode(
                    memberEvidence(fixtureMember($arguments[0])),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
                PHP_EOL
            ;
            break;

        case 'revalidate':
            if (count($arguments) !== 1) {
                exit(64);
            }
            echo
                app(DevelopmentAppInstanceSourceFinalizer::class)->revalidate(fixtureMember($arguments[0]))->value,
                PHP_EOL
            ;
            break;

        case 'finalize':
            if (count($arguments) !== 1) {
                exit(64);
            }
            echo app(DevelopmentAppInstanceSourceFinalizer::class)->finalize(fixtureMember($arguments[0])), PHP_EOL;
            break;

        case 'expect-revalidate-refusal':
            if (count($arguments) !== 1) {
                exit(64);
            }
            expectConflict(static fn () => app(DevelopmentAppInstanceSourceFinalizer::class)
                ->revalidate(fixtureMember($arguments[0])));
            break;

        case 'expect-finalize-refusal':
            if (count($arguments) !== 1) {
                exit(64);
            }
            expectConflict(static fn () => app(DevelopmentAppInstanceSourceFinalizer::class)
                ->finalize(fixtureMember($arguments[0])));
            break;

        case 'expect-record-refusal':
            if (count($arguments) !== 1) {
                exit(64);
            }
            expectConflict(static fn () => recordSource($arguments[0], true));
            break;

        case 'mutate-layout':
            if (count($arguments) !== 2) {
                exit(64);
            }
            fixtureInstance($arguments[0])->update(['source_layout' => $arguments[1]]);
            break;

        case 'cleanup':
            if ($arguments === []) {
                exit(64);
            }
            cleanup($arguments);
            break;

        default:
            fwrite(STDERR, "Unknown ORB-180 fixture command: {$command}\n");
            exit(64);
    }
}

try {
    runFixture($argv);
} catch (Throwable $exception) {
    $errorCode = $exception instanceof RuntimeConvergenceException
        ? $exception->errorCode
        : 'fixture.command_failed';
    fwrite(STDERR, "ORB-180 fixture failed: {$errorCode}: {$exception->getMessage()}\n");
    exit(70);
}
