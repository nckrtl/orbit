<?php

declare(strict_types=1);

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseServers\RemoteDatabaseServerAdmin;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;

const REMOTE_DATABASE_SERVER_ROOT = 'root-secret-aa12';

function remote_database_server(): DatabaseServer
{
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);
    $process = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => 'beast-mysql',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => ['image' => 'mysql:8.4', 'command' => ['mysqld'], 'environment' => [], 'ports' => [], 'volumes' => []],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);

    return DatabaseServer::query()->create([
        'slug' => 'beast-mysql',
        'node_id' => $node->id,
        'process_id' => $process->id,
        'tag' => '8.4',
        'port' => 3306,
        'root_password' => REMOTE_DATABASE_SERVER_ROOT,
        'status' => LifecycleStatus::Active,
    ]);
}

function remote_database_server_admin(RemoteDatabaseServerFakeSsh $ssh): RemoteDatabaseServerAdmin
{
    return new RemoteDatabaseServerAdmin(
        ssh: $ssh,
        keys: new RemoteDatabaseServerFakeKeys,
        knownHosts: new RemoteDatabaseServerFakeHosts,
        docker: new DockerProcessRenderer,
    );
}

describe('RemoteDatabaseServerAdmin', function (): void {
    it('runs SQL through docker exec with the root password only on protected input', function (): void {
        $server = remote_database_server();
        $ssh = new RemoteDatabaseServerFakeSsh([new CommandResult(0, "app\napp_test\tx\n", '', 5, false)]);

        $rows = remote_database_server_admin($ssh)->execute($server, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA;\n");
        $command = $ssh->commands[0];

        expect($rows)->toBe(['app', 'app_test'])
            ->and(array_slice($command->arguments, 0, 3))->toBe(['bash', '-eu', '-c'])
            ->and($command->arguments[3])->toBe(RemoteDatabaseServerAdmin::EXECUTE_SCRIPT)
            ->toContain('read -r MYSQL_PWD')
            ->toContain('docker container exec -i --env MYSQL_PWD -- "$1" mysql --user=root --batch --skip-column-names')
            ->not->toContain('--env-file')
            ->and(array_slice($command->arguments, 4))->toBe(['orbit-database-server-sql', "orbit-process-{$server->process_id}-beast-mysql"])
            ->and(json_encode($command->arguments, JSON_THROW_ON_ERROR))
            ->not->toContain(REMOTE_DATABASE_SERVER_ROOT)
            ->not->toContain('information_schema')
            ->and($ssh->protectedInputs[0])
            ->toBe(REMOTE_DATABASE_SERVER_ROOT."\nSELECT SCHEMA_NAME FROM information_schema.SCHEMATA;\n")
            ->and($ssh->connections[0]->host)->toBe('10.44.0.80')
            ->and($ssh->connections[0]->user)->toBe('orbit');
    });

    it('maps a failed command to database.server_command_failed without its output', function (): void {
        $ssh = new RemoteDatabaseServerFakeSsh([new CommandResult(1, '', 'ERROR 1045: Access denied for user root', 5, false)]);

        expect(fn () => remote_database_server_admin($ssh)->execute(remote_database_server(), "DROP DATABASE x;\n"))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('database.server_command_failed')
                    ->and($exception->status)->toBe(502)
                    ->and($exception->getMessage())
                    ->not->toContain('Access denied')
                    ->not->toContain(REMOTE_DATABASE_SERVER_ROOT);
            });
    });

    it('copies a database inside the container with the password only on protected input', function (): void {
        $server = remote_database_server();
        $ssh = new RemoteDatabaseServerFakeSsh([new CommandResult(0, '', '', 5, false), new CommandResult(1, '', 'mysqldump: Got error', 5, false)]);
        $admin = remote_database_server_admin($ssh);

        $admin->copyDatabase($server, 'acme_default', 'acme_feature_x');

        expect($ssh->commands[0]->arguments[3])->toBe(RemoteDatabaseServerAdmin::COPY_SCRIPT)
            ->toContain('mysqldump --user=root --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF')
            ->toContain('bash -euo pipefail')
            ->and(array_slice($ssh->commands[0]->arguments, 4))->toBe(['orbit-database-server-copy', "orbit-process-{$server->process_id}-beast-mysql", 'acme_default', 'acme_feature_x'])
            ->and(json_encode($ssh->commands[0]->arguments, JSON_THROW_ON_ERROR))->not->toContain(REMOTE_DATABASE_SERVER_ROOT)
            ->and($ssh->protectedInputs[0])->toBe(REMOTE_DATABASE_SERVER_ROOT."\n")
            ->and(fn () => $admin->copyDatabase($server, 'acme_default', 'acme_feature_x'))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('database.server_command_failed')
                ->and($exception->getMessage())->not->toContain('mysqldump'))
            ->and(fn () => $admin->copyDatabase($server, 'acme_default', 'x; DROP'))
            ->toThrow(ResourceOperationException::class);
    });

    it('waits for the root login over TCP and reports a timeout', function (): void {
        $ssh = new RemoteDatabaseServerFakeSsh([
            new CommandResult(0, '', '', 5, false),
            new CommandResult(75, '', '', 5, false),
        ]);
        $admin = remote_database_server_admin($ssh);
        $server = remote_database_server();

        expect($admin->waitUntilReady($server, 120))->toBeTrue()
            ->and($admin->waitUntilReady($server, 120))->toBeFalse()
            ->and($ssh->commands[0]->arguments[3])->toBe(RemoteDatabaseServerAdmin::READY_SCRIPT)
            ->toContain('mysql --protocol=TCP --host=127.0.0.1 --user=root')
            ->and(array_slice($ssh->commands[0]->arguments, 4))->toBe(['orbit-database-server-ready', "orbit-process-{$server->process_id}-beast-mysql", '120'])
            ->and($ssh->commands[0]->timeout)->toBe(180.0)
            ->and($ssh->protectedInputs[0])->toBe(REMOTE_DATABASE_SERVER_ROOT."\n");
    });
});

final class RemoteDatabaseServerFakeSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<string> */
    public array $protectedInputs = [];

    /** @param list<CommandResult> $results */
    public function __construct(private array $results = []) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;

        if ($command->protectedInput instanceof ProtectedInput) {
            $contents = stream_get_contents($command->protectedInput->stream());

            if ($contents === false) {
                throw new RuntimeException('Unable to read protected test input.');
            }

            $this->protectedInputs[] = $contents;
        }

        return array_shift($this->results) ?? new CommandResult(0, '', '', 1, false);
    }
}

final class RemoteDatabaseServerFakeKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/orbit/ssh/id_ed25519';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 test';
    }
}

final class RemoteDatabaseServerFakeHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/orbit/ssh/known_hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
