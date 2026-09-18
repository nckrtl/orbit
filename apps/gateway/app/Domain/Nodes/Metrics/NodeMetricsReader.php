<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Metrics;

use App\Models\Node;

interface NodeMetricsReader
{
    /**
     * Captures one synchronous metrics snapshot from the Node, decoded from
     * its `orbit internal:node-metrics` JSON output.
     *
     * @return array<string, mixed>
     */
    public function read(Node $node): array;
}
