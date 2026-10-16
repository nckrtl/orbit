<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramFailure;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\FakeValkeyAclProgramRunner;
use Tests\Support\FakeValkeyServerAdmin;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    app()->instance(DatabaseServerAdmin::class, new FakeDatabaseServerAdmin);
    $this->valkey = new FakeValkeyServerAdmin;
    app()->instance(ValkeyServerAdmin::class, $this->valkey);
    $this->programs = new FakeValkeyAclProgramRunner;
    app()->instance(RemoteProgramRunner::class, $this->programs);

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
function valkey_server_request(Node $node, array $overrides = []): array
{
    return ['slug' => 'beast-valkey', 'node_id' => $node->id, 'driver' => 'redis', ...$overrides];
}

function valkey_acl_file(string $password): string
{
    return 'user default on #'.hash('sha256', $password)." ~* &* +@all\n";
}

/** @return array<string, mixed> */
function valkey_server_runtime_config(string $port, string $volume, string $tag = '8.1'): array
{
    return [
        'image' => "valkey/valkey:{$tag}",
        'command' => ['valkey-server', '--appendonly', 'yes', '--dir', '/data', '--aclfile', '/data/users.acl'],
        'environment' => [],
        'ports' => [$port],
        'volumes' => [['source' => $volume, 'target' => '/data', 'read_only' => false]],
    ];
}

function valkey_server_assert_no_secret(TestResponse $response, string $secret): void
{
    $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

    expect($response->getContent())->not->toContain($secret)
        ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))->not->toContain($secret);
}

