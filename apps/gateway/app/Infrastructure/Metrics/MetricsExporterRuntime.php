<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Models\Node;

interface MetricsExporterRuntime
{
    public function snapshot(Node $node, Node $metricsNode): MetricsExporterState;

    public function converge(Node $node, Node $metricsNode): void;

    public function remove(Node $node, Node $metricsNode): void;

    public function restore(Node $node, Node $metricsNode, MetricsExporterState $state): void;

    public function actual(Node $node, Node $metricsNode): string;
}
