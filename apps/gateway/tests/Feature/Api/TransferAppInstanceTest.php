<?php

declare(strict_types=1);

use App\Actions\AppInstances\TransferAppInstanceAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Sqlite\AppInstanceSqliteSeeder;
use App\Domain\AppInstances\Transfer\AppInstanceTransferRouteProjector;
use App\Domain\AppInstances\Transfer\AppInstanceTransferRuntime;
use App\Domain\AppInstances\Transfer\AppInstanceTransferSource;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Tests\Support\Orb245Accounts;
use Tests\Support\Orb245DestinationGuard;
use Tests\Support\Orb245EnvironmentLock;
use Tests\Support\Orb245EnvironmentReader;
use Tests\Support\Orb245EnvironmentWriter;
use Tests\Support\Orb245Projection;
use Tests\Support\Orb245SourceLock;
use Tests\Support\Orb245SqliteSeeder;
use Tests\Support\Orb245TransferRuntime;
use Tests\Support\Orb245TransferSource;

beforeEach(function (): void {
    $this->caller = transfer_api_node('transfer-api-caller');
    $this->caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $sourceCluster = Cluster::query()->create([
        'name' => 'transfer-api-source',
        'tld' => 'dev.orbit',
        'state' => ClusterState::Active,
    ]);
    $destinationCluster = Cluster::query()->create([
        'name' => 'transfer-api-destination',
        'tld' => 'other.orbit',
        'state' => ClusterState::Active,
    ]);
    $this->sourceNode = transfer_api_app_dev('transfer-api-source', $sourceCluster, '10.44.46.10');
    $this->destinationNode = transfer_api_app_dev('transfer-api-destination', $destinationCluster, '10.44.46.11');
    $app = OrbitApp::query()->create([
        'name' => 'Transfer API',
        'slug' => 'transfer-api',
        'repository_url' => 'https://example.test/transfer-api.git',
        'default_branch' => 'main',
    ]);
    $this->instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->sourceNode->id,
        'name' => 'web',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/transfer-api/web',
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $sourceCluster->id,
        'generation_basis_node_id' => $this->sourceNode->id,
        'domain' => 'web.transfer-api.dev.orbit',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'app_instance_id' => $this->instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);
    $this->transferUrl = "/api/v1/instances/{$this->instance->id}/transfer";
});

it('returns 422 for malformed duplicate unknown or forbidden transfer input before mutation', function (
    string $body,
): void {
    $sentinel = 'TRANSFER_API_INPUT_SENTINEL';

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            $this->transferUrl,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: str_replace('__SENTINEL__', $sentinel, $body),
        );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($this->instance->refresh()->node_id)
        ->toBe($this->sourceNode->id)
        ->and(json_encode(Activity::query()->sole()->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
})->with([
    'malformed JSON' => ['{"node_id":'],
    'array body' => ['[]'],
    'duplicate member' => ['{"node_id":1,"node_id":2}'],
    'unknown member' => ['{"node_id":1,"unknown":"__SENTINEL__"}'],
    'destination path' => ['{"node_id":1,"destination_path":"__SENTINEL__"}'],
    'missing required members' => ['{}'],
    'malformed Node ID' => ['{"node_id":"bad"}'],
    'signed Node ID' => ['{"node_id":"+3"}'],
    'boolean Node ID' => ['{"node_id":true}'],
    'invalid target name' => ['{"node_id":1,"name":"Not Normalized"}'],
    'relative SQLite path' => ['{"node_id":1,"sqlite_source_path":"database.sqlite"}'],
]);

it('returns 403 before transfer when the caller lacks destination Node access', function (): void {
    $caller = transfer_api_node('transfer-api-direct-caller');
    $caller->accessibleNodes()->attach($this->sourceNode);
    $sqliteSourcePath = '/srv/orbit/apps/transfer-api/web/TRANSFER_SQLITE_PATH_SENTINEL.sqlite';

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson($this->transferUrl, [
            'node_id' => $this->destinationNode->id,
            'name' => 'preview',
            'sqlite_source_path' => $sqliteSourcePath,
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertJsonPath('error.details.serving_node.id', $this->destinationNode->id);

    $activity = Activity::query()->where('command', 'instance:transfer')->sole();
    expect($this->instance->refresh()->node_id)
        ->toBe($this->sourceNode->id)
        ->and($activity->properties?->get('input'))
        ->toBe([
            'node_id' => $this->destinationNode->id,
            'name' => 'preview',
            'sqlite_selected' => true,
        ])
        ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sqliteSourcePath, 'sqlite_source_path');
});

it('transfers the AppInstance and records sanitized activity', function (): void {
    transfer_api_bind_fakes();

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, [
            'node_id' => $this->destinationNode->id,
        ]);

    $instance = $this->instance->refresh()->load('routes');
    $response
        ->assertCreated()
        ->assertJsonPath('data.id', $instance->id)
        ->assertJsonPath('data.node_id', $this->destinationNode->id)
        ->assertJsonPath('data.name', 'web')
        ->assertJsonPath('data.checkout_path', '/srv/orbit/apps/transfer-api/web')
        ->assertJsonPath('data.domain', 'web.transfer-api.other.orbit')
        ->assertJsonPath('data.transfer.status', 'completed')
        ->assertJsonPath('data.transfer.cleanup_completed', true)
        ->assertJsonPath('data.transfer.sqlite_selected', false);

    $activity = Activity::query()->where('command', 'instance:transfer')->sole();
    expect($activity->status)->toBe('succeeded')
        ->and($activity->subject_id)->toBe($instance->id)
        ->and($activity->properties?->get('input'))
        ->toBe([
            'node_id' => $this->destinationNode->id,
            'sqlite_selected' => false,
        ]);
});

it('returns 200 for an identical completed transfer request', function (): void {
    transfer_api_bind_fakes();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id])
        ->assertCreated();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id])
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'completed');
});

function transfer_api_node(string $name): Node
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

function transfer_api_app_dev(string $name, Cluster $cluster, string $address): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => null,
        'cluster_id' => $cluster->id,
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => $address,
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $router = transfer_api_node("{$name}-router");
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function transfer_api_bind_fakes(): void
{
    app()->instance(ManagedUserAccountResolver::class, new Orb245Accounts);
    app()->instance(AppInstanceDestinationGuard::class, new Orb245DestinationGuard);
    app()->instance(AppInstanceEnvironmentOperationLock::class, new Orb245EnvironmentLock);
    app()->instance(AppDevSourceOperationLock::class, new Orb245SourceLock);
    app()->instance(AppInstanceTransferSource::class, new Orb245TransferSource);
    app()->instance(AppInstanceTransferRuntime::class, new Orb245TransferRuntime);
    app()->instance(AppInstanceSqliteSeeder::class, new Orb245SqliteSeeder);
    app()->instance(AppInstanceEnvironmentReader::class, new Orb245EnvironmentReader);
    app()->instance(AppInstanceEnvironmentWriter::class, new Orb245EnvironmentWriter);
    app()->instance(DevelopmentRouteProjector::class, new Orb245Projection);
    app()->instance(AppInstanceTransferRouteProjector::class, new Orb245Projection);
    app()->instance(TransferAppInstanceAction::class, app(TransferAppInstanceAction::class));
}