describe('database:server:create --driver=redis', function (): void {
    it('writes the ACL file, then runs Valkey from it without a password in the Process', function (): void {
        $this->programs->during = function (): void {
            expect(Process::query()->exists())->toBeFalse();
        };

        $response = $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode));

        $response
            ->assertCreated()
            ->assertJsonPath('data.slug', 'beast-valkey')
            ->assertJsonPath('data.driver', 'redis')
            ->assertJsonPath('data.tag', '8.1')
            ->assertJsonPath('data.port', 6379)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.databases_count', 0);

        expect(array_keys($response->json('data')))->toBe([
            'id', 'slug', 'driver', 'node_id', 'process_id', 'tag', 'port', 'status', 'databases_count',
        ]);

        $server = DatabaseServer::query()->sole();
        $password = $server->root_password;
        $process = Process::query()->findOrFail($server->process_id);

        expect(strlen($password))->toBe(48)
            ->and($this->programs->runs)->toBe([[
                'node' => 'beast',
                'input' => [
                    'volume' => 'orbit-beast-valkey-data',
                    'image' => 'valkey/valkey:8.1',
                    'acl' => valkey_acl_file($password),
                ],
            ]])
            ->and(json_encode($this->programs->runs, JSON_THROW_ON_ERROR))->not->toContain($password)
            ->and($process->owner_type)->toBe(Node::class)
            ->and($process->owner_id)->toBe($this->dbNode->id)
            ->and($process->name)->toBe('beast-valkey')
            ->and($process->runtime)->toBe(ProcessRuntime::Docker)
            ->and($process->working_directory)->toBe('/data')
            ->and($process->restart_policy)->toBe('unless-stopped')
            ->and($process->desired_state)->toBe(DesiredProcessState::Running)
            ->and($process->status)->toBe(LifecycleStatus::Active)
            ->and($process->runtime_config)->toBe(valkey_server_runtime_config('10.44.0.80:6379:6379', 'orbit-beast-valkey-data'))
            ->and(json_encode($process->getAttributes()))->not->toContain($password)
            ->and($this->runtime->convergedProcessIds)->toBe([$process->id])
            ->and($this->valkey->readyChecks)->toBe(['beast-valkey']);

        valkey_server_assert_no_secret($response, $password);
        $this->getJson('/api/v1/processes')->assertOk()->assertDontSee($password);
    });

    it('accepts a tag and a port, and returns the finished server on an exact retry', function (): void {
        $request = valkey_server_request($this->dbNode, ['tag' => '8.0', 'port' => 6380]);

        $this->postJson('/api/v1/database-servers', $request)
            ->assertCreated()
            ->assertJsonPath('data.tag', '8.0')
            ->assertJsonPath('data.port', 6380);

        $this->postJson('/api/v1/database-servers', $request)
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        expect(Process::query()->sole()->runtime_config)->toBe(valkey_server_runtime_config('10.44.0.80:6380:6379', 'orbit-beast-valkey-data', '8.0'))
            ->and($this->programs->runs)->toHaveCount(1)
            ->and($this->programs->runs[0]['input']['image'])->toBe('valkey/valkey:8.0')
            ->and($this->runtime->convergedProcessIds)->toHaveCount(1);
    });

    it('records a failed ACL file with the program log and writes it again on a retry', function (RemoteProgramFailed|string $failure): void {
        is_string($failure) ? $this->programs->outcome = $failure : $this->programs->failure = $failure;

        $response = $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'database.server_command_failed')
            ->assertJsonPath('error.details.step', 'acl_file');

        $server = DatabaseServer::query()->sole();
        $activity = Activity::query()->where('request_id', $response->headers->get('X-Orbit-Request-Id'))->sole();

        expect($server->status)->toBe(LifecycleStatus::Failed)
            ->and($server->failed_step)->toBe('acl_file')
            ->and($server->error_code)->toBe('database.server_command_failed')
            ->and(Process::query()->exists())->toBeFalse()
            ->and($activity->properties?->toArray()['stderr'] ?? null)->toContain('ACL file write failed.');

        $this->programs->outcome = 'written';
        $this->programs->failure = null;

        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        expect($this->programs->runs)->toHaveCount(2)
            ->and($this->programs->runs[1]['input']['acl'])->toBe(valkey_acl_file($server->root_password))
            ->and(Process::query()->sole()->status)->toBe(LifecycleStatus::Active)
            ->and(DatabaseServer::query()->sole()->failed_step)->toBeNull();
    })->with([
        'a failed outcome' => ['failed'],
        'a run without a result' => [new RemoteProgramFailed('database-servers.write-valkey-acl', RemoteProgramFailure::NoResult, 'docker: Error response from daemon: pull access denied', exitCode: 1)],
    ]);

    it('continues with the Process after a failed start, without writing the ACL file again', function (): void {
        $this->runtime->failConverge = true;

        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'process.docker_converge_failed');

        $server = DatabaseServer::query()->sole();
        $process = Process::query()->sole();

        expect($server->failed_step)->toBe('process')
            ->and($server->process_id)->toBeNull()
            ->and($process->status)->toBe(LifecycleStatus::Failed);

        $this->runtime->failConverge = false;

        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.process_id', $process->id);

        expect($this->programs->runs)->toHaveCount(1)
            ->and($this->runtime->convergedProcessIds)->toBe([$process->id, $process->id])
            ->and($process->refresh()->status)->toBe(LifecycleStatus::Active);
    });

    it('records a readiness timeout and continues from it on a retry', function (): void {
        $this->valkey->readiness = [false];

        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'database.server_start_failed')
            ->assertJsonPath('error.message', 'Database server [beast-valkey] did not accept the admin login within 120 seconds.');

        $server = DatabaseServer::query()->sole();
        $process = Process::query()->sole();

        expect($server->status)->toBe(LifecycleStatus::Failed)
            ->and($server->failed_step)->toBe('readiness')
            ->and($server->process_id)->toBe($process->id);

        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        expect($this->programs->runs)->toHaveCount(1)
            ->and($this->runtime->convergedProcessIds)->toBe([$process->id])
            ->and($this->valkey->readyChecks)->toBe(['beast-valkey', 'beast-valkey']);
    });

    it('refuses the slug of a server with another driver', function (): void {
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-db', 'node_id' => $this->dbNode->id, 'port' => 6379])
            ->assertCreated()
            ->assertJsonPath('data.driver', 'mysql');

        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode, ['slug' => 'beast-db', 'tag' => '8.4', 'port' => 6379]))
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_slug_conflict');

        expect(DatabaseServer::query()->sole()->driver->value)->toBe('mysql')
            ->and($this->programs->runs)->toBe([]);
    });

    it('validates the driver and the adoption fields', function (array $body): void {
        $this->postJson('/api/v1/database-servers', $body)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect(DatabaseServer::query()->exists())->toBeFalse()
            ->and($this->programs->runs)->toBe([]);
    })->with([
        'pgsql' => fn (): array => valkey_server_request($this->dbNode, ['driver' => 'pgsql']),
        'sqlite' => fn (): array => valkey_server_request($this->dbNode, ['driver' => 'sqlite']),
        'unknown driver' => fn (): array => valkey_server_request($this->dbNode, ['driver' => 'valkey']),
        'process without connection' => fn (): array => valkey_server_request($this->dbNode, ['process_id' => 1]),
        'connection without process' => fn (): array => valkey_server_request($this->dbNode, ['connection' => 'services-valkey']),
        'process with port' => fn (): array => valkey_server_request($this->dbNode, ['process_id' => 1, 'connection' => 'services-valkey', 'port' => 6379]),
        'string process' => fn (): array => valkey_server_request($this->dbNode, ['process_id' => '1', 'connection' => 'services-valkey']),
        'bad connection slug' => fn (): array => valkey_server_request($this->dbNode, ['process_id' => 1, 'connection' => 'Services Valkey']),
    ]);

    it('lists and shows the driver of each server', function (): void {
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-mysql', 'node_id' => $this->dbNode->id])->assertCreated();
        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))->assertCreated();

        $this->getJson('/api/v1/database-servers')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'beast-mysql')
            ->assertJsonPath('data.0.driver', 'mysql')
            ->assertJsonPath('data.1.slug', 'beast-valkey')
            ->assertJsonPath('data.1.driver', 'redis');

        $this->getJson('/api/v1/database-servers/beast-valkey')
            ->assertOk()
            ->assertJsonPath('data.driver', 'redis')
            ->assertJsonPath('data.databases', []);
    });
});

