<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Data\Fleet\DesiredFleetStateData;
use App\Models\Node;

/** Visits one Node of a fleet rollout and reports its outcome. It never throws for a Node failure. */
interface FleetNodeVisitor
{
    /**
     * @param  bool  $allowDowngrade  The desired state is a verified release, so a Gateway rollback may downgrade the CLI to it.
     * @param  bool  $firstVisit  No Node of this rollout has converged yet, so a fault of Orbit's own templates shows here first.
     */
    public function converge(Node $node, DesiredFleetStateData $desired, bool $allowDowngrade = false, bool $firstVisit = false): FleetNodeResult;

    /** Looks again at a Node left out for a foreign CLI; returns whether it rejoined the rollout set. */
    public function recheckCli(Node $node): bool;
}
