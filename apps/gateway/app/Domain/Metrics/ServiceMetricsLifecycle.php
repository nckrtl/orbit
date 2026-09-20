<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Models\Node;
use Closure;

interface ServiceMetricsLifecycle
{
    public function converge(Node $metricsNode, ?Closure $publish = null): void;

    public function remove(Node $metricsNode): void;

    public function removeNode(Node $node, Node $metricsNode): void;
}
