<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;
use App\Models\NodeRole;

interface MetricsExporterLifecycle
{
    public function converge(Node $node, NodeRole $assignment): void;

    public function remove(Node $node, NodeRole $assignment): void;

    public function removeNode(Node $node, Node $metricsNode): void;

    public function actual(Node $node): string;

    /** @return list<array{name: string, address: string}> */
    public function targets(Node $metricsNode): array;
}
