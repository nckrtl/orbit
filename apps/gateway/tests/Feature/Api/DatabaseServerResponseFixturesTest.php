<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\LifecycleStatus;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseUsersRequest;
use Orbit\Sdk\Requests\DatabaseServers\CloseDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\ListDatabaseServersRequest;
use Orbit\Sdk\Requests\DatabaseServers\ShowDatabaseServerRequest;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\FakeValkeyAclProgramRunner;
use Tests\Support\FakeValkeyServerAdmin;
use Tests\Support\ProcessesApiFakeRuntimeManager;

/**
 * Records the Database server and database user responses that the CLI replays for
 * `database:server:*`, `database:create --server`, and `database:user:*`.
 */
it('records the database server and database user responses', function (): void {
    $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));
    app()->instance(ProcessRuntimeManager::class, new ProcessesApiFakeRuntimeManager);
    app()->instance(DatabaseServerAdmin::class, new FakeDatabaseServerAdmin);
    app()->instance(InstanceOperationPreflight::class, new DatabaseServerFixtureEnvironment);
    app()->instance(InstanceEnvironmentReader::class, new DatabaseServerFixtureEnvironment);
    app()->instance(InstanceEnvironmentWriter::class, new DatabaseServerFixtureEnvironment);

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
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'wireguard_ip' => '10.44.0.80',
        'user' => 'orbit',
    ]);
    orbit_test_set_app_placement_role($dbNode, false);
    $project = Project::query()->create([
        'name' => 'DLF',
        'slug' => 'dlf',
        'repository_url' => 'https://example.test/dlf.git',
        'default_branch' => 'main',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $dbNode->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/dlf/main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', fixture_request_id());

    record_fixture(
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-mysql', 'node_id' => $dbNode->id])->assertCreated(),
        'database-servers/database-server-create/default',
        CreateDatabaseServerRequest::class,
        'POST /api/v1/database-servers',
    );

    record_fixture(
        $this->postJson('/api/v1/database-connections', [
            'slug' => 'dlf-leden',
            'server' => 'beast-mysql',
            'instance_id' => $instance->id,
        ])->assertCreated(),
        'database-connections/database-create/server',
        CreateDatabaseConnectionRequest::class,
        'POST /api/v1/database-connections',
    );

    record_fixture(
        $this->getJson('/api/v1/database-servers')->assertOk(),
        'database-servers/database-server-list/default',
        ListDatabaseServersRequest::class,
        'GET /api/v1/database-servers',
    );

    record_fixture(
        $this->getJson('/api/v1/database-servers/beast-mysql')->assertOk(),
        'database-servers/database-server-show/default',
        ShowDatabaseServerRequest::class,
        'GET /api/v1/database-servers/{database_server}',
    );

    record_fixture(
        $this->postJson('/api/v1/database-connections/dlf-leden/users', [
            'username' => 'reporting',
            'password' => 'reporting-secret',
            'read_only' => true,
        ])->assertCreated(),
        'database-connections/database-user-create/default',
        CreateDatabaseUserRequest::class,
        'POST /api/v1/database-connections/{database_connection}/users',
    );

    record_fixture(
        $this->getJson('/api/v1/database-connections/dlf-leden/users')->assertOk(),
        'database-connections/database-user-list/default',
        ListDatabaseUsersRequest::class,
        'GET /api/v1/database-connections/{database_connection}/users',
    );
    $valkeyAdmin = new FakeValkeyServerAdmin;
    app()->instance(ValkeyServerAdmin::class, $valkeyAdmin);
    app()->instance(RemoteProgramRunner::class, new FakeValkeyAclProgramRunner);
    record_fixture(
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-valkey', 'node_id' => $dbNode->id, 'driver' => 'redis'])->assertCreated(),
        'database-servers/database-server-create/valkey',
        CreateDatabaseServerRequest::class,
        'POST /api/v1/database-servers',
    );
    record_fixture(
        $this->postJson('/api/v1/database-connections', ['slug' => 'queue', 'server' => 'beast-valkey', 'database' => 1, 'patterns' => ['queue:*'], 'allow' => ['@list', 'eval']])->assertCreated(),
        'database-connections/database-create/valkey',
        CreateDatabaseConnectionRequest::class,
        'POST /api/v1/database-connections',
    );
    $valkey = DatabaseServer::query()->where('slug', 'beast-valkey')->sole();
    $valkey->update(['default_open' => true]);
    $valkeyAdmin->defaultClients = ['10.44.0.80:12345'];
    record_fixture(
        $this->postJson('/api/v1/database-servers/beast-valkey/close')->assertConflict(),
        'database-servers/database-server-close/in-use',
        CloseDatabaseServerRequest::class,
        'POST /api/v1/database-servers/{database_server}/close',
    );
    $valkeyAdmin->defaultClients = [];
    record_fixture(
        $this->postJson('/api/v1/database-servers/beast-valkey/close')->assertOk(),
        'database-servers/database-server-close/default',
        CloseDatabaseServerRequest::class,
        'POST /api/v1/database-servers/{database_server}/close',
    );

});

final class DatabaseServerFixtureEnvironment implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight
{
    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void
    {
        throw new RuntimeException('The fixture contacted the workload Node.');
    }

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void
    {
        throw new RuntimeException('The fixture contacted the workload Node.');
    }

    public function read(InstanceEnvironmentContext $context): string
    {
        throw new RuntimeException('The fixture contacted the workload Node.');
    }

    public function write(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents): InstanceEnvironmentWriteResult
    {
        throw new RuntimeException('The fixture contacted the workload Node.');
    }
}
