<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/** The default NodeMetricsSource until the Gateway exposes per-node metrics. */
final class NullNodeMetricsSource implements NodeMetricsSource
{
    public function forNode(int $nodeId): ?array
    {
        return null;
    }
}
