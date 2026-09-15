<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\ManagedMysqlProcess;
use App\Domain\DatabaseConnections\ManagedMysqlUserStatements;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseConnections\RemoteManagedMysqlUserProvisioner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Process;

const MANAGED_MYSQL_PROVISION_ROOT = 'root-secret-aa12';
const MANAGED_MYSQL_PROVISION_USER = 'user-secret-bb34';

it('executes a fixed docker exec script and keeps secrets out of argv', function (): void {
    $ssh = new ManagedMysqlProvisionerFakeSsh;
    $process = managed_mysql_provisioner_process();
    $managed = ManagedMysqlProcess::from($process);

    new RemoteManagedMysqlUserProvisioner(
        targets: new ProcessTargetResolver,
        ssh: $ssh,
        keys: new ManagedMysqlProvisionerFakeKeys,
        knownHosts: new ManagedMysqlProvisionerFakeHosts,
        docker: new DockerProcessRenderer,
        statements: new ManagedMysqlUserStatements,
    )->ensureUser($managed, 'app', 'app', MANAGED_MYSQL_PROVISION_USER);

    expect($ssh->commands)->toHaveCount(1);

    $command = $ssh->commands[0];
    $encoded = json_encode($command->arguments, JSON_THROW_ON_ERROR);

    expect($command->arguments[0])
        ->toBe('bash')
        ->and($command->arguments[1])
        ->toBe('-seu')
        ->and($command->arguments[2])
        ->toBe('-c')
        ->and($command->arguments[3])
        ->toContain('sudo docker container exec -i --env-file')
        ->toContain('mysql --user=root --batch')
        ->and($command->arguments[4])
        ->toBe('orbit-database-user-create')
        ->and($command->arguments[5])
        ->toBe('orbit-process-'.$process->id.'-mysql')
        ->and($encoded)
        ->not->toContain(MANAGED_MYSQL_PROVISION_ROOT)
        ->not->toContain(MANAGED_MYSQL_PROVISION_USER)
        ->and($ssh->protectedInputs[0])
        ->toStartWith('MYSQL_PWD='.MANAGED_MYSQL_PROVISION_ROOT."\n")
        ->toContain("IDENTIFIED BY '".MANAGED_MYSQL_PROVISION_USER."'")
        ->and($ssh->connections[0]->host)
        ->toBe('10.44.0.80');
});

it('maps a failed remote create to database.user_create_failed without leaking secrets', function (): void {
    $ssh = new ManagedMysqlProvisionerFakeSsh([
        new CommandResult(1, '', 'Access denied for user', 12, false),
    ]);
    $process = managed_mysql_provisioner_process();

    expect(fn () => new RemoteManagedMysqlUserProvisioner(
        targets: new ProcessTargetResolver,
        ssh: $ssh,
        keys: new ManagedMysqlProvisionerFakeKeys,
        knownHosts: new ManagedMysqlProvisionerFakeHosts,
        docker: new DockerProcessRenderer,
        statements: new ManagedMysqlUserStatements,
    )->ensureUser(ManagedMysqlProcess::from($process), 'app', 'app', MANAGED_MYSQL_PROVISION_USER))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('database.user_create_failed')
                ->and($exception->status)
                ->toBe(502)
                ->and($exception->getMessage())
                ->not->toContain(MANAGED_MYSQL_PROVISION_ROOT)
                ->not->toContain(MANAGED_MYSQL_PROVISION_USER)
                ->not->toContain('Access denied');
        });
});

function managed_mysql_provisioner_process(): Process
{
    $node = Node::query()->create([
        'name' => 'db',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);

    return Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => 'mysql',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'mysql:8.4',
            'command' => ['mysqld'],
            'environment' => ['MYSQL_ROOT_PASSWORD' => MANAGED_MYSQL_PROVISION_ROOT],
            'ports' => ['3307:3306/tcp'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
}

final class ManagedMysqlProvisionerFakeSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<string> */
    public array $protectedInputs = [];

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results = [],
    ) {}

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

final class ManagedMysqlProvisionerFakeKeys implements SshKeyProvider
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

final class ManagedMysqlProvisionerFakeHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/orbit/ssh/known_hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
