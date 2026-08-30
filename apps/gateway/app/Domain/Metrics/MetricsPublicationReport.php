<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * Carries what the Metrics baseline did to the publication out to the caller.
 *
 * `RoleBaseline::remove()` returns nothing and runs behind
 * `RemoveNodeRoleAction`, so the disable response would otherwise infer the
 * outcome by reading the Gateway state a second time. That inference is wrong
 * in either direction whenever the Gateway state changes between the two
 * reads, and the claim it feeds is the one an operator acts on. The baseline
 * records what it actually did instead.
 *
 * The recorder is shared for one request and is deliberately not readonly.
 */
final class MetricsPublicationReport
{
    private ?MetricsPublicationCleanup $outcome = null;

    public function record(MetricsPublicationCleanup $outcome): void
    {
        $this->outcome = $outcome;
    }

    /** Reads the recorded outcome and clears it, so no later call can reuse it. */
    public function take(): ?MetricsPublicationCleanup
    {
        $outcome = $this->outcome;
        $this->outcome = null;

        return $outcome;
    }
}
