<?php

declare(strict_types=1);

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\DeploymentLayout\DeploymentLayoutInventory;
use App\Domain\AppInstances\DeploymentLayout\ProductionLayoutConverter;
use App\Domain\AppInstances\DeploymentLayout\ProductionPhpRuntimeAdopter;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionReleaseLayout;
use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceDeploymentLayout;
use App\Models\Node;
use App\Models\Route;
use Closure;

beforeEach(function (): void {
    [$this->caller, $this->instance, $this->route] = orb217_deployment_api_fixture();
    $this->converter = new Orb217DeploymentConverter;
    $this->runtime = new Orb217RuntimeAdopter;
    $this->source = new Orb217DeploymentSourceLifecycle;
    $this->projection = new Orb217ProductionProjection;
    app()->instance(ProductionLayoutConverter::class, $this->converter);
    app()->instance(ProductionPhpRuntimeAdopter::class, $this->runtime);
    app()->instance(ProductionAppInstanceSourceLifecycle::class, $this->source);
    app()->instance(ProductionRouteProjector::class, $this->projection);
    app()->instance(ProductionReleaseLayout::class, new Orb217ReleaseLayout);
    app()->instance(AppInstanceEnvironmentReader::class, new Orb217EnvironmentReader);
    app()->instance(AppInstanceEnvironmentOperationLock::class, new Orb217OperationLock);
    app()->instance(ProcessAdmissionLock::class, new Orb217ProcessLock);
    app()->instance(DevelopmentProjectionOperationLock::class, new Orb217ProjectionLock);
});

it('converts one authorized flat production AppInstance and returns the current representation', function (): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson("/api/v1/instances/{$this->instance->id}/deployment-layout", [
            'sqlite_source_path' => 'storage/app.sqlite',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.id', $this->instance->id)
        ->assertJsonPath('data.checkout_path', "{$this->instance->production_home}/releases/initial")
        ->assertJsonPath('data.effective_root', "{$this->instance->production_home}/current/public");

    expect($response->json('meta.request_id'))->toBeString()->not->toBeEmpty();

    $record = AppInstanceDeploymentLayout::query()->sole();
    expect($record->step->value)
        ->toBe('completed')
        ->and($record->sqlite_source_path)
        ->toBe('storage/app.sqlite')
        ->and($record->inventory)
        ->not->toHaveKey('local_tuning')
        ->and($this->converter->calls)
        ->toBe([
            'preflight',
            'move-source',
            'sqlite-quiescence',
            'persistent-state',
            'runtime-tuning',
            'serving-validation',
            'validate',
            'validate',
        ])
        ->and($this->runtime->calls)
        ->toHaveCount(1)
        ->and($this->source->caddyPreparations)
        ->toBe(1)
        ->and($this->projection->publications)
        ->toBe(1)
        ->and(Activity::query()->where('command', 'instance:deployment-layout:prepare')->sole()->properties?->get('input'))
        ->toBe(['sqlite_selected' => true])
        ->and(Activity::query()->sole()->properties?->toJson())
        ->not->toContain('storage/app.sqlite');
});

it('keeps a completed retry read-only and requires the recorded SQLite selection', function (): void {
    $url = "/api/v1/instances/{$this->instance->id}/deployment-layout";
    $payload = ['sqlite_source_path' => 'database/legacy.sqlite'];

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])->postJson($url, $payload)->assertOk();
    $calls = $this->converter->calls;

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])->postJson($url, $payload)->assertOk();

    expect($this->converter->calls)
        ->toBe([...$calls, 'validate'])
        ->and($this->runtime->calls)
        ->toHaveCount(1)
        ->and($this->source->caddyPreparations)
        ->toBe(1)
        ->and($this->projection->publications)
        ->toBe(1);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
        ->assertConflict()
        ->assertJsonPath('error.code', 'deployment_layout.request_conflict');
});

it('rejects unknown malformed and wrongly typed input before conversion', function (string $body): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->instance->id}/deployment-layout",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

    $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    $activityInput = Activity::query()->sole()->properties?->get('input');
    expect($this->converter->calls)
        ->toBe([])
        ->and($activityInput === [] || $activityInput === ['sqlite_selected' => true])
        ->toBeTrue()
        ->and(json_encode($activityInput, JSON_THROW_ON_ERROR))
        ->not->toContain('sqlite_source_path', 'storage/', 'database.sqlite');
})->with([
    'unknown' => '{"path":"database.sqlite"}',
    'duplicate' => '{"sqlite_source_path":"one","sqlite_source_path":"two"}',
    'wrong type' => '{"sqlite_source_path":false}',
    'empty' => '{"sqlite_source_path":""}',
    'NUL byte' => '{"sqlite_source_path":"storage/app\\u0000.sqlite"}',
    'control character' => '{"sqlite_source_path":"storage/app\\u001f.sqlite"}',
    'malformed' => '{"sqlite_source_path":',
]);

it('enforces owning-Node access before inventory or environment access', function (): void {
    $denied = Node::query()->create([
        'name' => 'denied-layout-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.219',
        'wireguard_ip' => '10.44.0.219',
        'user' => 'orbit',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $denied->wireguard_ip])
        ->postJson("/api/v1/instances/{$this->instance->id}/deployment-layout")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    expect($this->converter->calls)->toBe([]);
});

