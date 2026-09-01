<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;

final readonly class MetricsExporterProjectionItem
{
    public function __construct(
        public Node $node,
        public ExporterSelection $selection,
    ) {}
}
