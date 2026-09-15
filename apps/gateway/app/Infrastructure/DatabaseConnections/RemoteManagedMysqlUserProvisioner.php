<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseConnections;

use App\Domain\DatabaseConnections\ManagedMysqlProcess;
use App\Domain\DatabaseConnections\ManagedMysqlUserProvisioner;
use App\Domain\DatabaseConnections\ManagedMysqlUserStatements;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use SensitiveParameter;

final readonly class RemoteManagedMysqlUserProvisioner implements ManagedMysqlUserProvisioner
{
    private const string MYSQL_USER_SCRIPT = <<<'BASH'
        umask 077
        env_file=$(mktemp /tmp/orbit-mysql-env.XXXXXX)
        sql_file=$(mktemp /tmp/orbit-mysql-sql.XXXXXX)
        trap 'rm -f -- "$env_file" "$sql_file"' EXIT
        IFS= read -r env_line
        printf '%s\n' "$env_line" > "$env_file"
        chmod 0600 "$env_file"
        cat > "$sql_file"
        chmod 0600 "$sql_file"
        sudo docker container exec -i --env-file "$env_file" -- "$1" mysql --user=root --batch < "$sql_file"
        BASH;

    public function __construct(
        private ProcessTargetResolver $targets,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private DockerProcessRenderer $docker,
        private ManagedMysqlUserStatements $statements,
    ) {}

    public function ensureUser(
        ManagedMysqlProcess $process,
        string $database,
        string $username,
        #[SensitiveParameter]
        string $password,
    ): void {
        $target = $this->targets->forInspection($process->process);
        $container = $this->docker->containerName($process->process);
        $sql = $this->statements->render($database, $username, $password);
        $input = ProtectedInput::fromString("MYSQL_PWD={$process->rootPassword}\n{$sql}");

        try {
            if (! is_string($target->node->wireguard_ip) || $target->node->wireguard_ip === '') {
                throw new ResourceOperationException(
                    errorCode: 'process.wireguard_ip_missing',
                    message: "Node [{$target->node->name}] has no WireGuard address.",
                    status: 422,
                );
            }

            $result = $this->ssh->execute(
                new SshConnection(
                    host: $target->node->wireguard_ip,
                    user: $target->node->user,
                    port: 22,
                    identityFile: $this->keys->privateKeyPath(),
                    knownHostsFile: $this->knownHosts->path(),
                ),
                new RemoteCommand(
                    [
                        'bash',
                        '-seu',
                        '-c',
                        self::MYSQL_USER_SCRIPT,
                        'orbit-database-user-create',
                        $container,
                    ],
                    protectedInput: $input,
                ),
            );
        } finally {
            $input->close();
        }

        if ($result->succeeded()) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'database.user_create_failed',
            message: "Process [{$process->process->name}] could not create the MySQL user.",
            status: 502,
        );
    }
}
