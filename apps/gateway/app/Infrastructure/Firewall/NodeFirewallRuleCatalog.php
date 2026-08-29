<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Nodes\RoleName;
use App\Models\Node;

final class NodeFirewallRuleCatalog
{
    /** @return list<UfwManagedRule> */ public function forNode(Node $node): array
    {
        return [
            $this->rule('orbit:public-ssh-recovery', (string) $node->public_ssh_port),
            $this->rule('orbit:vpn-ssh', '22', $this->wireguardAddress($node), 'orbit'),
        ];
    }

    /** @return list<UfwManagedRule> */ public function forRole(Node $node, RoleName $role): array
    {
        return match ($role) {
            RoleName::Gateway => [
                $this->rule('orbit:gateway-https', '443', $this->wireguardAddress($node), 'orbit'),
            ],
            RoleName::Vpn => [$this->rule('orbit:vpn-ssh', '22', $this->wireguardAddress($node), 'orbit')],
            RoleName::AppDev => [
                $this->rule('orbit:app-dev-http', '80', $this->wireguardAddress($node), 'orbit'),
                $this->rule('orbit:app-dev-https', '443', $this->wireguardAddress($node), 'orbit'),
            ],
            RoleName::AppProd => [$this->rule('orbit:app-prod-http', '80'), $this->rule('orbit:app-prod-https', '443')],
            RoleName::Metrics => [],
        };
    }

    private function rule(
        string $comment,
        string $port,
        ?string $destination = null,
        ?string $interface = null,
    ): UfwManagedRule {
        $destination ??= 'any';
        $on = $interface === null ? [] : ['on', $interface];

        return new UfwManagedRule(
            new UfwRuleShape(
                $comment,
                'allow',
                'in',
                'any',
                $destination,
                $port,
                'tcp',
                $interface,
                null,
                $destination === 'any' ? null : 'v4',
            ),
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                ...$on,
                'proto',
                'tcp',
                'to',
                $destination,
                'port',
                $port,
                'comment',
                $comment,
            ],
        );
    }

    private function wireguardAddress(Node $node): string
    {
        if (! is_string($node->wireguard_address) || $node->wireguard_address === '') {
            throw new FirewallOperationException(
                step: 'host-firewall',
                errorCode: 'node.firewall_convergence_failed',
                message: "Node [{$node->name}] has no WireGuard address for a scoped firewall rule.",
            );
        }

        return $node->wireguard_address;
    }
}
