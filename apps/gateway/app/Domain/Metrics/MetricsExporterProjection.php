<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;

interface MetricsExporterProjection
{
    /** @return list<MetricsExporterProjectionItem> */
    public function for(Node $metricsNode): array;
}
