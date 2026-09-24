<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

/**
 * Runs a Node Caddy build command. The Node that runs the Gateway process runs it through local
 * `sudo`; every other Node runs it over pinned SSH. The Gateway's recorded serving Node names that
 * Node. Before bootstrap records it, the Node whose `gateway` role serves its site is that Node,
 * so bootstrap and a failed reconvergence still build locally.
 */
final readonly class NodeCaddyTransport
{
    public function __construct(
        private ProcessRunner $processes,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private GatewayServingHost $servingHost,
    ) {}

    public function runsLocally(Node $node): bool
    {
        $serving = $this->servingHost->nodeId();

        if ($serving !== null) {
            return $serving === $node->id;
        }

        return CaddySiteRoles::nodeServes($node->id, RoleName::Gateway);
    }

    public function run(Node $node, RemoteCommand $command): CommandResult
    {
        if ($this->runsLocally($node)) {
            return $this->processes->run(new ProcessInvocation(
                arguments: $command->arguments,
                timeout: $command->timeout ?? 120.0,
                input: $command->input,
                maxOutputBytes: $command->maxOutputBytes,
            ));
        }

        $address = $node->wireguard_ip;

        if (! is_string($address) || $address === '') {
            throw new NodeCaddyBuildException($node->name, 'connect', 'The Node has no WireGuard address.');
        }

        return $this->ssh->execute(
            new SshConnection(
                host: $address,
                user: $node->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
                commandTimeout: $command->timeout ?? 120.0,
            ),
            $command,
        );
    }
}
