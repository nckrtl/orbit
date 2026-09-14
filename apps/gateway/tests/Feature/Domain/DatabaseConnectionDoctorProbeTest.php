<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Actions\Doctor\DatabaseConnectionDoctorProbe;
use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Doctor\DatabaseConnectionDoctorInspection;
use App\Domain\Doctor\DatabaseConnectionDoctorIssueCode;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
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

const DATABASE_CONNECTION_DOCTOR_SECRET = 'db-doctor-secret-3f91';

it('reports a missing registry connection for an attachment without a loaded record', function (): void {
    $node = database_connection_doctor_node();
    $instance = database_connection_doctor_instance($node);
    $connection = database_connection_doctor_mysql($node, 'app');
    $attachment = DatabaseConnectionTarget::query()->create([
        'database_connection_id' => $connection->id,
        'app_instance_id' => $instance->id,
        'prefix' => 'CACHE',
    ]);
    $attachment->database_connection_id = 999_999;

    $issues = database_connection_doctor_inspection()->attachment($attachment);
    $encoded = json_encode($issues, JSON_THROW_ON_ERROR);

    expect(array_map(static fn ($issue): string => $issue->code, $issues))
        ->toBe([DatabaseConnectionDoctorIssueCode::Missing->value])
        ->and($issues[0]->expected)
        ->toBe('present')
        ->and($issues[0]->observed)
        ->toBe('absent')
        ->and($encoded)
        ->not->toContain(DATABASE_CONNECTION_DOCTOR_SECRET)
        ->and(print_r($connection, true))
        ->not->toContain(DATABASE_CONNECTION_DOCTOR_SECRET);
});

it('reports distinct unhealthy and env-mismatch codes without exposing the password', function (): void {
    $node = database_connection_doctor_node();
    $instance = database_connection_doctor_instance($node);
    $healthy = database_connection_doctor_mysql($node, 'healthy');
    DatabaseConnectionTarget::query()->create([
        'database_connection_id' => $healthy->id,
        'app_instance_id' => $instance->id,
        'prefix' => 'DB',
    ]);
    $instance->environmentValues()->create([
        'env_key' => 'DB_CONNECTION',
        'env_value' => 'mysql',
    ]);

    DatabaseConnection::query()->create([
        'slug' => 'broken',
        'driver' => DatabaseDriver::Mysql,
        'node_id' => $node->id,
        'host' => null,
        'port' => null,
        'database' => null,
        'username' => null,
        'password' => null,
    ]);

    $report = database_connection_doctor_probe()->inspect(database_connection_doctor_context($node));
    $encoded = json_encode($report, JSON_THROW_ON_ERROR);

    expect($report->family)
        ->toBe(DoctorFamily::DatabaseConnection)
        ->and($report->checked)
        ->toBe(2)
        ->and(array_map(static fn ($issue): string => $issue->code, $report->issues))
        ->toBe([
            DatabaseConnectionDoctorIssueCode::EnvMismatch->value,
            DatabaseConnectionDoctorIssueCode::Unhealthy->value,
        ])
        ->and($encoded)
        ->not->toContain(DATABASE_CONNECTION_DOCTOR_SECRET)
        ->and(print_r($healthy, true))
        ->not->toContain(DATABASE_CONNECTION_DOCTOR_SECRET)
        ->and(print_r($instance->environmentValues, true))
        ->not->toContain(DATABASE_CONNECTION_DOCTOR_SECRET);
});

it('restores drifted stored env from the registry with the same attach projection rules', function (): void {
    $node = database_connection_doctor_node();
    $instance = database_connection_doctor_instance($node);
    $connection = database_connection_doctor_mysql($node, 'app');
    Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
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

    $attached = app(AttachDatabaseConnectionAction::class)->execute($instance, $connection, 'DB');

    expect($attached->host)->toBe('127.0.0.1')
        ->and($attached->port)
        ->toBe(3307)
        ->and(database_connection_doctor_stored($instance)['DB_HOST'])
        ->toBe('127.0.0.1');

    $instance->environmentValues()->where('env_key', 'DB_HOST')->sole()->update([
        'env_value' => '10.44.0.201',
    ]);
    $instance->environmentValues()->where('env_key', 'DB_PORT')->sole()->update([
        'env_value' => '3306',
    ]);
    $instance->environmentValues()->where('env_key', 'DB_PASSWORD')->delete();

    $drifted = database_connection_doctor_probe()->inspect(database_connection_doctor_context($node));

    expect(array_map(static fn ($issue): string => $issue->code, $drifted->issues))
        ->toBe([DatabaseConnectionDoctorIssueCode::EnvMismatch->value])
        ->and(json_encode($drifted, JSON_THROW_ON_ERROR))
        ->not->toContain(DATABASE_CONNECTION_DOCTOR_SECRET);

    app(AttachDatabaseConnectionAction::class)->execute($instance, $connection, 'DB');

    $restored = database_connection_doctor_probe()->inspect(database_connection_doctor_context($node));
    $stored = database_connection_doctor_stored($instance);

    expect($restored->issues)
        ->toBe([])
        ->and($stored['DB_HOST'])
        ->toBe('127.0.0.1')
        ->and($stored['DB_PORT'])
        ->toBe('3307')
        ->and($stored['DB_PASSWORD'])
        ->toBe(DATABASE_CONNECTION_DOCTOR_SECRET);
});

