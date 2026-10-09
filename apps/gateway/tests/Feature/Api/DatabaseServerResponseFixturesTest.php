<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseUsersRequest;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\ListDatabaseServersRequest;
use Orbit\Sdk\Requests\DatabaseServers\ShowDatabaseServerRequest;
use Tests\Support\FakeDatabaseServerAdmin;
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
        'apps' => fixture_apps(null),
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
