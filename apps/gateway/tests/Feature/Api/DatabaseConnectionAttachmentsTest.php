<?php

declare(strict_types=1);

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriteResult;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;

const DATABASE_ATTACHMENT_SECRET = 'db-attach-secret-7c21';

beforeEach(function (): void {
    [$this->caller, $this->instance, $this->route] = database_attachment_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    app()->instance(AppInstanceOperationPreflight::class, new DatabaseAttachmentAccess);
    app()->instance(AppInstanceEnvironmentReader::class, new DatabaseAttachmentAccess);
    app()->instance(AppInstanceEnvironmentWriter::class, new DatabaseAttachmentAccess);
});

it('attaches mysql keys into stored AppInstance env and redacts the password', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_ATTACHMENT_SECRET,
    ])->assertCreated();

    $attach = $this->call(
        'PUT',
        "/api/v1/instances/{$this->instance->id}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    );

    $attach
        ->assertOk()
        ->assertJsonPath('data.app_instance_id', $this->instance->id)
        ->assertJsonPath('data.slug', 'app')
        ->assertJsonPath('data.prefix', 'DB')
        ->assertJsonPath('data.operation', 'attach')
        ->assertJsonPath('data.changed', true)
        ->assertJsonPath('data.host', 'db.example.test')
        ->assertJsonPath('data.port', 3306)
        ->assertJsonPath('data.keys', [
            'DB_CONNECTION',
            'DB_DATABASE',
            'DB_HOST',
            'DB_PASSWORD',
            'DB_PORT',
            'DB_USERNAME',
        ])
        ->assertJsonMissingPath('data.password');

    expect($attach->getContent())->not->toContain(DATABASE_ATTACHMENT_SECRET);
    expect(stored_env($this->instance))->toBe([
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => 'app',
        'DB_HOST' => 'db.example.test',
        'DB_PASSWORD' => DATABASE_ATTACHMENT_SECRET,
        'DB_PORT' => '3306',
        'DB_USERNAME' => 'app',
    ]);
    expect(DatabaseConnectionTarget::query()->sole()->prefix)->toBe('DB');

    $activity = Activity::query()->where('request_id', $attach->json('meta.request_id'))->sole();
    $encoded = json_encode($activity->properties?->toArray() ?? [], JSON_THROW_ON_ERROR);

    expect($activity->command)
        ->toBe('instance:database:add')
        ->and($activity->subject_type)
        ->toBe(AppInstance::class)
        ->and($activity->subject_id)
        ->toBe($this->instance->id)
        ->and($activity->target_node_id)
        ->toBe($this->instance->node_id)
        ->and($encoded)
        ->not->toContain(DATABASE_ATTACHMENT_SECRET)
        ->and(print_r($this->instance->environmentValues, true))
        ->not->toContain(DATABASE_ATTACHMENT_SECRET);

    $this->deleteJson('/api/v1/database-connections/app')
        ->assertConflict()
        ->assertJsonPath('error.code', 'database.connection_attached');
});

it('writes sqlite-appropriate keys and clears leftover host keys on prefix reuse', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_ATTACHMENT_SECRET,
    ])->assertCreated();
    $this->call(
        'PUT',
        "/api/v1/instances/{$this->instance->id}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )->assertOk();

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'local',
        'driver' => 'sqlite',
        'path' => '/var/lib/app/database.sqlite',
    ])->assertCreated();

    $this->call(
        'PUT',
        "/api/v1/instances/{$this->instance->id}/database-connections/local",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )
        ->assertOk()
        ->assertJsonPath('data.keys', [
            'DB_CONNECTION',
            'DB_DATABASE',
        ])
        ->assertJsonPath('data.host', null)
        ->assertJsonPath('data.port', null);

    expect(stored_env($this->instance))->toBe([
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => '/var/lib/app/database.sqlite',
    ]);
    expect(DatabaseConnectionTarget::query()->sole()->database_connection_id)
        ->toBe(DatabaseConnection::query()->where('slug', 'local')->sole()->id);
});

