<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->admin = new FakeDatabaseServerAdmin;
    app()->instance(DatabaseServerAdmin::class, $this->admin);

    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->gateway = $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);

    $this->dbNode = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);
});

/** @return array<string, mixed> */
function database_server_request(Node $node, array $overrides = []): array
{
    return ['slug' => 'beast-mysql', 'node_id' => $node->id, ...$overrides];
}

function database_server_root_password(): string
{
    return DatabaseServer::query()->where('slug', 'beast-mysql')->sole()->root_password;
}

describe('database:server:create', function (): void {
    it('runs MySQL as a Docker Node Process and keeps the root password only on the server record', function (): void {
        $response = $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode));

        $response
            ->assertCreated()
            ->assertJsonPath('data.slug', 'beast-mysql')
            ->assertJsonPath('data.node_id', $this->dbNode->id)
            ->assertJsonPath('data.tag', '8.4')
            ->assertJsonPath('data.port', 3306)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.databases_count', 0);

        expect(array_keys($response->json('data')))->toBe([
            'id', 'slug', 'node_id', 'process_id', 'tag', 'port', 'status', 'databases_count',
        ]);

        $server = DatabaseServer::query()->sole();
        $password = $server->root_password;
        $raw = DB::table('database_servers')->sole();
        $process = Process::query()->findOrFail($server->process_id);

        expect(strlen($password))->toBe(48)
            ->and($raw->root_password)->not->toBe($password)
            ->and($response->getContent())->not->toContain($password)
            ->and(print_r($server, true))->not->toContain($password)
            ->and($process->owner_type)->toBe(Node::class)
            ->and($process->owner_id)->toBe($this->dbNode->id)
            ->and($process->name)->toBe('beast-mysql')
            ->and($process->runtime)->toBe(ProcessRuntime::Docker)
            ->and($process->restart_policy)->toBe('unless-stopped')
            ->and($process->desired_state)->toBe(DesiredProcessState::Running)
            ->and($process->status)->toBe(LifecycleStatus::Active)
            ->and($process->runtime_config)->toBe([
                'image' => 'mysql:8.4',
                'command' => ['mysqld'],
                'environment' => [],
                'ports' => ['10.44.0.80:3306:3306'],
                'volumes' => [['source' => 'orbit-beast-mysql-data', 'target' => '/var/lib/mysql', 'read_only' => false]],
            ])
            ->and(json_encode($process->getAttributes()))->not->toContain($password)
            // The Process is converged once with the variable and once more without it.
            ->and($this->runtime->convergedProcessIds)->toBe([$process->id, $process->id])
            ->and($this->admin->readyChecks)->toBe(['beast-mysql', 'beast-mysql']);

        $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

        expect($activity->command)->toBe('database:server:create')
            ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))->not->toContain($password);

        $this->getJson('/api/v1/processes')->assertOk()->assertDontSee($password);
    });

    it('accepts a tag and a port, and returns the finished server on an exact retry', function (): void {
        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode, ['tag' => '8.0.39', 'port' => 3307]))
            ->assertCreated()
            ->assertJsonPath('data.tag', '8.0.39')
            ->assertJsonPath('data.port', 3307);

        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode, ['tag' => '8.0.39', 'port' => 3307]))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        expect(Process::query()->sole()->runtime_config['image'])->toBe('mysql:8.0.39')
            ->and(Process::query()->sole()->runtime_config['ports'])->toBe(['10.44.0.80:3307:3306'])
            ->and($this->runtime->convergedProcessIds)->toHaveCount(2);
    });

    it('records a readiness timeout and continues from it on a retry', function (): void {
        $this->admin->readiness = [false];

        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode))
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'database.server_start_failed');

        $server = DatabaseServer::query()->sole();
        $process = Process::query()->sole();
        $password = $server->root_password;

        expect($server->status)->toBe(LifecycleStatus::Failed)
            ->and($server->failed_step)->toBe('readiness')
            ->and($server->error_code)->toBe('database.server_start_failed')
            ->and($process->runtime_config['environment'])->toBe(['MYSQL_ROOT_PASSWORD' => $password]);

        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode))
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.process_id', $process->id);

        expect(database_server_root_password())->toBe($password)
            ->and(Process::query()->sole()->runtime_config['environment'])->toBe([])
            ->and(DatabaseServer::query()->sole()->failed_step)->toBeNull()
            ->and($this->runtime->convergedProcessIds)->toBe([$process->id, $process->id, $process->id]);
    });

    it('continues with the root password removal when recreating the container failed', function (): void {
        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode))->assertCreated();
        $server = DatabaseServer::query()->sole();
        $process = Process::query()->sole();
        $server->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'root_password_removal']);
        $process->update(['status' => LifecycleStatus::Failed]);
        $this->runtime->convergedProcessIds = [];

        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        expect($this->runtime->convergedProcessIds)->toBe([$process->id])
            ->and($process->refresh()->status)->toBe(LifecycleStatus::Active);
    });

    it('refuses another Node, tag, or port for an existing slug', function (array $overrides): void {
        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode))->assertCreated();

        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode, $overrides))
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_slug_conflict');
    })->with([
        'tag' => [['tag' => '9.0']],
        'port' => [['port' => 3307]],
    ]);

    it('refuses a port that another Process on the Node publishes', function (): void {
        Process::query()->create([
            'owner_type' => Node::class,
            'owner_id' => $this->dbNode->id,
            'name' => 'mysql',
            'runtime' => ProcessRuntime::Docker,
            'working_directory' => '/app',
            'runtime_config' => [
                'image' => 'mysql:8.4',
                'command' => ['mysqld'],
                'environment' => [],
                'ports' => ['127.0.0.1:3306:3306/tcp'],
                'volumes' => [],
            ],
            'restart_policy' => 'unless-stopped',
            'desired_state' => DesiredProcessState::Running,
            'status' => LifecycleStatus::Active,
        ]);

        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode))
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_port_in_use');

        expect(DatabaseServer::query()->exists())->toBeFalse();
    });

    it('validates the request body', function (array $body): void {
        $this->postJson('/api/v1/database-servers', $body)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(DatabaseServer::query()->exists())->toBeFalse();
    })->with([
        'unknown key' => fn (): array => database_server_request($this->dbNode, ['environment' => ['A' => 'b']]),
        'bad slug' => fn (): array => database_server_request($this->dbNode, ['slug' => 'Beast_MySQL']),
        'bad tag' => fn (): array => database_server_request($this->dbNode, ['tag' => '8.4; rm -rf /']),
        'tag starting with a dot' => fn (): array => database_server_request($this->dbNode, ['tag' => '.8.4']),
        'version instead of tag' => fn (): array => database_server_request($this->dbNode, ['version' => '8.4']),
        'string port' => fn (): array => database_server_request($this->dbNode, ['port' => '3306']),
        'missing node' => fn (): array => ['slug' => 'beast-mysql'],
    ]);
});

