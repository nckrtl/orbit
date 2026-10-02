<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseServers;

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

/**
 * Runs `mysql` as root inside the server's container with `docker exec`, over SSH to its Node.
 *
 * The first line of protected standard input is the root password. The script reads it into
 * MYSQL_PWD and hands it to `docker exec` through the environment, so it never reaches an
 * argument list or a file. The rest of standard input is the SQL.
 */
final readonly class RemoteDatabaseServerAdmin implements DatabaseServerAdmin
{
    public const string EXECUTE_SCRIPT = <<<'BASH'
        IFS= read -r MYSQL_PWD
        export MYSQL_PWD
        exec sudo --preserve-env=MYSQL_PWD docker container exec -i --env MYSQL_PWD -- "$1" mysql --user=root --batch --skip-column-names
        BASH;

    /**
     * The dump and the load run in one shell inside the container, so the data never leaves it.
     * Both names are checked identifiers; the password reaches both clients through MYSQL_PWD.
     */
    public const string COPY_SCRIPT = <<<'BASH'
        IFS= read -r MYSQL_PWD
        export MYSQL_PWD
        exec sudo --preserve-env=MYSQL_PWD docker container exec -i --env MYSQL_PWD -- "$1" bash -euo pipefail -c 'mysqldump --user=root --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF "$1" | mysql --user=root "$2"' orbit-database-copy "$2" "$3"
        BASH;

    /** TCP reaches only the final server: the image's first-start server listens on its socket alone. */
    public const string READY_SCRIPT = <<<'BASH'
        IFS= read -r MYSQL_PWD
        export MYSQL_PWD
        deadline=$((SECONDS + $2))
        while :; do
            if printf 'SELECT 1;\n' | sudo --preserve-env=MYSQL_PWD docker container exec -i --env MYSQL_PWD -- "$1" mysql --protocol=TCP --host=127.0.0.1 --user=root --batch --skip-column-names >/dev/null 2>&1; then
                exit 0
            fi
            if [ "$SECONDS" -ge "$deadline" ]; then
                exit 75
            fi
            sleep 2
        done
        BASH;

    private const float COMMAND_TIMEOUT_SECONDS = 600.0;

    private const int MAX_OUTPUT_BYTES = 1_048_576;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private DockerProcessRenderer $docker,
    ) {}

    public function waitUntilReady(DatabaseServer $server, int $timeoutSeconds): bool
    {
        $result = $this->run(
            $server,
            self::READY_SCRIPT,
            'orbit-database-server-ready',
            [(string) $timeoutSeconds],
            '',
            (float) ($timeoutSeconds + 60),
        );

        return $result->succeeded();
    }

    public function execute(DatabaseServer $server, #[SensitiveParameter] string $sql): array
    {
        $result = $this->run(
            $server,
            self::EXECUTE_SCRIPT,
            'orbit-database-server-sql',
            [],
            $sql,
            self::COMMAND_TIMEOUT_SECONDS,
        );

        if (! $result->succeeded() || $result->truncated) {
            throw $this->failed($server);
        }

        $rows = [];

        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $rows[] = explode("\t", $line, 2)[0];
        }

        return $rows;
    }

    public function copyDatabase(DatabaseServer $server, string $source, string $target): void
    {
        foreach ([$source, $target] as $database) {
            if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1) {
                throw $this->failed($server);
            }
        }

        $result = $this->run(
            $server,
            self::COPY_SCRIPT,
            'orbit-database-server-copy',
            [$source, $target],
            '',
            self::COMMAND_TIMEOUT_SECONDS,
        );

        if (! $result->succeeded()) {
            throw $this->failed($server);
        }
    }

    /** @param list<string> $arguments */
    private function run(
        DatabaseServer $server,
        string $script,
        string $name,
        array $arguments,
        #[SensitiveParameter]
        string $sql,
        float $timeout,
    ): CommandResult {
        $node = $server->node;
        $process = $server->process;

        if (! $process instanceof Process) {
            throw $this->failed($server);
        }

        $host = $this->host($node);
        $container = $this->docker->containerName($process);
        $input = ProtectedInput::fromString($server->root_password."\n".$sql);

        try {
            return $this->ssh->execute(
                new SshConnection(
                    host: $host,
                    user: $node->user,
                    port: 22,
                    identityFile: $this->keys->privateKeyPath(),
                    knownHostsFile: $this->knownHosts->path(),
                ),
                new RemoteCommand(
                    ['bash', '-eu', '-c', $script, $name, $container, ...$arguments],
                    protectedInput: $input,
                    maxOutputBytes: self::MAX_OUTPUT_BYTES,
                    timeout: $timeout,
                ),
            );
        } finally {
            $input->close();
        }
    }

    private function host(Node $node): string
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'process.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
                status: 422,
            );
        }

        return $node->wireguard_ip;
    }

    private function failed(DatabaseServer $server): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.server_command_failed',
            message: "Database server [{$server->slug}] could not run an admin command.",
            status: 502,
        );
    }
}
