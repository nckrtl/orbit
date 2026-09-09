<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Models\AppInstance;
use App\Models\Route;
use Illuminate\Contracts\Console\Kernel;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';
$laravel = require '/home/orbit/orbit/apps/gateway/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

const PROOF_INSTANCE = 'orb168-proof';
const PROOF_HOSTNAME = 'orb168-proof.orbit';
const PROOF_STATE = '/home/orbit/.orbit/orb168-source-profile-state.json';

$command = $argv[1] ?? '';
$arguments = array_slice($argv, 2);

set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, "ORB-168 fixture failed: {$exception->getMessage()}\n");
    exit(70);
});

function sampleInstance(): AppInstance
{
    return AppInstance::query()->where('name', 'e2e-dev')->with('routes')->sole();
}

function proofInstance(): AppInstance
{
    return AppInstance::query()->where('name', PROOF_INSTANCE)->with('routes.targets')->sole();
}

function proofRoute(): Route
{
    return proofInstance()->routes->sole();
}

/** @return array<string, int|string|null> */
function recordedIdentity(): array
{
    $value = json_decode((string) file_get_contents(PROOF_STATE), true, 16, JSON_THROW_ON_ERROR);

    if (! is_array($value)) {
        throw new RuntimeException('Recorded proof identity is malformed.');
    }

    return $value;
}

function assertIdentity(AppInstance $instance, Route $route): void
{
    $recorded = recordedIdentity();
    $current = [
        'app_id' => $instance->app_id,
        'node_id' => $instance->node_id,
        'instance_id' => $instance->id,
        'route_id' => $route->id,
        'checkout_path' => $instance->checkout_path,
        'branch' => $instance->branch,
        'starting_commit' => $instance->starting_commit,
        'cluster_id' => $route->cluster_id,
        'hostname' => $route->hostname,
    ];

    if ($current !== $recorded) {
        throw new RuntimeException('AppInstance, source, placement, or Route identity changed.');
    }
}

function nullablePhp(string $value): ?string
{
    return $value === 'none' ? null : $value;
}

function nullableLaravel(string $value): ?bool
{
    return match ($value) {
        'true' => true,
        'false' => false,
        'legacy' => null,
        default => throw new InvalidArgumentException('Invalid Laravel profile value.'),
    };
}

function setCheckpoint(string $checkpoint, ?string $phpVersion, ?bool $laravel): void
{
    if (! in_array($checkpoint, ['php-selected', 'url-configured'], true)) {
        throw new InvalidArgumentException('Invalid retained checkpoint.');
    }

    $instance = proofInstance();
    $route = $instance->routes->sole();
    assertIdentity($instance, $route);
    $instance->update([
        'status' => AppInstanceState::SourceResolved,
        'selected_php_version' => $phpVersion,
        'source_is_laravel' => $laravel,
        'provisioning_step' => $checkpoint,
        'failed_step' => null,
        'error_code' => null,
    ]);
    $route->update([
        'status' => RouteStatus::Pending,
        'failed_step' => null,
        'error_code' => null,
    ]);
}

function assertCheckpoint(
    string $checkpoint,
    ?string $phpVersion,
    ?bool $laravel,
    RouteStatus $routeStatus,
): void {
    $instance = proofInstance();
    $route = $instance->routes->sole();
    assertIdentity($instance, $route);

    if (
        $instance->status !== AppInstanceState::SourceResolved
        || $instance->selected_php_version !== $phpVersion
        || $instance->source_is_laravel !== $laravel
        || $instance->provisioning_step !== $checkpoint
        || $route->status !== $routeStatus
    ) {
        throw new RuntimeException('Retained source profile checkpoint changed unexpectedly.');
    }

    if ($routeStatus === RouteStatus::Failed) {
        if (
            $instance->failed_step !== 'source-classification'
            || $instance->error_code !== 'app-dev.source_evidence_changed'
            || $route->failed_step !== 'source-classification'
            || $route->error_code !== 'app-dev.source_evidence_changed'
        ) {
            throw new RuntimeException('Source profile refusal evidence is incomplete.');
        }

        return;
    }

    if (
        $instance->failed_step !== null
        || $instance->error_code !== null
        || $route->failed_step !== null
        || $route->error_code !== null
    ) {
        throw new RuntimeException('Checkpoint carries unexpected failure evidence.');
    }
}

function assertActive(?string $phpVersion, bool $laravel): void
{
    $instance = proofInstance();
    $route = $instance->routes->sole();
    assertIdentity($instance, $route);

    if (
        $instance->status !== AppInstanceState::Active
        || $instance->selected_php_version !== $phpVersion
        || $instance->source_is_laravel !== $laravel
        || $instance->provisioning_step !== 'active'
        || $instance->failed_step !== null
        || $instance->error_code !== null
        || $route->status !== RouteStatus::Active
        || $route->failed_step !== null
        || $route->error_code !== null
    ) {
        throw new RuntimeException('AppInstance did not reach the expected Active profile.');
    }
}