it('restores sqlite path keys and clears leftover host keys on prefix reuse', function (): void {
    $node = database_connection_doctor_node();
    $instance = database_connection_doctor_instance($node);
    $mysql = database_connection_doctor_mysql($node, 'app');
    app(AttachDatabaseConnectionAction::class)->execute($instance, $mysql, 'DB');

    $sqlite = DatabaseConnection::query()->create([
        'slug' => 'local',
        'driver' => DatabaseDriver::Sqlite,
        'path' => '/var/lib/app/database.sqlite',
    ]);
    app(AttachDatabaseConnectionAction::class)->execute($instance, $sqlite, 'DB');

    $instance->environmentValues()->create([
        'env_key' => 'DB_HOST',
        'env_value' => 'db.example.test',
    ]);
    $instance->environmentValues()->create([
        'env_key' => 'DB_PORT',
        'env_value' => '3306',
    ]);
    $instance->environmentValues()->where('env_key', 'DB_DATABASE')->sole()->update([
        'env_value' => '/tmp/wrong.sqlite',
    ]);

    $drifted = database_connection_doctor_probe()->inspect(database_connection_doctor_context($node));

    expect(array_map(static fn ($issue): string => $issue->code, $drifted->issues))
        ->toBe([DatabaseConnectionDoctorIssueCode::EnvMismatch->value]);

    app(AttachDatabaseConnectionAction::class)->execute($instance, $sqlite, 'DB');

    expect(database_connection_doctor_probe()->inspect(database_connection_doctor_context($node))->issues)
        ->toBe([])
        ->and(database_connection_doctor_stored($instance))
        ->toBe([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/var/lib/app/database.sqlite',
        ]);
});

it('keeps a healthy attachment silent and omits the password from doctor activity', function (): void {
    $caller = database_connection_doctor_node('doctor-caller');
    $caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node = database_connection_doctor_node('doctor-owner');
    $caller->accessibleNodes()->attach($node->id);
    $instance = database_connection_doctor_instance($node);
    $connection = database_connection_doctor_mysql($node, 'app');
    app(AttachDatabaseConnectionAction::class)->execute($instance, $connection, 'DB');

    $response = test()
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson('/api/v1/doctor', [
            'node_id' => $node->id,
            'families' => ['database_connection'],
        ])
        ->assertOk();

    $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();
    $encodedActivity = json_encode($activity->properties?->toArray() ?? [], JSON_THROW_ON_ERROR);

    expect($response->json('data.nodes.0.families.0.issues'))
        ->toBe([])
        ->and($response->getContent())
        ->not->toContain(DATABASE_CONNECTION_DOCTOR_SECRET)
        ->and($encodedActivity)
        ->not->toContain(DATABASE_CONNECTION_DOCTOR_SECRET)
        ->and($activity->command)
        ->toBe('doctor');
});

function database_connection_doctor_inspection(): DatabaseConnectionDoctorInspection
{
    return new DatabaseConnectionDoctorInspection(new DatabaseConnectionEnvProjection);
}

function database_connection_doctor_probe(): DatabaseConnectionDoctorProbe
{
    return new DatabaseConnectionDoctorProbe(database_connection_doctor_inspection());
}

function database_connection_doctor_context(Node $node): DoctorNodeContext
{
    return new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true));
}

function database_connection_doctor_node(string $name = 'database-doctor'): Node
{
    static $number = 40;
    $number++;

    return Node::query()->create([
        'name' => "{$name}-{$number}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => "10.44.0.{$number}",
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
}

function database_connection_doctor_instance(Node $node): AppInstance
{
    static $number = 0;
    $number++;

    $app = OrbitApp::query()->create([
        'name' => "Database Doctor {$number}",
        'slug' => "database-doctor-{$number}",
        'repository_url' => "https://example.test/database-doctor-{$number}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'development',
        'checkout_path' => "/srv/orbit/database-doctor-{$number}/default",
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => "database-doctor-{$number}.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => 'active']);

    return $instance->fresh(['node']);
}

function database_connection_doctor_mysql(Node $node, string $slug): DatabaseConnection
{
    return DatabaseConnection::query()->create([
        'slug' => $slug,
        'driver' => DatabaseDriver::Mysql,
        'node_id' => $node->id,
        'host' => '10.44.0.201',
        'port' => 3306,
        'database' => 'app',
        'username' => 'app',
        'password' => DATABASE_CONNECTION_DOCTOR_SECRET,
    ]);
}

/** @return array<string, string> */
function database_connection_doctor_stored(AppInstance $instance): array
{
    return AppInstanceEnvironmentValue::query()
        ->where('app_instance_id', $instance->id)
        ->orderBy('env_key')
        ->get()
        ->mapWithKeys(static fn (AppInstanceEnvironmentValue $row): array => [$row->env_key => $row->env_value])
        ->all();
}