describe('database:server list, show, and destroy', function (): void {
    beforeEach(function (): void {
        $this->postJson('/api/v1/database-servers', database_server_request($this->dbNode))->assertCreated();
        $this->server = DatabaseServer::query()->sole();
    });

    it('lists servers and shows one with its databases', function (): void {
        DatabaseConnection::query()->create([
            'slug' => 'shop',
            'driver' => 'mysql',
            'node_id' => $this->dbNode->id,
            'database_server_id' => $this->server->id,
            'host' => '10.44.0.80',
            'port' => 3306,
            'database' => 'shop',
            'username' => 'shop',
            'password' => 'shop-secret',
        ]);

        $this->getJson('/api/v1/database-servers')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'beast-mysql')
            ->assertJsonPath('data.0.databases_count', 1)
            ->assertDontSee($this->server->root_password);

        $this->getJson('/api/v1/database-servers/beast-mysql')
            ->assertOk()
            ->assertJsonPath('data.databases.0.slug', 'shop')
            ->assertJsonPath('data.databases.0.server', 'beast-mysql')
            ->assertDontSee($this->server->root_password)
            ->assertDontSee('shop-secret');

        $this->getJson('/api/v1/database-servers/missing')->assertNotFound();
    });

    it('refuses to remove a server that a connection points to', function (): void {
        DatabaseConnection::query()->create([
            'slug' => 'shop',
            'driver' => 'mysql',
            'database_server_id' => $this->server->id,
            'host' => '10.44.0.80',
            'database' => 'shop',
            'username' => 'shop',
            'password' => 'shop-secret',
        ]);

        $this->deleteJson('/api/v1/database-servers/beast-mysql')
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_in_use');

        expect($this->runtime->removed)->toBe([])
            ->and(DatabaseServer::query()->exists())->toBeTrue();
    });

    it('removes the Process and the record and keeps the data volume', function (): void {
        $processId = $this->server->process_id;

        $this->deleteJson('/api/v1/database-servers/beast-mysql')
            ->assertOk()
            ->assertJsonPath('data.slug', 'beast-mysql');

        expect($this->runtime->removed)->toBe([$processId])
            ->and(Process::query()->exists())->toBeFalse()
            ->and(DatabaseServer::query()->exists())->toBeFalse();
    });

    it('keeps process:destroy from removing the server Process', function (): void {
        $this->deleteJson("/api/v1/processes/{$this->server->process_id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'process.required_by_database_server');

        expect($this->runtime->removed)->toBe([]);
    });

    it('keeps node removal from orphaning the server', function (): void {
        $this->deleteJson("/api/v1/nodes/{$this->dbNode->id}", ['force' => true, 'offline' => true])
            ->assertConflict()
            ->assertJsonPath('error.code', 'node.has_database_servers');
    });
});
