<?php

declare(strict_types=1);

namespace App\Domain\Gateway;

use App\Models\Node;

interface GatewayWebConverger
{
    /** Converges the Gateway site on the Gateway's own Node, which the Node Caddy build then publishes. */
    public function converge(Node $node, string $hostname, string $wireguardIp): void;
}
