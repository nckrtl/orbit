<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

/** What is updating a Node right now ([Nodes being updated](/reference/gateway-recovery#nodes-being-updated)). */
enum NodeUpdateKind: string
{
    /** The fleet rollout or its catch-up visits the Node. */
    case FleetRollout = 'fleet_rollout';

    /** A Gateway release runs on the Gateway Node. */
    case GatewayRelease = 'gateway_release';
}
