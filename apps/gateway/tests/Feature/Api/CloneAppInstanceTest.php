<?php

declare(strict_types=1);

use App\Actions\AppInstances\CloneAppInstanceAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceCloneCandidateInspector;
use App\Domain\AppInstances\CloneCandidateSource;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriteResult;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionCloneRouteProjector;
use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\AppInstances\Sqlite\AppInstanceSqliteSeeder;
use App\Domain\AppInstances\Sqlite\SqliteSeedPlacement;
use App\Domain\AppInstances\Sqlite\SqliteSeedResult;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

beforeEach(function (): void {
    $this->caller = clone_api_node('clone-api-caller');
    $this->caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->candidateNode = clone_api_node('clone-api-candidate');
    $this->destinationNode = clone_api_node('clone-api-destination');
    $app = OrbitApp::query()->create([
        'name' => 'Clone API',
        'slug' => 'clone-api',
        'repository_url' => 'https://example.test/clone-api.git',
        'default_branch' => 'main',
    ]);
    $this->candidate = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->candidateNode->id,
        'name' => 'candidate',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/clone-api/candidate',
        'branch' => 'main',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $this->cloneUrl = "/api/v1/instances/{$this->candidate->id}/clone";
});

it('returns 422 for malformed duplicate unknown or forbidden clone input before mutation', function (
    string $body,
): void {
    $sentinel = 'CLONE_API_INPUT_SENTINEL';

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            $this->cloneUrl,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: str_replace('__SENTINEL__', $sentinel, $body),
        );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(AppInstance::query()->count())
        ->toBe(1)
        ->and(json_encode(Activity::query()->sole()->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
})->with([
    'malformed JSON' => ['{"node_id":'],
    'array body' => ['[]'],
    'duplicate member' => ['{"node_id":1,"node_id":2,"name":"target","preview_name":"preview"}'],
    'unknown member' => ['{"node_id":1,"name":"target","preview_name":"preview","unknown":"__SENTINEL__"}'],
    'target App' => ['{"node_id":1,"name":"target","preview_name":"preview","app_id":"__SENTINEL__"}'],
    'commit SHA' => ['{"node_id":1,"name":"target","preview_name":"preview","commit_sha":"__SENTINEL__"}'],
    'production user' => ['{"node_id":1,"name":"target","preview_name":"preview","user":"__SENTINEL__"}'],
    'destination path' => ['{"node_id":1,"name":"target","preview_name":"preview","destination_path":"__SENTINEL__"}'],
    'missing required members' => ['{}'],
    'malformed Node ID' => ['{"node_id":"bad","name":"target","preview_name":"preview"}'],
    'signed Node ID' => ['{"node_id":"+3","name":"target","preview_name":"preview"}'],
    'boolean Node ID' => ['{"node_id":true,"name":"target","preview_name":"preview"}'],
    'invalid target name' => ['{"node_id":1,"name":"Not Normalized","preview_name":"preview"}'],
    'invalid preview name' => ['{"node_id":1,"name":"target","preview_name":"../preview"}'],
    'invalid branch' => ['{"node_id":1,"name":"target","preview_name":"preview","branch":"../main"}'],
    'relative SQLite path' => ['{"node_id":1,"name":"target","preview_name":"preview","sqlite_source_path":"database.sqlite"}'],
]);

it('returns 403 for malformed destination input when the caller lacks candidate Node access', function (
    mixed $nodeId,
): void {
    $caller = clone_api_node('clone-api-unprivileged-caller');

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson($this->cloneUrl, [
            'node_id' => $nodeId,
            'name' => 'target',
            'preview_name' => 'preview',
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertJsonPath('error.details.serving_node.id', $this->candidateNode->id);

    expect(AppInstance::query()->count())->toBe(1);
})->with([
    'signed integer string' => '+3',
    'boolean' => true,
]);

it('returns 403 before cloning when the caller lacks destination Node access', function (): void {
    $caller = clone_api_node('clone-api-direct-caller');
    $caller->accessibleNodes()->attach($this->candidateNode);
    $sqliteSourcePath = '/srv/orbit/apps/clone-api/candidate/CLONE_SQLITE_PATH_SENTINEL.sqlite';

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson($this->cloneUrl, [
            'node_id' => $this->destinationNode->id,
            'name' => 'target',
            'preview_name' => 'preview',
            'branch' => 'release',
            'sqlite_source_path' => $sqliteSourcePath,
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertJsonPath('error.details.serving_node.id', $this->destinationNode->id);

    $activity = Activity::query()->where('command', 'instance:clone')->sole();
    expect(AppInstance::query()->count())
        ->toBe(1)
        ->and($activity->properties?->get('input'))
        ->toBe([
            'node_id' => $this->destinationNode->id,
            'name' => 'target',
            'preview_name' => 'preview',
            'branch' => 'release',
            'sqlite_selected' => true,
        ])
        ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sqliteSourcePath, 'sqlite_source_path');
});

it('returns 404 for a missing candidate before clone execution', function (): void {
    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson('/api/v1/instances/999999/clone', [
            'node_id' => $this->destinationNode->id,
            'name' => 'target',
            'preview_name' => 'preview',
        ])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');

    expect(AppInstance::query()->count())->toBe(1);
});

it('returns the ordinary created AppInstance and records the target without SQLite path disclosure', function (): void {
    $this->destinationNode->update(['tld' => 'prod.orbit']);
    $this->destinationNode->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $candidateRoute = Route::query()->create([
        'app_id' => $this->candidate->app_id,
        'node_id' => $this->candidateNode->id,
        'hostname' => 'candidate.clone-api.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $candidateRoute->targets()->create([
        'app_instance_id' => $this->candidate->id,
        'position' => 0,
    ]);
    $candidateRoute->update(['status' => RouteStatus::Active]);
    $lock = new Orb198ApiEnvironmentLock;
    app()->instance(AppInstanceCloneCandidateInspector::class, new Orb198ApiCandidateInspector);
    app()->instance(AppInstanceEnvironmentOperationLock::class, $lock);
    app()->instance(AppDevSourceOperationLock::class, new Orb198ApiSourceLock);
    app()->instance(ProductionAppInstanceSourceLifecycle::class, new Orb198ApiProductionSource);
    app()->instance(AppInstanceOperationPreflight::class, new Orb198ApiEnvironmentPreflight);
    app()->instance(AppInstanceEnvironmentWriter::class, new Orb198ApiEnvironmentWriter);
    app()->instance(AppInstanceSqliteSeeder::class, new Orb198ApiSqliteSeeder);
    app()->instance(ProductionRouteProjector::class, new Orb198ApiProductionProjection);
    app()->instance(ProductionCloneRouteProjector::class, new Orb198ApiCloneProjection);
    app()->instance(DevelopmentProjectionOperationLock::class, new Orb198ApiProjectionOwner);
    app()->instance(CloneAppInstanceAction::class, app(CloneAppInstanceAction::class));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->cloneUrl, [
            'node_id' => $this->destinationNode->id,
            'name' => 'target',
            'preview_name' => 'shop.com',
        ]);

    $target = AppInstance::query()->where('name', 'target')->sole();
    $response
        ->assertCreated()
        ->assertJsonPath('data.id', $target->id)
        ->assertJsonPath('data.name', 'target')
        ->assertJsonPath('data.environment', 'production')
        ->assertJsonPath('data.status', 'active');

    $activity = Activity::query()->where('command', 'instance:clone')->sole();
    expect($activity->status)->toBe('succeeded')
        ->and($activity->subject_type)->toBe($target->getMorphClass())
        ->and($activity->subject_id)->toBe($target->id)
        ->and($activity->target_node_id)->toBe($this->destinationNode->id)
        ->and($activity->properties?->get('input'))->toBe([
            'node_id' => $this->destinationNode->id,
            'name' => 'target',
            'preview_name' => 'shop.com',
            'sqlite_selected' => false,
        ])
        ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))->not->toContain('sqlite_source_path');
});

function clone_api_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => '10.44.10.'.(Node::query()->count() + 2),
        'user' => 'orbit',
    ]);
}

