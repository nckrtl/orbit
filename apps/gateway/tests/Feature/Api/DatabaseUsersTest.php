<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\ManagedMysqlUserProvisioner;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeManagedMysqlUserProvisioner;

const DATABASE_USER_SECRET = 'db-user-secret-71ae';
const DATABASE_ROOT_SECRET = 'mysql-root-secret-3c09';

beforeEach(function (): void {
    $this->provisioner = new FakeManagedMysqlUserProvisioner;
    app()->instance(ManagedMysqlUserProvisioner::class, $this->provisioner);

    $node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->node = $this->markAsGateway($node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);

    $this->dbNode = Node::query()->create([
        'name' => 'db',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);

    $this->process = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $this->dbNode->id,
        'name' => 'mysql',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'mysql:8.4',
            'command' => ['mysqld'],
            'environment' => ['MYSQL_ROOT_PASSWORD' => DATABASE_ROOT_SECRET],
            'ports' => ['127.0.0.1:3307:3306/tcp'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
});

it('creates a MySQL user through a Node Docker Process and registers the connection', function (): void {
    $response = $this->postJson("/api/v1/processes/{$this->process->id}/database-users", [
        'slug' => 'app',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.slug', 'app')
        ->assertJsonPath('data.driver', 'mysql')
        ->assertJsonPath('data.node_id', $this->dbNode->id)
        ->assertJsonPath('data.host', '10.44.0.80')
        ->assertJsonPath('data.port', 3307)
        ->assertJsonPath('data.database', 'app')
        ->assertJsonPath('data.username', 'app')
        ->assertJsonPath('data.has_password', true)
        ->assertJsonMissingPath('data.password');

    expect($response->getContent())
        ->not->toContain(DATABASE_USER_SECRET)
        ->not->toContain(DATABASE_ROOT_SECRET)
        ->and($this->provisioner->calls)
        ->toBe([[
            'process_id' => $this->process->id,
            'database' => 'app',
            'username' => 'app',
        ]]);

    $raw = DB::table('database_connections')->where('slug', 'app')->sole();
    $connection = DatabaseConnection::query()->where('slug', 'app')->sole();

    expect($raw->password)
        ->not->toBe(DATABASE_USER_SECRET)
        ->and($connection->password)
        ->toBe(DATABASE_USER_SECRET);

    $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();
    $encoded = json_encode($activity->properties?->toArray() ?? [], JSON_THROW_ON_ERROR);

    expect($activity->command)
        ->toBe('database:user:create')
        ->and($encoded)
        ->not->toContain(DATABASE_USER_SECRET)
        ->not->toContain(DATABASE_ROOT_SECRET)
        ->and($activity->properties?->get('input'))
        ->toMatchArray(['password' => '[REDACTED]']);
});

it('refreshes an existing mysql connection for the same slug', function (): void {
    $this->postJson('/api/v1/database-connections', [
        'slug' => 'app',
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'database' => 'old',
        'username' => 'old',
        'password' => 'old-secret',
    ])->assertCreated();

    $refresh = $this->postJson("/api/v1/processes/{$this->process->id}/database-users", [
        'slug' => 'app',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
    ]);

    $refresh
        ->assertOk()
        ->assertJsonPath('data.slug', 'app')
        ->assertJsonPath('data.host', '10.44.0.80')
        ->assertJsonPath('data.port', 3307)
        ->assertJsonPath('data.database', 'app')
        ->assertJsonPath('data.username', 'app')
        ->assertJsonMissingPath('data.password');

    expect(DatabaseConnection::query()->where('slug', 'app')->sole()->password)
        ->toBe(DATABASE_USER_SECRET)
        ->and(DatabaseConnection::query()->count())
        ->toBe(1);
});

it('refuses a missing Process, a wrong Process, and a slug owned by another driver', function (): void {
    $this->postJson('/api/v1/processes/9999/database-users', [
        'slug' => 'app',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
    ])->assertNotFound()->assertJsonPath('error.code', 'http.404');

    $systemd = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $this->dbNode->id,
        'name' => 'queue',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/orbit',
        'runtime_config' => [
            'command' => ['/usr/bin/true'],
            'environment_file' => '',
        ],
        'restart_policy' => 'never',
        'desired_state' => DesiredProcessState::Stopped,
        'status' => LifecycleStatus::Active,
    ]);

    $this->postJson("/api/v1/processes/{$systemd->id}/database-users", [
        'slug' => 'app',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
    ])->assertStatus(422)->assertJsonPath('error.code', 'database.process_not_docker');

    $app = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->dbNode->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $instanceProcess = Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $instance->id,
        'name' => 'mysql',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => $this->process->runtime_config,
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    $this->postJson("/api/v1/processes/{$instanceProcess->id}/database-users", [
        'slug' => 'app',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
    ])->assertStatus(422)->assertJsonPath('error.code', 'database.process_not_node');

    $this->postJson('/api/v1/database-connections', [
        'slug' => 'local',
        'driver' => 'sqlite',
        'path' => '/tmp/app.sqlite',
    ])->assertCreated();

    $this->postJson("/api/v1/processes/{$this->process->id}/database-users", [
        'slug' => 'local',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
    ])->assertStatus(409)->assertJsonPath('error.code', 'database.slug_conflict');

    expect($this->provisioner->calls)->toBe([]);
});

it('refuses a non-MySQL image and a missing root password', function (): void {
    $redis = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $this->dbNode->id,
        'name' => 'redis',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'redis:8',
            'command' => ['redis-server'],
            'environment' => ['MYSQL_ROOT_PASSWORD' => DATABASE_ROOT_SECRET],
            'ports' => ['6379:6379'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    $this->postJson("/api/v1/processes/{$redis->id}/database-users", [
        'slug' => 'app',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
    ])->assertStatus(422)->assertJsonPath('error.code', 'database.process_not_mysql');

    $this->process->update([
        'runtime_config' => [
            ...$this->process->runtime_config,
            'environment' => [],
        ],
    ]);

    $this->postJson("/api/v1/processes/{$this->process->id}/database-users", [
        'slug' => 'app',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
    ])->assertStatus(422)->assertJsonPath('error.code', 'database.root_password_missing');

    expect($this->provisioner->calls)->toBe([]);
});

it('keeps a failed remote create from writing a connection', function (): void {
    $this->provisioner->failure = new ResourceOperationException(
        errorCode: 'database.user_create_failed',
        message: 'Process [mysql] could not create the MySQL user.',
        status: 502,
    );

    $this->postJson("/api/v1/processes/{$this->process->id}/database-users", [
        'slug' => 'app',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
    ])
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'database.user_create_failed');

    expect(DatabaseConnection::query()->exists())->toBeFalse();
});

it('refuses unknown keys and unsafe identifiers', function (): void {
    $this->postJson("/api/v1/processes/{$this->process->id}/database-users", [
        'slug' => 'app',
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
        'host' => 'evil.example.test',
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    $this->postJson("/api/v1/processes/{$this->process->id}/database-users", [
        'slug' => 'app',
        'database' => 'app-db',
        'username' => 'app',
        'password' => DATABASE_USER_SECRET,
    ])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');

    expect($this->provisioner->calls)->toBe([]);
});
