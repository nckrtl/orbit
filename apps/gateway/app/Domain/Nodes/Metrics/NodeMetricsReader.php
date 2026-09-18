<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Metrics;

use App\Models\Node;

interface NodeMetricsReader
{
    /**
     * Reads one metrics snapshot for the Node from the Metrics role's Grafana (Prometheus
     * datasource proxy).
     *
     * @return array<string, mixed>
     */
    public function read(Node $node): array;
}
