<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Node;

/** Publishes `https://analytics.orbit`: the Orbit CA certificate, the Caddy site on the role's node, and the private DNS record. */
interface AnalyticsPublicationManager
{
    public function converge(Node $node): void;

    public function remove(Node $node): void;

    /** Removes only what lives on the Gateway, for a node Orbit cannot reach: the private DNS record. */
    public function removeUnreachable(Node $node): void;
}