/** @return array{Node, AppInstance, Route} */
function orb217_deployment_api_fixture(): array
{
    $caller = Node::query()->create([
        'name' => 'layout-gateway-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.217',
        'wireguard_ip' => '10.44.0.217',
        'user' => 'orbit',
    ]);
    $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node = Node::query()->create([
        'name' => 'layout-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.218',
        'wireguard_ip' => '10.44.0.218',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $app = OrbitApp::query()->create([
        'name' => 'Deployment layout API',
        'slug' => 'deployment-layout-api',
        'repository_url' => 'https://example.test/deployment-layout-api.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $home = "/home/orbit-app-{$app->id}";
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'production',
        'checkout_path' => $home,
        'production_home' => $home,
        'production_user' => "orbit-app-{$app->id}",
        'root' => 'public',
        'selected_php_version' => '8.5',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $instance->environmentValues()->create(['env_key' => 'KEY', 'env_value' => 'value']);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'deployment-layout-api.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return [$caller, $instance->fresh(['app', 'node']), $route];
}

final class Orb217DeploymentConverter implements ProductionLayoutConverter
{
    /** @var list<string> */
    public array $calls = [];

    public function preflight(
        AppInstance $appInstance,
        Route $route,
        string $expectedEnvironment,
        ?string $sqliteSourcePath,
    ): DeploymentLayoutInventory {
        $this->calls[] = 'preflight';
        $resolvedSqlite = $sqliteSourcePath === null
            ? null
            : "{$appInstance->production_home}/{$sqliteSourcePath}";
        $tuning = "[orbit-{$appInstance->production_user}]\npm = ondemand\n";

        return new DeploymentLayoutInventory(
            sourcePath: $appInstance->checkout_path,
            releasePath: "{$appInstance->production_home}/releases/initial",
            sourceIdentity: '1:2',
            gitHead: str_repeat('a', 40),
            gitStateHash: hash('sha256', ''),
            environmentHash: hash('sha256', $expectedEnvironment),
            sqliteSourcePath: $resolvedSqlite,
            sqliteHash: $resolvedSqlite === null ? null : hash('sha256', 'sqlite'),
            sqliteIdentity: $resolvedSqlite === null ? null : '8:217',
            localTuningHash: hash('sha256', $tuning),
            routeId: $route->id,
            routeNodeId: (int) $route->node_id,
            routeHostname: $route->hostname,
            routeStatus: $route->status->value,
            routeTargetsHash: hash('sha256', json_encode([
                ['app_instance_id' => $appInstance->id, 'position' => 0],
            ], JSON_THROW_ON_ERROR)),
            documentRoot: 'public',
            previousSocket: "/run/php/orbit-app-instance-{$appInstance->id}.sock",
        );
    }

    public function moveSource(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $this->calls[] = 'move-source';
    }

    public function assertSqliteQuiescent(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $this->calls[] = 'sqlite-quiescence';
    }

    public function placePersistentState(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $this->calls[] = 'persistent-state';
    }

    public function runtimeTuning(AppInstance $appInstance, DeploymentLayoutInventory $inventory): string
    {
        $this->calls[] = 'runtime-tuning';

        return "[orbit-{$appInstance->production_user}]\npm = ondemand\n";
    }

    public function validateServingAssociation(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $this->calls[] = 'serving-validation';
    }

    public function validatePlacedLayout(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $this->calls[] = 'validate';
    }
}

final class Orb217RuntimeAdopter implements ProductionPhpRuntimeAdopter
{
    /** @var list<string> */
    public array $calls = [];

    public function adopt(AppInstance $appInstance, string $initialLocalTuning): void
    {
        $this->calls[] = $initialLocalTuning;
    }
}

final class Orb217DeploymentSourceLifecycle implements ProductionAppInstanceSourceLifecycle
{
    public int $caddyPreparations = 0;

    public function prepareUser(AppInstance $appInstance): void {}

    public function prepareSource(AppInstance $appInstance, bool $allowExisting): void {}

    public function resolve(AppInstance $appInstance): DevelopmentSourceResolution
    {
        throw new LogicException('Not used by this test fake.');
    }

    public function inspectProfile(AppInstance $appInstance): DevelopmentSourceProfile
    {
        throw new LogicException('Not used by this test fake.');
    }

    public function prepareCaddyAccess(AppInstance $appInstance): void
    {
        $this->caddyPreparations++;
    }
}

final class Orb217ProductionProjection implements ProductionRouteProjector
{
    public int $publications = 0;

    public function prepareRuntime(AppInstance $appInstance, Route $route): void {}

    public function prepareCertificate(AppInstance $appInstance, Route $route): void {}

    public function prepareFirewall(AppInstance $appInstance): void {}

    public function publish(AppInstance $appInstance, Route $route): void
    {
        $this->publications++;
    }
}

final class Orb217ReleaseLayout implements ProductionReleaseLayout
{
    public function validateCurrent(AppInstance $appInstance): void {}

    public function clearCurrent(AppInstance $appInstance): void {}
}

final class Orb217EnvironmentReader implements AppInstanceEnvironmentReader
{
    public function read(AppInstanceEnvironmentContext $context): string
    {
        return "KEY=\"value\"\n";
    }
}

class Orb217OperationLock implements AppInstanceEnvironmentOperationLock
{
    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        return $operation();
    }
}

final class Orb217ProcessLock implements ProcessAdmissionLock
{
    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        return $operation();
    }
}

final class Orb217ProjectionLock implements DevelopmentProjectionOperationLock
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}
