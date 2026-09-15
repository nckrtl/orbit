<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\ManagedMysqlProcess;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;

function managed_mysql_node(array $attributes = []): Node
{
    return Node::query()->create([
        'name' => $attributes['name'] ?? 'db',
        'status' => $attributes['status'] ?? LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $attributes['wireguard_ip'] ?? '10.44.0.80',
    ]);
}

function managed_mysql_process(Node $node, array $attributes = []): Process
{
    return Process::query()->create([
        'owner_type' => $attributes['owner_type'] ?? Node::class,
        'owner_id' => $attributes['owner_id'] ?? $node->id,
        'name' => $attributes['name'] ?? 'mysql',
        'runtime' => $attributes['runtime'] ?? ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => $attributes['runtime_config'] ?? [
            'image' => 'mysql:8.4',
            'command' => ['mysqld'],
            'environment' => ['MYSQL_ROOT_PASSWORD' => 'root-secret'],
            'ports' => ['127.0.0.1:3307:3306/tcp'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
}

it('accepts a Node-targeted Docker MySQL Process and hides the root password', function (): void {
    $node = managed_mysql_node();
    $process = managed_mysql_process($node);

    $managed = ManagedMysqlProcess::from($process);

    expect($managed->host)
        ->toBe('10.44.0.80')
        ->and($managed->port)
        ->toBe(3307)
        ->and($managed->rootPassword)
        ->toBe('root-secret')
        ->and($managed->__debugInfo())
        ->not->toHaveKey('rootPassword')
        ->and(print_r($managed, true))
        ->not->toContain('root-secret');
});

it('accepts mysql-server image names and published host ports', function (string $image): void {
    $node = managed_mysql_node();
    $process = managed_mysql_process($node, [
        'runtime_config' => [
            'image' => $image,
            'command' => ['mysqld'],
            'environment' => ['MYSQL_ROOT_PASSWORD' => 'root-secret'],
            'ports' => ['3308:3306'],
            'volumes' => [],
        ],
    ]);

    expect(ManagedMysqlProcess::from($process)->port)->toBe(3308);
})->with([
    'mysql:8',
    'docker.io/library/mysql:8.4',
    'mysql/mysql-server:8.4',
]);

it('refuses an AppInstance Process, a systemd Process, and a non-MySQL image', function (): void {
    $node = managed_mysql_node();
    $app = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);

    $instanceProcess = managed_mysql_process($node, [
        'owner_type' => AppInstance::class,
        'owner_id' => $instance->id,
    ]);

    expect(fn () => ManagedMysqlProcess::from($instanceProcess))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('database.process_not_node');
        });

    $systemd = managed_mysql_process($node, [
        'name' => 'queue',
        'runtime' => ProcessRuntime::Systemd,
    ]);

    expect(fn () => ManagedMysqlProcess::from($systemd))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('database.process_not_docker');
        });

    $redis = managed_mysql_process($node, [
        'name' => 'redis',
        'runtime_config' => [
            'image' => 'redis:8',
            'command' => ['redis-server'],
            'environment' => ['MYSQL_ROOT_PASSWORD' => 'root-secret'],
            'ports' => ['6379:6379'],
            'volumes' => [],
        ],
    ]);

    expect(fn () => ManagedMysqlProcess::from($redis))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('database.process_not_mysql');
        });
});

it('refuses a missing published MySQL port or root password', function (): void {
    $node = managed_mysql_node();

    $noPort = managed_mysql_process($node, [
        'name' => 'mysql-noport',
        'runtime_config' => [
            'image' => 'mysql:8',
            'command' => ['mysqld'],
            'environment' => ['MYSQL_ROOT_PASSWORD' => 'root-secret'],
            'ports' => ['8080:80'],
            'volumes' => [],
        ],
    ]);

    expect(fn () => ManagedMysqlProcess::from($noPort))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('database.process_not_mysql');
        });

    $noPassword = managed_mysql_process($node, [
        'name' => 'mysql-nopass',
        'runtime_config' => [
            'image' => 'mysql:8',
            'command' => ['mysqld'],
            'environment' => [],
            'ports' => ['3306:3306'],
            'volumes' => [],
        ],
    ]);

    expect(fn () => ManagedMysqlProcess::from($noPassword))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('database.root_password_missing');
        });
});
