<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use Closure;

/**
 * Holds back the fleet Metrics reconcile that each role converge and removal runs, while one action
 * changes several roles and reconciles Metrics once when it is done.
 *
 * A role relocation converges the destination and retracts the source. Each step would otherwise
 * reconcile Metrics against a half-moved role, and a slow Metrics Node would hold the source
 * retraction behind it.
 */
final class MetricsReconcileDeferral
{
    private bool $deferring = false;

    private bool $requested = false;

    /**
     * Runs `$steps` with Metrics reconciles held back.
     *
     * @param  Closure(): void  $steps
     * @return bool Whether any step asked for a Metrics reconcile.
     */
    public function during(Closure $steps): bool
    {
        $this->deferring = true;
        $this->requested = false;

        try {
            $steps();

            return $this->requested;
        } finally {
            $this->deferring = false;
            $this->requested = false;
        }
    }

    /** Records a reconcile request and says whether the caller must hold it back. */
    public function defers(): bool
    {
        if (! $this->deferring) {
            return false;
        }

        $this->requested = true;

        return true;
    }
}
