<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class DatabaseRoleBaseline implements RoleBaseline
{
    public function __construct(
        private NodeRolePrerequisiteCommandFactory $commands,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'database.wireguard_ip_missing',
                "Node [{$node->name}] has no WireGuard address.",
            );
        }

        $account = $this->accounts->resolve($node);
        $result = $this->ssh->execute(
            new SshConnection(
                $node->wireguard_ip,
                $node->user,
                22,
                $this->keys->privateKeyPath(),
                $this->knownHosts->path(),
            ),
            $this->commands->make($node, RoleName::Database, $account),
        );

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'database.prerequisite_failed',
                "Database role prerequisites failed on node [{$node->name}].",
                $result,
            );
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

    public function removeUnreachable(Node $node, NodeRole $assignment): void {}
}