it('rewrites same-node Docker Process host and port and keeps remote registry values otherwise', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'node_id' => $this->instance->node_id,
        'host' => '10.44.0.201',
        'port' => 3306,
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_ATTACHMENT_SECRET,
    ])->assertCreated();

    Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $this->instance->node_id,
        'name' => 'mysql',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'mysql:8',
            'command' => ['mysqld'],
            'environment' => [],
            'ports' => ['127.0.0.1:3307:3306/tcp'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Stopped,
        'status' => LifecycleStatus::Active,
    ]);

    $this->call(
        'PUT',
        "/api/v1/instances/{$this->instance->id}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )
        ->assertOk()
        ->assertJsonPath('data.host', '127.0.0.1')
        ->assertJsonPath('data.port', 3307);

    expect(stored_env($this->instance)['DB_HOST'])->toBe('127.0.0.1')
        ->and(stored_env($this->instance)['DB_PORT'])->toBe('3307');

    $remote = Node::query()->create([
        'name' => 'remote-app',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.210',
        'wireguard_ip' => '10.44.0.210',
        'user' => 'orbit',
    ]);
    $remoteInstance = AppInstance::query()->create([
        'app_id' => $this->instance->app_id,
        'node_id' => $remote->id,
        'name' => 'remote',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/environment-api/remote',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $remoteRoute = Route::query()->create([
        'app_id' => $this->instance->app_id,
        'node_id' => $remote->id,
        'hostname' => 'environment-api-remote.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $remoteRoute->targets()->create(['app_instance_id' => $remoteInstance->id, 'position' => 0]);
    $remoteRoute->update(['status' => RouteStatus::Active]);
    $remoteInstance->update(['status' => 'active']);

    $this->call(
        'PUT',
        "/api/v1/instances/{$remoteInstance->id}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )
        ->assertOk()
        ->assertJsonPath('data.host', '10.44.0.201')
        ->assertJsonPath('data.port', 3306);

    expect(stored_env($remoteInstance->fresh())['DB_HOST'])->toBe('10.44.0.201')
        ->and(stored_env($remoteInstance->fresh())['DB_PORT'])->toBe('3306');
});

it('detaches the mapping and clears related stored keys without logging the password', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_ATTACHMENT_SECRET,
    ])->assertCreated();
    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/app", [
        'prefix' => 'CACHE_DB',
    ])->assertOk();
    $this->putJson("/api/v1/instances/{$this->instance->id}/environment/APP_KEY", [
        'value' => 'keep-me',
    ])->assertOk();

    $detach = $this->call(
        'DELETE',
        "/api/v1/instances/{$this->route->hostname}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{"prefix":"CACHE_DB"}',
    );

    $detach
        ->assertOk()
        ->assertJsonPath('data.operation', 'detach')
        ->assertJsonPath('data.prefix', 'CACHE_DB')
        ->assertJsonPath('data.changed', true);

    expect($detach->getContent())->not->toContain(DATABASE_ATTACHMENT_SECRET);
    expect(Activity::query()->where('request_id', $detach->json('meta.request_id'))->sole())
        ->command->toBe('instance:database:remove')
        ->subject_type->toBe(AppInstance::class)
        ->subject_id->toBe($this->instance->id)
        ->target_node_id->toBe($this->instance->node_id);
    expect(stored_env($this->instance))->toBe(['APP_KEY' => 'keep-me']);
    expect(DatabaseConnectionTarget::query()->exists())->toBeFalse();

    $this->call(
        'DELETE',
        "/api/v1/instances/{$this->instance->id}/database-connections/app",
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{}',
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'database.attachment_missing');

    $this->deleteJson('/api/v1/database-connections/app')
        ->assertOk()
        ->assertJsonPath('data.slug', 'app');
});

it('refuses an unsupported attach key and an invalid prefix', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'sqlite',
        'path' => '/tmp/app.sqlite',
    ])->assertCreated();

    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/app", [
        'workspace_id' => 1,
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    $this->putJson("/api/v1/instances/{$this->instance->id}/database-connections/app", [
        'prefix' => 'db',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
});

/**
 * @return array{0: Node, 1: AppInstance, 2: Route}
 */
function database_attachment_fixture(): array
{
    $caller = Node::query()->create([
        'name' => 'gateway-peer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.200',
        'wireguard_ip' => '10.44.0.200',
        'user' => 'orbit',
    ]);
    $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node = Node::query()->create([
        'name' => 'environment-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.201',
        'wireguard_ip' => '10.44.0.201',
        'user' => 'orbit',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Environment API',
        'slug' => 'environment-api',
        'repository_url' => 'https://example.test/environment-api.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/environment-api/default',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'environment-api.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => 'active']);

    return [$caller, $instance->fresh(['node']), $route];
}

/** @return array<string, string> */
function stored_env(AppInstance $instance): array
{
    return AppInstanceEnvironmentValue::query()
        ->where('app_instance_id', $instance->id)
        ->orderBy('env_key')
        ->get()
        ->mapWithKeys(static fn (AppInstanceEnvironmentValue $row): array => [$row->env_key => $row->env_value])
        ->all();
}

final class DatabaseAttachmentAccess implements AppInstanceEnvironmentReader, AppInstanceEnvironmentWriter, AppInstanceOperationPreflight
{
    public function assertEnvironmentReadable(AppInstanceEnvironmentContext $context): void
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function assertEnvironmentWritable(
        AppInstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): void {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function read(AppInstanceEnvironmentContext $context): string
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function write(
        AppInstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): AppInstanceEnvironmentWriteResult {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }
}