describe('the Process of a Valkey server', function (): void {
    beforeEach(function (): void {
        $this->postJson('/api/v1/database-servers', valkey_server_request($this->dbNode))->assertCreated();
        $this->server = DatabaseServer::query()->sole();
        $this->process = Process::query()->sole();
    });

    it('refuses process:update before it changes anything', function (): void {
        $before = $this->process->getAttributes();

        $this->patchJson("/api/v1/processes/{$this->process->id}", [
            'runtime' => 'docker',
            'image' => 'valkey/valkey:8.1',
            'command' => ['valkey-server', '--appendonly', 'yes'],
            'environment' => ['VALKEY_EXTRA_FLAGS' => '--aclfile /dev/null'],
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'process.required_by_database_server');

        expect($this->process->refresh()->getAttributes())->toBe($before)
            ->and($this->runtime->convergedProcessIds)->toBe([$this->process->id]);
    });

    it('keeps process:restart and process:destroy as for every server Process', function (): void {
        $this->postJson("/api/v1/processes/{$this->process->id}/restart")->assertOk();

        $this->deleteJson("/api/v1/processes/{$this->process->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'process.required_by_database_server');

        expect($this->runtime->restarted)->toBe([$this->process->id])
            ->and($this->runtime->removed)->toBe([]);
    });
});

describe('database:server:create --process --connection', function (): void {
    beforeEach(function (): void {
        $this->otherNode = Node::query()->create([
            'name' => 'shark',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.81',
            'public_ssh_port' => 22,
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.81',
        ]);
        $this->process = Process::query()->create([
            'owner_type' => Node::class,
            'owner_id' => $this->dbNode->id,
            'name' => 'valkey',
            'runtime' => ProcessRuntime::Docker,
            'working_directory' => null,
            'runtime_config' => [
                'image' => 'valkey/valkey:8.0',
                'command' => ['sh', '-c', 'exec valkey-server --appendonly yes --requirepass "$VALKEY_PASSWORD"'],
                'environment' => ['VALKEY_PASSWORD' => 'queue-secret'],
                'ports' => ['10.44.0.80:6379:6379'],
                'volumes' => [['source' => 'services-valkey-data', 'target' => '/data', 'read_only' => false]],
            ],
            'restart_policy' => 'unless-stopped',
            'desired_state' => DesiredProcessState::Running,
            'status' => LifecycleStatus::Active,
        ]);
        $this->connection = DatabaseConnection::query()->create([
            'slug' => 'services-valkey',
            'driver' => 'redis',
            'node_id' => $this->dbNode->id,
            'host' => '10.44.0.80',
            'port' => 6379,
            'password' => 'queue-secret',
        ]);
        $this->valkey->passwords['10.44.0.80:6379'] = 'queue-secret';
        $this->adoption = valkey_server_request($this->dbNode, [
            'slug' => 'services-valkey',
            'process_id' => $this->process->id,
            'connection' => 'services-valkey',
        ]);
    });

    it('adopts an open server without locking out clients and closes it only after migration', function (): void {
        $this->connection->update(['password' => null]);
        $this->valkey->anonymous = true;
        $this->postJson('/api/v1/database-servers', $this->adoption)->assertCreated();
        $server = DatabaseServer::query()->sole();
        expect($server->default_open)->toBeTrue()
            ->and(strlen($server->root_password))->toBe(48)
            ->and($this->programs->runs[0]['input']['acl'])->toBe("user default on nopass ~* &* +@all\n")
            ->and($this->connection->refresh()->password)->toBeNull();
        $this->postJson('/api/v1/database-servers', $this->adoption)->assertOk();
        expect($this->programs->runs)->toHaveCount(1);

        $url = '/api/v1/database-servers/services-valkey/close';
        $this->postJson($url)->assertConflict()->assertJsonPath('error.code', 'database.server_in_use');
        $this->connection->delete();
        $this->valkey->defaultClients = ['10.44.0.80:12345'];
        $this->postJson($url)->assertConflict()->assertJsonPath('error.code', 'database.server_in_use');
        expect($server->refresh()->default_open)->toBeTrue();
        $this->valkey->defaultClients = [];
        $this->valkey->failCommands = true;
        $this->postJson($url)->assertStatus(502);
        expect($server->refresh()->default_open)->toBeTrue();
        $this->valkey->failCommands = false;
        $response = $this->postJson($url)->assertOk()->assertJsonPath('data.driver', 'redis');
        valkey_server_assert_no_secret($response, $server->root_password);
        expect($server->refresh()->default_open)->toBeFalse()->and($this->valkey->anonymous)->toBeFalse();
        $calls = count($this->valkey->calls);
        $this->postJson($url)->assertOk();
        expect($this->valkey->calls)->toHaveCount($calls);
        $server->update(['status' => LifecycleStatus::Failed]);
        $this->postJson($url)->assertConflict()->assertJsonPath('error.code', 'database.server_inactive');
    });

    it('retries failed open adoption with the same generated admin password', function (): void {
        $this->connection->update(['password' => null]);
        $this->valkey->anonymous = true;
        $this->runtime->failConverge = true;
        $this->postJson('/api/v1/database-servers', $this->adoption)->assertStatus(502);
        $password = DatabaseServer::query()->sole()->root_password;
        $this->runtime->failConverge = false;
        $this->postJson('/api/v1/database-servers', $this->adoption)->assertOk();
        expect(DatabaseServer::query()->sole()->root_password)->toBe($password)
            ->and($this->programs->runs)->toHaveCount(1);
    });

    it('refuses adoption that would overwrite existing ACL users', function (): void {
        $this->valkey->users['queue'] = new ValkeyAclUser('queue', 'on ~queue:* -@all +@list', []);
        $this->postJson('/api/v1/database-servers', $this->adoption)->assertConflict()->assertJsonPath('error.code', 'database.server_adoption_refused');
        expect($this->programs->runs)->toBe([])->and(DatabaseServer::query()->exists())->toBeFalse();
    });

    it('refuses concurrent provisioning before any remote mutation', function (): void {
        $lock = Cache::lock('database-server:provision:services-valkey', 900);
        $lock->get();
        try {
            $this->postJson('/api/v1/database-servers', $this->adoption)->assertConflict()->assertJsonPath('error.code', 'database.server_in_use');
            expect($this->programs->runs)->toBe([])->and(DatabaseServer::query()->exists())->toBeFalse();
        } finally {
            $lock->release();
        }
    });

    it('takes the connection password, writes the ACL file, replaces the specification, and links the connection', function (): void {
        $this->programs->during = function (): void {
            expect($this->process->refresh()->runtime_config['image'])->toBe('valkey/valkey:8.0');
        };

        $response = $this->postJson('/api/v1/database-servers', $this->adoption)
            ->assertCreated()
            ->assertJsonPath('data.slug', 'services-valkey')
            ->assertJsonPath('data.driver', 'redis')
            ->assertJsonPath('data.process_id', $this->process->id)
            ->assertJsonPath('data.port', 6379)
            ->assertJsonPath('data.tag', '8.1')
            ->assertJsonPath('data.status', 'active');

        $server = DatabaseServer::query()->sole();
        $process = $this->process->refresh();

        expect($server->root_password)->toBe('queue-secret')
            ->and($this->programs->runs)->toBe([[
                'node' => 'beast',
                'input' => [
                    'volume' => 'services-valkey-data',
                    'image' => 'valkey/valkey:8.1',
                    'acl' => valkey_acl_file('queue-secret'),
                ],
            ]])
            ->and(Process::query()->count())->toBe(1)
            ->and($process->name)->toBe('valkey')
            ->and($process->working_directory)->toBe('/data')
            ->and($process->restart_policy)->toBe('unless-stopped')
            ->and($process->desired_state)->toBe(DesiredProcessState::Running)
            ->and($process->status)->toBe(LifecycleStatus::Active)
            ->and($process->runtime_config)->toBe(valkey_server_runtime_config('10.44.0.80:6379:6379', 'services-valkey-data'))
            ->and(json_encode($process->getAttributes()))->not->toContain('queue-secret')
            ->and($this->runtime->convergedProcessIds)->toBe([$process->id])
            ->and($this->valkey->readyChecks)->toBe(['services-valkey'])
            ->and($this->connection->refresh()->database_server_id)->toBe($server->id);

        valkey_server_assert_no_secret($response, 'queue-secret');

        $this->getJson('/api/v1/database-servers/services-valkey')
            ->assertOk()
            ->assertJsonPath('data.databases_count', 1)
            ->assertJsonPath('data.databases.0.slug', 'services-valkey')
            ->assertDontSee('queue-secret');
    });

    it('returns the adopted server with status 200 on a repeat', function (): void {
        $this->postJson('/api/v1/database-servers', $this->adoption)->assertCreated();

        $this->postJson('/api/v1/database-servers', $this->adoption)
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.process_id', $this->process->id);

        expect($this->programs->runs)->toHaveCount(1)
            ->and($this->runtime->convergedProcessIds)->toHaveCount(1)
            ->and(DatabaseServer::query()->count())->toBe(1);
    });

    it('continues a failed adoption from the step that failed and links the connection last', function (): void {
        $this->runtime->failConverge = true;

        $this->postJson('/api/v1/database-servers', $this->adoption)
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'process.docker_converge_failed');

        expect(DatabaseServer::query()->sole()->failed_step)->toBe('process')
            ->and($this->connection->refresh()->database_server_id)->toBeNull();

        $this->runtime->failConverge = false;
        $this->valkey->readiness = [false];
        // The Process no longer starts with the old password variable, so the live check cannot run again.
        $this->valkey->passwords = [];

        $this->postJson('/api/v1/database-servers', $this->adoption)
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'database.server_start_failed');

        expect(DatabaseServer::query()->sole()->failed_step)->toBe('readiness')
            ->and($this->connection->refresh()->database_server_id)->toBeNull();

        $this->postJson('/api/v1/database-servers', $this->adoption)
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        expect($this->programs->runs)->toHaveCount(1)
            ->and($this->runtime->convergedProcessIds)->toBe([$this->process->id, $this->process->id])
            ->and($this->connection->refresh()->database_server_id)->toBe(DatabaseServer::query()->sole()->id);
    });

    it('refuses another Process for an adopted slug', function (): void {
        $this->postJson('/api/v1/database-servers', $this->adoption)->assertCreated();

        $this->postJson('/api/v1/database-servers', [...$this->adoption, 'process_id' => $this->process->id + 1])
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_slug_conflict');
    });

    it('refuses an adoption that fails a check, names the check, and changes nothing', function (Closure $arrange, string $message): void {
        $arrange->call($this);
        $process = $this->process->refresh()->getAttributes();
        $servers = DatabaseServer::query()->count();

        $this->postJson('/api/v1/database-servers', $this->adoption)
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_adoption_refused')
            ->assertJsonPath('error.message', fn (string $actual): bool => str_contains($actual, $message));

        expect(DatabaseServer::query()->count())->toBe($servers)
            ->and($this->process->refresh()->getAttributes())->toBe($process)
            ->and($this->connection->refresh()->database_server_id)->toBeNull()
            ->and($this->programs->runs)->toBe([])
            ->and($this->runtime->convergedProcessIds)->toBe([]);
    })->with([
        'a mysql driver' => [function (): void {
            $this->adoption['driver'] = 'mysql';
        }, 'Only a redis server can adopt a Process.'],
        'no driver' => [function (): void {
            unset($this->adoption['driver']);
        }, 'Only a redis server can adopt a Process.'],
        'a missing Process' => [function (): void {
            $this->adoption['process_id'] = 9999;
        }, 'is not a Docker Process of Node [beast]'],
        'a Process of another Node' => [function (): void {
            $this->process->update(['owner_id' => $this->otherNode->id]);
        }, 'is not a Docker Process of Node [beast]'],
        'a systemd Process' => [function (): void {
            $this->process->update(['runtime' => ProcessRuntime::Systemd]);
        }, 'is not a Docker Process of Node [beast]'],
        'another image' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'image' => 'redis:8']]);
        }, 'does not run a valkey/valkey image'],
        'another repository' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'image' => 'valkey/valkey-bundle:8.1']]);
        }, 'does not run a valkey/valkey image'],
        'no volume at /data' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'volumes' => []]]);
        }, 'has no Docker volume at /data'],
        'a host path at /data' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'volumes' => [['source' => '/srv/valkey', 'target' => '/data', 'read_only' => false]]]]);
        }, 'has no Docker volume at /data'],
        'a port outside WireGuard' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'ports' => ['127.0.0.1:6379:6379']]]);
        }, 'does not publish container port 6379 on the WireGuard address of Node [beast]'],
        'another container port' => [function (): void {
            $this->process->update(['runtime_config' => [...$this->process->runtime_config, 'ports' => ['10.44.0.80:6379:6380']]]);
        }, 'does not publish container port 6379 on the WireGuard address of Node [beast]'],
        'a Process that a server owns' => [function (): void {
            DatabaseServer::query()->create([
                'slug' => 'older-valkey',
                'driver' => 'redis',
                'node_id' => $this->dbNode->id,
                'process_id' => $this->process->id,
                'tag' => '8.1',
                'port' => 6379,
                'root_password' => 'other-secret',
                'status' => LifecycleStatus::Active,
            ]);
        }, 'already runs Database server [older-valkey]'],
        'a missing connection' => [function (): void {
            $this->adoption['connection'] = 'missing';
        }, 'Database connection [missing] is not a redis connection.'],
        'a mysql connection' => [function (): void {
            $this->connection->update(['driver' => 'mysql', 'database' => 'app', 'username' => 'app']);
        }, 'Database connection [services-valkey] is not a redis connection.'],
        'a connection to another host' => [function (): void {
            $this->connection->update(['host' => '10.44.0.81']);
        }, 'Database connection [services-valkey] does not point to 10.44.0.80:6379.'],
        'a connection to another port' => [function (): void {
            $this->connection->update(['port' => 6380]);
        }, 'Database connection [services-valkey] does not point to 10.44.0.80:6379.'],
        'a connection without a password' => [function (): void {
            $this->connection->update(['password' => null]);
        }, 'The password of Database connection [services-valkey] does not log in as default.'],
        'a connection with an ACL user' => [function (): void {
            $this->connection->update(['username' => 'queue']);
        }, 'The password of Database connection [services-valkey] does not log in as default.'],
        'a password that does not log in' => [function (): void {
            $this->valkey->passwords['10.44.0.80:6379'] = 'rotated-secret';
        }, 'The password of Database connection [services-valkey] does not log in as default.'],
    ]);
});

describe('database:server:close boundary', function (): void {
    it('refuses MySQL and missing servers', function (): void {
        $this->postJson('/api/v1/database-servers', ['slug' => 'beast-mysql', 'node_id' => $this->dbNode->id])->assertCreated();
        $this->postJson('/api/v1/database-servers/beast-mysql/close')->assertUnprocessable()->assertJsonPath('error.code', 'database.driver_unsupported');
        $this->postJson('/api/v1/database-servers/missing/close')->assertNotFound();
    });

    it('requires an active peer', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->postJson('/api/v1/database-servers/missing/close')->assertForbidden();
    });
});
