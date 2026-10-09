<?php

declare(strict_types=1);

use App\Actions\Instances\CloneInstanceAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\CloneCandidateSource;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\InstanceCloneCandidateInspector;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionInstanceSourceLifecycle;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\Sqlite\InstanceSqliteSeeder;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSeedResult;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $this->caller = clone_api_node('clone-api-caller');
    $this->caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->candidateNode = clone_api_node('clone-api-candidate');
    $this->candidateNode->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->destinationNode = clone_api_node('clone-api-destination');
    $project = Project::query()->create([
        'name' => 'Clone API',
        'slug' => 'clone-api',
        'repository_url' => 'https://example.test/clone-api.git',
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $this->candidate = Instance::query()->create([
        'project_id' => $project->id,
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

    expect(Instance::query()->count())
        ->toBe(1)
        ->and(json_encode(Activity::query()->sole()->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
})->with([
    'malformed JSON' => ['{"node_id":'],
    'array body' => ['[]'],
    'duplicate member' => ['{"node_id":1,"node_id":2,"name":"target","preview_name":"preview"}'],
    'unknown member' => ['{"node_id":1,"name":"target","preview_name":"preview","unknown":"__SENTINEL__"}'],
    'target App' => ['{"node_id":1,"name":"target","preview_name":"preview","project_id":"__SENTINEL__"}'],
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

    expect(Instance::query()->count())->toBe(1);
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
    expect(Instance::query()->count())
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

    expect(Instance::query()->count())->toBe(1);
});

it('returns the ordinary created Instance and records the target without SQLite path disclosure', function (): void {
    $this->destinationNode->update(['tld' => 'prod.orbit']);
    $this->destinationNode->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $candidateRoute = Route::query()->create([
        'project_id' => $this->candidate->project_id,
        'node_id' => $this->candidateNode->id,
        'domain' => 'candidate.clone-api.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $candidateRoute->targets()->create([
        'instance_id' => $this->candidate->id,
        'position' => 0,
    ]);
    $candidateRoute->update(['status' => RouteStatus::Active]);
    $lock = new Orb198ApiEnvironmentLock;
    app()->instance(InstanceCloneCandidateInspector::class, new Orb198ApiCandidateInspector);
    app()->instance(InstanceEnvironmentOperationLock::class, $lock);
    app()->instance(AppDevSourceOperationLock::class, new Orb198ApiSourceLock);
    app()->instance(ProductionInstanceSourceLifecycle::class, new Orb198ApiProductionSource);
    app()->instance(InstanceOperationPreflight::class, new Orb198ApiEnvironmentPreflight);
    app()->instance(InstanceEnvironmentWriter::class, new Orb198ApiEnvironmentWriter);
    app()->instance(InstanceSqliteSeeder::class, new Orb198ApiSqliteSeeder);
    app()->instance(ProductionRouteProjector::class, new Orb198ApiProductionProjection);
    app()->instance(ProductionCloneRouteProjector::class, new Orb198ApiCloneProjection);
    app()->instance(DevelopmentProjectionOperationLock::class, new Orb198ApiProjectionOwner);
    app()->instance(CloneInstanceAction::class, app(CloneInstanceAction::class));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->cloneUrl, [
            'node_id' => $this->destinationNode->id,
            'name' => 'target',
            'preview_name' => 'shop.com',
        ]);

    $target = Instance::query()->where('name', 'target')->sole();
    $response
        ->assertCreated()
        ->assertJsonPath('data.id', $target->id)
        ->assertJsonPath('data.name', 'target')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonMissingPath('data.environment');

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

it('returns an active Cluster-scoped clone with the production Node TLD', function (): void {
    $this->destinationNode->update(['tld' => 'prod.orbit']);
    $this->destinationNode->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $cluster = Cluster::query()->create([
        'name' => 'clone-api-cluster',
        'tld' => 'cluster.orbit',
        'state' => ClusterState::Active,
    ]);
    $this->destinationNode->update(['cluster_id' => $cluster->id]);
    $router = clone_api_node('clone-api-router');
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $candidateRoute = Route::query()->create([
        'project_id' => $this->candidate->project_id,
        'node_id' => $this->candidateNode->id,
        'domain' => 'candidate.clone-api.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $candidateRoute->targets()->create([
        'instance_id' => $this->candidate->id,
        'position' => 0,
    ]);
    $candidateRoute->update(['status' => RouteStatus::Active]);
    app()->instance(InstanceCloneCandidateInspector::class, new Orb198ApiCandidateInspector);
    app()->instance(InstanceEnvironmentOperationLock::class, new Orb198ApiEnvironmentLock);
    app()->instance(AppDevSourceOperationLock::class, new Orb198ApiSourceLock);
    app()->instance(ProductionInstanceSourceLifecycle::class, new Orb198ApiProductionSource);
    app()->instance(InstanceOperationPreflight::class, new Orb198ApiEnvironmentPreflight);
    app()->instance(InstanceEnvironmentWriter::class, new Orb198ApiEnvironmentWriter);
    app()->instance(InstanceSqliteSeeder::class, new Orb198ApiSqliteSeeder);
    app()->instance(ProductionRouteProjector::class, new Orb198ApiProductionProjection);
    app()->instance(ProductionCloneRouteProjector::class, new Orb198ApiCloneProjection);
    app()->instance(DevelopmentProjectionOperationLock::class, new Orb198ApiProjectionOwner);
    app()->instance(CloneInstanceAction::class, app(CloneInstanceAction::class));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->cloneUrl, [
            'node_id' => $this->destinationNode->id,
            'name' => 'target',
            'preview_name' => 'shop.com',
        ]);

    $target = Instance::query()->where('name', 'target')->sole();
    $route = $target->routes->sole();
    $response
        ->assertCreated()
        ->assertJsonPath('data.id', $target->id)
        ->assertJsonPath('data.status', 'active');

    expect($route->domain)->toBe('shop.com.prod.orbit')
        ->and($route->cluster_id)->toBe($cluster->id)
        ->and($route->node_id)->toBeNull()
        ->and($route->targets)->toHaveCount(1);
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

final class Orb198ApiCandidateInspector implements InstanceCloneCandidateInspector
{
    public function inspect(Instance $candidate, string $targetBranch): CloneCandidateSource
    {
        $candidate->loadMissing('node');

        return new CloneCandidateSource(
            instanceId: $candidate->id,
            environment: $candidate->defaultAppEnv(),
            basePath: $candidate->checkout_path,
            executionUser: $candidate->node->user,
            branch: (string) $candidate->branch,
            commit: str_repeat('a', 40),
            node: $candidate->node,
        );
    }
}

final class Orb198ApiEnvironmentLock implements InstanceEnvironmentOperationLock
{
    public function run(array $instanceIds, Closure $operation): mixed
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

final class Orb198ApiProductionSource implements ProductionInstanceSourceLifecycle
{
    public function prepareUser(Instance $instance): void {}

    public function prepareSource(Instance $instance, bool $allowExisting): void {}

    public function resolve(Instance $instance): DevelopmentSourceResolution
    {
        return new DevelopmentSourceResolution((string) $instance->branch, str_repeat('b', 40));
    }

    public function inspectProfile(Instance $instance): DevelopmentSourceProfile
    {
        return new DevelopmentSourceProfile(null, false);
    }

    public function prepareCaddyAccess(Instance $instance): void {}
}

final class Orb198ApiEnvironmentPreflight implements InstanceOperationPreflight
{
    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void {}

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void {}
}

final class Orb198ApiEnvironmentWriter implements InstanceEnvironmentWriter
{
    public function write(InstanceEnvironmentContext $context, string $contents): InstanceEnvironmentWriteResult
    {
        return InstanceEnvironmentWriteResult::changed();
    }
}

final class Orb198ApiSqliteSeeder implements InstanceSqliteSeeder
{
    public function abandon(SqliteSeedPlacement $source, SqliteSeedPlacement $target, string $sourcePath): bool
    {
        throw new LogicException('Cloning retains its seed for identical retries.');
    }

    public function seed(SqliteSeedPlacement $source, SqliteSeedPlacement $target, string $sourcePath): SqliteSeedResult
    {
        return SqliteSeedResult::changed();
    }
}

final class Orb198ApiProductionProjection implements ProductionRouteProjector
{
    public function prepareRuntime(Instance $instance, Route $route): void {}

    public function prepareCertificate(Instance $instance, Route $route): void {}

    public function prepareFirewall(Instance $instance): void {}

    public function publish(Instance $instance, Route $route): void
    {
        throw new LogicException('Clone publication must use the split projector.');
    }
}

final class Orb198ApiCloneProjection implements ProductionCloneRouteProjector
{
    public function prepareWorkloadCaddy(Instance $instance, Route $route): void {}

    public function prepareRouterCertificate(Instance $instance, Route $route): void {}

    public function prepareRouteFirewall(Instance $instance, Route $route): void {}

    public function verifyWorkload(Instance $instance, Route $route): void {}

    public function prepareRouterCaddy(Instance $instance, Route $route): void {}

    public function prepareDns(Route $route): void {}
}

final class Orb198ApiProjectionOwner implements DevelopmentProjectionOperationLock
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}
