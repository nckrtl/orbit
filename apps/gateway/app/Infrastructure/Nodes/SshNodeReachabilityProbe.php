<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Models\Node;
use Throwable;

/**
 * Answers reachability with the SSH liveness check `orbit doctor` already uses.
 *
 * Anything short of a clean answer over the managed SSH path counts as
 * reachable, so a node that replies with nonsense keeps the fail-closed
 * teardown instead of being written off as gone.
 */
final readonly class SshNodeReachabilityProbe implements NodeReachabilityProbe
{
    public function __construct(
        private NodeStateInspector $inspector,
    ) {}

    #[\Override]
    public function degradation(Node $node): ?ExporterDegradationReason
    {
        try {
            $reachable = $this->inspector->inspect($node)->reachable;
        } catch (Throwable) {
            // The node answered with something the inspector could not read.
            // That is a broken node, not an absent one.
            return null;
        }

        return $reachable ? null : ExporterDegradationReason::Unreachable;
    }
}
