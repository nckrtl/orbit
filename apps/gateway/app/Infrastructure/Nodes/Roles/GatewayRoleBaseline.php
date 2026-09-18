<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class GatewayRoleBaseline implements RoleBaseline
{
    public function __construct(
        private NodeRoleFirewallManager $firewall,
        private PrivateDnsManager $dns,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->firewall->converge($node, RoleName::Gateway, $node->user);
        $this->dns->converge();
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->firewall->remove($node, RoleName::Gateway, $node->user);
        $this->dns->converge();
    }

    /**
     * Removes only what lives on the Gateway, for a gateway node Orbit
     * cannot reach: the private DNS record. Caddy, PHP-FPM, the serving
     * checkout, and the HTTPS firewall stay on the box.
     */
    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->dns->converge();
    }
}
