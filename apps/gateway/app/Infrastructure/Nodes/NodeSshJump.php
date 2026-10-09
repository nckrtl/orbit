<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Ssh\SshConnection;
use App\Models\Node;

/**
 * The connection to the Node that public SSH to a Node goes through. It applies only while the Node
 * has no active role: after that, the Gateway reaches the Node over WireGuard.
 */
final readonly class NodeSshJump
{
    public static function connection(Node $node, string $identityFile, string $knownHostsFile): ?SshConnection
    {
        if ($node->ssh_jump_node_id === null || $node->roles()->where('status', LifecycleStatus::Active)->exists()) {
            return null;
        }

        $jump = $node->sshJumpNode;

        if (! $jump instanceof Node || ! is_string($jump->wireguard_ip) || $jump->wireguard_ip === '') {
            throw new NodeProvisioningException(
                'wireguard-address',
                'vpn.peer_address_missing',
                "The jump node of node [{$node->name}] has no WireGuard address.",
            );
        }

        return new SshConnection(
            host: $jump->wireguard_ip,
            user: $jump->user,
            port: 22,
            identityFile: $identityFile,
            knownHostsFile: $knownHostsFile,
        );
    }
}
