<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\ManagedMysqlUserProvisioner;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Carbon;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseUsersRequest;
use Tests\Support\FakeManagedMysqlUserProvisioner;

/**
 * Records the database user list response that the CLI replays for
 * `database:user:list`.
 */
it('records the database user list response', function (): void {
    $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));

    app()->instance(ManagedMysqlUserProvisioner::class, new FakeManagedMysqlUserProvisioner);

    $caller = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.1',
        'user' => 'orbit',
    ]);
    $this->markAsGateway($caller);
    $dbNode = Node::query()->create([
        'name' => 'db',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'wireguard_ip' => '10.44.0.80',
        'user' => 'orbit',
    ]);
    $process = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $dbNode->id,
        'name' => 'mysql',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
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

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', fixture_request_id())
        ->postJson("/api/v1/processes/{$process->id}/database-users", [
            'slug' => 'app',
            'database' => 'app',
            'username' => 'app',
            'password' => 'db-user-secret',
        ])->assertCreated();

    record_fixture(
        $this->getJson('/api/v1/database-connections/app/users')->assertOk(),
        'database-connections/database-user-list/default',
        ListDatabaseUsersRequest::class,
        'GET /api/v1/database-connections/{database_connection}/users',
    );
});
