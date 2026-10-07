<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** The result of one Node in a fleet rollout. */
enum FleetNodeOutcome: string
{
    /** Not visited yet. */
    case Pending = 'pending';

    /** A step changed the Node, and the verify passed. */
    case Converged = 'converged';

    /** Nothing needed to change, and the verify passed. */
    case Unchanged = 'unchanged';

    /** SSH could not connect. The rollout skipped the Node and the catch-up converges it later. */
    case Unreachable = 'unreachable';

    /** Another operation held the Node's converge lock. The catch-up tries again. */
    case Deferred = 'deferred';

    /**
     * The CLI release of the desired state is not published yet, as `orbit self-update` on the Node saw
     * it. Nothing changed; the catch-up visits the Node again. It never halts the rollout.
     */
    case Waiting = 'waiting';

    /** The Node answered, but a step or the verify failed. The rollout halted here. */
    case Failed = 'failed';

    /** An operator resumed the rollout past this Node with `--skip`. */
    case Skipped = 'skipped';

    /** Whether the Node runs the desired state. */
    public function isConverged(): bool
    {
        return $this === self::Converged || $this === self::Unchanged;
    }

    /** Whether the catch-up should visit the Node again. */
    public function awaitsCatchUp(): bool
    {
        return $this === self::Pending || $this === self::Unreachable || $this === self::Deferred || $this === self::Waiting;
    }
}
