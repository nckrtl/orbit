<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;

/**
 * The private DNS route on the machine that holds the `gateway` role (ADR 0156).
 *
 * Role convergence and removal use it through the gateway role baseline. Relocation moves the
 * role without running that baseline, so it adds the route on the target and removes it from the
 * source through this contract.
 */
interface GatewayPrivateDnsRoute
{
    /**
     * Routes the private domain on the Node to Orbit VPN DNS. A failure never throws: it is logged
     * with its underlying error code and recorded as the role response's `follow_up`.
     */
    public function convergeRoute(Node $node): void;

    /**
     * Removes the route's drop-in and reverts the `orbit` link.
     *
     * @throws NodeRoleOperationException with error code `node_role.remove_failed` and underlying
     *                                    code `vpn.dns_resolver_failed` when the step fails
     */
    public function removeRoute(Node $node): void;
}
