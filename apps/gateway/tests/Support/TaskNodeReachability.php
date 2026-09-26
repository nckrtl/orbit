<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Models\Node;

/**
 * Answers the task-node reachability probe without opening SSH.
 * Cancel treats only an unreachable node as offline; a reachable answer still runs removal.
 */
function bind_task_node_reachability(bool $unreachable = false): void
{
    app()->instance(NodeReachabilityProbe::class, new class($unreachable) implements NodeReachabilityProbe
    {
        public function __construct(private bool $unreachable) {}

        public function degradation(Node $node): ?ExporterDegradationReason
        {
            return $this->unreachable ? ExporterDegradationReason::Unreachable : null;
        }
    });
}
