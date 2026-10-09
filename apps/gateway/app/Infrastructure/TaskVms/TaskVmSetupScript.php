<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

/**
 * Runs one of the one-time task VM setup scripts in `resources/task-vms` on a Node as root.
 * The script goes on stdin; only the validated arguments are on the command line.
 */
final readonly class TaskVmSetupScript
{
    public const string HostScript = 'incus-host.sh';

    public const string HubScript = 'hub.sh';

    private const string Confirmation = '{"ok":true}';

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    /** @param  list<string>  $arguments */
    public function run(Node $node, string $script, array $arguments): void
    {
        $source = file_get_contents(resource_path('task-vms/'.$script));

        if (! is_string($source) || ! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException('task_vm.setup_unavailable', "Cannot run [{$script}] on Node [{$node->name}].", 409);
        }

        $result = $this->ssh->execute(
            new SshConnection(
                host: $node->wireguard_ip,
                user: $node->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
                commandTimeout: 900.0,
            ),
            new RemoteCommand(['sudo', '-n', 'bash', '-s', '--', ...$arguments], input: $source, maxOutputBytes: 65_536),
        );

        if (! $result->succeeded() || trim($result->stdout) !== self::Confirmation) {
            $detail = implode("\n", array_slice(explode("\n", trim($result->stderr)), -10));

            throw new ResourceOperationException(
                'task_vm.setup_failed',
                "[{$script}] failed on Node [{$node->name}] with exit code [{$result->exitCode}].".($detail === '' ? '' : "\n{$detail}"),
                409,
            );
        }
    }
}