final class Orb198ApiCandidateInspector implements AppInstanceCloneCandidateInspector
{
    public function inspect(AppInstance $candidate, string $targetBranch): CloneCandidateSource
    {
        $candidate->loadMissing('node');

        return new CloneCandidateSource(
            appInstanceId: $candidate->id,
            environment: $candidate->environment,
            basePath: $candidate->checkout_path,
            executionUser: $candidate->node->user,
            branch: (string) $candidate->branch,
            commit: str_repeat('a', 40),
            node: $candidate->node,
        );
    }
}

final class Orb198ApiEnvironmentLock implements AppInstanceEnvironmentOperationLock
{
    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        return $operation();
    }
}

final class Orb198ApiSourceLock implements AppDevSourceOperationLock
{
    public function synchronized(int $nodeId, Closure $operation): mixed
    {
        return $operation();
    }
}

final class Orb198ApiProductionSource implements ProductionAppInstanceSourceLifecycle
{
    public function prepareUser(AppInstance $appInstance): void {}

    public function prepareSource(AppInstance $appInstance, bool $allowExisting): void {}

    public function resolve(AppInstance $appInstance): DevelopmentSourceResolution
    {
        return new DevelopmentSourceResolution((string) $appInstance->branch, str_repeat('b', 40));
    }

    public function inspectProfile(AppInstance $appInstance): DevelopmentSourceProfile
    {
        return new DevelopmentSourceProfile(null, false);
    }

    public function prepareCaddyAccess(AppInstance $appInstance): void {}
}

final class Orb198ApiEnvironmentPreflight implements AppInstanceOperationPreflight
{
    public function assertEnvironmentReadable(AppInstanceEnvironmentContext $context): void {}

    public function assertEnvironmentWritable(AppInstanceEnvironmentContext $context, int $requiredCapacityBytes): void {}
}

final class Orb198ApiEnvironmentWriter implements AppInstanceEnvironmentWriter
{
    public function write(AppInstanceEnvironmentContext $context, string $contents): AppInstanceEnvironmentWriteResult
    {
        return AppInstanceEnvironmentWriteResult::changed();
    }
}

final class Orb198ApiSqliteSeeder implements AppInstanceSqliteSeeder
{
    public function seed(SqliteSeedPlacement $source, SqliteSeedPlacement $target, string $sourcePath): SqliteSeedResult
    {
        return SqliteSeedResult::changed();
    }
}

final class Orb198ApiProductionProjection implements ProductionRouteProjector
{
    public function prepareRuntime(AppInstance $appInstance, Route $route): void {}

    public function prepareCertificate(AppInstance $appInstance, Route $route): void {}

    public function prepareFirewall(AppInstance $appInstance): void {}

    public function publish(AppInstance $appInstance, Route $route): void
    {
        throw new LogicException('Clone publication must use the split projector.');
    }
}

final class Orb198ApiCloneProjection implements ProductionCloneRouteProjector
{
    public function prepareCaddy(AppInstance $appInstance, Route $route): void {}

    public function prepareDns(Route $route): void {}
}

final class Orb198ApiProjectionOwner implements DevelopmentProjectionOperationLock
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}
