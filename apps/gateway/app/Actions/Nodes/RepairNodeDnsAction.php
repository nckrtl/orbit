<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Models\Node;

final readonly class RepairNodeDnsAction
{
    public function __construct(
        private WireGuardPeerDnsRepairer $repairer,
    ) {}

    public function execute(string $name): Node
    {
        $node = Node::query()->with('roles')->where('name', $name)->first();

        if (! $node instanceof Node) {
            throw $this->failure('lookup', 'node.dns_repair_missing', "Node [{$name}] does not exist.");
        }

        if ($node->status !== LifecycleStatus::Active) {
            throw $this->failure('lookup', 'node.dns_repair_inactive', "Node [{$name}] is not active.");
        }

        if ($node->roles->isEmpty()) {
            throw $this->failure(
                'validation',
                'node.dns_repair_operator_owned',
                "Node [{$name}] has client-owned resolver configuration.",
            );
        }

        if ($node->roles->contains('role', RoleName::Vpn)) {
            throw $this->failure(
                'validation',
                'node.dns_repair_vpn_server',
                "Node [{$name}] hosts Orbit VPN DNS.",
            );
        }

        if ($node->platform !== 'linux') {
            throw $this->failure(
                'validation',
                'node.dns_repair_platform_unsupported',
                "Node [{$name}] is not a managed Linux peer.",
            );
        }

        if (
            ! is_string($node->wireguard_ip)
            || $node->wireguard_ip === ''
            || ! is_string($node->wireguard_public_key)
            || $node->wireguard_public_key === ''
            || ! is_string($node->ssh_host_fingerprint)
            || $node->ssh_host_fingerprint === ''
        ) {
            throw $this->failure(
                'validation',
                'node.dns_repair_identity_missing',
                "Node [{$name}] has no complete managed peer identity.",
            );
        }

        $this->repairer->repair($node);

        return $node->refresh()->load('roles');
    }

    private function failure(string $step, string $errorCode, string $message): NodeProvisioningException
    {
        return new NodeProvisioningException($step, $errorCode, $message);
    }
}
