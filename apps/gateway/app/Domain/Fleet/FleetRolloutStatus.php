<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** Where one fleet rollout stands ([Fleet rollout](/reference/gateway-recovery#fleet-rollout)). */
enum FleetRolloutStatus: string
{
    /** The desired state names no published CLI release yet. The catch-up tries again. */
    case Waiting = 'waiting';

    /** The rollout visits its Nodes, or will on its next run. */
    case Running = 'running';

    /** Every Node was visited. Unreachable or busy Nodes wait for the catch-up. */
    case Completed = 'completed';

    /** A Node failed. Nothing else runs until an operator resumes the rollout. */
    case Halted = 'halted';

    /** A newer desired state replaced this rollout before it finished. */
    case Superseded = 'superseded';

    public function isOpen(): bool
    {
        return $this === self::Waiting || $this === self::Running;
    }
}