switch ($command) {
    case 'setup':
        if (AppInstance::query()->where('name', PROOF_INSTANCE)->exists()) {
            throw new RuntimeException('ORB-168 proof AppInstance already exists.');
        }

        $sample = sampleInstance();
        $sampleRoute = $sample->routes->sole();
        if (
            $sample->status !== AppInstanceState::Active
            || $sampleRoute->status !== RouteStatus::Active
            || ! is_string($sample->branch)
            || ! is_string($sample->starting_commit)
        ) {
            throw new RuntimeException('Sample development AppInstance is not ready.');
        }

        $checkout = dirname($sample->checkout_path).'/'.PROOF_INSTANCE;
        $instance = AppInstance::query()->create([
            'app_id' => $sample->app_id,
            'node_id' => $sample->node_id,
            'name' => PROOF_INSTANCE,
            'environment' => 'development',
            'source_layout' => AppInstanceSourceLayout::Checkout,
            'checkout_path' => $checkout,
            'root' => null,
            'branch' => PROOF_INSTANCE,
            'starting_commit' => $sample->starting_commit,
            'selected_php_version' => '8.5',
            'source_is_laravel' => null,
            'provisioning_step' => 'url-configured',
            'status' => AppInstanceState::SourceResolved,
        ]);
        $route = Route::query()->create([
            'app_id' => $sample->app_id,
            'cluster_id' => $sampleRoute->cluster_id,
            'hostname' => PROOF_HOSTNAME,
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
        $state = [
            'app_id' => $instance->app_id,
            'node_id' => $instance->node_id,
            'instance_id' => $instance->id,
            'route_id' => $route->id,
            'checkout_path' => $instance->checkout_path,
            'branch' => $instance->branch,
            'starting_commit' => $instance->starting_commit,
            'cluster_id' => $route->cluster_id,
            'hostname' => $route->hostname,
        ];
        file_put_contents(PROOF_STATE, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
        assertIdentity($instance->refresh(), $route->refresh());
        break;

    case 'identity':
        $instance = proofInstance();
        $route = $instance->routes->sole();
        assertIdentity($instance, $route);
        echo json_encode(recordedIdentity(), JSON_THROW_ON_ERROR), "\n";
        break;

    case 'checkpoint':
        if (count($arguments) !== 3) {
            throw new InvalidArgumentException('checkpoint expects checkpoint, PHP version, and Laravel evidence.');
        }

        setCheckpoint($arguments[0], nullablePhp($arguments[1]), nullableLaravel($arguments[2]));
        break;

    case 'assert-refusal':
        if (count($arguments) !== 3) {
            throw new InvalidArgumentException('assert-refusal expects checkpoint, PHP version, and Laravel evidence.');
        }

        assertCheckpoint(
            $arguments[0],
            nullablePhp($arguments[1]),
            nullableLaravel($arguments[2]),
            RouteStatus::Failed,
        );
        break;

    case 'assert-checkpoint':
        if (count($arguments) !== 3) {
            throw new InvalidArgumentException('assert-checkpoint expects checkpoint, PHP version, and Laravel evidence.');
        }

        assertCheckpoint(
            $arguments[0],
            nullablePhp($arguments[1]),
            nullableLaravel($arguments[2]),
            RouteStatus::Pending,
        );
        break;

    case 'assert-active':
        if (count($arguments) !== 2) {
            throw new InvalidArgumentException('assert-active expects PHP version and Laravel evidence.');
        }

        $laravel = nullableLaravel($arguments[1]);
        if (! is_bool($laravel)) {
            throw new InvalidArgumentException('Active source profiles must be complete.');
        }
        assertActive(nullablePhp($arguments[0]), $laravel);
        break;

    case 'configure-url':
        $instance = proofInstance();
        $route = $instance->routes->sole();
        assertIdentity($instance, $route);
        app(DevelopmentAppInstanceConfigurator::class)->configureLaravelUrl(
            $instance,
            'https://'.PROOF_HOSTNAME,
        );
        break;

    case 'assert-removed':
        $identity = recordedIdentity();
        if (
            AppInstance::query()->whereKey($identity['instance_id'])->exists()
            || Route::query()->whereKey($identity['route_id'])->exists()
            || ! AppInstance::query()->where('name', 'e2e-dev')->where('status', AppInstanceState::Active)->exists()
        ) {
            throw new RuntimeException('Recovered AppInstance removal did not preserve the sample application.');
        }
        break;

    default:
        throw new InvalidArgumentException('Unknown ORB-168 fixture command.');
}
