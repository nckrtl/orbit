<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Nodes\OsReleaseParserProgram;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NodeRoleOperatingSystemGuard
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function assert(Node $node, RoleName $role): void
    {
        $wireGuardAddress = $node->wireguard_ip;

        if (! is_string($wireGuardAddress) || $wireGuardAddress === '') {
            throw new NodeRoleOperationException(
                'operating-system',
                'node_role.convergence_failed',
                'node_role.wireguard_missing',
                "Node [{$node->name}] role [{$role->value}] requires a WireGuard address.",
            );
        }

        $requiredReleases = UbuntuRelease::forRole($role);
        $result = $this->ssh->execute(
            new SshConnection(
                $wireGuardAddress,
                $node->user,
                22,
                $this->keys->privateKeyPath(),
                $this->knownHosts->path(),
            ),
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $role->value,
                    'ubuntu',
                    UbuntuRelease::unsupportedText(),
                    (string) count($requiredReleases),
                    ...array_map(
                        static fn (UbuntuRelease $release): string => $release->value,
                        $requiredReleases,
                    ),
                ],
                input: <<<'BASH'
                    role=$1
                    shift
                    BASH."\n".OsReleaseParserProgram::render(),
            ),
        );

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'operating-system',
                'node_role.convergence_failed',
                'node_role.operating_system_unsupported',
                "Node [{$node->name}] role [{$role->value}]. "
                    .UbuntuRelease::unsupportedTextFromOutput($result->stderr),
                result: $result,
            );
        }
    }
}
