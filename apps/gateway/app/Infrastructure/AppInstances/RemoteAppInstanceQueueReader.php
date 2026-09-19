<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Queue\AppInstanceQueueReader;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Process;
use JsonException;

final readonly class RemoteAppInstanceQueueReader implements AppInstanceQueueReader
{
    private const string Marker = "\n--orbit-queue--\n";

    public function __construct(
        private ProcessTargetResolver $targets,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function read(Process $horizon, string $state, int $limit): array
    {
        $target = $this->targets->forInspection($horizon);
        $directory = StoragePath::tryParse($horizon->working_directory ?? $target->defaultWorkingDirectory);
        $php = StoragePath::tryParse((string) ($horizon->runtime_config['command'][0] ?? ''));

        if ($directory === null || $php === null) {
            throw $this->failed($horizon);
        }

        // The script is fixed and arrives on stdin. The only values from the caller are the
        // validated state and limit, and each is one argument, never part of a shell line.
        $result = $this->ssh->execute(
            $this->connection($target->node),
            new RemoteCommand(
                [
                    'sudo', '-u', $target->user, '-H',
                    'env', '--chdir='.$directory->value,
                    $php->value, '-d', 'display_errors=0', '--', $state, (string) $limit,
                ],
                (string) file_get_contents(resource_path('scripts/horizon-queue.php')),
                maxOutputBytes: 1_048_576,
            ),
        );

        $marker = strrpos($result->stdout, self::Marker);

        if (! $result->succeeded() || $marker === false) {
            throw $this->failed($horizon);
        }

        try {
            $report = json_decode(
                substr($result->stdout, $marker + strlen(self::Marker)),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw $this->failed($horizon);
        }

        return is_array($report) ? $report : throw $this->failed($horizon);
    }

    private function failed(Process $horizon): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.queue_failed',
            message: "The queue of Process [{$horizon->name}] could not be read.",
            status: 502,
        );
    }

    private function connection(Node $node): SshConnection
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'instance.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
            );
        }

        return new SshConnection(
            host: $node->wireguard_ip,
            user: $node->user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
            commandTimeout: 30.0,
        );
    }
}
