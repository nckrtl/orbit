<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;

interface MetricsPublicationManager
{
    public function converge(Node $gateway, Node $metrics): void;

    public function remove(Node $gateway, Node $metrics): void;

    /**
     * Removes only the Metrics node's side of the publication.
     *
     * Used when no single active Gateway can be resolved. The route, the
     * certificate and the DNS record are bound to a Gateway nobody can name,
     * so they stay where they are and the caller reports them as un-cleaned.
     */
    public function abandon(Node $metrics): void;
}
