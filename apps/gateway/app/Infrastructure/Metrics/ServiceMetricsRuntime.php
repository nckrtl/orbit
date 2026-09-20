<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Models\Node;

interface ServiceMetricsRuntime
{
    /** Snapshot includes only owned monitoring state; application content is never included. */
    public function snapshot(ServiceMetricsNode $target): string;

    public function converge(ServiceMetricsNode $target, Node $metricsNode): void;

    public function restore(ServiceMetricsNode $target, string $snapshot): void;
}
