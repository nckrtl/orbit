<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;

/**
 * Re-renders and publishes the Gateway site for the active Gateway Node, using the hostname and
 * WireGuard address that bootstrap chose, without touching roles, VPN settings, or Node status.
 */
final readonly class ConvergeGatewayWebAction
{
    public function __construct(
        private GatewayWebConverger $web,
        private VpnSettings $vpnSettings,
    ) {}

    public function execute(): Node
    {
        $node = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static fn ($query) => $query
                ->where('role', RoleName::Gateway)
                ->where('status', LifecycleStatus::Active))
            ->first();

        if (! $node instanceof Node || ! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new NodeProvisioningException(
                step: 'gateway-web-lookup',
                errorCode: 'gateway.web_node_missing',
                message: 'No active Gateway Node with a WireGuard address exists.',
            );
        }

        $this->web->converge("{$node->name}.{$this->vpnSettings->domain()}", $node->wireguard_ip);

        return $node;
    }
}
